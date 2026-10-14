<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Tests;

use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\PaymentsServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

abstract class TestCase extends Orchestra
{
    private static string $privateKey = '';

    private static string $publicKey = '';

    protected function getPackageProviders($app): array
    {
        return [PaymentsServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
        Http::preventStrayRequests();
        config()->set('payments.gateways.midtrans.server_key', 'midtrans-test-key');
        config()->set('payments.gateways.xendit', ['secret_key' => 'xendit-test-key', 'webhook_token' => 'callback-token']);
        config()->set('payments.gateways.paywuz.api_key', 'paywuz-test-key');
        config()->set('payments.gateways.ipaymu', ['environment' => 'sandbox', 'va' => '1179000899', 'api_key' => 'ipaymu-test-key']);
        if (self::$privateKey === '') {
            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => __DIR__.'/Fixtures/openssl.cnf'];
            $key = openssl_pkey_new($options);
            if ($key === false || ! openssl_pkey_export($key, self::$privateKey, null, $options)) {
                throw new RuntimeException('Cannot generate the test RSA key.');
            }
            self::$publicKey = openssl_pkey_get_details($key)['key'];
        }
        config()->set('payments.gateways.doku', ['environment' => 'sandbox', 'client_id' => 'MCH-TEST', 'secret_key' => 'doku-test-key', 'private_key' => self::$privateKey, 'public_key' => self::$publicKey, 'merchant_id' => 'MALL-TEST', 'terminal_id' => 'TERM-001', 'postal_code' => '13120', 'channel_id' => 'H2H', 'banks' => ['bca' => ['partner_service_id' => '19008', 'customer_number' => '0']]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function payment(PaymentMethod $method = PaymentMethod::Qris, ?Bank $bank = null): CreatePaymentRequest
    {
        return new CreatePaymentRequest('ORDER-1', 10000, $method, $bank, customer: new Customer('Bayu', 'bayu@example.com', '628123456789'), notifyUrl: 'https://shop.example/webhooks/payments');
    }

    public function checkout(): CreateCheckoutRequest
    {
        return new CreateCheckoutRequest('ORDER-1', 10000, 'https://shop.example/success', 'https://shop.example/cancel', customer: new Customer('Bayu', 'bayu@example.com', '628123456789'), notifyUrl: 'https://shop.example/webhooks/payments');
    }

    public function rsaSignature(string $payload): string
    {
        openssl_sign($payload, $signature, self::$privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    public function rsaPublicKey(): string
    {
        return self::$publicKey;
    }
}
