<?php

declare(strict_types=1);

use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\DTO\Options\DokuOptions;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\References\DokuReference;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\CheckoutStatus;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Exceptions\ConfigurationException;
use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Bayurifkialghifari\Payments\Exceptions\ProviderException;
use Bayurifkialghifari\Payments\Exceptions\StatusUnavailableException;
use Bayurifkialghifari\Payments\Facades\Payments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function dokuTokenBody(): array
{
    return ['responseCode' => '2007300', 'accessToken' => 'TOKEN-1', 'expiresIn' => 900];
}

function dokuVaBody(): array
{
    return ['responseCode' => '2002700', 'virtualAccountData' => ['partnerServiceId' => '   19008', 'customerNo' => '00000000000000000001', 'virtualAccountNo' => '   1900800000000000000000001', 'virtualAccountName' => 'Bayu', 'totalAmount' => ['value' => '10000.00', 'currency' => 'IDR'], 'expiredDate' => '2026-10-14T11:00:00+07:00']];
}

function dokuNotificationBody(): array
{
    return ['partnerServiceId' => '   19008', 'customerNo' => '00000000000000000001', 'virtualAccountNo' => '   1900800000000000000000001', 'virtualAccountName' => 'Bayu', 'trxId' => 'ORDER-1', 'paymentRequestId' => 'DOKU-PAY-1', 'paidAmount' => ['value' => '10000.00', 'currency' => 'IDR']];
}

