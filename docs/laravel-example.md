# Contoh integrasi Laravel

Contoh ini membuat pembayaran untuk satu produk demo melalui Midtrans: direct QRIS, VA, atau hosted checkout. Aplikasi menghitung nominal, menyimpan payment attempt, mengembalikan data untuk UI, melakukan polling, dan menerima webhook terverifikasi. Ini contoh aplikasi pemakai SDK; package sendiri tidak membuat tabel atau route.

Gunakan aplikasi Laravel 12/13 yang sudah memasang package, memiliki tabel users, dan memiliki autentikasi. Endpoint pembayaran berada dalam middleware auth. Contoh test membutuhkan Pest dengan bootstrap Laravel dan User factory. Untuk instalasi package, baca [panduan penggunaan](usage.md#1-instalasi).

## Daftar isi

1. [Konfigurasi dan alur](#1-konfigurasi-dan-alur)
2. [Migration dan model](#2-migration-dan-model)
3. [Service penyimpanan konfirmasi](#3-service-penyimpanan-konfirmasi)
4. [Controller pembayaran](#4-controller-pembayaran)
5. [Controller webhook](#5-controller-webhook)
6. [Routes](#6-routes)
7. [Contoh request dan respons](#7-contoh-request-dan-respons)
8. [Testing aplikasi](#8-testing-aplikasi)
9. [Memakai gateway lain](#9-memakai-gateway-lain)
10. [Melanjutkan ke aplikasi toko](#10-melanjutkan-ke-aplikasi-toko)

## 1. Konfigurasi dan alur

Di .env aplikasi:

```dotenv
APP_URL=https://shop.example
PAYMENTS_GATEWAY=midtrans
MIDTRANS_ENVIRONMENT=sandbox
MIDTRANS_SERVER_KEY="server-key-sandbox-dari-dashboard"
```

Ganti shop.example dengan domain aplikasi yang dapat menerima callback. URL yang dihasilkan route() harus sesuai domain HTTPS tersebut. Bersihkan cache config setelah mengubah nilai.

Alur yang dicontohkan:

1. User yang login mengirim SKU, quantity, dan mode pembayaran.
2. Backend mengambil harga dari katalog demo, membuat merchant reference unik, dan menyimpan attempt sebelum HTTP request.
3. Backend memanggil SDK, menyimpan reference/instruksi, lalu mengirim JSON ke frontend.
4. Frontend menampilkan QR/VA atau mengarahkan browser ke checkout_url.
5. Webhook atau polling mengonfirmasi status dan nominal, kemudian menyimpan hasil dengan row lock.

Status pembayaran dan hasil request create disimpan terpisah. creation_state=needs_reconciliation berarti hasil create belum pasti; jangan mengartikan timeout sebagai Failed atau otomatis mengulang POST create. Midtrans dapat di-inquiry menggunakan merchant order ID yang disimpan sebelum request.

## 2. Migration dan model

Buat migration di aplikasi:

```bash
php artisan make:migration create_payment_attempts_table
```

Isi migration dengan kode berikut. Semua contoh file PHP di panduan ini dimulai setelah pembuka `<?php`.

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('product_sku');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('amount');
            $table->string('provider');
            $table->string('merchant_reference')->unique();
            $table->string('mode');
            $table->string('bank')->nullable();
            $table->string('status')->default('pending');
            $table->string('provider_status')->nullable();
            $table->string('creation_state')->default('creating');
            $table->json('reference')->nullable();
            $table->json('display_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
```

Buat `app/Models/PaymentAttempt.php`:

```php
namespace App\Models;

use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Enums\Provider;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PaymentAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'product_sku', 'quantity', 'amount', 'provider',
        'merchant_reference', 'mode', 'bank', 'status', 'provider_status',
        'creation_state', 'reference', 'display_payload', 'paid_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'quantity' => 'integer',
            'provider' => Provider::class,
            'bank' => Bank::class,
            'status' => PaymentStatus::class,
            'reference' => 'array',
            'display_payload' => 'array',
            'paid_at' => 'immutable_datetime',
        ];
    }
}
```

Jalankan `php artisan migrate` setelah menambahkan migration. Column reference menyimpan hasil PaymentReference.toArray(), termasuk identifier native. display_payload hanya menyimpan data yang memang perlu ditampilkan ke pemilik pembayaran.

## 3. Service penyimpanan konfirmasi

Buat `app/Services/SyncMidtransPayment.php`. Webhook dan polling memakai service yang sama agar pemeriksaan identity, nominal, dan status konsisten.

```php
namespace App\Services;

use App\Models\PaymentAttempt;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\PaymentStatusResult;
use Bayurifkialghifari\Payments\DTO\References\MidtransReference;
use Bayurifkialghifari\Payments\DTO\WebhookEvent;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Enums\Provider;
use Illuminate\Support\Facades\DB;

final class SyncMidtransPayment
{
    public function apply(
        PaymentAttempt $attempt,
        WebhookEvent|PaymentStatusResult $confirmation,
    ): PaymentAttempt {
        return DB::transaction(function () use ($attempt, $confirmation): PaymentAttempt {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $incoming = $confirmation->reference;

            abort_unless(
                $current->provider === Provider::Midtrans
                && $incoming->provider() === Provider::Midtrans
                && $current->merchant_reference === $incoming->merchantReference,
                409,
                'Identity pembayaran tidak cocok.',
            );

            $stored = PaymentReference::fromArray($current->reference);
            $oldIds = $stored->identifiers;
            $newIds = $incoming->identifiers;

            abort_unless(
                $oldIds instanceof MidtransReference
                && $newIds instanceof MidtransReference
                && $oldIds->orderId === $newIds->orderId,
                409,
                'Order ID provider tidak cocok.',
            );

            if ($oldIds->transactionId !== null && $newIds->transactionId !== null) {
                abort_unless(
                    $oldIds->transactionId === $newIds->transactionId,
                    409,
                    'Transaction ID provider tidak cocok.',
                );
            }

            if ($confirmation->amount !== null) {
                abort_unless($confirmation->amount === $current->amount, 409, 'Nominal tidak cocok.');
            }

            if ($confirmation->status === PaymentStatus::Paid) {
                abort_unless($confirmation->amount !== null, 409, 'Konfirmasi Paid tidak memiliki nominal.');
            }

            // Paid tidak diturunkan oleh notifikasi duplikat atau status lama.
            if ($current->status !== PaymentStatus::Paid) {
                $current->provider_status = $confirmation->providerStatus;

                if ($confirmation->status !== PaymentStatus::Unknown) {
                    $current->status = $confirmation->status;
                }

                if ($confirmation->status === PaymentStatus::Paid) {
                    $current->paid_at = $confirmation instanceof PaymentStatusResult
                        ? ($confirmation->paidAt ?? now())
                        : now();
                }

                $current->save();
            }

            return $current;
        });
    }
}
```

Service ini khusus nominal gross Midtrans: amount harus sama dengan nominal attempt. Jangan memakai aturan ini untuk iPaymu tanpa menyesuaikan semantik fee. Unknown disimpan pada provider_status untuk pemeriksaan tetapi tidak mengganti status aplikasi.

paid_at memakai waktu settlement dari polling jika tersedia, atau waktu diterimanya konfirmasi. WebhookEvent tidak menyediakan paidAt. Reference webhook tidak menggantikan reference tersimpan, sehingga metode/bank dan identifier dari create tetap terjaga.

Service hanya menyimpan status pembayaran. Bila ada fulfillment, gunakan proses aplikasi yang idempotent setelah commit; contoh ini tidak mengirim barang, email, atau menjalankan job fulfillment.

## 4. Controller pembayaran

Buat `app/Http/Controllers/PaymentController.php`. Katalog produk di bawah sengaja didefinisikan di backend agar nominal tidak berasal dari form pelanggan. Pada toko nyata, ganti katalog ini dengan order dan harga yang sudah dihitung aplikasi.

```php
namespace App\Http\Controllers;

use App\Models\PaymentAttempt;
use App\Services\SyncMidtransPayment;
use Bayurifkialghifari\Payments\DTO\CheckoutResult;
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\DTO\Instructions\QrCode;
use Bayurifkialghifari\Payments\DTO\LineItem;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\PaymentResult;
use Bayurifkialghifari\Payments\DTO\References\MidtransReference;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Exceptions\PaymentException;
use Bayurifkialghifari\Payments\PaymentsManager;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class PaymentController extends Controller
{
    private const PRODUCTS = [
        'KAOS-001' => ['name' => 'Kaos Laravel', 'price' => 100_000],
    ];

    public function __construct(
        private readonly PaymentsManager $payments,
        private readonly SyncMidtransPayment $sync,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['required', Rule::in(array_keys(self::PRODUCTS))],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'mode' => ['required', Rule::in(['qris', 'va', 'checkout'])],
            'bank' => [
                'required_if:mode,va', 'prohibited_unless:mode,va',
                Rule::in(['bca', 'bni', 'bri', 'permata', 'mandiri']),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);
        $product = self::PRODUCTS[$validated['sku']];
        $quantity = (int) $validated['quantity'];
        $mode = $validated['mode'];
        $bank = $mode === 'va' ? Bank::from($validated['bank']) : null;
        $method = $mode === 'checkout' ? null : (
            $mode === 'qris' ? PaymentMethod::Qris : PaymentMethod::VirtualAccount
        );
        $merchantReference = 'PAY-'.Str::uuid();

        // Midtrans dapat di-inquiry berdasarkan order ID sebelum respons create diterima.
        $initialReference = new PaymentReference(
            merchantReference: $merchantReference,
            identifiers: new MidtransReference(orderId: $merchantReference),
            method: $method,
            bank: $bank,
        );

        $attempt = PaymentAttempt::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'product_sku' => $validated['sku'],
            'quantity' => $quantity,
            'amount' => $product['price'] * $quantity,
            'provider' => Provider::Midtrans,
            'merchant_reference' => $merchantReference,
            'mode' => $mode,
            'bank' => $bank,
            'status' => PaymentStatus::Pending,
            'creation_state' => 'creating',
            'reference' => $initialReference->toArray(),
        ]);

        $gateway = $this->payments->gateway($attempt->provider);
        $customer = new Customer($user->name, $user->email, $validated['phone'] ?? null);
        $items = [new LineItem($product['name'], $product['price'], $quantity, $validated['sku'])];
        $notifyUrl = route('payments.webhook.midtrans');
        $expiresAt = new DateTimeImmutable('+1 hour');

        try {
            $result = $mode === 'checkout'
                ? $gateway->createCheckout(new CreateCheckoutRequest(
                    reference: $merchantReference,
                    amount: $attempt->amount,
                    returnUrl: route('payments.show', $attempt),
                    cancelUrl: route('payments.show', $attempt),
                    description: $product['name'],
                    customer: $customer,
                    items: $items,
                    expiresAt: $expiresAt,
                    notifyUrl: $notifyUrl,
                ))
                : $gateway->createPayment(new CreatePaymentRequest(
                    reference: $merchantReference,
                    amount: $attempt->amount,
                    method: $method,
                    bank: $bank,
                    description: $product['name'],
                    customer: $customer,
                    items: $items,
                    expiresAt: $expiresAt,
                    notifyUrl: $notifyUrl,
                ));
        } catch (PaymentException $exception) {
            DB::transaction(function () use ($attempt): void {
                $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                $current->creation_state = 'needs_reconciliation';
                $current->save();
            });

            Log::warning('Create payment perlu pemeriksaan', [
                'payment_id' => $attempt->id,
                'reference' => $merchantReference,
                'exception_type' => $exception::class,
            ]);

            return response()->json([
                'id' => $attempt->id,
                'message' => 'Hasil pembuatan pembayaran perlu diperiksa.',
                'creation_state' => 'needs_reconciliation',
                'status_url' => route('payments.show', $attempt),
            ], 502);
        }

        $attempt = $this->saveResult($attempt, $result);

        return $this->present($attempt, 201);
    }

    public function show(Request $request, PaymentAttempt $attempt): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        abort_unless((string) $attempt->user_id === (string) $user->getAuthIdentifier(), 404);

        if ($attempt->status === PaymentStatus::Paid) {
            return $this->present($attempt);
        }

        if ($attempt->creation_state === 'creating') {
            return $this->present($attempt, 202);
        }

        try {
            $status = $this->payments->getStatus(PaymentReference::fromArray($attempt->reference));
        } catch (PaymentException $exception) {
            Log::warning('Polling payment belum berhasil', [
                'payment_id' => $attempt->id,
                'exception_type' => $exception::class,
            ]);

            return response()->json([
                'id' => $attempt->id,
                'status' => $attempt->status->value,
                'message' => 'Status provider belum dapat diperiksa. Coba polling lagi nanti.',
            ], 502);
        }

        return $this->present($this->sync->apply($attempt, $status));
    }

    private function saveResult(
        PaymentAttempt $attempt,
        PaymentResult|CheckoutResult $result,
    ): PaymentAttempt {
        $payload = ['expires_at' => $result->expiresAt?->format(DATE_ATOM)];

        if ($result instanceof CheckoutResult) {
            $payload['checkout_url'] = $result->checkoutUrl;
        } else {
            $instructions = $result->instructions;
            $payload['total_payment'] = $result->totalPayment;
            $payload['fee'] = $result->fee;
            $payload['instructions'] = $instructions instanceof QrCode
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
        }

        return DB::transaction(function () use ($attempt, $result, $payload): PaymentAttempt {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $current->reference = $result->reference->toArray();
            $current->display_payload = $payload;
            $current->creation_state = 'created';

            // Webhook bisa tiba sebelum respons create selesai disimpan.
            if ($current->status !== PaymentStatus::Paid) {
                $current->status = $result instanceof PaymentResult
                    ? $result->status
                    : PaymentStatus::Pending;
                $current->provider_status = $result instanceof PaymentResult
                    ? $result->providerStatus
                    : null;
            }

            $current->save();

            return $current;
        });
    }

    private function present(PaymentAttempt $attempt, int $httpStatus = 200): JsonResponse
    {
        return response()->json([
            'id' => $attempt->id,
            'reference' => $attempt->merchant_reference,
            'provider' => $attempt->provider->value,
            'mode' => $attempt->mode,
            'amount' => $attempt->amount,
            'status' => $attempt->status->value,
            'creation_state' => $attempt->creation_state,
            'paid_at' => $attempt->paid_at?->format(DATE_ATOM),
            'status_url' => route('payments.show', $attempt),
            'payment' => $attempt->display_payload,
        ], $httpStatus);
    }
}
```

Controller tidak menahan transaksi database selama HTTP request provider. Row lock dipakai saat menyimpan respons/konfirmasi. Jika webhook Paid datang sebelum respons create, saveResult tetap mempertahankan Paid.

Pada contoh ini setiap POST membuat attempt baru. Disable tombol selama request berlangsung; bila aplikasi memerlukan satu attempt per order atau request idempotency, tambahkan kebijakan tersebut di backend. Jangan retry create secara otomatis ketika menerima respons 502. ProviderException yang merupakan penolakan pasti dapat ditangani lebih spesifik; contoh menyimpan kasus error SDK untuk pemeriksaan tanpa menyatakan transaksi gagal.

## 5. Controller webhook

Buat `app/Http/Controllers/MidtransWebhookController.php`:

```php
namespace App\Http\Controllers;

use App\Models\PaymentAttempt;
use App\Services\SyncMidtransPayment;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Exceptions\InvalidResponseException;
use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Bayurifkialghifari\Payments\PaymentsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MidtransWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentsManager $payments,
        private readonly SyncMidtransPayment $sync,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $gateway = $this->payments->midtrans();

        try {
            $event = $gateway->parseWebhook(WebhookRequest::fromRequest($request));
        } catch (InvalidWebhookException|InvalidResponseException) {
            return response()->json(['message' => 'Notifikasi tidak valid.'], 400);
        }

        $attempt = PaymentAttempt::query()
            ->where('provider', Provider::Midtrans->value)
            ->where('merchant_reference', $event->reference->merchantReference)
            ->firstOrFail();

        $this->sync->apply($attempt, $event);

        // Respons dikirim setelah transaksi penyimpanan selesai, termasuk event duplikat.
        return $gateway->webhookResponse($event)->toResponse();
    }
}
```

Webhook tidak memakai session user. Verifikasi signature dilakukan sebelum pencarian dan perubahan pembayaran. Unknown merchant reference menghasilkan 404; nominal/identity berbeda menghasilkan 409 dan tidak mengubah record. Tentukan penanganan operasional untuk kasus itu sesuai aplikasi. Kesalahan database tidak dibalas sebagai webhook sukses.

Notifikasi Paid yang sama dapat dikirim berkali-kali; service mengunci record dan tidak mengubah paid_at yang sudah tersimpan. Ini idempotensi pembaruan status. Jika kelak menambahkan efek bisnis, gunakan identitas pembayaran/event dan outbox atau job yang juga idempotent untuk efek tersebut.

## 6. Routes

Tambahkan ke `routes/web.php`:

```php
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:60,1'])->group(function (): void {
    Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('/payments/{attempt}', [PaymentController::class, 'show'])->name('payments.show');
});
```

Tambahkan ke `routes/api.php`:

```php
use App\Http\Controllers\MidtransWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/payments/midtrans', MidtransWebhookController::class)
    ->name('payments.webhook.midtrans');
```

Jika routes/api.php belum ada, buat file tersebut dan tambahkan argument api pada withRouting yang sudah ada di `bootstrap/app.php`:

```php
// Potongan builder yang sudah ada di bootstrap/app.php:
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

Snippet builder adalah bagian konfigurasi Laravel, bukan file lengkap. Pertahankan konfigurasi bootstrap aplikasi yang sudah ada. Prefix API default membuat webhook berada di `/api/webhooks/payments/midtrans` dan tidak memakai middleware CSRF web. Endpoint POST /payments tetap memakai session autentikasi dan CSRF.

Jalankan `php artisan route:list --path=payments` untuk memeriksa URL dan middleware. Daftarkan notification URL pada dashboard Midtrans bila tidak memakai notifyUrl override. Return/cancel URL pada contoh sama-sama membuka endpoint status; tidak ada perubahan Paid/Cancelled dari redirect browser.

## 7. Contoh request dan respons

Dari halaman Blade milik user yang login, sediakan token:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">
```

Lalu kirim request dari JavaScript frontend:

```javascript
async function createPayment(mode, bank = null) {
    const body = { sku: 'KAOS-001', quantity: 1, mode };

    if (mode === 'va') {
        body.bank = bank;
    }

    const response = await fetch('/payments', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify(body),
    });

    const payment = await response.json();

    if (!response.ok) {
        // Untuk 502, simpan payment.id/status_url dan lakukan rekonsiliasi atau polling.
        // Tampilkan pesan validasi Laravel untuk 422; jangan otomatis mengulang create.
        throw new Error(payment.message ?? 'Pembayaran belum berhasil dibuat.');
    }

    if (payment.payment?.checkout_url) {
        window.location.assign(payment.payment.checkout_url);
    }

    return payment;
}

// Panggil dari event tombol sesuai pilihan pelanggan:
// await createPayment('qris');
// await createPayment('va', 'bca');
// await createPayment('checkout');
```

Contoh body VA:

```json
{
  "sku": "KAOS-001",
  "quantity": 1,
  "mode": "va",
  "bank": "bca"
}
```

Contoh respons direct; ID, nomor VA, dan URL hanya ilustrasi:

```json
{
  "id": "01900000-0000-7000-8000-000000000001",
  "reference": "PAY-reference-unik-dari-backend",
  "provider": "midtrans",
  "mode": "va",
  "amount": 100000,
  "status": "pending",
  "creation_state": "created",
  "paid_at": null,
  "status_url": "https://shop.example/payments/01900000-0000-7000-8000-000000000001",
  "payment": {
    "expires_at": null,
    "total_payment": null,
    "fee": null,
    "instructions": {
      "type": "virtual_account",
      "bank": "bca",
      "number": "nomor-va-dari-provider",
      "name": null,
      "biller_code": null
    }
  }
}
```

Untuk QRIS, payment.instructions berisi type=qris, qr_text, dan qr_image_url. Gunakan image URL bila tersedia atau QR renderer dari text. QR string dummy dalam test tidak bisa dipakai untuk pembayaran asli. SDK tidak membuat widget atau gambar QR lokal.

Untuk hosted, payment berisi checkout_url dan expires_at. Frontend melakukan redirect setelah backend menyimpan hasil. Browser yang kembali ke aplikasi tetap perlu membaca status tersimpan atau polling provider.

Polling memakai GET status_url dengan session user dan header Accept: application/json. Pada contoh ini Paid yang sudah tersimpan langsung dikembalikan tanpa inquiry tambahan. Gunakan interval yang wajar, misalnya 10–15 detik saat halaman pembayaran terbuka, hentikan setelah status selesai, dan beri jeda ketika menerima error. Jadwal rekonsiliasi background merupakan tanggung jawab aplikasi.

## 8. Testing aplikasi

Letakkan contoh di `tests/Feature/PaymentFlowTest.php`. Gunakan database test terpisah dengan RefreshDatabase; contoh HTTP fake tidak menghubungi Midtrans asli.

```php
use App\Models\PaymentAttempt;
use App\Models\User;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\References\MidtransReference;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Facades\Payments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('payments.gateways.midtrans.environment', 'sandbox');
    config()->set('payments.gateways.midtrans.server_key', 'test-key');
    Payments::forgetGateways();
    Http::preventStrayRequests();
});

