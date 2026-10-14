<?php

declare(strict_types=1);

use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\DTO\Options\MidtransOptions;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Enums\QrisAcquirer;
use Bayurifkialghifari\Payments\Exceptions\InvalidResponseException;
use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Bayurifkialghifari\Payments\Exceptions\ProviderException;
use Bayurifkialghifari\Payments\Facades\Payments;
use Illuminate\Support\Facades\Http;

function midtransBody(string $status = 'pending', ?string $fraud = null): array
{
    return array_filter(['order_id' => 'ORDER-1', 'transaction_id' => 'MID-1', 'status_code' => '201', 'gross_amount' => '10000.00', 'transaction_status' => $status, 'fraud_status' => $fraud], fn ($value) => $value !== null);
}

it('accepts Midtrans QR text without an optional image action', function (): void {
    Http::fake(['*' => Http::response(midtransBody() + ['qr_string' => '000201QRIS'])]);
    $result = Payments::midtrans()->createPayment($this->payment());
    expect($result->instructions->text)->toBe('000201QRIS')->and($result->instructions->imageUrl)->toBeNull();
});

it('creates Midtrans QRIS with exact authentication and customer payload', function (): void {
    Http::fake(['api.sandbox.midtrans.com/v2/charge' => Http::response(midtransBody() + ['qr_string' => '000201010212QRIS', 'actions' => [['name' => 'generate-qr-code', 'url' => 'https://example.com/qr.png'], ['name' => 'generate-qr-code-v2', 'url' => 'https://example.com/qr-v2.png']], 'expiry_time' => '2026-10-14 11:00:00'])]);
    $request = new CreatePaymentRequest('ORDER-1', 10000, PaymentMethod::Qris, customer: new Customer('Bayu', 'bayu@example.com', '628123456789'), expiresAt: new DateTimeImmutable('2026-10-14T11:00:00+07:00'), notifyUrl: 'https://shop.example/hook', options: new MidtransOptions(QrisAcquirer::ShopeePay));
    $result = Payments::midtrans()->createPayment($request);
    expect($result->instructions->text)->toBe('000201010212QRIS')->and($result->instructions->imageUrl)->toBe('https://example.com/qr-v2.png')
        ->and($result->amount)->toBe(10000)->and($result->reference->identifiers->transactionId)->toBe('MID-1')
        ->and($result->expiresAt->format(DATE_ATOM))->toBe('2026-10-14T11:00:00+07:00');
    Http::assertSent(fn ($http) => $http->method() === 'POST' && $http->hasHeader('Authorization', 'Basic '.base64_encode('midtrans-test-key:')) && $http->hasHeader('X-Override-Notification', 'https://shop.example/hook') && $http->data() === [
        'transaction_details' => ['order_id' => 'ORDER-1', 'gross_amount' => 10000], 'item_details' => [['id' => 'ORDER-1', 'name' => 'Payment', 'price' => 10000, 'quantity' => 1]],
        'customer_details' => ['first_name' => 'Bayu', 'email' => 'bayu@example.com', 'phone' => '628123456789'], 'payment_type' => 'qris', 'qris' => ['acquirer' => 'airpay shopee'],
        'custom_expiry' => ['order_time' => '2026-10-14 10:00:00 +0700', 'expiry_duration' => 60, 'unit' => 'minute'],
    ]);
});

it('supports each Midtrans VA format', function (Bank $bank, array $extra, string $type, string $number, ?string $biller): void {
    Http::fake(['*' => Http::response(midtransBody() + $extra)]);
    $result = Payments::midtrans()->createPayment(new CreatePaymentRequest('ORDER-1', 10000, PaymentMethod::VirtualAccount, $bank, options: new MidtransOptions(virtualAccountNumber: '000001')));
    expect($result->instructions->number)->toBe($number)->and($result->instructions->billerCode)->toBe($biller)->and($result->reference->bank)->toBe($bank);
    Http::assertSent(fn ($http) => $http['payment_type'] === $type && ($type === 'echannel' ? $http['echannel']['bill_info2'] === 'Payment' : $http['bank_transfer']['bank'] === $bank->value && $http['bank_transfer']['va_number'] === '000001'));
})->with([
    [Bank::Bca, ['va_numbers' => [['bank' => 'bca', 'va_number' => '001234567890']]], 'bank_transfer', '001234567890', null],
    [Bank::Bni, ['va_numbers' => [['bank' => 'bni', 'va_number' => '8808123456']]], 'bank_transfer', '8808123456', null],
    [Bank::Bri, ['va_numbers' => [['bank' => 'bri', 'va_number' => '8809123456']]], 'bank_transfer', '8809123456', null],
    [Bank::Permata, ['permata_va_number' => '00199123456'], 'bank_transfer', '00199123456', null],
    [Bank::Mandiri, ['bill_key' => '0012345678', 'biller_code' => '70012'], 'echannel', '0012345678', '70012'],
]);

