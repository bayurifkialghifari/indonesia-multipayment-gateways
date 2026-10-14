<?php

declare(strict_types=1);

use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\DTO\Options\IPaymuOptions;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\References\IPaymuReference;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\FeeDirection;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Bayurifkialghifari\Payments\Exceptions\ProviderException;
use Bayurifkialghifari\Payments\Exceptions\StatusUnavailableException;
use Bayurifkialghifari\Payments\Exceptions\UnsupportedFeatureException;
use Bayurifkialghifari\Payments\Facades\Payments;
use Bayurifkialghifari\Payments\Support\Data;
use Bayurifkialghifari\Payments\Support\IPaymuSignature;
use Illuminate\Support\Facades\Http;

it('creates iPaymu QRIS or VA with a signature over the exact JSON body', function (PaymentMethod $method, ?Bank $bank): void {
    Http::fake(['*' => Http::response(['Status' => 200, 'Data' => ['TransactionId' => 193205, 'SessionId' => 'SID-1', 'PaymentNo' => '001234567890', 'QrString' => '000201QRIS', 'QrImage' => 'https://example.com/qr.png', 'SubTotal' => 10000, 'Total' => 10990, 'Fee' => 990, 'Expired' => '2026-10-14 10:05:00']])]);
    $result = Payments::ipaymu()->createPayment($this->payment($method, $bank));
    expect($result->amount)->toBe(10000)->and($result->reference->identifiers->transactionId)->toBe('193205')->and($result->fee)->toBe(990)
        ->and($method === PaymentMethod::Qris ? $result->instructions->text : $result->instructions->number)->toBe($method === PaymentMethod::Qris ? '000201QRIS' : '001234567890');
    Http::assertSent(function ($http) use ($method, $bank): bool {
        $canonical = 'POST:1179000899:'.hash('sha256', $http->body()).':ipaymu-test-key';

        return $http->url() === 'https://sandbox.ipaymu.com/api/v2/payment/direct' && $http->hasHeader('signature', hash_hmac('sha256', $canonical, 'ipaymu-test-key')) && $http->hasHeader('timestamp', '20261014100000') && $http['name'] === 'Bayu' && $http['amount'] === 10000 && $http['paymentMethod'] === $method->value && $http['paymentChannel'] === ($method === PaymentMethod::Qris ? 'mpm' : $bank->value);
    });
})->with([[PaymentMethod::Qris, null], [PaymentMethod::VirtualAccount, Bank::Bri]]);

it('handles the documented legacy iPaymu VA response without SubTotal', function (): void {
    Http::fake(['*' => Http::response(['Status' => 200, 'Data' => ['TransactionId' => 3084673, 'SessionId' => '1', 'PaymentNo' => '578893000000163', 'Total' => 10000, 'Fee' => 3500, 'Expired' => '2026-10-14 11:00:00']])]);
    $result = Payments::ipaymu()->createPayment(new CreatePaymentRequest('ORDER-1', 10000, PaymentMethod::VirtualAccount, Bank::Bri, customer: new Customer('Bayu', 'bayu@example.com', '628123456789'), expiresAt: new DateTimeImmutable('2026-10-14T11:00:00+07:00'), notifyUrl: 'https://shop.example/hook', options: new IPaymuOptions(FeeDirection::Merchant)));
    expect($result->amount)->toBe(10000);
    Http::assertSent(fn ($http) => $http['expired'] === 1 && $http['feeDirection'] === 'MERCHANT' && ! array_key_exists('expiredType', $http->data()));
});