it('creates DOKU SNAP QRIS and signs both token and transaction requests', function (): void {
    Http::fake(['api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(dokuTokenBody()), 'api-sandbox.doku.com/snap-adapter/b2b/v1.0/qr/qr-mpm-generate' => Http::response(['responseCode' => '2004700', 'referenceNo' => 'QR-1', 'qrContent' => '000201QRIS', 'additionalInfo' => ['validityPeriod' => '2026-10-14T11:00:00+07:00']])]);
    $request = new CreatePaymentRequest('ORDER-1', 10000, PaymentMethod::Qris, expiresAt: new DateTimeImmutable('2026-10-14T11:00:00+07:00'), options: new DokuOptions);
    $result = Payments::doku()->createPayment($request);
    expect($result->instructions->text)->toBe('000201QRIS')->and($result->reference->identifiers->referenceNo)->toBe('QR-1');
    Http::assertSent(function ($http): bool {
        if (! str_contains($http->url(), 'access-token')) {
            return false;
        }
        $payload = 'MCH-TEST|'.$http->header('X-TIMESTAMP')[0];

        return openssl_verify($payload, base64_decode($http->header('X-SIGNATURE')[0]), $this->rsaPublicKey(), OPENSSL_ALGO_SHA256) === 1 && $http['grantType'] === 'client_credentials';
    });
    Http::assertSent(function ($http): bool {
        $path = '/snap-adapter/b2b/v1.0/qr/qr-mpm-generate';
        if (! str_ends_with($http->url(), $path)) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha512', 'POST:'.$path.':TOKEN-1:'.hash('sha256', $http->body()).':'.$http->header('X-TIMESTAMP')[0], 'doku-test-key', true));

        return $http->hasHeader('X-SIGNATURE', $expected) && $http->hasHeader('CHANNEL-ID', 'H2H') && ctype_digit($http->header('X-EXTERNAL-ID')[0]) && $http['amount'] === ['value' => '10000.00', 'currency' => 'IDR'] && $http['additionalInfo'] === ['postalCode' => '13120', 'feeType' => '1'];
    });
});

it('creates DOKU VA with assigned identifiers and preserves padded polling data', function (): void {
    Http::fake(['*access-token/b2b' => Http::response(dokuTokenBody()), '*create-va' => Http::response(dokuVaBody()), '*transfer-va/status' => Http::response(['responseCode' => '2002600', 'virtualAccountData' => ['paymentFlagReason' => ['english' => 'Pending'], 'paidAmount' => ['value' => '10000.00', 'currency' => 'IDR']]])]);
    $request = new CreatePaymentRequest('ORDER-1', 10000, PaymentMethod::VirtualAccount, Bank::Bca, customer: new Customer('Bayu', 'bayu@example.com', '628123456789'), expiresAt: new DateTimeImmutable('2026-10-14T11:00:00+07:00'), options: new DokuOptions('00000000000000000001', '   1900800000000000000000001'));
    $payment = Payments::doku()->createPayment($request);
    expect($payment->instructions->number)->toBe('1900800000000000000000001')->and($payment->reference->identifiers->partnerServiceId)->toBe('   19008');
    $status = Payments::getStatus(PaymentReference::fromArray($payment->reference->toArray()));
    expect($status->status)->toBe(PaymentStatus::Pending)->and($status->amount)->toBe(10000);
    Http::assertSent(fn ($http) => str_ends_with($http->url(), '/create-va') && $http['partnerServiceId'] === '   19008' && $http['customerNo'] === '00000000000000000001' && $http['virtualAccountNo'] === '   1900800000000000000000001' && $http['additionalInfo']['virtualAccountConfig']['reusableStatus'] === false && $http['virtualAccountEmail'] === 'bayu@example.com');
    Http::assertSent(fn ($http) => str_ends_with($http->url(), '/status') && $http['virtualAccountNo'] === '   1900800000000000000000001');
    Http::assertSentCount(3);
});

it('renews the DOKU token before its expiry', function (): void {
    Http::fake(['*access-token/b2b' => Http::response(dokuTokenBody()), '*qr-mpm-generate' => Http::response(['responseCode' => '2004700', 'referenceNo' => 'QR-1', 'qrContent' => '000201QRIS'])]);
    Payments::doku()->createPayment($this->payment());
    Payments::doku()->createPayment($this->payment());
    Http::assertSentCount(3);
    Carbon::setTestNow(Carbon::parse('2026-10-14T10:14:31+07:00'));
    Payments::doku()->createPayment($this->payment());
    Http::assertSentCount(5);
});

it('creates DOKU checkout with an exact non SNAP signature and production URL', function (): void {
    config()->set('payments.gateways.doku.environment', 'production');
    Http::fake(['api.doku.com/checkout/v1/payment' => Http::response(['response' => ['order' => ['amount' => '10000'], 'payment' => ['url' => 'https://doku.com/checkout-link-v2/CK-1', 'token_id' => 'CK-1', 'expired_date' => '20261014110000']]])]);
    $result = Payments::doku()->createCheckout($this->checkout());
    expect($result->checkoutUrl)->toBe('https://doku.com/checkout-link-v2/CK-1')->and($result->token)->toBe('CK-1')->and($result->expiresAt->format(DATE_ATOM))->toBe('2026-10-14T11:00:00+07:00');
    Http::assertSent(function ($http) use ($result): bool {
        $components = ['Client-Id:MCH-TEST', 'Request-Id:'.$result->reference->identifiers->requestId, 'Request-Timestamp:2026-10-14T03:00:00Z', 'Request-Target:/checkout/v1/payment', 'Digest:'.base64_encode(hash('sha256', $http->body(), true))];
        $expected = 'HMACSHA256='.base64_encode(hash_hmac('sha256', implode("\n", $components), 'doku-test-key', true));

        return $http->hasHeader('Signature', $expected) && $http['order']['callback_url_cancel'] === 'https://shop.example/cancel' && $http['customer']['name'] === 'Bayu' && $http['additional_info']['override_notification_url'] === 'https://shop.example/webhooks/payments' && $http['payment']['payment_due_date'] === 60;
    });
});

it('handles DOKU checkout expiry and customer omission', function (): void {
    Http::fake(['*' => Http::response(['response' => ['order' => ['amount' => '10000'], 'payment' => ['url' => 'https://doku.com/checkout/CK-1']]])]);
    Payments::doku()->createCheckout(new CreateCheckoutRequest('ORDER-1', 10000, 'https://shop.example/success', 'https://shop.example/cancel', expiresAt: new DateTimeImmutable('2026-10-14T12:00:00+07:00')));
    Http::assertSent(fn ($http) => $http['payment']['payment_due_date'] === 120 && ! array_key_exists('customer', $http->data()));
});

it('maps DOKU checkout statuses without closing a checkout on an attempt failure', function (string $native, PaymentStatus $expected, CheckoutStatus $checkout): void {
    Http::fake(['*' => Http::response(['transaction' => ['status' => $native], 'order' => ['amount' => 10000]])]);
    $result = Payments::doku()->getStatus(new PaymentReference('ORDER/1', new DokuReference(requestId: 'REQ-1')));
    expect($result->status)->toBe($expected)->and($result->checkoutStatus)->toBe($checkout);
    Http::assertSent(function ($http): bool {
        $components = ['Client-Id:MCH-TEST', 'Request-Id:'.$http->header('Request-Id')[0], 'Request-Timestamp:2026-10-14T03:00:00Z', 'Request-Target:/orders/v1/status/ORDER%2F1'];

        return $http->method() === 'GET' && $http->hasHeader('Signature', 'HMACSHA256='.base64_encode(hash_hmac('sha256', implode("\n", $components), 'doku-test-key', true)));
    });
})->with([['SUCCESS', PaymentStatus::Paid, CheckoutStatus::Completed], ['PENDING', PaymentStatus::Pending, CheckoutStatus::Open], ['FAILED', PaymentStatus::Failed, CheckoutStatus::Open], ['EXPIRED', PaymentStatus::Expired, CheckoutStatus::Expired], ['CANCELLED', PaymentStatus::Cancelled, CheckoutStatus::Cancelled], ['REFUNDED', PaymentStatus::Refunded, CheckoutStatus::Unknown], ['TIMEOUT', PaymentStatus::Processing, CheckoutStatus::Open], ['REDIRECT', PaymentStatus::Processing, CheckoutStatus::Open], ['FUTURE', PaymentStatus::Unknown, CheckoutStatus::Unknown]]);

it('queries DOKU QRIS using both native and merchant references', function (): void {
    Http::fake(['*access-token/b2b' => Http::response(dokuTokenBody()), '*qr-mpm-query' => Http::response(['responseCode' => '2005100', 'latestTransactionStatus' => '00', 'amount' => ['value' => '10000.00'], 'paidTime' => '2026-10-14T10:05:00+07:00'])]);
    $result = Payments::doku()->getStatus(new PaymentReference('ORDER-1', new DokuReference(referenceNo: 'QR-1'), PaymentMethod::Qris));
    expect($result->status)->toBe(PaymentStatus::Paid)->and($result->paidAt->format('H:i'))->toBe('10:05');
    Http::assertSent(fn ($http) => str_ends_with($http->url(), '/qr-mpm-query') && $http->data() === ['originalReferenceNo' => 'QR-1', 'originalPartnerReferenceNo' => 'ORDER-1', 'serviceCode' => '47', 'merchantId' => 'MALL-TEST']);
});

it('maps documented SNAP status codes and preserves not found as unknown', function (string $native, PaymentStatus $expected): void {
    Http::fake(['*access-token/b2b' => Http::response(dokuTokenBody()), '*qr-mpm-query' => Http::response(['responseCode' => '2005100', 'latestTransactionStatus' => $native])]);
    $result = Payments::doku()->getStatus(new PaymentReference('ORDER-1', new DokuReference(referenceNo: 'QR-1'), PaymentMethod::Qris));
    expect($result->status)->toBe($expected)->and($result->providerStatus)->toBe($native);
})->with([['01', PaymentStatus::Pending], ['02', PaymentStatus::Processing], ['03', PaymentStatus::Pending], ['04', PaymentStatus::Refunded], ['05', PaymentStatus::Cancelled], ['06', PaymentStatus::Failed], ['07', PaymentStatus::Unknown]]);

it('verifies DOKU VA SNAP notifications and returns the required acknowledgment', function (string $mode): void {
    config()->set('payments.gateways.doku.snap_webhook_signature', $mode);
    $fields = dokuNotificationBody();
    $body = json_encode($fields, JSON_PRETTY_PRINT);
    $compact = json_encode($fields);
    $path = '/webhooks/doku';
    $timestamp = '2026-10-14T10:05:00+07:00';
    $signature = $mode === 'symmetric' ? base64_encode(hash_hmac('sha512', 'POST:'.$path.':WEBHOOK-TOKEN:'.hash('sha256', $compact).':'.$timestamp, 'doku-test-key', true)) : $this->rsaSignature('POST:'.$path.':'.hash('sha256', $compact).':'.$timestamp);
    $event = Payments::doku()->parseWebhook(new WebhookRequest($body, ['X-TIMESTAMP' => $timestamp, 'X-SIGNATURE' => $signature, 'X-PARTNER-ID' => 'MCH-TEST', 'X-EXTERNAL-ID' => 'DEL-1', 'Authorization' => 'Bearer WEBHOOK-TOKEN'], $path));
    $ack = Payments::webhookResponse($event);
    unset($fields['paidAmount']);
    expect($event->status)->toBe(PaymentStatus::Paid)->and($event->amount)->toBe(10000)->and($ack->body)->toBe(['responseCode' => '2002500', 'responseMessage' => 'Success', 'virtualAccountData' => $fields]);
})->with(['symmetric', 'asymmetric']);

it('verifies the documented DOKU non SNAP QRIS notification from the SNAP adapter', function (): void {
    $fields = ['service' => ['id' => 'QRIS'], 'order' => ['invoice_number' => 'ORDER-1', 'amount' => 10000.0], 'transaction' => ['status' => 'SUCCESS'], 'additional_info' => ['origin' => ['system' => 'snap-adapter', 'apiFormat' => 'SNAP']]];
    $body = json_encode($fields, JSON_PRETTY_PRINT);
    $components = ['Client-Id:MCH-TEST', 'Request-Id:DEL-1', 'Request-Timestamp:2026-10-14T03:05:00Z', 'Request-Target:/webhooks/doku', 'Digest:'.base64_encode(hash('sha256', $body, true))];
    $signature = 'HMACSHA256='.base64_encode(hash_hmac('sha256', implode("\n", $components), 'doku-test-key', true));
    $event = Payments::doku()->parseWebhook(new WebhookRequest($body, ['Signature' => $signature, 'Client-Id' => 'MCH-TEST', 'Request-Id' => 'DEL-1', 'Request-Timestamp' => '2026-10-14T03:05:00Z'], '/webhooks/doku'));
    expect($event->reference->method)->toBe(PaymentMethod::Qris)->and($event->status)->toBe(PaymentStatus::Paid)->and(Payments::webhookResponse($event)->body)->toBe(['status' => 'OK']);
});

it('rejects DOKU notification signatures with wrong bytes or paths', function (string $mode): void {
    config()->set('payments.gateways.doku.snap_webhook_signature', $mode);
    $body = json_encode(dokuNotificationBody());
    Payments::doku()->parseWebhook(new WebhookRequest($body, ['X-TIMESTAMP' => '2026-10-14T10:05:00+07:00', 'X-SIGNATURE' => base64_encode('wrong'), 'X-PARTNER-ID' => 'MCH-TEST', 'Authorization' => 'Bearer TOKEN'], '/wrong-path'));
})->with(['symmetric', 'asymmetric'])->throws(InvalidWebhookException::class);

it('rejects DOKU non SNAP authentication mismatches', function (): void {
    Payments::doku()->parseWebhook(new WebhookRequest('{}', ['Signature' => 'bad', 'Client-Id' => 'other-merchant', 'Request-Id' => 'DEL-1', 'Request-Timestamp' => '2026-10-14T03:05:00Z'], '/hook'));
})->throws(InvalidWebhookException::class);

it('rejects invalid DOKU webhook signing configuration or bearer token', function (string $mode, string $token, string $exception): void {
    config()->set('payments.gateways.doku.snap_webhook_signature', $mode);
    expect(fn () => Payments::doku()->parseWebhook(new WebhookRequest('{}', ['X-TIMESTAMP' => '2026-10-14T10:05:00+07:00', 'X-SIGNATURE' => 'bad', 'Authorization' => $token], '/hook')))->toThrow($exception);
})->with([['unknown', 'Bearer TOKEN', ConfigurationException::class], ['symmetric', 'Basic TOKEN', InvalidWebhookException::class], ['symmetric', 'Bearer ', InvalidWebhookException::class]]);

it('requires assigned DOKU bank identifiers instead of inventing account prefixes', function (mixed $banks): void {
    config()->set('payments.gateways.doku.banks', $banks);
    Payments::doku()->createPayment($this->payment(PaymentMethod::VirtualAccount, Bank::Bca));
})->with([[[]], ['bad'], [['bca' => ['partner_service_id' => 19008, 'customer_number' => '0']]], [['bca' => ['partner_service_id' => '19008', 'customer_number' => 'not-numeric']]]])->throws(ConfigurationException::class);

it('requires complete DOKU references for direct status checks', function (PaymentMethod $method): void {
    Payments::doku()->getStatus(new PaymentReference('ORDER-1', new DokuReference, $method));
})->with([PaymentMethod::Qris, PaymentMethod::VirtualAccount])->throws(StatusUnavailableException::class);

it('rejects DOKU SNAP application failures with sanitized error codes', function (string $code, ?string $expected): void {
    Http::fake(['*access-token/b2b' => Http::response(['responseCode' => $code])]);
    try {
        Payments::doku()->createPayment($this->payment());
        $this->fail('Expected rejection');
    } catch (ProviderException $exception) {
        expect($exception->providerCode)->toBe($expected);
    }
})->with([['4017300', '4017300'], ['private-message', null]]);

it('validates the DOKU private key passphrase type', function (): void {
    config()->set('payments.gateways.doku.private_key_passphrase', 1);
    Payments::doku()->createPayment($this->payment());
})->throws(ConfigurationException::class);
