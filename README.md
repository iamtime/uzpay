# uzpay

[![Packagist](https://img.shields.io/packagist/v/iamtime/uzpay)](https://packagist.org/packages/iamtime/uzpay)
[![PHP](https://img.shields.io/packagist/php-v/iamtime/uzpay)](https://packagist.org/packages/iamtime/uzpay)
[![License](https://img.shields.io/packagist/l/iamtime/uzpay)](LICENSE)

Payme, Click, Uzum, Paynet и Octo (карты Visa, Mastercard, Uzcard, Humo) для магазинов Узбекистана на чистом PHP. Ответы на вызовы провайдеров, сохранённые карты и повторные платежи Payme, ссылки на оплату, строки фискального чека и эмулятор оплаты для тестов. Без зависимостей, с любым фреймворком или без него.

Неофициальная библиотека: не связана с Payme, Click, Uzum, Paynet и Octo.

[O‘zbekcha](#ozbekcha) · [English](#english)

## Возможности

| Провайдер | Классы | Что умеет |
|---|---|---|
| Payme Merchant API | `Payme\Merchant` | все шесть методов: CheckPerformTransaction, CreateTransaction, PerformTransaction, CancelTransaction, CheckTransaction, GetStatement. Basic-авторизация, тестовый ключ, блокировка при создании и проведении, истечение транзакции через 12 часов |
| Payme Subscribe API | `Payme\Subscribe` | сохранённые карты (токен + код из SMS) и чеки, которые магазин оплачивает сам: подписки, повторные платежи, холдирование, возврат |
| Click SHOP API | `Click\Shop` | Prepare и Complete, подпись md5. Повторный Prepare возвращает ту же транзакцию |
| Uzum Bank Merchant API | `Uzum\Merchant` | check, create, confirm, reverse, status. Basic-авторизация, суммы в тийинах |
| Paynet | `Paynet\Merchant` | GetInformation, PerformTransaction, CheckTransaction, CancelTransaction, GetStatement, ChangePassword. Paynet списывает деньги до вызова, поэтому заказ оплачивается за один вызов |
| Octo | `Octo\Client`, `Octo\Payments`, `Octo\Notifications` | страница оплаты Octo, двухстадийная оплата (Octo держит деньги, мы отвечаем `capture` или `cancel`), возврат |

И ещё:

- ссылки на оплату: `Payme\Checkout`, `Click\Checkout`, `Uzum\Checkout`, `Paynet\Checkout`;
- фискальный чек `Fiscal\Receipt`: код МХИК (ИКПУ), код упаковки, ставка НДС, доставка и скидки; `problems()` показывает, что не примет налоговая; `Octo\Basket` превращает чек в корзину Octo;
- `Sandbox\Emulator` проигрывает вызовы любого из пяти провайдеров с вашими ключами: оплату можно проверить без провайдера;
- `Testing\InMemoryTransactions`, `Testing\FakeOcto`, `Testing\FakePaymeSubscribe` для тестов;
- `Money`: сумы ↔ тийины по цифрам, без ошибок округления float.

Если заказ изменили или оплатили другим способом, пока провайдер проводил оплату, эта оплата не проходит. Заказ помечается оплаченным ровно один раз, каким бы провайдером его ни оплатили.

## Установка

```bash
composer require iamtime/uzpay
```

PHP 8.2+ и `ext-mbstring`. Для Octo и Payme Subscribe API нужен `ext-curl` или свой `Http\Transport`.

## Как подключить

### 1. Четыре интерфейса

| Интерфейс | Что это |
|---|---|
| `Contracts\Payable` | то, что оплачивают: заказ или счёт. Номер, сумма в сумах (`"125000.00"`), состояние, `markPaidVia()`, фискальный чек |
| `Contracts\PayableSource` | поиск заказа по номеру из вызова провайдера и имя этого поля (`order_id`) |
| `Contracts\Gateway` | ключи магазина у провайдера и тестовый режим |
| `Contracts\TransactionStore` | таблица транзакций. `atomic()` запускает код в транзакции БД, чтения внутри неё блокируют строки (`SELECT … FOR UPDATE`) |

Готовые реализации есть в примерах: на PDO (`examples/pdo/`) и на Laravel (`examples/laravel/`). `Gateway` может быть совсем простым:

```php
final class Keys implements Uzpay\Contracts\Gateway
{
    public function __construct(private array $keys, private bool $test = false) {}

    public function credential(string $key): ?string { return $this->keys[$key] ?? null; }

    public function testMode(): bool { return $this->test; }
}

$payme = new Keys(['merchant_id' => getenv('PAYME_MERCHANT_ID'), 'key' => getenv('PAYME_KEY'), 'test_key' => getenv('PAYME_TEST_KEY')]);
```

### 2. Ключи

| Провайдер | Ключи в `Gateway::credential()` | Тестовый режим (`testMode()`) |
|---|---|---|
| Payme | `merchant_id` (id кассы), `key`, `test_key` | проверяется `test_key`; Subscribe API идёт на `checkout.test.paycom.uz`; ссылка - на `test.paycom.uz` |
| Click | `service_id`, `merchant_id`, `secret_key` | нет: тестовые ключи выдаёт Click |
| Uzum | `service_id`, `login`, `password` | нет: тестовые ключи выдаёт Uzum |
| Paynet | `service_id` (необязательно), `login`, `password` | нет: тестовые ключи выдаёт Paynet |
| Octo | `shop_id`, `secret`, `unique_key` (выдаёт поддержка Octo, нужен для подписи уведомлений) | в Octo уходит `test: true` |

### 3. Адреса для вызовов провайдеров

Payme, Uzum, Paynet и Octo присылают JSON, Click - поля формы. Адреса указываются в кабинетах провайдеров.

```php
use Uzpay\Click\Shop;
use Uzpay\Octo;
use Uzpay\Payme\Merchant;
use Uzpay\Paynet\Merchant as Paynet;
use Uzpay\Uzum\Merchant as Uzum;

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

// POST /payme - ответ всегда HTTP 200
$answer = (new Merchant($store, $orders))->handle($body, $auth, $paymeKeys);

// POST /click - Prepare и Complete на один адрес
$answer = (new Shop($store, $orders))->handle($_POST, $clickKeys);

// POST /uzum/{action} - Uzum добавляет к адресу /check, /create, /confirm, /reverse, /status
$answer = (new Uzum($store, $orders))->handle($action, $body, $auth, $uzumKeys);
http_response_code(Uzum::httpStatus($answer));

// POST /paynet - колбэк сохраняет новый пароль, если Paynet его сменит (без колбэка смена отклоняется)
$answer = (new Paynet($store, $orders, changePassword: fn (string $password) => $keys->save('password', $password)))
    ->handle($body, $auth, $paynetKeys);

// POST /octo - notify_url
$answer = (new Octo\Notifications($store))->handle($body, $octoKeys);
http_response_code(Octo\Notifications::httpStatus($answer));

header('Content-Type: application/json');
echo json_encode($answer);
```

Заказ становится оплаченным только здесь: `Payable::markPaidVia()` вызывается один раз, внутри транзакции `atomic()`. Возврат покупателя со страницы оплаты ничего не оплачивает.

### 4. Кнопка «Оплатить»

```php
use Uzpay\Money;

$tiyin = Money::tiyin($order->paymentAmount());

$url = Uzpay\Payme\Checkout::url($merchantId, 'order_id', $order->paymentKey(), $tiyin, $returnUrl, test: false);
$url = Uzpay\Click\Checkout::url($serviceId, $clickMerchantId, $order->paymentAmount(), $order->paymentKey(), $returnUrl);
$url = Uzpay\Uzum\Checkout::url($serviceId, 'order_id', $order->paymentKey(), $tiyin, $returnUrl);
$url = Uzpay\Paynet\Checkout::url($paynetMerchant, $order->paymentKey(), $tiyin);

// Octo: запрос к Octo, в ответ - страница оплаты. Описание по умолчанию - «Заказ №<номер>», страница на русском;
// и то и другое меняется через $options ('description', 'language').
$url = (new Uzpay\Octo\Payments($store, new Uzpay\Octo\Client($octoKeys)))->start($order, $returnUrl, 'https://shop.example/octo');
```

Octo фискализирует корзину только по товарам, которые менеджер Octo заранее внёс для магазина. Когда они внесены, передайте корзину: `start($order, $back, $notify, ['basket' => Octo\Basket::fromReceipt($order->fiscalDetail(), $inn)])`.

## Payme Subscribe API: сохранённые карты

Покупатель один раз сохраняет карту, дальше магазин сам списывает с неё деньги: подписка, ежемесячный счёт, оплата в одно касание.

Что нужно заранее:

- попросить Payme включить Subscribe API для кассы (и холдирование, если оно нужно);
- подключить Merchant API той же кассы (`Payme\Merchant`, раздел выше). Пока Payme оплачивает чек, он вызывает CheckPerformTransaction, CreateTransaction и PerformTransaction для номера заказа из чека. Заказ становится оплаченным там, а не по ответу `charge()`;
- ключи те же, что у Merchant API: `merchant_id`, `key`, `test_key`.

Сохранение карты происходит в браузере, номер карты не попадает на ваш сервер:

1. Форма карты вызывает `cards.create`, `cards.get_verify_code` и `cards.verify` на `checkout.paycom.uz/api` с заголовком `X-Auth: <id кассы>` (`Payme\Subscribe::frontAuth($keys)`). Код из SMS вводит владелец карты.
2. Браузер отправляет на сервер только токен.
3. Сервер проверяет токен и хранит токен и маску номера:

```php
use Uzpay\Payme\ReceiptState;
use Uzpay\Payme\Subscribe;
use Uzpay\Payme\SubscribeException;

$payme = new Subscribe($paymeKeys);

$card = $payme->checkCard($tokenFromBrowser);
if (! $card->verified || ! $card->recurrent) {
    // не сохраняем: карта не подтверждена или токен одноразовый
}
$customer->saveCard($card->token, $card->number); // "860006******6311"
```

Списание:

```php
try {
    // номер заказа уходит в поле order_id: то же поле, по которому ищет заказы ваш Merchant API
    $receipt = $payme->charge($invoice, $customer->cardToken, accountKey: 'order_id', description: 'Подписка');
    ReceiptState::from($receipt['state']); // Paid (4); заказ уже оплачен через Merchant API
} catch (SubscribeException $e) {
    // Payme отказал (недостаточно средств, карта заблокирована...): чек отменён, $e->getCode(), $e->messages['uz']
} catch (Uzpay\Http\TransportException $e) {
    // ответа нет - исход неизвестен: проверьте $payme->checkReceipt($id) прежде чем списывать снова
}
```

Остальное:

| Метод | Что делает |
|---|---|
| `createReceipt($amount, $account, $description, $detail, hold: false)` / `receiptFor($order)` | создать чек |
| `payReceipt($id, $token, $payer, hold: false)` | оплатить чек сохранённой картой |
| `charge($order, $token, ...)` | создать и оплатить; если Payme отказал, чек отменяется |
| `sendReceipt($id, $phone)` | Payme отправит покупателю SMS со ссылкой на оплату |
| `cancelReceipt($id)` | отменить неоплаченный, вернуть деньги за оплаченный, отпустить захолдированный |
| `confirmHold($id)` | списать захолдированные деньги (`hold: true` при создании и оплате) |
| `checkReceipt($id)` → `ReceiptState` | состояние: 0 создан, 4 оплачен, 5 холд, 50 отменён... |
| `getReceipt($id)`, `receipts($from, $to)` | чек целиком, список чеков за период (время в мс) |
| `createCard()`, `sendVerifyCode()`, `verifyCard()`, `removeCard()` | карты с сервера: только если вы сертифицированы для работы с номерами карт (PCI DSS); `removeCard()` - отвязать карту |

В песочнице Payme код из SMS для любой тестовой карты - `666666` (`Subscribe::TEST_CODE`).

## Тестовый режим и проверка без провайдера

`Testing\InMemoryTransactions` хранит транзакции в памяти, `Sandbox\Emulator` присылает вызовы провайдера в том же порядке и с той же подписью:

```php
use Uzpay\Sandbox\Emulator;
use Uzpay\Testing\InMemoryTransactions;

$store = new InMemoryTransactions;
$merchant = new Merchant($store, $orders);
$log = Emulator::payme(fn ($body, $auth) => $merchant->handle($body, $auth, $paymeKeys), 'test-key', 'order_id', $order, approve: true);
```

То же для `Emulator::click()`, `uzum()`, `paynet()` и `octo()`. API Octo в памяти - `Testing\FakeOcto`, Subscribe API - `Testing\FakePaymeSubscribe` (его колбэк получает оплаченный чек, чтобы проиграть вызовы Merchant API эмулятором). Их же можно поставить на тестовую страницу магазина с кнопками «оплатить» и «отказаться».

## Фискальный чек

```php
use Uzpay\Fiscal\Receipt;

$receipt = Receipt::make()
    ->item('Чехол для телефона', '50000', 2, '06109001001000000', '1503426', 12)
    ->shipping('Доставка', '15000')
    ->discount('Скидка', '5000');

$receipt->problems(); // [] или ["items.0.code", ...] - что не примет налоговая
$order->fiscalDetail(); // верните $receipt->toArray()
```

## Безопасность

- **Номера карт.** Сохраняйте карты через браузер (раздел Subscribe API). Сервер получает только токен и проверяет его `checkCard()`; флагам из браузера не доверяйте.
- **Ключи не в логах.** `Http\Transport` получает ключи в заголовках и теле запроса: своя реализация не должна их логировать. `TransportException` содержит адрес и начало ответа, без ключей. `FakeOcto::$requests` и `FakePaymeSubscribe::$requests` хранят запросы вместе с ключами - только для тестов.
- **Тест и прод.** Держите отдельный `test_key` Payme: без него в тестовом режиме используется боевой `key`, и он уйдёт на тестовый хост.
- **Адреса вызовов.** Пропускайте к ним только адреса провайдеров (их публикуют сами провайдеры), отключите для них CSRF. За Apache с PHP-FPM/CGI передайте заголовок Authorization: `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`.
- **Примеры.** Демо-ключи из `examples/` работают только в демо: вебхук примера без настоящих ключей не запускается.

Об уязвимостях сообщайте по [SECURITY.md](SECURITY.md), не в открытых issues.

## Примеры

Примеры лежат в репозитории (в пакет из Composer они не входят) и запускаются без установки: `php examples/sandbox.php`.

| Файл | Что показывает |
|---|---|
| `sandbox.php` | оплату целиком в памяти всеми пятью провайдерами; Click с отказом и повторной оплатой |
| `payme-subscribe.php` | сохранить карту с кодом из SMS и списывать с неё ежемесячные счета |
| `fiscal-receipt.php` | чек с МХИК, кодом упаковки и НДС; что налоговая не примет |
| `checkout-links.php` | ссылки на оплату Payme (боевую и тестовую), Click, Uzum и Paynet |
| `pdo/` | интеграция на чистом PHP: таблицы, заказ, хранилище транзакций, вебхук. `php examples/pdo/demo.php` оплачивает и возвращает заказы в SQLite |
| `laravel/` | то же для Laravel: миграция, модели, `EloquentTransactions`, контроллер с маршрутами |

Тесты пакета: `composer install && composer test`. Изменения - в [CHANGELOG.md](CHANGELOG.md). Лицензия MIT.

---

## O‘zbekcha

Payme, Click, Uzum, Paynet va Octo (Visa, Mastercard, Uzcard, Humo kartalari) O‘zbekiston do‘konlari uchun, toza PHP. Provayder so‘rovlariga javoblar, Payme saqlangan kartalari va takroriy to‘lovlar, to‘lov havolalari, fiskal chek qatorlari va sinov uchun to‘lov emulyatori. Bog‘liqliklarsiz, istalgan freymvork bilan yoki usiz. Norasmiy kutubxona: Payme, Click, Uzum, Paynet va Octo bilan bog‘liq emas.

O‘rnatish: `composer require iamtime/uzpay`. PHP 8.2+ va `ext-mbstring`; Octo va Payme Subscribe API uchun `ext-curl` yoki o‘zingizning `Http\Transport`.

**Imkoniyatlar**

- `Payme\Merchant`: Merchant API ning oltita metodi, Basic avtorizatsiya, sinov kaliti, 12 soatlik muddat, qulf ostida yaratish va o‘tkazish.
- `Payme\Subscribe`: saqlangan kartalar va do‘kon o‘zi to‘laydigan cheklar (obunalar, takroriy to‘lovlar, ushlab turish, qaytarish).
- `Click\Shop`: Prepare va Complete, md5 imzo.
- `Uzum\Merchant`: check, create, confirm, reverse, status; summalar tiyinda.
- `Paynet\Merchant`: GetInformation, PerformTransaction, CheckTransaction, CancelTransaction, GetStatement, ChangePassword. Yangi parolni ilova kolbeki saqlaydi.
- `Octo\*`: Octo sahifasida to‘lov, ikki bosqichli to‘lov (`capture` yoki `cancel`), qaytarish.
- To‘lov havolalari: `Payme\Checkout`, `Click\Checkout`, `Uzum\Checkout`, `Paynet\Checkout`.
- `Fiscal\Receipt`: MXIK (IKPU), qadoq kodi, QQS; `problems()` soliq qabul qilmaydigan joylarni ko‘rsatadi. `Octo\Basket` chekni Octo savatiga aylantiradi.
- `Sandbox\Emulator`, `Testing\InMemoryTransactions`, `Testing\FakeOcto`, `Testing\FakePaymeSubscribe`: provayderisiz sinov.
- `Money`: so‘m ↔ tiyin, float xatolarisiz.

**Ulash.** Ilova to‘rtta interfeysni amalga oshiradi: `Payable` (buyurtma), `PayableSource` (buyurtmani raqami bo‘yicha topish), `Gateway` (kalitlar va sinov rejimi), `TransactionStore` (tranzaksiyalar jadvali; `atomic()` DB tranzaksiyasi, ichidagi o‘qishlar qatorlarni qulflaydi). Kod misollari yuqorida, rus bo‘limida.

| Provayder | Kalitlar | Sinov rejimi |
|---|---|---|
| Payme | `merchant_id`, `key`, `test_key` | `test_key` tekshiriladi, Subscribe API `checkout.test.paycom.uz` ga boradi |
| Click | `service_id`, `merchant_id`, `secret_key` | yo‘q: sinov kalitlarini Click beradi |
| Uzum | `service_id`, `login`, `password` | yo‘q |
| Paynet | `service_id` (ixtiyoriy), `login`, `password` | yo‘q |
| Octo | `shop_id`, `secret`, `unique_key` | Octo ga `test: true` yuboriladi |

Manzillar: Payme, Uzum, Paynet va Octo JSON yuboradi, Click - forma maydonlari. Payme javobi har doim HTTP 200; Uzum va Octo uchun `Uzum\Merchant::httpStatus()` va `Octo\Notifications::httpStatus()`. Buyurtma faqat shu yerda to‘langan bo‘ladi: `markPaidVia()` bir marta, tranzaksiya ichida chaqiriladi.

**Payme Subscribe API.** Karta brauzerda saqlanadi: forma `cards.create`, `cards.get_verify_code`, `cards.verify` ni `X-Auth: <kassa id>` (`Subscribe::frontAuth()`) bilan chaqiradi, serverga faqat token keladi, server uni `checkCard()` bilan tekshiradi. Keyin `charge($buyurtma, $token)` chek yaratib to‘laydi; to‘lov paytida Payme kassaning Merchant API sini chaqiradi, buyurtma o‘sha yerda to‘lanadi. Boshqa metodlar: `createReceipt()`, `payReceipt()`, `sendReceipt()`, `cancelReceipt()`, `confirmHold()`, `checkReceipt()` → `ReceiptState`, `getReceipt()`, `receipts()`, `removeCard()`. Xatolar: `SubscribeException` (kod va ru / uz / en matnlar). Payme qumdonida SMS kodi - `666666`.

**Xavfsizlik.** Karta raqamlari serverga kelmasin; kalitlarni loglarga yozmang (o‘z `Http\Transport` ingiz sarlavha va tanani loglamasin); Payme uchun alohida `test_key` saqlang; kolbek manzillariga faqat provayderlar manzillarini qo‘ying va CSRF ni o‘chiring. Zaifliklar haqida [SECURITY.md](SECURITY.md) orqali xabar bering.

Misollar repozitoriyda (`examples/`): `sandbox.php`, `payme-subscribe.php`, `fiscal-receipt.php`, `checkout-links.php`, `pdo/`, `laravel/`. Testlar: `composer install && composer test`. Litsenziya: MIT.

---

## English

Payme, Click, Uzum, Paynet and Octo (Visa, Mastercard, Uzcard and Humo cards) for Uzbekistan shops in plain PHP: answers to the providers' callbacks, Payme saved cards and recurring payments, checkout links, fiscal receipt lines and a payment emulator for tests. No dependencies; works with any framework or none. Unofficial: not affiliated with Payme, Click, Uzum, Paynet or Octo.

### Features

| Provider | Classes | What it does |
|---|---|---|
| Payme Merchant API | `Payme\Merchant` | all six methods: CheckPerformTransaction, CreateTransaction, PerformTransaction, CancelTransaction, CheckTransaction, GetStatement. Basic auth, test key, a lock around create and perform, transactions expire after 12 hours |
| Payme Subscribe API | `Payme\Subscribe` | saved cards (a token plus the SMS code) and receipts the shop charges itself: subscriptions, repeat payments, holds, refunds |
| Click SHOP API | `Click\Shop` | Prepare and Complete, md5 signature. A repeated Prepare returns the same transaction |
| Uzum Bank Merchant API | `Uzum\Merchant` | check, create, confirm, reverse, status. Basic auth, amounts in tiyin |
| Paynet | `Paynet\Merchant` | GetInformation, PerformTransaction, CheckTransaction, CancelTransaction, GetStatement, ChangePassword. Paynet takes the money before calling, so an order is paid in one call |
| Octo | `Octo\Client`, `Octo\Payments`, `Octo\Notifications` | Octo's payment page, two-stage payment (Octo holds the money, you answer `capture` or `cancel`), refunds |

Also: checkout links (`Payme\Checkout`, `Click\Checkout`, `Uzum\Checkout`, `Paynet\Checkout`), fiscal receipt lines (`Fiscal\Receipt` with the MXIK (IKPU) code, package code and VAT; `problems()` lists what the tax office will reject; `Octo\Basket`), `Sandbox\Emulator`, `Testing\InMemoryTransactions`, `Testing\FakeOcto`, `Testing\FakePaymeSubscribe`, and `Money` (so'm ↔ tiyin digit by digit, no float rounding).

If the order was changed or paid another way while a provider was paying it, that payment does not go through. An order is marked paid exactly once, whichever provider paid it.

### Install

```bash
composer require iamtime/uzpay
```

PHP 8.2+ and `ext-mbstring`. Octo and the Payme Subscribe API need `ext-curl` or your own `Http\Transport`.

### Setup

Your app implements four interfaces:

| Interface | What it is |
|---|---|
| `Contracts\Payable` | what is paid: an order or an invoice. Number, sum in so'm (`"125000.00"`), state, `markPaidVia()`, the fiscal receipt |
| `Contracts\PayableSource` | finds an order by the number in a callback and names that field (`order_id`) |
| `Contracts\Gateway` | your keys at a provider and the test mode |
| `Contracts\TransactionStore` | the transactions table. `atomic()` runs a DB transaction; reads inside it lock rows (`SELECT … FOR UPDATE`) |

Ready implementations are in `examples/pdo/` (plain PDO) and `examples/laravel/` (Eloquent).

| Provider | Keys in `Gateway::credential()` | Test mode (`testMode()`) |
|---|---|---|
| Payme | `merchant_id` (cashbox id), `key`, `test_key` | `test_key` is checked; the Subscribe API goes to `checkout.test.paycom.uz`; links go to `test.paycom.uz` |
| Click | `service_id`, `merchant_id`, `secret_key` | none: Click issues test keys |
| Uzum | `service_id`, `login`, `password` | none: Uzum issues test keys |
| Paynet | `service_id` (optional), `login`, `password` | none: Paynet issues test keys |
| Octo | `shop_id`, `secret`, `unique_key` (from Octo support, signs notifications) | sends `test: true` to Octo |

### Callbacks

Payme, Uzum, Paynet and Octo post JSON, Click posts form fields. Set the URLs in each provider's merchant dashboard.

```php
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

$answer = (new Uzpay\Payme\Merchant($store, $orders))->handle($body, $auth, $paymeKeys);        // POST /payme, always HTTP 200
$answer = (new Uzpay\Click\Shop($store, $orders))->handle($_POST, $clickKeys);                 // POST /click, Prepare and Complete
$answer = (new Uzpay\Uzum\Merchant($store, $orders))->handle($action, $body, $auth, $uzumKeys); // POST /uzum/{action}
http_response_code(Uzpay\Uzum\Merchant::httpStatus($answer));
$answer = (new Uzpay\Paynet\Merchant($store, $orders, changePassword: fn ($password) => $keys->save('password', $password)))
    ->handle($body, $auth, $paynetKeys);                                                       // POST /paynet
$answer = (new Uzpay\Octo\Notifications($store))->handle($body, $octoKeys);                    // POST /octo (notify_url)
http_response_code(Uzpay\Octo\Notifications::httpStatus($answer));

header('Content-Type: application/json');
echo json_encode($answer);
```

An order becomes paid only here: `Payable::markPaidVia()` is called once, inside `atomic()`. The buyer coming back from a payment page pays nothing.

Checkout links: `Payme\Checkout::url($merchantId, 'order_id', $number, $tiyin, $returnUrl, test: false)`, `Click\Checkout::url($serviceId, $merchantId, $amount, $number, $returnUrl)`, `Uzum\Checkout::url($serviceId, 'order_id', $number, $tiyin, $returnUrl)`, `Paynet\Checkout::url($merchant, $number, $tiyin)`. For Octo, `(new Octo\Payments($store, new Octo\Client($octoKeys)))->start($order, $returnUrl, $notifyUrl)` asks Octo for the page; the default description is Russian ("Заказ №<number>") and so is the page - change them with `$options` (`description`, `language`). Octo fiscalises a basket only for goods its manager has registered for the shop: then pass `['basket' => Octo\Basket::fromReceipt($order->fiscalDetail(), $tin)]`.

### Payme Subscribe API: saved cards

The buyer saves a card once; then the shop charges it itself (subscriptions, monthly invoices, one-tap payments).

Before you start: ask Payme to enable the Subscribe API (and holds, if needed) for the cashbox, and connect the Merchant API of the same cashbox (`Payme\Merchant`). While paying a receipt Payme calls CheckPerformTransaction, CreateTransaction and PerformTransaction for the receipt's account: the order becomes paid there, never from the answer of `charge()`. The keys are those of the Merchant API.

Saving a card happens in the browser, so the card number never reaches your server: the card form calls `cards.create`, `cards.get_verify_code` and `cards.verify` on `checkout.paycom.uz/api` with `X-Auth: <cashbox id>` (`Payme\Subscribe::frontAuth($keys)`), the owner types the SMS code, and only the token comes to your server.

```php
use Uzpay\Payme\Subscribe;
use Uzpay\Payme\SubscribeException;

$payme = new Subscribe($paymeKeys);

$card = $payme->checkCard($tokenFromBrowser);           // never trust flags sent by the browser
if ($card->verified && $card->recurrent) {
    $customer->saveCard($card->token, $card->number);  // "860006******6311"
}

try {
    // the order number goes to "order_id": the field your Merchant API finds orders by
    $receipt = $payme->charge($invoice, $customer->cardToken, accountKey: 'order_id', description: 'Subscription');
} catch (SubscribeException $e) {
    // Payme refused (no money, card blocked...): the receipt is canceled; $e->getCode(), $e->messages['en']
} catch (Uzpay\Http\TransportException $e) {
    // no answer - the outcome is unknown: check $payme->checkReceipt($id) before charging again
}
```

Other methods: `createReceipt()`, `receiptFor()`, `payReceipt()`, `sendReceipt()` (Payme texts the buyer a payment link), `cancelReceipt()` (drop an unpaid receipt, refund a paid one, release a hold), `confirmHold()` (take held money; pass `hold: true` when creating and paying), `checkReceipt()` → `ReceiptState`, `getReceipt()`, `receipts($from, $to)`, and the card methods `createCard()`, `sendVerifyCode()`, `verifyCard()`, `removeCard()` (call the first three from a server only if you are PCI DSS certified). In Payme's sandbox the SMS code of every test card is `666666` (`Subscribe::TEST_CODE`).

### Testing without the providers

`Testing\InMemoryTransactions` keeps transactions in memory, and `Sandbox\Emulator` sends a provider's calls in the same order and signed with your keys:

```php
$store = new Uzpay\Testing\InMemoryTransactions;
$merchant = new Uzpay\Payme\Merchant($store, $orders);
$log = Uzpay\Sandbox\Emulator::payme(fn ($body, $auth) => $merchant->handle($body, $auth, $paymeKeys), 'test-key', 'order_id', $order, approve: true);
```

The same goes for `Emulator::click()`, `uzum()`, `paynet()` and `octo()`. `Testing\FakeOcto` is Octo's API in memory, and `Testing\FakePaymeSubscribe` is the Subscribe API in memory (its callback gets every paid receipt so you can replay the Merchant API calls with the emulator).

### Security

- **Card numbers.** Save cards from the browser (see above). The server gets only the token and checks it with `checkCard()`.
- **Keys out of logs.** `Http\Transport` receives keys in headers and the body: your implementation must not log them. `TransportException` carries the URL and the start of the answer, never the keys. `FakeOcto::$requests` and `FakePaymeSubscribe::$requests` keep requests with the keys - for tests only.
- **Test vs live.** Keep a separate Payme `test_key`: without it test mode uses the live `key` and sends it to the test host.
- **Callback URLs.** Allow only the providers' published addresses, exempt the routes from CSRF, and behind Apache with PHP-FPM/CGI pass the Authorization header on (`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`).
- **Examples.** The demo keys in `examples/` work only in the demo: the example webhook refuses to start without real keys.

Report vulnerabilities as described in [SECURITY.md](SECURITY.md), not in public issues.

### Examples

The examples are in the repository (Composer does not install them) and run without installing: `php examples/sandbox.php`.

- `sandbox.php`: a whole payment in memory through all five providers;
- `payme-subscribe.php`: save a card with the SMS code and charge monthly invoices;
- `fiscal-receipt.php`: a receipt and what the tax office would reject;
- `checkout-links.php`: payment links;
- `pdo/`: a working integration in plain PHP with PDO (`php examples/pdo/demo.php`);
- `laravel/`: the Laravel version with a migration, models, a store and a controller.

Tests: `composer install && composer test`. Changes: [CHANGELOG.md](CHANGELOG.md). License: MIT.
