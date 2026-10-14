# Indonesia Multi Payment Gateways

SDK Laravel untuk Midtrans, Xendit, DOKU, Paywuz, dan iPaymu. Satu API dengan DTO dan enum untuk direct QRIS/virtual account, hosted checkout, status, daftar metode, dan webhook terverifikasi.

PHP 8.2+ dan Laravel 12, atau PHP 8.3+ dan Laravel 13. Membutuhkan OpenSSL. Semua nominal menggunakan integer Rupiah; nomor VA dan identifier tetap string agar nol di depan tidak hilang.

Panduan lengkap dengan contoh pemakaian:

- [Panduan penggunaan](docs/usage.md): instalasi, DTO, QRIS/VA, hosted checkout, status, webhook, error, dan HTTP fake.
- [Gateway dan options](docs/gateways.md): konfigurasi dan contoh Midtrans, Xendit, DOKU, Paywuz, serta iPaymu.
- [Contoh integrasi Laravel](docs/laravel-example.md): migration, model, controller, routes, webhook, dan test alur pembayaran.

## Instalasi

Setelah package dipublikasikan ke registry Composer:

```bash
composer require bayurifkialghifari/indonesia-multipayment-gateways
php artisan vendor:publish --tag=payments-config
```

Untuk mencoba checkout lokal sebelum publikasi, tambahkan path repository ke composer.json aplikasi:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../indonesia-multipayment-gateways",
      "options": {
        "symlink": true,
        "versions": {
          "bayurifkialghifari/indonesia-multipayment-gateways": "dev-main"
        }
      }
    }
  ]
}
```

Lalu jalankan `composer require bayurifkialghifari/indonesia-multipayment-gateways:"dev-main"`. Service provider dan facade ditemukan otomatis oleh Laravel.

## Konfigurasi

Isi credential gateway yang dipakai di .env. Jangan masukkan credential ke frontend.

```dotenv
PAYMENTS_GATEWAY=midtrans
MIDTRANS_ENVIRONMENT=sandbox
MIDTRANS_SERVER_KEY=

XENDIT_SECRET_KEY=
XENDIT_WEBHOOK_TOKEN=

DOKU_ENVIRONMENT=sandbox
DOKU_CLIENT_ID=
DOKU_SECRET_KEY=
DOKU_PRIVATE_KEY=
DOKU_PUBLIC_KEY=
DOKU_PRIVATE_KEY_PASSPHRASE=
DOKU_MERCHANT_ID=
DOKU_TERMINAL_ID=
DOKU_POSTAL_CODE=
DOKU_SNAP_WEBHOOK_SIGNATURE=symmetric

PAYWUZ_API_KEY=

IPAYMU_ENVIRONMENT=sandbox
IPAYMU_VA=
IPAYMU_API_KEY=
```

Environment Midtrans, DOKU, dan iPaymu menerima sandbox atau production. Xendit memakai secret key test/live dari dashboard; Paywuz memakai API key project. Isi timeout dan konfigurasi bank DOKU di config/payments.php.

DOKU direct memakai key RSA untuk token SNAP serta secret key untuk request transaksi. Hosted checkout memakai client ID dan secret key. PEM dapat berupa teks multiline atau teks dengan literal `\n`. Pilih mode signature webhook sesuai kontrak DOKU; mode asymmetric membutuhkan public key DOKU. SDK tidak mencoba mode lain jika verifikasi gagal.

Untuk DOKU VA, gunakan identifier yang diberikan DOKU untuk setiap bank, misalnya:

```php
'banks' => [
    'bca' => [
        'partner_service_id' => env('DOKU_BCA_PARTNER_SERVICE_ID'),
        'customer_number' => env('DOKU_BCA_CUSTOMER_NUMBER'),
    ],
],
```

## API agnostic

Facade `Payments` dan dependency injection `PaymentGateway` menggunakan gateway default. `PaymentsManager` menyediakan pilihan gateway per transaksi.

```php
use Bayurifkialghifari\Payments\Contracts\PaymentGateway;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\PaymentResult;