it('creates iPaymu hosted checkout in production and exposes the session polling limitation', function (): void {
    config()->set('payments.gateways.ipaymu.environment', 'production');
    Http::fake(['*' => Http::response(['Status' => 200, 'Data' => ['SessionID' => 'SID-1', 'Url' => 'https://my.ipaymu.com/payment/SID-1']])]);
    $result = Payments::ipaymu()->createCheckout(new CreateCheckoutRequest('ORDER-1', 10000, 'https://shop.example/success', 'https://shop.example/cancel', expiresAt: new DateTimeImmutable('2026-10-14T11:00:01+07:00'), notifyUrl: 'https://shop.example/hook'));
    expect($result->expiresAt)->toBeNull();
    expect($result->reference->identifiers->sessionId)->toBe('SID-1');
    Http::assertSent(fn ($http) => $http->url() === 'https://my.ipaymu.com/api/v2/payment' && $http['product'] === ['Payment'] && $http['qty'] === [1] && $http['price'] === [10000] && $http['expired'] === 2 && $http['cancelUrl'] === 'https://shop.example/cancel');
    expect(fn () => Payments::getStatus($result->reference))->toThrow(StatusUnavailableException::class);
});

it('adds iPaymu hosted buyer details', function (): void {
    Http::fake(['*' => Http::response(['Status' => 200, 'Data' => ['SessionID' => 'SID-1', 'Url' => 'https://my.ipaymu.com/payment/SID-1']])]);
    Payments::ipaymu()->createCheckout($this->checkout());
    Http::assertSent(fn ($http) => $http['buyerName'] === 'Bayu' && $http['buyerEmail'] === 'bayu@example.com' && $http['buyerPhone'] === '628123456789');
});

it('maps documented iPaymu transaction statuses', function (int $native, PaymentStatus $expected): void {
    Http::fake(['*' => Http::response(['Status' => 200, 'Success' => true, 'Data' => ['Status' => $native, 'Amount' => 10000, 'SuccessDate' => '2026-10-14 10:05:00']])]);
    $result = Payments::ipaymu()->getStatus(new PaymentReference('ORDER-1', new IPaymuReference('193205', 'SID-1')));
    expect($result->status)->toBe($expected)->and($result->paidAt->format('H:i'))->toBe('10:05');
    Http::assertSent(fn ($http) => $http->url() === 'https://sandbox.ipaymu.com/api/v2/transaction' && $http['transactionId'] === '193205');
})->with([[0, PaymentStatus::Pending], [1, PaymentStatus::Paid], [2, PaymentStatus::Cancelled], [3, PaymentStatus::Refunded], [4, PaymentStatus::Failed], [5, PaymentStatus::Failed], [6, PaymentStatus::Paid], [7, PaymentStatus::Paid], [-2, PaymentStatus::Expired], [99, PaymentStatus::Unknown]]);

it('normalizes and verifies equivalent iPaymu form and JSON webhooks', function (bool $form): void {
    $fields = ['trx_id' => 193205, 'sid' => 'SID-1', 'reference_id' => 'ORDER-1', 'status' => 'berhasil', 'status_code' => 1, 'transaction_status_code' => 1, 'paid_off' => 10000, 'amount' => '10990', 'is_escrow' => false, 'additional_info' => [], 'url' => 'https://shop.example/hook'];
    $sorted = $fields;
    ksort($sorted, SORT_STRING);
    $signature = hash_hmac('sha256', json_encode($sorted, JSON_THROW_ON_ERROR), '1179000899');
    $fields['signature'] = 'ignored-body-signature';
    if ($form) {
        $fields['is_escrow'] = 'false';
        $fields['additional_info'] = '[]';
    }
    $body = $form ? http_build_query($fields) : json_encode($fields);
    $event = Payments::ipaymu()->parseWebhook(new WebhookRequest($body, ['Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json', 'X-Signature' => $signature, 'X-External-ID' => 'DEL-1'], '/hook'));
    expect($event->status)->toBe(PaymentStatus::Paid)->and($event->reference->identifiers->transactionId)->toBe('193205')->and($event->amount)->toBe(10990)->and($event->deliveryId)->toBe('DEL-1');
})->with([true, false]);

