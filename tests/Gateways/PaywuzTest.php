<?php

declare(strict_types=1);

use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\DTO\Options\PaywuzOptions;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Bayurifkialghifari\Payments\Exceptions\ProviderException;
use Bayurifkialghifari\Payments\Exceptions\UnsupportedFeatureException;
use Bayurifkialghifari\Payments\Facades\Payments;
use Illuminate\Support\Facades\Http;

function paywuzBody(string $status = 'pending'): array
{
    return ['id' => 'PW-1', 'orderId' => 'ORDER-1', 'amount' => 10000, 'fee' => 990, 'totalPayment' => 10990, 'status' => $status, 'paymentNumber' => '000201QRIS', 'paymentUrl' => 'https://paywuz.id/pay/PW-1', 'expiresAt' => '2026-10-14T11:00:00+07:00'];
}

it('creates Paywuz direct QRIS with explicit fee and reminder options', function (): void {
    Http::fake(['*' => Http::response(['data' => paywuzBody()])]);
    $request = new CreatePaymentRequest('ORDER-1', 10000, PaymentMethod::Qris, customer: new Customer('Bayu', 'bayu@example.com'), expiresAt: new DateTimeImmutable('2026-10-14T11:00:01+07:00'), options: new PaywuzOptions(false, true));
    $result = Payments::paywuz()->createPayment($request);
    expect($result->instructions->text)->toBe('000201QRIS')->and($result->fee)->toBe(990)->and($result->totalPayment)->toBe(10990);
    Http::assertSent(fn ($http) => $http->url() === 'https://api.paywuz.id/v1/transactions' && $http->hasHeader('Authorization', 'Bearer paywuz-test-key') && $http->data() === ['orderId' => 'ORDER-1', 'amount' => 10000, 'paymentMethod' => 'QRIS', 'expiryMinutes' => 61, 'feeByMerchant' => false, 'paymentReminderEnabled' => true, 'metadata' => ['customerName' => 'Bayu', 'email' => 'bayu@example.com']]);
});

it('resolves active Paywuz VA codes from its catalog including numeric codes', function (): void {
    Http::fake(['api.paywuz.id/v1/payment-methods' => Http::response(['data' => [
        ['code' => 'QRIS', 'name' => 'QRIS', 'type' => 'qris', 'limits' => ['minIdr' => 10000, 'maxIdr' => 50000000]],
        ['code' => 'BCAVA', 'name' => 'BCA VA', 'type' => 'virtual_account'], ['code' => '42', 'name' => 'Mandiri Virtual Account', 'type' => 'virtual_account'],
        ['code' => '99', 'name' => 'Unsupported Bank', 'type' => 'virtual_account'], ['code' => 'ALL', 'name' => 'All', 'type' => 'meta'],
    ]]), 'api.paywuz.id/v1/transactions' => Http::response(['data' => array_replace(paywuzBody(), ['paymentNumber' => '001234567890'])])]);
    $methods = Payments::paywuz()->paymentMethods();
    expect($methods->count())->toBe(3)->and($methods->items[0]->minimumAmount)->toBe(10000)->and($methods->items[0]->maximumAmount)->toBe(50000000)->and($methods->items[1]->bank)->toBe(Bank::Bca);
    $result = Payments::paywuz()->createPayment($this->payment(PaymentMethod::VirtualAccount, Bank::Mandiri));
    expect($result->instructions->number)->toBe('001234567890')->and($result->reference->bank)->toBe(Bank::Mandiri);
    Http::assertSent(fn ($http) => $http->method() === 'POST' && $http['paymentMethod'] === '42');
});

it('rejects a bank absent from the active Paywuz catalog', function (): void {
    Http::fake(['*' => Http::response(['data' => []])]);
    Payments::paywuz()->createPayment($this->payment(PaymentMethod::VirtualAccount, Bank::Bca));
})->throws(UnsupportedFeatureException::class);

it('creates hosted Paywuz checkout and polls by merchant reference', function (bool $vaOnly): void {
    Http::fake(['*' => Http::response(['data' => paywuzBody('success')])]);
    $result = Payments::paywuz()->createCheckout(new CreateCheckoutRequest('ORDER-1', 10000, 'https://shop.example/success', 'https://shop.example/cancel', options: new PaywuzOptions(vaOnlyCheckout: $vaOnly)));
    expect($result->checkoutUrl)->toBe('https://paywuz.id/pay/PW-1')->and(Payments::getStatus($result->reference)->status)->toBe(PaymentStatus::Paid);
    Http::assertSent(fn ($http) => $http->method() === 'POST' && $http['paymentMethod'] === ($vaOnly ? 'VA' : 'ALL') && $http['redirectUrl'] === 'https://shop.example/success');
    Http::assertSent(fn ($http) => $http->method() === 'GET' && $http->url() === 'https://api.paywuz.id/v1/transactions/ORDER-1');
})->with([true, false]);

it('verifies Paywuz raw body signatures and separates settlement from payment', function (string $status, PaymentStatus $expected): void {
    $body = json_encode(['event' => 'transaction.'.$status, 'data' => paywuzBody($status)], JSON_PRETTY_PRINT);
    $headers = ['X-Paywuz-Signature' => 'sha256='.hash_hmac('sha256', $body, 'paywuz-test-key'), 'X-Paywuz-Event' => 'transaction.'.$status, 'X-Paywuz-Delivery' => 'DEL-1'];
    $event = Payments::paywuz()->parseWebhook(new WebhookRequest($body, $headers, '/hook'));
    expect($event->status)->toBe($expected)->and($event->deliveryId)->toBe('DEL-1')->and($event->reference->identifiers->transactionId)->toBe('PW-1');
})->with([['pending', PaymentStatus::Pending], ['settlement', PaymentStatus::Processing], ['success', PaymentStatus::Paid], ['failed', PaymentStatus::Failed], ['expired', PaymentStatus::Expired], ['cancelled', PaymentStatus::Cancelled], ['new-status', PaymentStatus::Unknown]]);

it('rejects a Paywuz signature or event mismatch', function (bool $badSignature): void {
    $body = json_encode(['event' => 'transaction.success', 'data' => paywuzBody('success')]);
    $headers = ['X-Paywuz-Signature' => $badSignature ? 'invalid' : 'sha256='.hash_hmac('sha256', $body, 'paywuz-test-key'), 'X-Paywuz-Event' => 'transaction.failed', 'X-Paywuz-Delivery' => 'DEL-1'];
    Payments::paywuz()->parseWebhook(new WebhookRequest($body, $headers, '/hook'));
})->with([true, false])->throws(InvalidWebhookException::class);

it('rejects Paywuz application errors returned over HTTP 200', function (): void {
    Http::fake(['*' => Http::response(['success' => false])]);
    Payments::paywuz()->createCheckout($this->checkout());
})->throws(ProviderException::class);
