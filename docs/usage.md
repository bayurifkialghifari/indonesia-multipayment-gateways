# Panduan penggunaan

Panduan ini memakai API yang tersedia di package saat ini. Semua contoh PHP dijalankan di backend aplikasi Laravel yang sudah memasang package. Nama class memakai namespace `Bayurifkialghifari\Payments`.

Untuk contoh konfigurasi dan options setiap provider, baca [Gateway dan options](gateways.md). Untuk contoh aplikasi dengan database, route, controller, dan webhook, baca [Contoh integrasi Laravel](laravel-example.md).

## Daftar isi

1. [Instalasi](#1-instalasi)
2. [Konfigurasi](#2-konfigurasi)
3. [Memilih gateway](#3-memilih-gateway)
4. [Membuat DTO request](#4-membuat-dto-request)
5. [Direct QRIS](#5-direct-qris)
6. [Direct virtual account](#6-direct-virtual-account)
7. [Hosted checkout](#7-hosted-checkout)
8. [Data untuk UI sendiri](#8-data-untuk-ui-sendiri)
9. [Menyimpan reference dan mengecek status](#9-menyimpan-reference-dan-mengecek-status)
10. [Webhook](#10-webhook)
11. [Daftar metode pembayaran](#11-daftar-metode-pembayaran)
12. [Error dan retry](#12-error-dan-retry)
13. [Testing](#13-testing)

## 1. Instalasi

### Dari checkout lokal

Contoh struktur direktori:

```text
workspace/
├── toko-laravel/
└── indonesia-multipayment-gateways/
```

Tambahkan repository ini ke `composer.json` milik `toko-laravel`. Gabungkan dengan konfigurasi yang sudah ada:

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

Jalankan dari direktori aplikasi:

```bash
composer require bayurifkialghifari/indonesia-multipayment-gateways:"dev-main"
php artisan vendor:publish --tag=payments-config
```

`symlink: true` membuat perubahan source package lokal langsung terbaca aplikasi. Path repository harus menunjuk ke direktori package yang memiliki `composer.json`.

### Setelah tersedia di registry Composer

Saat versi rilis sudah dipublikasikan, gunakan:

```bash
composer require bayurifkialghifari/indonesia-multipayment-gateways
php artisan vendor:publish --tag=payments-config
```

Package memakai auto-discovery Laravel untuk service provider dan facade. Persyaratan package: PHP 8.2+ dengan Laravel 12, atau PHP 8.3+ dengan Laravel 13, serta ekstensi OpenSSL.

## 2. Konfigurasi

Contoh memulai dengan Midtrans sandbox di `.env` aplikasi:

```dotenv
PAYMENTS_GATEWAY=midtrans
MIDTRANS_ENVIRONMENT=sandbox
MIDTRANS_SERVER_KEY="isi-server-key-sandbox"
```

Sesudah mengubah konfigurasi aplikasi:

```bash
php artisan config:clear
```

Gateway yang dapat dijadikan default: `midtrans`, `xendit`, `doku`, `paywuz`, dan `ipaymu`. Cukup isi credential untuk gateway yang dipakai.

Timeout berada di `config/payments.php`, dalam detik:

```php
return [
    'default' => env('PAYMENTS_GATEWAY', 'midtrans'),
    'connect_timeout' => 10,
    'timeout' => 30,
    // Pertahankan bagian gateways dari config yang dipublikasikan.
];
```

Contoh di atas hanya menunjukkan bagian timeout; jangan mengganti seluruh config dan menghapus `gateways`. Timeout harus berupa integer positif.

Manager menyimpan instance gateway selama proses berjalan. Jika config diubah melalui `config()->set()` pada proses yang sama, reset cache instance:

```php
use Bayurifkialghifari\Payments\Facades\Payments;

config()->set('payments.gateways.midtrans.server_key', 'server-key-yang-baru');
Payments::forgetGateways();
```

Untuk worker atau Octane yang berjalan lama, terapkan perubahan config melalui mekanisme reload proses aplikasi. Simpan secret di backend.

## 3. Memilih gateway

### Facade dengan gateway default

```php
use Bayurifkialghifari\Payments\Facades\Payments;

$gateway = Payments::gateway();
```

`Payments::createPayment()`, `createCheckout()`, `paymentMethods()`, dan `parseWebhook()` memakai gateway default. `getStatus($reference)` dan `webhookResponse($event)` memilih provider dari reference/event.

### Memilih per transaksi

```php
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Facades\Payments;

$midtrans = Payments::gateway(Provider::Midtrans);
$xendit = Payments::gateway('xendit');

// Accessor dengan return type kelas gateway yang spesifik.
$doku = Payments::doku();
$paywuz = Payments::paywuz();
$ipaymu = Payments::ipaymu();
```

Contoh: proyek A memilih Midtrans, proyek B memilih Xendit. Logika membuat pembayaran tetap memanggil `createPayment(CreatePaymentRequest)`; pemilihan gateway dilakukan di batas aplikasi.

### Dependency injection

Untuk service yang memakai default:

```php
namespace App\Services;

use Bayurifkialghifari\Payments\Contracts\PaymentGateway;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\PaymentResult;

final class CreateOrderPayment
{
    public function __construct(private readonly PaymentGateway $payments) {}

    public function handle(CreatePaymentRequest $request): PaymentResult
    {
        return $this->payments->createPayment($request);
    }
}
```

Untuk memilih provider secara eksplisit di service:

```php
use Bayurifkialghifari\Payments\PaymentsManager;
use Bayurifkialghifari\Payments\Gateways\XenditGateway;

$manager = app(PaymentsManager::class);
$gateway = $manager->xendit();

// Bisa juga inject XenditGateway langsung ke constructor.
$xendit = app(XenditGateway::class);
```

## 4. Membuat DTO request

`CreatePaymentRequest` dipakai untuk direct payment. `CreateCheckoutRequest` dipakai untuk halaman checkout provider.

| Field umum | Type | Cara mengisi |
| --- | --- | --- |
| reference | string | ID unik percobaan pembayaran dari backend, misalnya PAY-ORDER-10-ATTEMPT-1 |
| amount | int | Nominal Rupiah, misalnya 100_000 untuk Rp100.000 |
| description | string | Default Payment; isi ringkasan pembelian |
| customer | ?Customer | Nama, email, telepon, dan reference pelanggan |
| items | list<LineItem> | Opsional; total price × quantity harus sama dengan amount |
| expiresAt | ?DateTimeImmutable | Waktu kedaluwarsa di masa depan, jika channel mendukung |
| notifyUrl | ?string | URL callback backend; dukungan per request berbeda tiap gateway |
| options | ?ProviderOptions | DTO options milik provider yang dipilih |

`CreatePaymentRequest` menambahkan `method: PaymentMethod` dan `bank: ?Bank`. `CreateCheckoutRequest` menambahkan `returnUrl: string` dan `cancelUrl: string` yang wajib diisi dengan URL HTTP/HTTPS.

Contoh customer dan item untuk total Rp100.000:

```php
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\DTO\LineItem;

$customer = new Customer(
    name: 'Bayu',
    email: 'bayu@example.com',
    phone: '628123456789',
    reference: 'CUSTOMER-001',
);

$items = [
    new LineItem(name: 'Kaos', price: 40_000, quantity: 2, reference: 'SKU-KAOS'),
    new LineItem(name: 'Ongkir', price: 20_000, reference: 'SHIPPING'),
];
```

`LineItem` menerima harga dan quantity positif. Jika ada diskon, hitung harga akhir di backend terlebih dahulu; contoh item negatif akan ditolak. Jika `items` kosong, adapter yang membutuhkan daftar item membuat satu item dari description dan amount.

DTO bersifat readonly. Untuk mengubah request, buat instance baru. Nomor VA dan identifier harus tetap string supaya nol di depan dan padding tidak hilang.

## 5. Direct QRIS

Contoh ini memakai Midtrans yang sudah dikonfigurasi. Provider lain memakai DTO yang sama; baca persyaratan provider di [Gateway dan options](gateways.md).

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\DTO\Instructions\QrCode;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::midtrans()->createPayment(new CreatePaymentRequest(
    reference: 'PAY-ORDER-001-ATTEMPT-1',
    amount: 100_000,
    method: PaymentMethod::Qris,
    description: 'Pembelian ORDER-001',
    customer: new Customer('Bayu', 'bayu@example.com', '628123456789'),
    notifyUrl: 'https://toko.example/api/webhooks/payments/midtrans',
));

$qr = $payment->instructions;
if ($qr instanceof QrCode) {
    $qrText = $qr->text;
    $qrImageUrl = $qr->imageUrl;
}

$referenceForDatabase = $payment->reference->toArray();
```

`QrCode::text` adalah payload yang di-encode menjadi gambar QR oleh renderer UI milik aplikasi. `imageUrl` adalah gambar dari provider jika tersedia. Salah satu dapat null; package tidak menghasilkan gambar QR sendiri.

QRIS tidak memakai `bank`. Menambahkan `bank: Bank::Bca` pada request QRIS menghasilkan `InvalidArgumentException`.

## 6. Direct virtual account

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\DTO\Instructions\VirtualAccount;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::midtrans()->createPayment(new CreatePaymentRequest(
    reference: 'PAY-ORDER-002-ATTEMPT-1',
    amount: 100_000,
    method: PaymentMethod::VirtualAccount,
    bank: Bank::Bca,
    customer: new Customer('Bayu', 'bayu@example.com', '628123456789'),
    expiresAt: new DateTimeImmutable('+1 hour'),
    notifyUrl: 'https://toko.example/api/webhooks/payments/midtrans',
));

$va = $payment->instructions;
if ($va instanceof VirtualAccount) {
    $number = $va->number;
    $bank = $va->bank->value;
    $accountName = $va->name;
    $billerCode = $va->billerCode;
}
```

VA wajib memiliki bank. Gunakan enum `Bank`, bukan kode native provider. Untuk Midtrans Mandiri, tampilkan `billerCode` dan `number` sebagai dua nilai; provider menggunakan biller code dan bill key.

## 7. Hosted checkout

```php
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\Facades\Payments;

$checkout = Payments::midtrans()->createCheckout(new CreateCheckoutRequest(
    reference: 'PAY-ORDER-003-ATTEMPT-1',
    amount: 100_000,
    returnUrl: 'https://toko.example/payments/return',
    cancelUrl: 'https://toko.example/payments/cancel',
    description: 'Pembelian ORDER-003',
    customer: new Customer('Bayu', 'bayu@example.com', '628123456789'),
    notifyUrl: 'https://toko.example/api/webhooks/payments/midtrans',
));

$referenceForDatabase = $checkout->reference->toArray();

// Setelah reference dan checkoutUrl disimpan aplikasi:
return redirect()->away($checkout->checkoutUrl);
```

Untuk frontend terpisah, kembalikan `checkoutUrl` dalam JSON, lalu frontend membuka URL tersebut. Hosted checkout dapat menampilkan metode tambahan yang diaktifkan provider walaupun API direct SDK baru mencakup QRIS/VA.

| Field CheckoutResult | Kegunaan |
| --- | --- |
| reference | Disimpan untuk status dan pencocokan transaksi |
| amount | Nominal transaksi |
| checkoutUrl | URL redirect pelanggan |
| status | CheckoutStatus, bukan status order aplikasi |
| token | Token provider jika tersedia; null pada provider lain |
| expiresAt | Expiry jika diketahui; nullable |

`returnUrl` dan `cancelUrl` adalah navigasi browser. Aplikasi harus mengecek status atau webhook untuk menetapkan pembayaran berhasil. URL callback backend adalah `notifyUrl` atau pengaturan webhook di dashboard, sesuai provider.

## 8. Data untuk UI sendiri

Contoh mapper DTO direct payment ke JSON. Fungsi ini bisa diletakkan di controller atau presenter aplikasi:

```php
use Bayurifkialghifari\Payments\DTO\Instructions\QrCode;
use Bayurifkialghifari\Payments\DTO\PaymentResult;
use Illuminate\Http\JsonResponse;

function paymentResponse(PaymentResult $payment): JsonResponse
{
    $instructions = $payment->instructions;

    $data = $instructions instanceof QrCode
        ? [
            'type' => 'qris',
            'qr_text' => $instructions->text,
            'qr_image_url' => $instructions->imageUrl,
        ]
        : [
            'type' => 'virtual_account',
            'bank' => $instructions->bank->value,
            'number' => $instructions->number,
            'name' => $instructions->name,
            'biller_code' => $instructions->billerCode,
        ];

    return response()->json([
        'reference' => $payment->reference->merchantReference,
        'provider' => $payment->reference->provider()->value,
        'status' => $payment->status->value,
        'amount' => $payment->amount,
        'total_payment' => $payment->totalPayment,
        'fee' => $payment->fee,
        'expires_at' => $payment->expiresAt?->format(DATE_ATOM),
        'instructions' => $data,
    ]);
}
```

Frontend cukup membaca `instructions.type`, render QR atau nomor VA, lalu menampilkan nominal dan expiry. Simpan reference lengkap di backend; endpoint status menerima ID record pembayaran aplikasi yang sudah dicek kepemilikannya.

`totalPayment` dan `fee` dapat null ketika provider tidak mengirimkan data. Jangan menampilkan null sebagai biaya nol atau menganggap semua biaya provider selalu ditambahkan ke amount. Gunakan kebijakan nominal sesuai kontrak merchant.

## 9. Menyimpan reference dan mengecek status

Reference berisi merchant reference, provider, metode, bank, dan identifier native. Format ini dapat disimpan sebagai JSON:

```php
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\Facades\Payments;

// $payment berasal dari createPayment() di contoh sebelumnya.
$storedArray = $payment->reference->toArray();
$storedJson = json_encode($storedArray, JSON_THROW_ON_ERROR);

// Ketika membaca kembali dari kolom JSON yang sudah di-cast sebagai array:
$reference = PaymentReference::fromArray($storedArray);
$status = Payments::getStatus($reference);
```

Contoh bentuk JSON untuk VA Midtrans; identifier di bawah hanya ilustrasi:

```json
{
  "provider": "midtrans",
  "merchant_reference": "PAY-ORDER-002-ATTEMPT-1",
  "method": "va",
  "bank": "bca",
  "identifiers": {
    "orderId": "PAY-ORDER-002-ATTEMPT-1",
    "transactionId": "transaction-id-dari-midtrans"
  }
}
```

Memanggil `Payments::getStatus($reference)` tetap memilih provider reference walaupun gateway default berubah. Jika memanggil `Payments::xendit()->getStatus($referenceMidtrans)`, reference ditolak.

| PaymentStatus | Makna untuk aplikasi |
| --- | --- |
| Pending | Menunggu pembayaran |
| Processing | Pembayaran masih diproses |
| Paid | Provider menyatakan berhasil; cocokkan identity dan nominal sebelum fulfillment |
| Failed | Percobaan pembayaran gagal |
| Expired | Kedaluwarsa menurut provider |
| Cancelled | Dibatalkan menurut provider |
| Refunded | Status refund yang dilaporkan provider; SDK tidak menjalankan refund |
| Unknown | Nilai yang belum dapat dikenali; perlu rekonsiliasi |

`PaymentStatusResult` juga memiliki `providerStatus`, `checkoutStatus`, `amount`, dan `paidAt`. Tiga field terakhir nullable. Status sesi `CheckoutStatus::Open` dapat tetap terbuka setelah satu percobaan pembayaran gagal, misalnya pada Xendit.

Pisahkan status pembayaran dari status order/fulfillment. Polling yang menghasilkan `Pending` dengan nominal tertentu tetap bukan bukti `Paid`. Hindari menurunkan pembayaran Paid karena respons atau event lama.

### Hosted iPaymu

Hosted iPaymu awalnya hanya memberikan SessionID. `getStatus()` membutuhkan TransactionId dari callback yang sudah diverifikasi. Tangani `StatusUnavailableException` dengan menunggu callback; baca contoh memperbarui reference di [bagian iPaymu](gateways.md#5-ipaymu).

### Reference dari webhook

Jangan menimpa reference tersimpan dengan `$event->reference->toArray()` secara umum. Event dapat membawa identifier parsial, khususnya DOKU. Cocokkan provider dan merchant reference, kemudian gabungkan identifier yang dibutuhkan sesuai provider.

## 10. Webhook

Alur penggunaan:

1. Terima request asli dari gateway.
2. Pilih gateway berdasarkan endpoint/provider yang dikonfigurasi aplikasi.
3. Panggil parseWebhook() untuk verifikasi dan normalisasi.
4. Cocokkan pembayaran tersimpan, identity, nominal, dan event yang sudah diproses.
5. Simpan perubahan secara durable.
6. Kembalikan webhookResponse() milik gateway.

Contoh mengambil event di dalam controller:

```php
use Bayurifkialghifari\Payments\DTO\WebhookEvent;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\PaymentsManager;
use Illuminate\Http\Request;

function readPaymentEvent(Request $request, PaymentsManager $payments): WebhookEvent
{
    $gateway = $payments->gateway(Provider::Midtrans);

    return $gateway->parseWebhook(WebhookRequest::fromRequest($request));
}
```

Untuk endpoint Xendit, gunakan `Provider::Xendit`, dan seterusnya. Paket tidak membuat route, tabel, deduplication, atau queue otomatis.

| Field WebhookEvent | Kegunaan |
| --- | --- |
| reference | Identity provider dan merchant; identifier dapat parsial |
| providerEvent | Nama event asli |
| subject | Payment, Checkout, atau Unknown |
| status | PaymentStatus hasil normalisasi |
| providerStatus | Status asli |
| deliveryId | ID delivery jika provider mengirimkannya; nullable |
| amount | Nominal notifikasi jika tersedia; nullable |
| checkoutStatus | Status sesi jika diketahui; nullable |
| acknowledgment | Body acknowledgment khusus jika diperlukan provider |

Setelah pemrosesan durable berhasil:

```php
// $gateway dan $event berasal dari parseWebhook() yang berhasil.
return $gateway->webhookResponse($event)->toResponse();
```

Gunakan helper ini juga untuk event duplikat yang sudah diproses. DOKU VA memerlukan body acknowledgment khusus; respons JSON generik saja tidak menggantikannya.

### Raw body dan reverse proxy

`WebhookRequest::fromRequest()` mempertahankan raw body, header, method, dan path. Jangan membentuk webhook dari `$request->all()` yang di-encode ulang.

Jika proxy mengubah path yang dilihat Laravel, gunakan path publik yang memang ditandatangani DOKU. Contoh berikut mengubah path saja:

```php
use Bayurifkialghifari\Payments\DTO\WebhookRequest;

$incoming = WebhookRequest::fromRequest($request);
$webhook = new WebhookRequest(
    body: $incoming->body,
    headers: $incoming->headers,
    path: '/api/webhooks/payments/doku',
    method: $incoming->method,
);

$event = $gateway->parseWebhook($webhook);
```

Gunakan path yang terdaftar untuk aplikasi tersebut; contoh di atas bukan nilai universal. Error verifikasi menghasilkan `InvalidWebhookException`; payload rusak dapat menghasilkan `InvalidResponseException`. Lihat controller lengkap pada [Contoh integrasi Laravel](laravel-example.md#5-controller-webhook).

## 11. Daftar metode pembayaran

```php
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$methods = Payments::paywuz()->paymentMethods();
$available = [];

foreach ($methods as $method) {
    if ($method->enabled === false) {
        continue;
    }

    $available[] = [
        'code' => $method->code,
        'name' => $method->name,
        'method' => $method->method->value,
        'bank' => $method->bank?->value,
        'enabled' => $method->enabled,
        'minimum_amount' => $method->minimumAmount,
        'maximum_amount' => $method->maximumAmount,
        'instructions_url' => $method->instructionsUrl,
    ];
}

$total = count($methods);
$vaMethods = array_values(array_filter(
    $methods->items,
    fn ($method) => $method->method === PaymentMethod::VirtualAccount,
));
```

`PaymentMethodCollection` mendukung foreach, count(), dan property `items`. `enabled === null` berarti aktivasi akun tidak diperiksa; jangan menafsirkannya sebagai pasti aktif atau pasti nonaktif. Midtrans/Xendit/DOKU menyediakan katalog channel yang dikenali SDK. Paywuz dan iPaymu membaca katalog API.

Kode native hanya untuk informasi katalog. Request tetap memakai `PaymentMethod` dan `Bank`; adapter menerjemahkannya ke channel provider.

## 12. Error dan retry

| Exception | Penyebab umum | Tindakan aplikasi |
| --- | --- | --- |
| InvalidArgumentException | amount/item/URL salah, QRIS diberi bank, options beda provider | Perbaiki input sebelum mengirim request |
| ConfigurationException | Credential, environment, timeout, atau identifier DOKU tidak valid | Perbaiki config backend |
| UnsupportedFeatureException | Bank/options/expiry tidak didukung | Pilih fitur atau channel yang didukung |
| StatusUnavailableException | Reference belum memiliki identifier untuk polling | Lengkapi reference dari create atau callback terverifikasi |
| ProviderException | Provider menolak HTTP atau melaporkan error aplikasi | Periksa httpStatus dan providerCode yang tersedia |
| TransportException | Koneksi atau timeout | Rekonsiliasi request yang hasilnya belum diketahui |
| InvalidResponseException | Payload provider tidak sesuai format yang dibutuhkan | Catat konteks transaksi dan periksa integrasi |
| InvalidWebhookException | Header, token, atau signature notifikasi tidak valid | Jangan memproses event tersebut |

Semua exception SDK di atas mewarisi `PaymentException`, kecuali `InvalidArgumentException` dari validasi input.

Contoh batas service aplikasi:

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\PaymentResult;
use Bayurifkialghifari\Payments\Exceptions\ProviderException;
use Bayurifkialghifari\Payments\Exceptions\TransportException;
use Bayurifkialghifari\Payments\Facades\Payments;
use Illuminate\Support\Facades\Log;

function createWithLogging(CreatePaymentRequest $request): PaymentResult
{
    try {
        return Payments::createPayment($request);
    } catch (ProviderException $exception) {
        Log::warning('Provider menolak pembuatan pembayaran', [
            'reference' => $request->reference,
            'provider' => $exception->provider->value,
            'http_status' => $exception->httpStatus,
            'provider_code' => $exception->providerCode,
        ]);

        throw $exception;
    } catch (TransportException $exception) {
        Log::warning('Hasil pembuatan pembayaran perlu rekonsiliasi', [
            'reference' => $request->reference,
        ]);

        throw $exception;
    }
}
```

SDK tidak melakukan retry otomatis. Timeout setelah POST dikirim dapat terjadi ketika provider sebenarnya sudah membuat transaksi. Simpan merchant reference sebelum request dan rekonsiliasi; jangan otomatis membuat transaksi kedua dengan reference baru sebagai respons timeout. Queue untuk polling berbeda dari queue untuk membuat pembayaran: retry polling tidak mengulangi POST pembuatan transaksi.

## 13. Testing

### Menguji SDK dari aplikasi dengan HTTP fake

Letakkan contoh Pest ini di `tests/Feature/PaymentSdkTest.php` aplikasi yang sudah memiliki Pest dan bootstrap test Laravel:

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Instructions\QrCode;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Facades\Payments;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('membuat QRIS tanpa menghubungi provider asli', function () {
    config()->set('payments.gateways.midtrans.environment', 'sandbox');
    config()->set('payments.gateways.midtrans.server_key', 'test-key');
    Payments::forgetGateways();
    Http::preventStrayRequests();

    Http::fake([
        'api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code' => '201',
            'order_id' => 'PAY-TEST-001',
            'transaction_id' => 'MID-TEST-001',
            'transaction_status' => 'pending',
            'gross_amount' => '100000.00',
            'qr_string' => 'CONTOH-PAYLOAD-QR-UNTUK-TEST',
        ], 201),
    ]);

    $payment = Payments::midtrans()->createPayment(new CreatePaymentRequest(
        reference: 'PAY-TEST-001',
        amount: 100_000,
        method: PaymentMethod::Qris,
    ));

    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($payment->instructions)->toBeInstanceOf(QrCode::class);
    expect($payment->instructions->text)->toBe('CONTOH-PAYLOAD-QR-UNTUK-TEST');

    Http::assertSent(fn (Request $request) =>
        $request->method() === 'POST'
        && $request['transaction_details']['order_id'] === 'PAY-TEST-001'
        && $request['transaction_details']['gross_amount'] === 100_000
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-key:'))
    );
    Http::assertSentCount(1);
});
```

Payload QR pada test adalah string dummy untuk menguji transport dan DTO, bukan QR pembayaran yang bisa dipindai. Package tidak memiliki `Payments::fake()`; gunakan Laravel HTTP fake atau mock contract `PaymentGateway` untuk service aplikasi yang inject contract tersebut.

### Menjalankan suite package

Dari direktori package:

```bash
composer install
composer test
composer analyse
composer format:check
XDEBUG_MODE=coverage composer coverage
```

Coverage membutuhkan Xdebug/driver coverage yang sesuai. Tes HTTP fake menguji payload, signature, status, dan integrasi Laravel; uji sandbox merchant tetap diperlukan untuk memastikan credential dan channel akun yang dipakai.