it('creates hosted Snap checkout and polls the persisted order in production', function (): void {
    config()->set('payments.gateways.midtrans.environment', 'production');
    Http::fake(['app.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'SNAP-1', 'redirect_url' => 'https://app.midtrans.com/snap/v2/vtweb/SNAP-1']), 'api.midtrans.com/v2/ORDER-1/status' => Http::response(midtransBody('settlement') + ['settlement_time' => '2026-10-14 10:30:00'])]);
    $request = new CreateCheckoutRequest('ORDER-1', 10000, 'https://shop.example/success', 'https://shop.example/cancel', expiresAt: new DateTimeImmutable('2026-10-14T11:00:00+07:00'));
    $checkout = Payments::midtrans()->createCheckout($request);
    expect($checkout->token)->toBe('SNAP-1')->and($checkout->checkoutUrl)->toContain('/vtweb/SNAP-1')
        ->and(Payments::getStatus($checkout->reference)->status)->toBe(PaymentStatus::Paid)
        ->and(Payments::getStatus($checkout->reference)->paidAt->format('H:i'))->toBe('10:30');
    Http::assertSent(fn ($http) => str_contains($http->url(), '/snap/v1/') && $http['callbacks'] === ['finish' => 'https://shop.example/success', 'error' => 'https://shop.example/cancel'] && $http['expiry']['duration'] === 60);
});

it('verifies Midtrans webhook amounts without changing the signed decimal string', function (string $status, ?string $fraud, PaymentStatus $expected): void {
    $body = midtransBody($status, $fraud);
    $body['signature_key'] = hash('sha512', 'ORDER-1'.'201'.'10000.00'.'midtrans-test-key');
    $event = Payments::midtrans()->parseWebhook(new WebhookRequest(json_encode($body, JSON_THROW_ON_ERROR), [], '/hook'));
    expect($event->status)->toBe($expected)->and($event->providerStatus)->toBe($status)->and($event->amount)->toBe(10000)
        ->and(Payments::webhookResponse($event)->statusCode)->toBe(200);
})->with([
    ['pending', null, PaymentStatus::Pending], ['settlement', null, PaymentStatus::Paid], ['capture', 'accept', PaymentStatus::Paid],
    ['capture', 'challenge', PaymentStatus::Processing], ['capture', 'deny', PaymentStatus::Failed], ['capture', null, PaymentStatus::Unknown],
    ['authorize', null, PaymentStatus::Processing], ['deny', null, PaymentStatus::Failed], ['failure', null, PaymentStatus::Failed],
    ['expire', null, PaymentStatus::Expired], ['cancel', null, PaymentStatus::Cancelled], ['future_status', null, PaymentStatus::Unknown],
]);

it('rejects tampered Midtrans notifications', function (): void {
    $body = midtransBody('settlement') + ['signature_key' => hash('sha512', 'ORDER-1'.'201'.'10000.00'.'midtrans-test-key')];
    $body['gross_amount'] = '20000.00';
    Payments::midtrans()->parseWebhook(new WebhookRequest(json_encode($body), [], '/hook'));
})->throws(InvalidWebhookException::class);

it('rejects missing payment instructions', function (PaymentMethod $method, ?Bank $bank, array $extra): void {
    Http::fake(['*' => Http::response(midtransBody() + $extra)]);
    Payments::midtrans()->createPayment($this->payment($method, $bank));
})->with([[PaymentMethod::Qris, null, ['actions' => []]], [PaymentMethod::VirtualAccount, Bank::Bca, ['va_numbers' => []]]])->throws(InvalidResponseException::class);

it('preserves a sanitized Midtrans application error code', function (string $code, ?string $expected): void {
    Http::fake(['*' => Http::response(['status_code' => $code, 'status_message' => 'private-data'])]);
    try {
        Payments::midtrans()->createCheckout($this->checkout());
        $this->fail('Expected rejection');
    } catch (ProviderException $exception) {
        expect($exception->providerCode)->toBe($expected)->and($exception->getMessage())->not->toContain('private-data');
    }
})->with([['406', '406'], ['secret-response', null]]);