/** @return array<string, string> */
function signedMidtransPayload(string $reference, string $status = 'settlement'): array
{
    $payload = [
        'order_id' => $reference,
        'transaction_id' => 'MID-TEST-001',
        'status_code' => '200',
        'gross_amount' => '100000.00',
        'transaction_status' => $status,
    ];
    $payload['signature_key'] = hash('sha512', $reference.'200'.'100000.00'.'test-key');

    return $payload;
}

function makePaymentAttempt(string $reference): PaymentAttempt
{
    return PaymentAttempt::query()->create([
        'user_id' => User::factory()->create()->id,
        'product_sku' => 'KAOS-001',
        'quantity' => 1,
        'amount' => 100_000,
        'provider' => Provider::Midtrans,
        'merchant_reference' => $reference,
        'mode' => 'qris',
        'status' => PaymentStatus::Pending,
        'creation_state' => 'created',
        'reference' => (new PaymentReference(
            $reference,
            new MidtransReference($reference),
        ))->toArray(),
    ]);
}

it('menghitung harga di backend dan menyimpan reference QRIS', function (): void {
    Http::fake([
        'api.sandbox.midtrans.com/v2/charge' => function (Request $request) {
            return Http::response([
                'status_code' => '201',
                'order_id' => $request['transaction_details']['order_id'],
                'transaction_id' => 'MID-TEST-001',
                'gross_amount' => '200000.00',
                'transaction_status' => 'pending',
                'qr_string' => 'DUMMY-QR-TEST',
            ], 201);
        },
    ]);

    $response = $this->actingAs(User::factory()->create())->postJson('/payments', [
        'sku' => 'KAOS-001', 'quantity' => 2, 'mode' => 'qris',
        'amount' => 1, // Field ini tidak dipakai controller.
    ])->assertCreated()->assertJsonPath('amount', 200_000)
        ->assertJsonPath('payment.instructions.type', 'qris');

    $attempt = PaymentAttempt::query()->findOrFail($response->json('id'));
    expect($attempt->reference['identifiers']['orderId'])->toBe($attempt->merchant_reference);
    Http::assertSentCount(1);
});

