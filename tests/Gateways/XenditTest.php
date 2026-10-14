<?php

declare(strict_types=1);

use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\DTO\Options\XenditOptions;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\References\XenditReference;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\CheckoutStatus;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Enums\WebhookSubject;
use Bayurifkialghifari\Payments\Exceptions\InvalidResponseException;
use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Bayurifkialghifari\Payments\Exceptions\StatusUnavailableException;
use Bayurifkialghifari\Payments\Facades\Payments;
use Illuminate\Support\Facades\Http;

it('creates Xendit direct QRIS or VA using Payments API v3', function (PaymentMethod $method, ?Bank $bank, string $channel, string $descriptor): void {
    Http::fake(['*' => Http::response(['payment_request_id' => 'PR-1', 'reference_id' => 'ORDER-1', 'request_amount' => 10000, 'status' => 'REQUIRES_ACTION', 'actions' => [['descriptor' => 'IGNORE', 'value' => 'other'], ['descriptor' => $descriptor, 'value' => '001234567890']]])]);
    $request = new CreatePaymentRequest('ORDER-1', 10000, $method, $bank, customer: new Customer('Bayu', 'bayu@example.com', '628123456789', 'CUST-1'), expiresAt: new DateTimeImmutable('2026-10-14T11:00:00+07:00'), options: new XenditOptions('001234567890', 'Bayu', 'BUS-1'));
    $result = Payments::xendit()->createPayment($request);
    expect($result->status)->toBe(PaymentStatus::Pending)->and($result->reference->identifiers->businessId)->toBe('BUS-1')
        ->and($method === PaymentMethod::Qris ? $result->instructions->text : $result->instructions->number)->toBe('001234567890');
    Http::assertSent(fn ($http) => $http->url() === 'https://api.xendit.co/v3/payment_requests' && $http->hasHeader('api-version', '2024-11-11') && $http->hasHeader('Authorization', 'Basic '.base64_encode('xendit-test-key:')) && $http->hasHeader('for-user-id', 'BUS-1') && $http['type'] === 'PAY' && $http['channel_code'] === $channel && $http['customer']['reference_id'] === 'CUST-1' && $http['channel_properties']['expires_at'] === '2026-10-14T11:00:00+07:00');
    if ($method === PaymentMethod::VirtualAccount) {
        Http::assertSent(fn ($http) => $http['channel_properties']['virtual_account_number'] === '001234567890' && $http['channel_properties']['display_name'] === 'Bayu');
    }
})->with([[PaymentMethod::Qris, null, 'QRIS', 'QR_STRING'], [PaymentMethod::VirtualAccount, Bank::Bca, 'BCA_VIRTUAL_ACCOUNT', 'VIRTUAL_ACCOUNT_NUMBER']]);

it('creates a minimal Xendit payment without inventing customer details', function (): void {
    Http::fake(['*' => Http::response(['payment_request_id' => 'PR-1', 'request_amount' => 10000, 'status' => 'PENDING', 'actions' => [['descriptor' => 'QR_STRING', 'value' => '000201QRIS']]])]);
    Payments::xendit()->createPayment(new CreatePaymentRequest('ORDER-1', 10000, PaymentMethod::Qris));
    Http::assertSent(fn ($http) => $http->data() === ['reference_id' => 'ORDER-1', 'type' => 'PAY', 'country' => 'ID', 'currency' => 'IDR', 'request_amount' => 10000, 'channel_code' => 'QRIS', 'description' => 'Payment']);
});

it('creates hosted Xendit sessions and polls with the saved business id', function (): void {
    Http::fake(['api.xendit.co/sessions' => Http::response(['payment_session_id' => 'PS-1', 'amount' => 10000, 'status' => 'ACTIVE', 'payment_link_url' => 'https://checkout.xendit.co/PS-1', 'expires_at' => '2026-10-14T11:00:00+07:00']), 'api.xendit.co/sessions/PS-1' => Http::response(['status' => 'COMPLETED', 'amount' => 10000])]);
    $request = new CreateCheckoutRequest('ORDER-1', 10000, 'https://shop.example/success', 'https://shop.example/cancel', customer: new Customer('Bayu'), options: new XenditOptions(businessId: 'BUS-1'));
    $result = Payments::xendit()->createCheckout($request);
    $status = Payments::getStatus(PaymentReference::fromArray($result->reference->toArray()));
    expect($result->status)->toBe(CheckoutStatus::Open)->and($status->status)->toBe(PaymentStatus::Paid)->and($status->checkoutStatus)->toBe(CheckoutStatus::Completed);
    Http::assertSent(fn ($http) => $http->method() === 'POST' && $http['session_type'] === 'PAY' && $http['mode'] === 'PAYMENT_LINK' && $http['cancel_return_url'] === 'https://shop.example/cancel');
    Http::assertSent(fn ($http) => $http->method() === 'GET' && $http->hasHeader('for-user-id', 'BUS-1'));
});

