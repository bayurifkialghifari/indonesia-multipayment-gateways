# Gateway dan options

Contoh di bawah memakai akses gateway eksplisit supaya tidak bergantung pada default aplikasi. Semua nominal adalah integer Rupiah dan nomor VA tetap string. Credential, bank aktif, dan ketentuan biaya mengikuti akun merchant.

Baca [panduan penggunaan](usage.md) untuk instalasi, struktur DTO, render instruksi, status, dan error. Contoh penyimpanan transaksi lengkap ada di [integrasi Laravel](laravel-example.md).

## Daftar isi

1. [Midtrans](#1-midtrans)
2. [Xendit](#2-xendit)
3. [DOKU](#3-doku)
4. [Paywuz](#4-paywuz)
5. [iPaymu](#5-ipaymu)
6. [Perbedaan yang perlu ditangani aplikasi](#6-perbedaan-yang-perlu-ditangani-aplikasi)

## 1. Midtrans

### Konfigurasi

```dotenv
MIDTRANS_ENVIRONMENT=sandbox
MIDTRANS_SERVER_KEY="server-key-sandbox-dari-dashboard"
```

Untuk production, ubah environment dan server key bersamaan. SDK memakai Core API untuk direct payment dan Snap untuk hosted checkout. Tidak diperlukan client key untuk alur redirect yang dicontohkan di sini.

### QRIS dengan pilihan acquirer

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Options\MidtransOptions;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\QrisAcquirer;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::midtrans()->createPayment(new CreatePaymentRequest(
    reference: 'PAY-MIDTRANS-QR-001',
    amount: 100_000,
    method: PaymentMethod::Qris,
    expiresAt: new DateTimeImmutable('+1 hour'),
    notifyUrl: 'https://shop.example/api/webhooks/payments/midtrans',
    options: new MidtransOptions(acquirer: QrisAcquirer::GoPay),
));
```

Pilihan lain adalah `QrisAcquirer::ShopeePay`. Default options memakai GoPay. Instruksi dapat menyediakan QR text, image URL, atau keduanya; periksa DTO sebelum memilih cara render.

### Virtual account

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::midtrans()->createPayment(new CreatePaymentRequest(
    reference: 'PAY-MIDTRANS-VA-001',
    amount: 100_000,
    method: PaymentMethod::VirtualAccount,
    bank: Bank::Bca,
    expiresAt: new DateTimeImmutable('+1 hour'),
));
```

Bank yang dikenali: BCA, BNI, BRI, Permata, dan Mandiri. Untuk channel bank transfer yang mendukung nomor khusus, `MidtransOptions(virtualAccountNumber: 'nomor-yang-diizinkan-merchant')` tersedia. Nilai tersebut harus mengikuti kontrak Midtrans; contoh placeholder itu bukan nomor valid.

Mandiri memakai e-channel: `VirtualAccount::number` berisi bill key dan `VirtualAccount::billerCode` berisi kode perusahaan. UI perlu menampilkan keduanya. Options nomor VA tidak diterapkan ke e-channel Mandiri atau QRIS.

### Hosted Snap

```php
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\Facades\Payments;

$checkout = Payments::midtrans()->createCheckout(new CreateCheckoutRequest(
    reference: 'PAY-MIDTRANS-CHECKOUT-001',
    amount: 100_000,
    returnUrl: 'https://shop.example/payments/return',
    cancelUrl: 'https://shop.example/payments/cancel',
    customer: new Customer('Bayu', 'bayu@example.com', '628123456789'),
    expiresAt: new DateTimeImmutable('+1 hour'),
    notifyUrl: 'https://shop.example/api/webhooks/payments/midtrans',
));

// Simpan $checkout->reference sebelum mengirim URL ke pelanggan.
return redirect()->away($checkout->checkoutUrl);
```

`CheckoutResult::token` berisi token Snap. Options direct seperti acquirer dan nomor VA tidak membatasi metode Snap. Return URL menjadi callback finish dan cancel URL menjadi callback error; membuka URL tersebut tidak mengubah status pembayaran aplikasi.

### Status dan webhook

Polling memakai order ID yang sudah disimpan dalam `MidtransReference`. Jika hasil create belum diketahui akibat timeout, order ID tetap bisa dipakai untuk rekonsiliasi:

```php
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\References\MidtransReference;
use Bayurifkialghifari\Payments\Facades\Payments;

$reference = new PaymentReference(
    merchantReference: 'PAY-MIDTRANS-QR-001',
    identifiers: new MidtransReference(orderId: 'PAY-MIDTRANS-QR-001'),
);

$status = Payments::getStatus($reference);
```

Notifikasi diverifikasi memakai SHA-512 atas string asli order ID, status code, gross amount, dan server key. SDK menangani rumusnya melalui `parseWebhook()`. `notifyUrl` dikirim lewat `X-Override-Notification` pada direct dan hosted; tanpa override gunakan notification URL akun merchant.

`settlement` menjadi Paid. `capture` menjadi Paid hanya jika fraud status accept; challenge menjadi Processing. Expiry custom dibulatkan ke menit. Contoh controller, pemeriksaan nominal, dan webhook duplikat ada di [integrasi Laravel](laravel-example.md).

## 2. Xendit

### Konfigurasi

```dotenv
XENDIT_SECRET_KEY="secret-key-test-dari-dashboard"
XENDIT_WEBHOOK_TOKEN="callback-token-dari-dashboard"
```

Mode test/live mengikuti secret key. SDK menggunakan Payment Requests v3 untuk direct dan Payment Sessions dengan mode PAYMENT_LINK untuk hosted; contoh ini tidak memakai Invoice API lama.

### QRIS

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::xendit()->createPayment(new CreatePaymentRequest(
    reference: 'PAY-XENDIT-QR-001',
    amount: 100_000,
    method: PaymentMethod::Qris,
    expiresAt: new DateTimeImmutable('+1 hour'),
));
```

### VA dengan nama tampilan

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Options\XenditOptions;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::xendit()->createPayment(new CreatePaymentRequest(
    reference: 'PAY-XENDIT-VA-001',
    amount: 100_000,
    method: PaymentMethod::VirtualAccount,
    bank: Bank::Bni,
    expiresAt: new DateTimeImmutable('+1 hour'),
    options: new XenditOptions(displayName: 'TOKO BAYU'),
));
```

Bank yang dikenali: BCA, BNI, BRI, Mandiri, Permata, CIMB, dan BSI. Options `virtualAccountNumber` tersedia untuk nomor yang diizinkan channel/akun.

Untuk xenPlatform/subaccount, isi `XenditOptions(businessId: 'id-subaccount-yang-diizinkan')`. SDK mengirim header `for-user-id` dan menyimpan business ID dalam reference agar polling memakai akun yang sama. Simpan reference lengkap; jangan membuang field businessId.

### Hosted Payment Session

```php
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\Facades\Payments;

$checkout = Payments::xendit()->createCheckout(new CreateCheckoutRequest(
    reference: 'PAY-XENDIT-CHECKOUT-001',
    amount: 100_000,
    returnUrl: 'https://shop.example/payments/return',
    cancelUrl: 'https://shop.example/payments/cancel',
    customer: new Customer('Bayu', 'bayu@example.com', '628123456789'),
    expiresAt: new DateTimeImmutable('+1 hour'),
));

return redirect()->away($checkout->checkoutUrl);
```

Hosted juga menerima businessId dari `XenditOptions`. Nomor VA dan displayName adalah options direct dan tidak diteruskan ke hosted session.

### Status dan webhook

Direct menyimpan paymentRequestId; hosted menyimpan paymentSessionId. SDK memilih endpoint polling dari identifier tersebut. Set webhook untuk Payment Requests/Payments dan Payment Sessions yang dipakai di dashboard Xendit. `notifyUrl` pada request umum tidak diteruskan per transaksi oleh adapter Xendit.

Webhook diverifikasi melalui `x-callback-token`. Header `webhook-id`, jika ada, menjadi deliveryId. Sesi terbuka menghasilkan CheckoutStatus Open dan PaymentStatus Pending; sesi selesai menjadi Completed dan Paid. Satu attempt gagal dapat menghasilkan PaymentStatus Failed sementara checkout tetap Open. Simpan dua status secara terpisah jika aplikasi memakai hosted Xendit.

## 3. DOKU

### Konfigurasi

```dotenv
DOKU_ENVIRONMENT=sandbox
DOKU_CLIENT_ID="client-id-dari-doku"
DOKU_SECRET_KEY="secret-key-dari-doku"
DOKU_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nisi-key-merchant\n-----END PRIVATE KEY-----"
DOKU_PRIVATE_KEY_PASSPHRASE=""
DOKU_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\nisi-public-key-doku\n-----END PUBLIC KEY-----"
DOKU_MERCHANT_ID="merchant-id-untuk-qris"
DOKU_TERMINAL_ID="terminal-id-untuk-qris"
DOKU_POSTAL_CODE="kode-pos-merchant"
DOKU_SNAP_WEBHOOK_SIGNATURE=symmetric
```

Isi PEM dengan key sebenarnya. SDK menerima teks PEM multiline atau teks yang mengandung literal `\n`; isi path file saja tidak akan dibaca otomatis. Jika menyimpan key dalam file, baca file tersebut saat menyiapkan config backend, kemudian pastikan hasilnya menjadi nilai config yang valid.

Hosted checkout memerlukan client ID dan secret key. Direct SNAP memerlukan private key RSA merchant untuk token dan secret key untuk request transaksi. QRIS juga memerlukan merchant ID, terminal ID, dan postal code. Pilih symmetric atau asymmetric untuk webhook SNAP sesuai kontrak integrasi; asymmetric membutuhkan public key DOKU. Tidak ada fallback antar mode jika signature gagal.

### Konfigurasi VA per bank

Bagian `gateways.doku.banks` pada config yang dipublikasikan awalnya kosong. Tambahkan bank yang sudah diberikan DOKU:

```php
// Di dalam 'gateways' => ['doku' => [...]] pada config/payments.php:
'banks' => [
    'bca' => [
        'partner_service_id' => env('DOKU_BCA_PARTNER_SERVICE_ID'),
        'customer_number' => env('DOKU_BCA_CUSTOMER_NUMBER'),
    ],
    'bni' => [
        'partner_service_id' => env('DOKU_BNI_PARTNER_SERVICE_ID'),
        'customer_number' => env('DOKU_BNI_CUSTOMER_NUMBER'),
    ],
],
```

Snippet ini adalah bagian array config, bukan file PHP lengkap. Isi environment dengan identifier yang dialokasikan DOKU. Partner service ID berupa 1–8 digit, customer number 1–20 digit. Simpan keduanya sebagai string.

SDK memberi padding spasi di kiri partner service ID sampai delapan karakter. Reference native mempertahankan padding tersebut untuk inquiry dan notifikasi; nomor VA untuk UI sudah dibersihkan dengan ltrim. Jangan trim identifier yang disimpan dalam `PaymentReference`.

### Direct QRIS

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::doku()->createPayment(new CreatePaymentRequest(
    reference: 'PAY-DOKU-QR-001',
    amount: 100_000,
    method: PaymentMethod::Qris,
    expiresAt: new DateTimeImmutable('+1 hour'),
));
```

### Direct VA

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::doku()->createPayment(new CreatePaymentRequest(
    reference: 'PAY-DOKU-VA-001',
    amount: 100_000,
    method: PaymentMethod::VirtualAccount,
    bank: Bank::Bca,
    customer: new Customer('Bayu', 'bayu@example.com', '628123456789'),
    expiresAt: new DateTimeImmutable('+1 hour'),
));
```

Bank yang dikenali: BCA, BRI, Mandiri, BNI, Permata, Danamon, CIMB, BSI, BNC, BTN, BSS, BJB, dan Sinarmas. Setiap bank yang dipakai memerlukan config identifier sendiri.

`DokuOptions(customerNumber: ..., virtualAccountNumber: ...)` dapat mengganti customer number/nomor VA dari config jika nomor tersebut diizinkan DOKU. Gunakan DTO `Bayurifkialghifari\Payments\DTO\Options\DokuOptions`; jangan membuat nomor asal atau mengganti partner service ID yang dialokasikan bank.

### Hosted checkout

```php
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\Facades\Payments;

$checkout = Payments::doku()->createCheckout(new CreateCheckoutRequest(
    reference: 'PAY-DOKU-CHECKOUT-001',
    amount: 100_000,
    returnUrl: 'https://shop.example/payments/return',
    cancelUrl: 'https://shop.example/payments/cancel',
    customer: new Customer('Bayu', 'bayu@example.com', '628123456789'),
    expiresAt: new DateTimeImmutable('+1 hour'),
    notifyUrl: 'https://shop.example/api/webhooks/payments/doku',
));

return redirect()->away($checkout->checkoutUrl);
```

Hosted meneruskan `notifyUrl` melalui additional_info.override_notification_url. Direct SNAP tidak meneruskan field itu; atur endpoint notifikasi yang sesuai dengan integrasi merchant.

### Reference, status, dan acknowledgment

| Alur | Identifier untuk polling |
| --- | --- |
| QRIS | referenceNo dari respons create dan merchant reference |
| VA | customerNo, partnerServiceId, dan virtualAccountNo dari respons create |
| Hosted | Invoice/merchant reference |

Simpan `$result->reference->toArray()` seluruhnya. Status SNAP 07/not found dinormalisasi menjadi Unknown, bukan bukti pembayaran gagal atau Paid.

Notifikasi non-SNAP dengan header Signature diverifikasi terhadap raw body dan path. Parser SNAP menangani callback VA; mode verifikasinya mengikuti config. Reference notifikasi dapat parsial, sehingga tidak boleh menggantikan reference create secara umum.

Untuk respons webhook, selalu gunakan:

```php
// $gateway = Payments::doku();
// $event adalah hasil $gateway->parseWebhook(...) yang berhasil diverifikasi.
return $gateway->webhookResponse($event)->toResponse();
```

Callback VA SNAP membutuhkan acknowledgment khusus yang mempertahankan identity virtual account dari notifikasi. Kembalikan respons helper yang sama setelah menemukan event duplikat yang sudah tersimpan. Jika reverse proxy mengubah path, lihat [raw body dan reverse proxy](usage.md#raw-body-dan-reverse-proxy).

## 4. Paywuz

### Konfigurasi

```dotenv
PAYWUZ_API_KEY="api-key-project-dari-paywuz"
```

API key mewakili project. Gunakan channel yang aktif pada project tersebut.

### Direct QRIS

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Options\PaywuzOptions;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::paywuz()->createPayment(new CreatePaymentRequest(
    reference: 'PAY-PAYWUZ-QR-001',
    amount: 100_000,
    method: PaymentMethod::Qris,
    expiresAt: new DateTimeImmutable('+1 hour'),
    options: new PaywuzOptions(
        feeByMerchant: true,
        paymentReminderEnabled: false,
    ),
));
```

### Direct VA

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::paywuz()->createPayment(new CreatePaymentRequest(
    reference: 'PAY-PAYWUZ-VA-001',
    amount: 100_000,
    method: PaymentMethod::VirtualAccount,
    bank: Bank::Bca,
    expiresAt: new DateTimeImmutable('+1 hour'),
));
```

Adapter mengambil katalog metode untuk memetakan Bank ke kode Paywuz yang aktif. Jika BCA tidak tersedia pada project, request ditolak dengan UnsupportedFeatureException. Gunakan `paymentMethods()` untuk memilih bank dan membaca batas minimum/maksimum nominal sebelum membuat transaksi.

### Hosted checkout

```php
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\Options\PaywuzOptions;
use Bayurifkialghifari\Payments\Facades\Payments;

$checkout = Payments::paywuz()->createCheckout(new CreateCheckoutRequest(
    reference: 'PAY-PAYWUZ-CHECKOUT-001',
    amount: 100_000,
    returnUrl: 'https://shop.example/payments/return',
    cancelUrl: 'https://shop.example/payments/cancel',
    expiresAt: new DateTimeImmutable('+1 hour'),
    options: new PaywuzOptions(
        feeByMerchant: true,
        paymentReminderEnabled: false,
        vaOnlyCheckout: true,
    ),
));

return redirect()->away($checkout->checkoutUrl);
```

Default hosted memakai metode ALL. `vaOnlyCheckout: true` memakai metode VA. Options feeByMerchant/paymentReminderEnabled berlaku untuk direct dan hosted. Return URL menjadi redirectUrl; cancelUrl dan notifyUrl tidak diteruskan oleh adapter. Konfigurasikan webhook melalui pengaturan project Paywuz.

### Status dan webhook

Reference menyimpan transactionId native, sementara polling menggunakan merchant order ID. Header `X-Paywuz-Signature` diverifikasi sebagai sha256=HMAC-SHA256 atas raw body dengan API key project. Nama event pada header harus sama dengan event di body; `X-Paywuz-Delivery` menjadi deliveryId jika tersedia.

Status `success` menjadi Paid, sementara `settlement` menjadi Processing. Gunakan PaymentStatus dari SDK; nama status ini berbeda makna dengan settlement Midtrans.

## 5. iPaymu

### Konfigurasi

```dotenv
IPAYMU_ENVIRONMENT=sandbox
IPAYMU_VA="merchant-va-dari-ipaymu"
IPAYMU_API_KEY="api-key-sandbox-dari-ipaymu"
```

Merchant VA pada config adalah identity akun iPaymu, berbeda dari nomor VA pembayaran pelanggan. Direct dan hosted wajib mengisi notifyUrl. Direct juga wajib customer name, email, dan phone.

### Direct QRIS

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\DTO\Options\IPaymuOptions;
use Bayurifkialghifari\Payments\Enums\FeeDirection;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::ipaymu()->createPayment(new CreatePaymentRequest(
    reference: 'PAY-IPAYMU-QR-001',
    amount: 100_000,
    method: PaymentMethod::Qris,
    customer: new Customer('Bayu', 'bayu@example.com', '628123456789'),
    notifyUrl: 'https://shop.example/api/webhooks/payments/ipaymu',
    options: new IPaymuOptions(feeDirection: FeeDirection::Merchant),
));
```

QRIS memakai channel MPM. Jangan memberi expiresAt untuk direct QRIS atau BCA VA karena expiry channel tetap; SDK melempar UnsupportedFeatureException jika diisi.

### Direct VA

```php
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Facades\Payments;

$payment = Payments::ipaymu()->createPayment(new CreatePaymentRequest(
    reference: 'PAY-IPAYMU-VA-001',
    amount: 100_000,
    method: PaymentMethod::VirtualAccount,
    bank: Bank::Bni,
    customer: new Customer('Bayu', 'bayu@example.com', '628123456789'),
    expiresAt: new DateTimeImmutable('+1 hour'),
    notifyUrl: 'https://shop.example/api/webhooks/payments/ipaymu',
));
```

Bank yang dikenali: BAG, BCA, BPD Bali, BNI, CIMB, Mandiri, Muamalat, BRI, BSI, Permata, Danamon, dan BTN. Enum Muamalat adalah Bank::Muamalat dengan value bmi. BPD Bali adalah Bank::BpdBali dengan value bpd_bali.

Custom expiry dibulatkan ke jam penuh. BRI maksimal dua jam dan BSI maksimal tiga jam. Untuk BCA, hilangkan expiresAt. Nominal PaymentResult dapat mencakup SubTotal, Total, dan Fee dari provider; tampilkan sesuai kebijakan merchant. FeeDirection::Buyer juga tersedia.

### Hosted checkout

```php
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\Facades\Payments;

$checkout = Payments::ipaymu()->createCheckout(new CreateCheckoutRequest(
    reference: 'PAY-IPAYMU-CHECKOUT-001',
    amount: 100_000,
    returnUrl: 'https://shop.example/payments/return',
    cancelUrl: 'https://shop.example/payments/cancel',
    customer: new Customer('Bayu', 'bayu@example.com', '628123456789'),
    notifyUrl: 'https://shop.example/api/webhooks/payments/ipaymu',
));

return redirect()->away($checkout->checkoutUrl);
```

Hosted awalnya hanya mempunyai SessionID. CheckoutResult.expiresAt tetap null karena channel pilihan pelanggan memengaruhi expiry sebenarnya. Polling memerlukan TransactionId dari callback yang diverifikasi, sehingga getStatus() awal dapat melempar StatusUnavailableException.

### Melengkapi reference hosted dari callback

Fungsi berikut menerima reference tersimpan dan event hasil `Payments::ipaymu()->parseWebhook(...)`. Jalankan pencocokan dan penyimpanan hasilnya di dalam transaksi database yang mengunci record pembayaran.

```php
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\References\IPaymuReference;
use Bayurifkialghifari\Payments\DTO\WebhookEvent;
use Bayurifkialghifari\Payments\Enums\Provider;
function enrichIPaymuReference(
    PaymentReference $stored,
    WebhookEvent $event,
): PaymentReference {
    if ($stored->provider() !== Provider::IPaymu
        || $event->reference->provider() !== Provider::IPaymu
        || $stored->merchantReference !== $event->reference->merchantReference) {
        throw new InvalidArgumentException('Provider atau merchant reference tidak cocok.');
    }

    $old = $stored->identifiers;
    $incoming = $event->reference->identifiers;

    if (! $old instanceof IPaymuReference || ! $incoming instanceof IPaymuReference) {
        throw new InvalidArgumentException('Identifier iPaymu tidak valid.');
    }

    if ($old->sessionId !== null && $incoming->sessionId !== null
        && $old->sessionId !== $incoming->sessionId) {
        throw new InvalidArgumentException('Session iPaymu tidak cocok.');
    }

    if ($incoming->transactionId === null) {
        throw new InvalidArgumentException('Callback tidak menyediakan TransactionId.');
    }

    if ($old->transactionId !== null && $old->transactionId !== $incoming->transactionId) {
        throw new InvalidArgumentException('TransactionId berbeda dari transaksi tersimpan.');
    }

    return new PaymentReference(
        merchantReference: $stored->merchantReference,
        identifiers: new IPaymuReference(
            transactionId: $incoming->transactionId,
            sessionId: $old->sessionId ?? $incoming->sessionId,
        ),
        method: $stored->method,
        bank: $stored->bank,
    );
}
```

Setelah memanggil fungsi, persist `$updated->toArray()` lalu pakai reference terbaru untuk getStatus(). Jangan menjalankan enrichment atas body yang belum diverifikasi. Return/cancel URL dari browser tidak menyediakan bukti pembayaran.

Webhook memverifikasi header X-Signature dengan formula callback iPaymu dan merchant VA. JSON dan form-urlencoded didukung. Field trx_id menjadi transactionId, sid menjadi sessionId, dan reference_id menjadi merchant reference.

Nominal `WebhookEvent::amount` / status iPaymu dapat mengikuti nominal yang mencakup fee. Tentukan pemeriksaan nominal sesuai kontrak channel dan FeeDirection; jangan menyalin pemeriksaan gross amount Midtrans begitu saja. SDK tidak membuat aturan fulfillment atau menghitung ulang biaya iPaymu.

## 6. Perbedaan yang perlu ditangani aplikasi

| Provider | notifyUrl per request | Identifier penting | Hal khusus |
| --- | --- | --- | --- |
| Midtrans | Direct dan hosted | orderId | Mandiri perlu billerCode; capture bergantung fraud status |
| Xendit | Tidak diteruskan | paymentRequestId / paymentSessionId, businessId jika subaccount | PaymentStatus dan CheckoutStatus terpisah |
| DOKU | Hosted saja | Reference QRIS atau seluruh identity VA | Padding reference VA dan acknowledgment SNAP |
| Paywuz | Tidak diteruskan | transactionId serta merchant reference | Bank aktif dari katalog project; settlement masih Processing |
| iPaymu | Wajib direct dan hosted | transactionId; sessionId untuk hosted awal | Callback melengkapi hosted; expiry dan nominal fee bergantung channel |

API agnostic menyamakan alur membuat pembayaran, membaca status, dan menerima event. Detail options tetap memakai DTO provider yang benar. Options Xendit pada request Midtrans, misalnya, ditolak saat validasi.

Accessor `Payments::midtrans()` dan sejenisnya mengembalikan kelas gateway spesifik, tetapi operasi yang tersedia saat ini tetap createPayment, createCheckout, getStatus, paymentMethods, parseWebhook, dan webhookResponse. Tidak ada client raw API, refund, payout, tokenisasi kartu, atau charge e-wallet langsung dalam package saat ini. Metode yang muncul di hosted mengikuti konfigurasi provider.

Untuk dokumentasi kontrak API vendor, lihat [daftar dokumentasi resmi di README](../README.md#dokumentasi-resmi).
