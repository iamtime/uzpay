<?php

namespace Uzpay\Paynet;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Uzpay\Contracts\Gateway;
use Uzpay\Contracts\Payable;
use Uzpay\Contracts\PayableSource;
use Uzpay\Contracts\TransactionStore;
use Uzpay\Money;
use Uzpay\PayableState;
use Uzpay\TransactionState as State;

/**
 * Paynet provider API: JSON-RPC 2.0 over HTTP, Basic auth (login and password Paynet gave), amounts in tiyin,
 * times as "Y-m-d H:i:s" in Tashkent time. Give it the decoded body and the Authorization header; send back
 * the array it returns as JSON with HTTP 200.
 *
 * Paynet takes the money first and then calls PerformTransaction: there is no separate create step, so
 * the payable is paid (or refused) in that one call. The key comes in fields.<account key> (order_id).
 *
 * ChangePassword: Paynet may set a new password for its calls. Pass $changePassword to store it with the
 * merchant's keys; without it the call is refused and the old password stays.
 */
final class Merchant
{
    public const TIMEZONE = 'Asia/Tashkent';

    private Closure $now;

    /**
     * @param  (Closure(string $password): void)|null  $changePassword
     */
    public function __construct(
        private TransactionStore $store,
        private PayableSource $source,
        private string $provider = 'paynet',
        private ?Closure $changePassword = null,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? fn (): int => (int) round(microtime(true) * 1000);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function handle(array $body, ?string $authorization, Gateway $gateway): array
    {
        $id = $body['id'] ?? null;

        if (! self::authorized($authorization, $gateway)) {
            return Errors::response($id, Errors::INVALID_LOGIN);
        }
        if (! isset($body['method']) || ! is_array($body['params'] ?? null)) {
            return Errors::response($id, Errors::INVALID_REQUEST);
        }
        $params = $body['params'];
        $service = $gateway->credential('service_id');
        if ($service !== null && isset($params['serviceId']) && (string) $params['serviceId'] !== $service) {
            return Errors::response($id, Errors::SERVICE_NOT_FOUND);
        }

        return match ((string) $body['method']) {
            'GetInformation' => $this->information($id, $params),
            'PerformTransaction' => $this->store->atomic(fn (): array => $this->perform($id, $params)),
            'CheckTransaction' => $this->check($id, $params),
            'CancelTransaction' => $this->store->atomic(fn (): array => $this->cancel($id, $params)),
            'GetStatement' => $this->statement($id, $params),
            'ChangePassword' => $this->changePassword($id, $params),
            default => Errors::response($id, Errors::METHOD_NOT_FOUND),
        };
    }

    public static function authorized(?string $authorization, Gateway $gateway): bool
    {
        $login = $gateway->credential('login');
        $password = $gateway->credential('password');
        if ($login === null || $password === null || ! str_starts_with((string) $authorization, 'Basic ')) {
            return false;
        }
        [$user, $secret] = array_pad(explode(':', (string) base64_decode(substr((string) $authorization, 6)), 2), 2, '');

        return hash_equals($login, $user) && hash_equals($password, $secret);
    }

    /** Unix milliseconds → "2026-10-04 12:00:00" in Tashkent time. */
    public static function time(int $milliseconds): string
    {
        return (new DateTimeImmutable('@'.intdiv($milliseconds, 1000)))->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i:s');
    }

    /** "2026-10-04 12:00:00" in Tashkent time → Unix milliseconds, null when unreadable. */
    public static function milliseconds(mixed $time): ?int
    {
        if (! is_string($time)) {
            return null;
        }
        try {
            $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time, new DateTimeZone(self::TIMEZONE));
        } catch (Throwable) {
            return null;
        }

        return $moment ? $moment->getTimestamp() * 1000 : null;
    }

    private function information(mixed $id, array $params): array
    {
        $payable = $this->payableFor($params);
        if (is_int($payable)) {
            return Errors::response($id, $payable);
        }

        return Errors::result($id, [
            'status' => '0',
            'timestamp' => self::time($this->now()),
            'fields' => [$this->source->accountKey() => $payable->paymentKey(), 'amount' => Money::tiyin($payable->paymentAmount())],
        ]);
    }

