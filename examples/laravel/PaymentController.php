<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Payments\ConfigGateway;
use App\Payments\EloquentTransactions;
use App\Payments\Orders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Uzpay\Click\Checkout as ClickCheckout;
use Uzpay\Click\Shop;
use Uzpay\Money;
use Uzpay\Octo\Client as OctoClient;
use Uzpay\Octo\Notifications as OctoNotifications;
use Uzpay\Octo\Payments as OctoPayments;
use Uzpay\Payme\Checkout as PaymeCheckout;
use Uzpay\Payme\Merchant;
use Uzpay\Paynet\Checkout as PaynetCheckout;
use Uzpay\Paynet\Merchant as PaynetMerchant;
use Uzpay\Uzum\Checkout as UzumCheckout;
use Uzpay\Uzum\Merchant as UzumMerchant;

/**
 * routes/web.php:
 *
 *   Route::post('/payments/payme', [PaymentController::class, 'payme']);
 *   Route::post('/payments/click', [PaymentController::class, 'click']);
 *   Route::post('/payments/uzum/{action}', [PaymentController::class, 'uzum']);
 *   Route::post('/payments/paynet', [PaymentController::class, 'paynet']);
 *   Route::post('/payments/octo', [PaymentController::class, 'octo'])->name('payments.octo');
 *   Route::get('/orders/{order}/pay/{provider}', [PaymentController::class, 'pay'])->name('orders.pay');
 *   // pay() sends the buyer back to your order page, named orders.show here.
 *
 * bootstrap/app.php - the callbacks come without a CSRF token (their own auth and signature are checked):
 *
 *   $middleware->validateCsrfTokens(except: ['payments/*']);
 *
 * config/services.php:
 *
 *   'payme' => ['merchant_id' => env('PAYME_MERCHANT_ID'), 'key' => env('PAYME_KEY'), 'test_key' => env('PAYME_TEST_KEY'), 'test' => env('PAYME_TEST', false)],
 *   'click' => ['service_id' => env('CLICK_SERVICE_ID'), 'merchant_id' => env('CLICK_MERCHANT_ID'), 'secret_key' => env('CLICK_SECRET_KEY'), 'test' => false],
 *   'uzum' => ['service_id' => env('UZUM_SERVICE_ID'), 'login' => env('UZUM_LOGIN'), 'password' => env('UZUM_PASSWORD'), 'test' => false],
 *   // 'merchant' only builds the Paynet checkout link (Paynet\Checkout::url())
 *   'paynet' => ['service_id' => env('PAYNET_SERVICE_ID'), 'merchant' => env('PAYNET_MERCHANT'), 'login' => env('PAYNET_LOGIN'), 'password' => env('PAYNET_PASSWORD'), 'test' => false],
 *   'octo' => ['shop_id' => env('OCTO_SHOP_ID'), 'secret' => env('OCTO_SECRET'), 'unique_key' => env('OCTO_UNIQUE_KEY'), 'test' => env('OCTO_TEST', false)],
 */
class PaymentController extends Controller
{
    public function payme(Request $request): JsonResponse
    {
        $merchant = new Merchant(new EloquentTransactions(Order::class), new Orders);

        return response()->json($merchant->handle($request->all(), $request->header('Authorization'), new ConfigGateway('payme')));
    }

    public function click(Request $request): JsonResponse
    {
        $shop = new Shop(new EloquentTransactions(Order::class), new Orders);

        return response()->json($shop->handle($request->all(), new ConfigGateway('click')));
    }

    public function uzum(Request $request, string $action): JsonResponse
    {
        $merchant = new UzumMerchant(new EloquentTransactions(Order::class), new Orders);
        $answer = $merchant->handle($action, $request->all(), $request->header('Authorization'), new ConfigGateway('uzum'));

        return response()->json($answer, UzumMerchant::httpStatus($answer));
    }

    /** Paynet's ChangePassword is refused here: keys in .env can't be rewritten. Keep them in the database to accept it. */
    public function paynet(Request $request): JsonResponse
    {
        $merchant = new PaynetMerchant(new EloquentTransactions(Order::class), new Orders);

        return response()->json($merchant->handle($request->all(), $request->header('Authorization'), new ConfigGateway('paynet')));
    }

    public function octo(Request $request): JsonResponse
    {
        $answer = (new OctoNotifications(new EloquentTransactions(Order::class)))->handle($request->all(), new ConfigGateway('octo'));

        return response()->json($answer, OctoNotifications::httpStatus($answer));
    }

    /** The buyer's "Pay" button. The order is paid by the callback above, never by coming back from this page. */
    public function pay(Order $order, string $provider): RedirectResponse
    {
        $back = route('orders.show', $order);
        $keys = new ConfigGateway($provider);
        $tiyin = Money::tiyin($order->paymentAmount());

        return redirect()->away(match ($provider) {
            'payme' => PaymeCheckout::url((string) $keys->credential('merchant_id'), 'order_id', $order->paymentKey(), $tiyin, $back, $keys->testMode()),
            'click' => ClickCheckout::url((string) $keys->credential('service_id'), (string) $keys->credential('merchant_id'), $order->paymentAmount(), $order->paymentKey(), $back),
            'uzum' => UzumCheckout::url((string) $keys->credential('service_id'), 'order_id', $order->paymentKey(), $tiyin, $back),
            'paynet' => PaynetCheckout::url((string) $keys->credential('merchant'), $order->paymentKey(), $tiyin),
            // Octo gives a new page for every attempt; Visa and Mastercard are paid there.
            'octo' => (new OctoPayments(new EloquentTransactions(Order::class), new OctoClient($keys)))->start($order, $back, route('payments.octo')),
            default => abort(404),
        });
    }
}
