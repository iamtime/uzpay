# Changelog

All notable changes to `iamtime/uzpay`. The format follows [Keep a Changelog](https://keepachangelog.com/), versions follow [Semantic Versioning](https://semver.org/). Until 1.0 a minor version may change the public API; such changes are listed under "Changed".

## [0.1.0] - 2026-10-07

First public release.

### Added

- Payme Merchant API (`Payme\Merchant`): CheckPerformTransaction, CreateTransaction, PerformTransaction, CancelTransaction, CheckTransaction, GetStatement.
- Payme Subscribe API (`Payme\Subscribe`): saved cards (`createCard`, `sendVerifyCode`, `verifyCard`, `checkCard`, `removeCard`) and receipts (`createReceipt`, `receiptFor`, `payReceipt`, `charge`, `sendReceipt`, `cancelReceipt`, `confirmHold`, `checkReceipt`, `getReceipt`, `receipts`); `Payme\ReceiptState`, `Payme\Card`, `Payme\SubscribeException`.
- Click SHOP API (`Click\Shop`): Prepare and Complete.
- Uzum Bank Merchant API (`Uzum\Merchant`): check, create, confirm, reverse, status.
- Paynet (`Paynet\Merchant`): GetInformation, PerformTransaction, CheckTransaction, CancelTransaction, GetStatement, ChangePassword.
- Octo (`Octo\Client`, `Octo\Payments`, `Octo\Notifications`): Visa, Mastercard, Uzcard and Humo on Octo's payment page, two-stage capture, refunds.
- Checkout links for Payme, Click, Uzum and Paynet.
- Fiscal receipt lines (`Fiscal\Receipt`) with MXIK (IKPU), package code and VAT; `Octo\Basket`.
- `Sandbox\Emulator` for all five providers, `Testing\InMemoryTransactions`, `Testing\FakeOcto`, `Testing\FakePaymeSubscribe`.
- `Money`: so'm ↔ tiyin without float rounding.
- `Http\Transport` (`post($url, $body, $headers = [])`) with `Http\CurlTransport`.
- `UzpayException`: every error the package throws on purpose implements it (`Http\TransportException`, `Octo\OctoException`, `Payme\SubscribeException`, `InvalidAmount` from `Money`).

[0.1.0]: https://github.com/iamtime/uzpay/releases/tag/v0.1.0
