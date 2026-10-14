<?php

declare(strict_types=1);

use Bayurifkialghifari\Payments\Contracts\ProviderReference;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Options\DokuOptions;
use Bayurifkialghifari\Payments\DTO\Options\IPaymuOptions;
use Bayurifkialghifari\Payments\DTO\Options\MidtransOptions;
use Bayurifkialghifari\Payments\DTO\Options\PaywuzOptions;
use Bayurifkialghifari\Payments\DTO\Options\XenditOptions;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\References\PaywuzReference;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Exceptions\ConfigurationException;
use Bayurifkialghifari\Payments\Exceptions\InvalidResponseException;
use Bayurifkialghifari\Payments\Exceptions\ProviderException;
use Bayurifkialghifari\Payments\Exceptions\TransportException;
use Bayurifkialghifari\Payments\Exceptions\UnsupportedFeatureException;
use Bayurifkialghifari\Payments\Facades\Payments;
use Bayurifkialghifari\Payments\Support\Transport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

it('sanitizes provider HTTP failures and never follows a payment redirect or retries', function (int $status): void {
    Http::fake(['*' => Http::response(['message' => 'secret-key customer-data'], $status, ['Location' => 'https://example.com/redirect'])]);
    try {
        Payments::midtrans()->createCheckout($this->checkout());
        $this->fail('Expected provider failure');
    } catch (ProviderException $exception) {
        expect($exception->httpStatus)->toBe($status)->and($exception->provider)->toBe(Provider::Midtrans)
            ->and($exception->getMessage())->not->toContain('secret-key', 'customer-data')->and($exception->getPrevious())->toBeNull();
    }
    Http::assertSentCount(1);
})->with([302, 400, 401, 429, 500]);

it('sanitizes connection errors without retaining credential containing exceptions', function (): void {
    Http::fake(fn () => throw new ConnectionException('https://secret-key@example.com/private'));
    try {
        Payments::midtrans()->createCheckout($this->checkout());
        $this->fail('Expected transport failure');
    } catch (TransportException $exception) {
        expect($exception->getMessage())->toBe('Payment provider connection failed.')->and($exception->getPrevious())->toBeNull();
    }
});

it('configures HTTP timeouts and redirects on the actual Laravel request', function (): void {
    $seen = [];
    $http = new Factory;
    $http->fake(['*' => Http::response(['ok' => true])]);
    $http->globalMiddleware(function (callable $handler) use (&$seen): Closure {
        return function ($request, array $options) use ($handler, &$seen) {
            $seen = $options;

            return $handler($request, $options);
        };
    });
    (new Transport($http, Provider::Midtrans, 41, 7))->send('GET', 'https://example.com/status');
    expect($seen['timeout'])->toBe(41)->and($seen['connect_timeout'])->toBe(7)->and($seen['allow_redirects'])->toBeFalse();
});

it('validates credential and environment settings before requesting a provider', function (string $path, mixed $value): void {
    config()->set($path, $value);
    Payments::midtrans()->createCheckout($this->checkout());
})->with([['payments.gateways.midtrans.server_key', null], ['payments.gateways.midtrans.server_key', ' '], ['payments.gateways.midtrans.server_key', 123], ['payments.gateways.midtrans.environment', 'invalid'], ['payments.gateways.midtrans.environment', 123]])->throws(ConfigurationException::class);

it('rejects options belonging to another gateway', function (object $options, Provider $provider): void {
    Payments::gateway($provider)->createPayment(new CreatePaymentRequest('ORDER-1', 10000, PaymentMethod::Qris, options: $options));
})->with([[new MidtransOptions, Provider::Xendit], [new XenditOptions, Provider::Doku], [new DokuOptions, Provider::Paywuz], [new PaywuzOptions, Provider::IPaymu], [new IPaymuOptions, Provider::Midtrans]])->throws(InvalidArgumentException::class);

it('rejects references belonging to another gateway', function (): void {
    Payments::midtrans()->getStatus(new PaymentReference('ORDER-1', new PaywuzReference('PW-1')));
})->throws(InvalidArgumentException::class);

it('rejects unsupported VA banks and past expiry before sending', function (): void {
    expect(fn () => Payments::midtrans()->createPayment($this->payment(PaymentMethod::VirtualAccount, Bank::Bjb)))->toThrow(UnsupportedFeatureException::class);
    expect(fn () => Payments::midtrans()->createPayment(new CreatePaymentRequest('ORDER-1', 10000, PaymentMethod::Qris, expiresAt: new DateTimeImmutable('2026-10-14T09:59:59+07:00'))))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('rejects incompatible concrete identifiers from an extended reference', function (Provider $provider): void {
    $ids = new class($provider) implements ProviderReference
    {
        public function __construct(private Provider $value) {}

        public function provider(): Provider
        {
            return $this->value;
        }

        public function toArray(): array
        {
            return [];
        }
    };
    Payments::gateway($provider)->getStatus(new PaymentReference('ORDER-1', $ids));
})->with([Provider::Midtrans, Provider::Xendit, Provider::Doku, Provider::IPaymu])->throws(InvalidResponseException::class);
