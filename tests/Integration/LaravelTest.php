<?php

declare(strict_types=1);

use Bayurifkialghifari\Payments\Contracts\PaymentGateway;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\References\MidtransReference;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Exceptions\ConfigurationException;
use Bayurifkialghifari\Payments\Facades\Payments;
use Bayurifkialghifari\Payments\Gateways\DokuGateway;
use Bayurifkialghifari\Payments\Gateways\IPaymuGateway;
use Bayurifkialghifari\Payments\Gateways\MidtransGateway;
use Bayurifkialghifari\Payments\Gateways\PaywuzGateway;
use Bayurifkialghifari\Payments\Gateways\XenditGateway;
use Bayurifkialghifari\Payments\PaymentsManager;
use Bayurifkialghifari\Payments\PaymentsServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

it('resolves default, explicit and typed gateways through the facade and container', function (Provider $provider, string $accessor, string $class): void {
    config()->set('payments.default', $provider->value);
    $manager = app(PaymentsManager::class);
    expect(app(PaymentGateway::class))->toBe($manager)
        ->and(app(PaymentsManager::class))->toBe($manager)
        ->and(Payments::gateway())->toBeInstanceOf($class)
        ->and(Payments::gateway($provider))->toBe($manager->{$accessor}())
        ->and(Payments::gateway($provider->value))->toBe(Payments::{$accessor}())
        ->and(app($class))->toBe(Payments::gateway());
})->with([
    [Provider::Midtrans, 'midtrans', MidtransGateway::class], [Provider::Xendit, 'xendit', XenditGateway::class],
    [Provider::Doku, 'doku', DokuGateway::class], [Provider::Paywuz, 'paywuz', PaywuzGateway::class], [Provider::IPaymu, 'ipaymu', IPaymuGateway::class],
]);

it('merges and publishes package configuration', function (): void {
    expect(config('payments.default'))->toBe('midtrans')->and(config('payments.timeout'))->toBe(30);
    $paths = ServiceProvider::pathsToPublish(PaymentsServiceProvider::class, 'payments-config');
    expect($paths)->toHaveCount(1)->and(array_values($paths))->toBe([config_path('payments.php')]);
    expect(is_file(array_key_first($paths)))->toBeTrue();
});

it('refreshes cached gateways after changing credentials', function (): void {
    $first = Payments::midtrans();
    expect(app(MidtransGateway::class))->toBe($first);
    config()->set('payments.gateways.midtrans.server_key', 'replacement-key');
    expect(Payments::midtrans())->toBe($first);
    Payments::forgetGateways();
    expect(Payments::midtrans())->not->toBe($first)->and(app(MidtransGateway::class))->toBe(Payments::midtrans());
    Http::fake(['*' => Http::response(['token' => 'SNAP-1', 'redirect_url' => 'https://example.com/checkout'])]);
    Payments::createCheckout($this->checkout());
    Http::assertSent(fn ($http) => $http->hasHeader('Authorization', 'Basic '.base64_encode('replacement-key:')));
});

it('dispatches common payments, status and verified webhooks through Laravel', function (): void {
    Http::fake(['*/charge' => Http::response(midtransBody() + ['qr_string' => '000201QRIS']), '*/snap/v1/transactions' => Http::response(['token' => 'SNAP-1', 'redirect_url' => 'https://example.com/checkout']), '*/status' => Http::response(midtransBody('settlement'))]);
    expect(Payments::createPayment($this->payment())->amount)->toBe(10000)
        ->and(Payments::createCheckout($this->checkout())->token)->toBe('SNAP-1')
        ->and(count(Payments::paymentMethods()))->toBe(6);
    $body = midtransBody('settlement') + ['signature_key' => hash('sha512', 'ORDER-1'.'201'.'10000.00'.'midtrans-test-key')];
    $event = Payments::parseWebhook(new WebhookRequest(json_encode($body), [], '/hook'));
    expect($event->status)->toBe(PaymentStatus::Paid);
    config()->set('payments.default', 'paywuz');
    expect(Payments::getStatus(new PaymentReference('ORDER-1', new MidtransReference('ORDER-1')))->status)->toBe(PaymentStatus::Paid)
        ->and(Payments::webhookResponse($event)->toResponse()->getStatusCode())->toBe(200);
});

it('supports a swapped typed facade manager', function (): void {
    $manager = new PaymentsManager(new Repository(['payments' => ['default' => Provider::Paywuz]]), new Factory);
    Payments::swap($manager);
    expect(Payments::gateway())->toBe($manager->paywuz());
});

it('reports an invalid facade root', function (): void {
    Payments::swap(new stdClass);
    Payments::gateway();
})->throws(RuntimeException::class, 'Payments facade requires a registered PaymentsManager.');

it('rejects unknown or invalid default gateways', function (mixed $default): void {
    config()->set('payments.default', $default);
    Payments::gateway();
})->with(['unknown', [42], [false]])->throws(ConfigurationException::class);

it('rejects invalid gateway configuration and HTTP timeouts', function (string $path, mixed $value): void {
    config()->set($path, $value);
    Payments::midtrans();
})->with([
    ['payments.gateways.midtrans', 'invalid'], ['payments.gateways.midtrans', [123]],
    ['payments.timeout', '30'], ['payments.connect_timeout', null], ['payments.timeout', 0], ['payments.connect_timeout', -1],
])->throws(ConfigurationException::class);