    private function perform(mixed $id, array $params): array
    {
        $external = (string) ($params['transactionId'] ?? '');
        if ($external === '') {
            return Errors::response($id, Errors::MISSING_PARAMETERS);
        }
        if ($this->store->find($this->provider, $external)) {
            return Errors::response($id, Errors::TRANSACTION_EXISTS);
        }

        // Checked on the locked row: a payment through another provider at the same moment is seen.
        $payable = $this->payableFor($params, lock: true);
        if (is_int($payable)) {
            return Errors::response($id, $payable);
        }
        if (! is_numeric($params['amount'] ?? null) || Money::tiyin($payable->paymentAmount()) !== (int) $params['amount']) {
            return Errors::response($id, Errors::INVALID_AMOUNT);
        }

        $now = $this->now();
        $transaction = $this->store->create($this->provider, $payable, [
            'external_id' => $external,
            'amount' => $payable->paymentAmount(),
            'state' => State::PERFORMED,
            'create_time' => $now,
            'perform_time' => $now,
            'payload' => ['time' => $params['transactionTime'] ?? null],
        ]);
        $payable->markPaidVia($this->provider);

        return Errors::result($id, [
            'providerTrnId' => $transaction->id,
            'timestamp' => self::time($now),
            'fields' => [$this->source->accountKey() => $payable->paymentKey()],
        ]);
    }

    private function check(mixed $id, array $params): array
    {
        $transaction = $this->store->find($this->provider, (string) ($params['transactionId'] ?? ''));

        return Errors::result($id, match (true) {
            ! $transaction => ['transactionState' => TransactionState::NOT_FOUND, 'providerTrnId' => 0, 'timestamp' => self::time($this->now())],
            default => [
                'transactionState' => $transaction->state === State::PERFORMED ? TransactionState::SUCCESS : TransactionState::CANCELED,
                'providerTrnId' => $transaction->id,
                'timestamp' => self::time($transaction->cancelTime ?? $transaction->performTime ?? $transaction->createTime ?? $this->now()),
            ],
        });
    }

    private function cancel(mixed $id, array $params): array
    {
        $transaction = $this->store->find($this->provider, (string) ($params['transactionId'] ?? ''));
        if (! $transaction) {
            return Errors::response($id, Errors::TRANSACTION_NOT_FOUND);
        }
        if ($transaction->state !== State::PERFORMED) {
            return Errors::response($id, Errors::TRANSACTION_CANCELED);
        }
        $payable = $this->store->payable($transaction, lock: true);
        if (! $payable?->canRefund()) {
            return Errors::response($id, Errors::CANNOT_CANCEL);
        }

        $transaction = $this->store->update($transaction, ['state' => State::CANCELED_AFTER_PERFORM, 'cancel_time' => $this->now()]);
        $payable->markRefunded();

        return Errors::result($id, [
            'providerTrnId' => $transaction->id,
            'timestamp' => self::time((int) $transaction->cancelTime),
            'transactionState' => TransactionState::CANCELED,
        ]);
    }

    private function statement(mixed $id, array $params): array
    {
        $from = self::milliseconds($params['dateFrom'] ?? null);
        $to = self::milliseconds($params['dateTo'] ?? null);
        if ($from === null || $to === null) {
            return Errors::response($id, Errors::INVALID_DATE);
        }

        $statements = [];
        foreach ($this->store->between($this->provider, $from, $to + 999) as $t) {
            if ($t->state === State::PERFORMED) {
                $statements[] = [
                    'amount' => Money::tiyin($t->amount),
                    'providerTrnId' => $t->id,
                    'transactionId' => $t->externalId,
                    'timestamp' => self::time((int) $t->createTime),
                ];
            }
        }

        return Errors::result($id, ['statements' => $statements]);
    }

    private function changePassword(mixed $id, array $params): array
    {
        $password = $params['newPassword'] ?? null;
        if (! $this->changePassword) {
            return Errors::response($id, Errors::ACCESS_DENIED);
        }
        if (! is_string($password) || $password === '') {
            return Errors::response($id, Errors::MISSING_PARAMETERS);
        }
        ($this->changePassword)($password);

        return Errors::result($id, 'success');
    }

    /** The thing being paid, or an error code. */
    private function payableFor(array $params, bool $lock = false): Payable|int
    {
        $fields = is_array($params['fields'] ?? null) ? $params['fields'] : [];
        $account = $fields[$this->source->accountKey()] ?? null;
        if (! is_scalar($account) || (string) $account === '') {
            return Errors::MISSING_PARAMETERS;
        }
        $payable = $this->source->find((string) $account);
        if ($payable && $lock) {
            $payable = $this->store->lock($payable);
        }

        return match (true) {
            // Paynet has no "already paid" code: a paid or closed order is "not found" for it.
            ! $payable, $payable->paymentState() !== PayableState::Open => Errors::CLIENT_NOT_FOUND,
            default => $payable,
        };
    }

    private function now(): int
    {
        return ($this->now)();
    }
}