it('maps Xendit session statuses independently from payment attempts', function (string $native, PaymentStatus $payment, CheckoutStatus $checkout): void {
    Http::fake(['*' => Http::response(['status' => $native, 'amount' => 10000])]);
    $status = Payments::xendit()->getStatus(new PaymentReference('ORDER-1', new XenditReference(paymentSessionId: 'PS-1')));
    expect($status->status)->toBe($payment)->and($status->checkoutStatus)->toBe($checkout);
})->with([['ACTIVE', PaymentStatus::Pending, CheckoutStatus::Open], ['COMPLETED', PaymentStatus::Paid, CheckoutStatus::Completed], ['EXPIRED', PaymentStatus::Expired, CheckoutStatus::Expired], ['CANCELED', PaymentStatus::Cancelled, CheckoutStatus::Cancelled], ['future', PaymentStatus::Unknown, CheckoutStatus::Unknown]]);

it('maps Xendit request statuses and encodes identifiers in status URLs', function (string $native, PaymentStatus $expected): void {
    Http::fake(['*' => Http::response(['status' => $native, 'request_amount' => 10000])]);
    $status = Payments::xendit()->getStatus(new PaymentReference('ORDER-1', new XenditReference(paymentRequestId: 'PR/1')));
    expect($status->status)->toBe($expected)->and($status->providerStatus)->toBe($native);
    Http::assertSent(fn ($http) => $http->url() === 'https://api.xendit.co/v3/payment_requests/PR%2F1');
})->with([['ACCEPTING_PAYMENTS', PaymentStatus::Pending], ['PENDING', PaymentStatus::Pending], ['AUTHORIZED', PaymentStatus::Processing], ['PENDING_CAPTURE', PaymentStatus::Processing], ['SUCCEEDED', PaymentStatus::Paid], ['FAILED', PaymentStatus::Failed], ['EXPIRED', PaymentStatus::Expired], ['CANCELLED', PaymentStatus::Cancelled], ['FUTURE', PaymentStatus::Unknown]]);

it('parses verified Xendit payment and checkout events', function (string $eventName, ?string $native, PaymentStatus $expected, WebhookSubject $subject, ?CheckoutStatus $checkout): void {
    $body = ['event' => $eventName, 'business_id' => 'BUS-1', 'data' => array_filter(['reference_id' => 'ORDER-1', 'payment_request_id' => 'PR-1', 'payment_session_id' => 'PS-1', 'payment_id' => 'PAY-1', 'status' => $native, 'capture_amount' => 10000], fn ($value) => $value !== null)];
    $event = Payments::xendit()->parseWebhook(new WebhookRequest(json_encode($body), ['x-callback-token' => 'callback-token', 'webhook-id' => 'DEL-1'], '/hook'));
    expect($event->status)->toBe($expected)->and($event->subject)->toBe($subject)->and($event->checkoutStatus)->toBe($checkout)->and($event->reference->identifiers->businessId)->toBe('BUS-1')->and($event->deliveryId)->toBe('DEL-1')->and($event->amount)->toBe(10000);
})->with([
    ['payment.capture', 'SUCCEEDED', PaymentStatus::Paid, WebhookSubject::Payment, null], ['payment.succeeded', 'SUCCEEDED', PaymentStatus::Paid, WebhookSubject::Payment, null],
    ['payment.failure', 'FAILED', PaymentStatus::Failed, WebhookSubject::Payment, CheckoutStatus::Open], ['payment.failed', 'FAILED', PaymentStatus::Failed, WebhookSubject::Payment, CheckoutStatus::Open],
    ['payment_request.expiry', null, PaymentStatus::Expired, WebhookSubject::Payment, null],
    ['payment_session.completed', null, PaymentStatus::Paid, WebhookSubject::Checkout, CheckoutStatus::Completed], ['payment_session.expired', null, PaymentStatus::Expired, WebhookSubject::Checkout, CheckoutStatus::Expired],
    ['payment_session.active', 'ACTIVE', PaymentStatus::Unknown, WebhookSubject::Checkout, CheckoutStatus::Open], ['future.event', null, PaymentStatus::Unknown, WebhookSubject::Payment, null],
]);

it('rejects invalid Xendit callback tokens', function (): void {
    Payments::xendit()->parseWebhook(new WebhookRequest('{}', ['x-callback-token' => 'bad-token'], '/hook'));
})->throws(InvalidWebhookException::class);

it('requires an Xendit request or session id to poll', function (): void {
    Payments::xendit()->getStatus(new PaymentReference('ORDER-1', new XenditReference));
})->throws(StatusUnavailableException::class);

it('rejects a successful Xendit response with no usable instructions', function (): void {
    Http::fake(['*' => Http::response(['actions' => []])]);
    Payments::xendit()->createPayment($this->payment());
})->throws(InvalidResponseException::class);