it('menerima Paid dua kali dan menjaga status saat event pending terlambat', function (): void {
    $attempt = makePaymentAttempt('PAY-WEBHOOK-001');
    $paid = signedMidtransPayload($attempt->merchant_reference);

    $this->postJson('/api/webhooks/payments/midtrans', $paid)->assertOk();
    $confirmedAt = $attempt->fresh()->paid_at;
    $this->postJson('/api/webhooks/payments/midtrans', $paid)->assertOk();
    $this->postJson('/api/webhooks/payments/midtrans',
        signedMidtransPayload($attempt->merchant_reference, 'pending'),
    )->assertOk();

    expect($attempt->fresh()->status)->toBe(PaymentStatus::Paid);
    expect($attempt->fresh()->paid_at->equalTo($confirmedAt))->toBeTrue();
});

it('menolak signature yang salah', function (): void {
    $attempt = makePaymentAttempt('PAY-WEBHOOK-INVALID');
    $payload = signedMidtransPayload($attempt->merchant_reference);
    $payload['signature_key'] = str_repeat('0', 128);

    $this->postJson('/api/webhooks/payments/midtrans', $payload)->assertStatus(400);
    expect($attempt->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('menolak nominal berbeda walaupun signature valid untuk payload itu', function (): void {
    $attempt = makePaymentAttempt('PAY-WEBHOOK-AMOUNT');
    $payload = signedMidtransPayload($attempt->merchant_reference);
    $payload['gross_amount'] = '90000.00';
    $payload['signature_key'] = hash('sha512', $attempt->merchant_reference.'200'.'90000.00'.'test-key');

    $this->postJson('/api/webhooks/payments/midtrans', $payload)->assertStatus(409);
    expect($attempt->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('menolak polling pembayaran milik user lain sebelum HTTP provider', function (): void {
    $attempt = makePaymentAttempt('PAY-PRIVATE-001');

    $this->actingAs(User::factory()->create())->getJson('/payments/'.$attempt->id)->assertNotFound();
    Http::assertNothingSent();
});
```

Jalankan dari direktori aplikasi:

```bash
php artisan test --filter=PaymentFlowTest
```

HTTP fake menguji alur aplikasi dan kontrak DTO tanpa transaksi merchant. Setelah itu coba satu QRIS, satu VA, dan satu hosted pada sandbox akun sendiri, termasuk callback yang benar-benar mencapai aplikasi. Pemakaian production memerlukan credential/channel yang aktif.

## 9. Memakai gateway lain

Bagian pemanggilan tetap melalui PaymentGateway: createPayment, createCheckout, getStatus, parseWebhook, dan webhookResponse. Saat mengganti provider, ikuti [contoh tiap gateway](gateways.md) dan ubah batas integrasi berikut:

| Bagian aplikasi | Penyesuaian |
| --- | --- |
| Pemilihan provider | Simpan provider pada attempt sebelum create; gunakan provider tersimpan untuk polling |
| Validasi channel | Bank dan expiry harus sesuai provider, bukan selalu daftar Midtrans |
| Customer/notifyUrl | iPaymu direct wajib customer lengkap; kedua alurnya wajib notifyUrl |
| Initial reference | Midtrans bisa memakai order ID lebih awal; provider lain perlu identifier dari create/callback |
| Penyimpanan konfirmasi | Sesuaikan identity native, semantik nominal, dan status checkout |
| Webhook route | Endpoint eksplisit provider, verifikasi lewat gateway tersebut, lalu acknowledgment miliknya |
| Recovery | Hosted iPaymu perlu enrichment TransactionId; DOKU direct perlu reference native lengkap |

Jangan sekadar mengganti Provider::Midtrans di controller dan memakai SyncMidtransPayment untuk semua provider. Nama service tersebut menunjukkan kebijakan identity dan nominal yang khusus Midtrans. Reference create tetap disimpan lengkap dan reference webhook tidak menimpanya secara umum.

Untuk proyek yang memilih gateway dari config, gunakan `$this->payments->gateway()` saat create dan simpan provider dari hasilnya. Tetap siapkan credential, validasi input, dan kebijakan konfirmasi untuk provider yang diaktifkan proyek. Sebuah project dapat membatasi pilihan gateway dari config server, sehingga frontend tidak menentukan akun merchant.

## 10. Melanjutkan ke aplikasi toko

Ganti katalog produk demo dengan order tersimpan yang nominalnya sudah final. Hubungkan payment attempt ke order, simpan total yang harus dibayar, dan tentukan kapan order boleh memiliki attempt baru. Customer dan item tetap dibuat memakai DTO.

Gunakan satu aturan fulfillment yang mengidentifikasi order dan pembayaran sukses. Status Paid yang idempotent pada payment attempt tidak otomatis mencegah dua pembayaran berbeda menyelesaikan order yang sama. Terapkan aturan tersebut di database/service order dan pastikan job lanjutan dapat diulang dengan aman.

Simpan event/delivery ID jika aplikasi memerlukan audit dan deduplication lintas event. Tidak semua provider menyediakan deliveryId. Tentukan transisi untuk refund dan status terminal lainnya sesuai bisnis; contoh Midtrans ini mempertahankan Paid setelah konfirmasi sukses dan tidak menjalankan refund.

Tambahkan rekonsiliasi untuk attempt needs_reconciliation dan attempt creating yang tertinggal ketika proses mati. Polling/inquiry dapat dijadwalkan tanpa mengulang create. Untuk provider yang belum memberikan identifier inquiry, gunakan callback, dashboard, atau mekanisme rekonsiliasi provider sesuai kontrak akun.