it('rejects invalid iPaymu signatures', function (): void {
    Payments::ipaymu()->parseWebhook(new WebhookRequest('{"reference_id":"ORDER-1","status_code":1}', ['X-Signature' => 'invalid'], '/hook'));
})->throws(InvalidWebhookException::class);

it('canonicalizes scalar callback fields and validates integer fields', function (): void {
    $fields = ['true' => true, 'false' => false, 'null' => null, 'is_escrow' => '1'];
    $sorted = ['additional_info' => [], 'false' => 'false', 'is_escrow' => true, 'null' => 'null', 'true' => 'true'];
    expect(IPaymuSignature::callback(new Data($fields), '1179000899'))->toBe(hash_hmac('sha256', json_encode($sorted), '1179000899'));
    expect(fn () => IPaymuSignature::callback(new Data(['trx_id' => 'bad']), '1179000899'))->toThrow(InvalidWebhookException::class);
    expect(fn () => IPaymuSignature::callback(new Data(['field' => []]), '1179000899'))->toThrow(InvalidWebhookException::class);
});

it('reads iPaymu active method availability and signs GET with an empty body', function (): void {
    $channel = ['Code' => 'mpm', 'Name' => 'QRIS', 'FeatureStatus' => 'active', 'HealthStatus' => 'online'];
    Http::fake(['*' => Http::response(['Status' => 200, 'Data' => [
        ['Code' => 'cc'], ['Code' => 'qris', 'Channels' => [$channel, array_replace($channel, ['Code' => 'unsupported'])]],
        ['Code' => 'va', 'Channels' => [array_replace($channel, ['Code' => 'bca', 'Name' => 'BCA', 'HealthStatus' => 'offline', 'PaymentInstructionsDoc' => 'https://example.com/va.pdf']), array_replace($channel, ['Code' => 'unknown'])]],
    ]])]);
    $methods = Payments::ipaymu()->paymentMethods();
    expect($methods->count())->toBe(2)->and($methods->items[0]->enabled)->toBeTrue()->and($methods->items[1]->enabled)->toBeFalse()->and($methods->items[1]->instructionsUrl)->toBe('https://example.com/va.pdf');
    Http::assertSent(fn ($http) => $http->method() === 'GET' && $http->body() === '' && $http->hasHeader('signature', hash_hmac('sha256', 'GET:1179000899:'.hash('sha256', '').':ipaymu-test-key', 'ipaymu-test-key')));
});

it('rejects unsupported iPaymu expiry customization', function (PaymentMethod $method, ?Bank $bank, string $expiry): void {
    Payments::ipaymu()->createPayment(new CreatePaymentRequest('ORDER-1', 10000, $method, $bank, expiresAt: new DateTimeImmutable($expiry), notifyUrl: 'https://shop.example/hook'));
})->with([[PaymentMethod::Qris, null, '2026-10-14T11:00:00+07:00'], [PaymentMethod::VirtualAccount, Bank::Bca, '2026-10-14T11:00:00+07:00'], [PaymentMethod::VirtualAccount, Bank::Bri, '2026-10-14T13:00:00+07:00'], [PaymentMethod::VirtualAccount, Bank::Bsi, '2026-10-14T14:00:00+07:00']])->throws(UnsupportedFeatureException::class);

it('requires iPaymu notification URL and direct customer identity', function (bool $missingNotify): void {
    Payments::ipaymu()->createPayment(new CreatePaymentRequest('ORDER-1', 10000, PaymentMethod::Qris, notifyUrl: $missingNotify ? null : 'https://shop.example/hook'));
})->with([true, false])->throws(InvalidArgumentException::class);

it('rejects iPaymu application failures', function (array $body): void {
    Http::fake(['*' => Http::response($body)]);
    Payments::ipaymu()->createCheckout($this->checkout());
})->with([[['Status' => 401]], [['Status' => 200, 'Success' => false]]])->throws(ProviderException::class);
