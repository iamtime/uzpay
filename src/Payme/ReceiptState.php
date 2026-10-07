<?php

namespace Uzpay\Payme;

/**
 * State of a Payme Subscribe API receipt (receipts.check, the "state" of a receipt).
 */
enum ReceiptState: int
{
    case Created = 0;
    case Checking = 1;
    case Charging = 2;
    case Closing = 3;
    case Paid = 4;
    case Held = 5;
    case Holding = 6;
    case Paused = 20;
    case Canceling = 21;
    case ClosingQueued = 30;
    case Canceled = 50;

    /** A label for people, in Russian, Uzbek or English. */
    public function label(string $lang = 'ru'): string
    {
        $labels = match ($this) {
            self::Created => ['Чек создан, ждёт оплаты', 'Chek yaratildi, to‘lov kutilmoqda', 'Created, waiting for payment'],
            self::Checking => ['Проверка, создание транзакции у поставщика', 'Tekshiruv, yetkazib beruvchida tranzaksiya yaratilmoqda', 'Checking, creating the merchant transaction'],
            self::Charging => ['Списание денег с карты', 'Kartadan pul yechilmoqda', 'Charging the card'],
            self::Closing => ['Закрытие транзакции у поставщика', 'Yetkazib beruvchida tranzaksiya yopilmoqda', 'Closing the merchant transaction'],
            self::Paid => ['Чек оплачен', 'Chek to‘landi', 'Paid'],
            self::Held => ['Деньги захолдированы', 'Pul ushlab turilibdi', 'Money on hold'],
            self::Holding => ['Холдирование средств', 'Pul ushlab turilmoqda', 'Placing a hold'],
            self::Paused => ['Пауза для ручной проверки', 'Qo‘lda tekshirish uchun to‘xtatilgan', 'Paused for a manual check'],
            self::Canceling => ['В очереди на отмену', 'Bekor qilish navbatida', 'Queued for cancellation'],
            self::ClosingQueued => ['В очереди на закрытие транзакции', 'Tranzaksiyani yopish navbatida', 'Queued for closing the transaction'],
            self::Canceled => ['Чек отменён', 'Chek bekor qilindi', 'Canceled'],
        };

        return $labels[['ru' => 0, 'uz' => 1, 'en' => 2][$lang] ?? 0];
    }

    /**
     * Payme has finished with it: paid or canceled. A paid receipt can still be refunded with cancelReceipt();
     * a held one waits for confirmHold() or cancelReceipt().
     */
    public function isFinal(): bool
    {
        return $this === self::Paid || $this === self::Canceled;
    }

    /** Payme is still working on it: ask again later (Subscribe::checkReceipt()). */
    public function inProgress(): bool
    {
        return in_array($this, [self::Checking, self::Charging, self::Closing, self::Holding, self::Paused, self::Canceling, self::ClosingQueued], true);
    }
}