final class CreateOrderPayment
{
    public function __construct(private PaymentGateway $payments) {}

    public function __invoke(CreatePaymentRequest $request): PaymentResult
    {
        return $this->payments->createPayment($request);
    }
}
```

Untuk memilih provider secara eksplisit:

```php
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Facades\Payments;

$gateway = Payments::gateway(Provider::Xendit);
// Payments::gateway('xendit') juga tersedia.
```

## Direct payment untuk UI sendiri

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\DTO\Instructions\QrCode;
use Bayurifkialghifari\Payments\DTO\Instructions\VirtualAccount;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::createPayment(new CreatePaymentRequest(
    reference: 'ORDER-001',
    amount: 100_000,
    method: PaymentMethod::Qris,
    customer: new Customer('Bayu', 'bayu@example.com', '628123456789'),
    notifyUrl: 'https://shop.example/api/webhooks/payments/midtrans',
));

$instructions = $payment->instructions;
if ($instructions instanceof QrCode) {
    // Render text menjadi QR, atau tampilkan imageUrl jika tersedia.
    $qrText = $instructions->text;
    $qrImage = $instructions->imageUrl;
} elseif ($instructions instanceof VirtualAccount) {
    $number = $instructions->number;
    $bank = $instructions->bank;
    $billerCode = $instructions->billerCode; // Mandiri Midtrans.
}
```

Untuk VA, gunakan `method: PaymentMethod::VirtualAccount, bank: Bank::Bca`. QRIS tidak menerima bank. Pilihan bank bergantung pada provider dan aktivasi akun.

PaymentResult berisi reference, amount, status, providerStatus, instructions, dan nilai opsional totalPayment, fee, expiresAt. Fee/total tidak dihitung sendiri ketika provider tidak mengirimkannya. Customer dan line items opsional kecuali persyaratan provider. Jika items diberikan, total price × quantity harus sama dengan amount.

## Hosted checkout

```php
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\Facades\Payments;

$checkout = Payments::createCheckout(new CreateCheckoutRequest(
    reference: 'ORDER-002',
    amount: 100_000,
    returnUrl: 'https://shop.example/payments/return',
    cancelUrl: 'https://shop.example/payments/cancel',
    notifyUrl: 'https://shop.example/api/webhooks/payments/midtrans',
));

return redirect()->away($checkout->checkoutUrl);
```

Redirect pelanggan bukan bukti pembayaran. Gunakan status atau webhook terverifikasi untuk menyelesaikan order. CheckoutStatus memisahkan status sesi checkout dari PaymentStatus; percobaan pembayaran yang gagal dapat meninggalkan checkout tetap Open.

## Simpan reference dan cek status

Simpan seluruh `$payment->reference->toArray()` atau `$checkout->reference->toArray()` ke kolom JSON aplikasi. Jangan hanya menyimpan merchant reference; status direct DOKU, Xendit, dan iPaymu memerlukan identifier tambahan.

```php
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Facades\Payments;

$stored = $payment->reference->toArray();
$reference = PaymentReference::fromArray($stored);
$status = Payments::getStatus($reference);

if ($status->status === PaymentStatus::Paid) {
    // Verifikasi nominal dan selesaikan order secara idempotent di aplikasi.
}
```

getStatus memilih provider dari reference, walaupun gateway default berubah. Status bersama: Pending, Processing, Paid, Failed, Expired, Cancelled, Refunded, Unknown. Nilai native tetap tersedia di providerStatus. Unknown tidak boleh dianggap Paid.

## Webhook

SDK tidak menambahkan route, database, queue, atau deduplication otomatis. Daftarkan endpoint API aplikasi dan pakai gateway yang sesuai URL. `Payments::parseWebhook()` memakai default; endpoint untuk beberapa provider perlu `Payments::gateway($provider)->parseWebhook()`.

```php
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\Exceptions\InvalidResponseException;
use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Bayurifkialghifari\Payments\PaymentsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MidtransWebhookController
{
    public function __invoke(Request $request, PaymentsManager $payments): JsonResponse
    {
        $gateway = $payments->midtrans();

        try {
            $event = $gateway->parseWebhook(WebhookRequest::fromRequest($request));
        } catch (InvalidWebhookException|InvalidResponseException) {
            return response()->json(['error' => 'Invalid webhook'], 401);
        }

        // Di sini: cari order dari provider + merchantReference, verifikasi nominal,
        // dan persist perubahan secara idempotent sebelum mengirim acknowledgment.
        // Gunakan deliveryId jika tersedia; jangan menurunkan order Paid karena event lama.

        return $gateway->webhookResponse($event)->toResponse();
    }
}
```

Blok penyimpanan di atas perlu diimplementasikan sesuai model order aplikasi. Kirim acknowledgment hanya setelah pemrosesan atau pencatatan durable berhasil. iPaymu callback amount bisa mencakup biaya; bandingkan dengan nominal yang sesuai kontrak provider dan reference tersimpan.

WebhookRequest mempertahankan raw body, header, HTTP method, dan path; jangan decode lalu encode ulang body sebelum verifikasi. DOKU menandatangani path endpoint juga. Jika reverse proxy mengubah path, berikan path publik yang ditandatangani melalui konstruktor WebhookRequest. DOKU VA membutuhkan body acknowledgment khusus; gunakan webhookResponse(event), termasuk untuk delivery ulang.

## Metode dan options provider

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Options\XenditOptions;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$gateway = Payments::xendit();
$methods = $gateway->paymentMethods(); // Countable + IteratorAggregate<PaymentMethodInfo>.

$payment = $gateway->createPayment(new CreatePaymentRequest(
    reference: 'ORDER-003',
    amount: 100_000,
    method: PaymentMethod::VirtualAccount,
    bank: Bank::Bni,
    options: new XenditOptions(displayName: 'Toko Bayu', businessId: 'business-id'),
));
```

Akses typed tersedia melalui midtrans(), xendit(), doku(), paywuz(), ipaymu(), atau DI kelas gateway. Options tetap DTO; tidak ada public raw-request API. Options dari provider lain ditolak sebelum request dikirim.

| DTO | Options |
| --- | --- |
| MidtransOptions | acquirer QRIS GoPay/ShopeePay, virtualAccountNumber |
| XenditOptions | virtualAccountNumber, displayName, businessId |
| DokuOptions | customerNumber, virtualAccountNumber sesuai assignment DOKU |
| PaywuzOptions | feeByMerchant, paymentReminderEnabled, vaOnlyCheckout |
| IPaymuOptions | FeeDirection::Merchant atau FeeDirection::Buyer |

## Dukungan dan batas provider

| Provider | Hosted | Direct | Status | Verifikasi webhook |
| --- | --- | --- | --- | --- |
| Midtrans | Snap redirect | Core QRIS + 5 bank VA | Order ID | SHA-512 body fields |
| Xendit | Payment Session PAYMENT_LINK | Payment Requests v3 QRIS + 7 bank VA | Session/payment request ID | Callback token |
| DOKU | Checkout | SNAP QRIS + 13 bank VA | Invoice/QR reference/VA identifiers | Non-SNAP HMAC; SNAP HMAC atau RSA sesuai config |
| Paywuz | ALL atau VA checkout | QRIS + VA dari katalog project | Merchant order ID | HMAC raw body + event header |
| iPaymu | Redirect payment | QRIS MPM + 12 bank VA | Transaction ID | Canonical callback HMAC |

Daftar Midtrans/Xendit/DOKU adalah channel yang dikenali SDK, dengan enabled null karena aktivasi tidak diperiksa. Paywuz mengambil katalog project dan mengenali bank dari kode/nama. iPaymu mengambil katalog dan menyertakan status feature/health. Metode yang tersedia tetap bergantung aktivasi dan kontrak merchant.

- Midtrans: cancelUrl dikirim sebagai callback error; perilakunya mengikuti metode pembayaran Snap. Expiry dibulatkan ke menit dan token Snap dibatasi provider.
- Xendit: secret key menentukan test/live. API version Payment Requests dipatok 2024-11-11. URL webhook diatur di dashboard; notifyUrl per request tidak didukung. Business ID disimpan bersama reference untuk polling subaccount.
- DOKU: bank VA harus memakai partner service ID/customer number dari DOKU. Padding identifier dipertahankan saat polling. Webhook QRIS dapat membawa reference parsial; pertahankan reference lengkap dari createPayment untuk polling. Kode SNAP 07/not found menjadi Unknown.
- Paywuz: webhook diatur pada project/dashboard. Hosted memakai returnUrl sebagai redirectUrl; cancelUrl dan notifyUrl per request tidak didukung. Direct VA mengambil katalog sebelum membuat transaksi.
- iPaymu: notifyUrl wajib; direct juga membutuhkan customer name/email/phone. Hosted hanya mengembalikan SessionID; polling menunggu TransactionId dari callback terverifikasi dan akan melempar StatusUnavailableException sebelumnya. Expiry custom dibulatkan ke jam; QRIS/BCA memakai expiry tetap, BRI maksimal 2 jam, BSI maksimal 3 jam. Hosted expiresAt tetap null karena channel yang dipilih menentukan expiry sebenarnya.

Direct kartu/e-wallet, refund, payout, dan cancel API belum termasuk ruang lingkup versi ini. Hosted checkout dapat menampilkan metode tambahan yang diaktifkan oleh provider. Status Refunded hanya menormalisasi hasil polling/notifikasi, bukan menyediakan refund API.

Gateway di-cache oleh manager. Setelah mengganti config dalam proses yang sama, panggil Payments::forgetGateways(). HTTP memakai Laravel client, timeout yang dikonfigurasi, redirect nonaktif, dan tanpa retry otomatis agar POST pembayaran tidak terkirim ulang secara tidak sengaja.

## Error dan testing

Input tidak valid menghasilkan InvalidArgumentException. SDK menyediakan ConfigurationException, UnsupportedFeatureException, StatusUnavailableException, ProviderException, TransportException, InvalidResponseException, InvalidWebhookException; semuanya di bawah PaymentException kecuali validasi input. ProviderException menyimpan provider, httpStatus, dan providerCode yang sudah difilter. Error HTTP/koneksi tidak menyertakan response body atau credential.

```bash
composer install
composer test
composer analyse
composer format:check
XDEBUG_MODE=coverage composer coverage
```

Test memakai Orchestra Testbench, Pest, Laravel HTTP fake, clock tetap, payload terdokumentasi, signature yang dihitung independen, penolakan signature/input, mapping status, dan integrasi container/facade. Target CI: PHPStan max dan 100% source line coverage; matrix Laravel 12/PHP 8.2–8.5 dan Laravel 13/PHP 8.3–8.5.

Suite tidak melakukan transaksi live dan belum menjadi sertifikasi sandbox merchant. Gunakan credential sandbox/test sendiri untuk validasi aktivasi channel dan kontrak akun sebelum produksi.

## Dokumentasi resmi

- [Midtrans Core API](https://docs.midtrans.com/docs/core-api-overview) dan [webhook](https://docs.midtrans.com/docs/https-notification-webhooks).
- [Xendit Payment Requests](https://docs.xendit.co/apidocs/create-payment-request), [Payment Sessions](https://docs.xendit.co/apidocs/create-session), dan [index](https://docs.xendit.co/llms.txt).
- [DOKU](https://developers.doku.com/llms.txt), [SNAP signature](https://developers.doku.com/get-started-with-doku-api/signature-component/snap/symmetric-signature), dan [status](https://developers.doku.com/get-started-with-doku-api/check-status-api/snap).
- [Paywuz](https://paywuz.id/docs).
- [iPaymu](https://docs.ipaymu.com/), [callback](https://docs.ipaymu.com/id/docs/callback), dan [API collection](https://documenter.getpostman.com/view/40296808/2sB3WtseBT).

MIT License.
