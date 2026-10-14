<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments;

use Bayurifkialghifari\Payments\Contracts\PaymentGateway;
use Bayurifkialghifari\Payments\DTO\CheckoutResult;
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\PaymentMethodCollection;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\PaymentResult;
use Bayurifkialghifari\Payments\DTO\PaymentStatusResult;
use Bayurifkialghifari\Payments\DTO\WebhookEvent;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\DTO\WebhookResponse;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Exceptions\ConfigurationException;
use Bayurifkialghifari\Payments\Gateways\DokuGateway;
use Bayurifkialghifari\Payments\Gateways\Gateway;
use Bayurifkialghifari\Payments\Gateways\IPaymuGateway;
use Bayurifkialghifari\Payments\Gateways\MidtransGateway;
use Bayurifkialghifari\Payments\Gateways\PaywuzGateway;
use Bayurifkialghifari\Payments\Gateways\XenditGateway;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;

final class PaymentsManager implements PaymentGateway
{
    /** @var array<string, Gateway> */
    private array $gateways = [];

    public function __construct(private readonly Repository $config, private readonly Factory $http) {}

    public function gateway(Provider|string|null $provider = null): DokuGateway|IPaymuGateway|MidtransGateway|PaywuzGateway|XenditGateway
    {
        $value = $provider ?? $this->config->get('payments.default', 'midtrans');
        $driver = $value instanceof Provider ? $value : (is_string($value) ? Provider::tryFrom($value) : null);
        if ($driver === null) {
            throw new ConfigurationException('Unknown payment gateway.');
        }

        return match ($driver) {
            Provider::Midtrans => $this->midtrans(), Provider::Xendit => $this->xendit(), Provider::Doku => $this->doku(), Provider::Paywuz => $this->paywuz(), Provider::IPaymu => $this->ipaymu()
        };
    }

    /**
     * @template T of Gateway
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function resolve(Provider $driver, string $class): Gateway
    {
        $cached = $this->gateways[$driver->value] ?? null;
        if ($cached instanceof $class) {
            return $cached;
        }
        $settings = $this->config->get('payments.gateways.'.$driver->value, []);
        if (! is_array($settings)) {
            throw new ConfigurationException('Gateway configuration must be an array.');
        }
        $fields = [];
        foreach ($settings as $key => $value) {
            if (! is_string($key)) {
                throw new ConfigurationException('Gateway configuration must have string keys.');
            }
            $fields[$key] = $value;
        }
        $timeout = $this->config->get('payments.timeout', 30);
        $connectTimeout = $this->config->get('payments.connect_timeout', 10);
        if (! is_int($timeout) || ! is_int($connectTimeout)) {
            throw new ConfigurationException('HTTP timeouts must be integers.');
        }

        return $this->gateways[$driver->value] = new $class($this->http, $fields, $timeout, $connectTimeout);
    }

    public function midtrans(): MidtransGateway
    {
        return $this->resolve(Provider::Midtrans, MidtransGateway::class);
    }

    public function xendit(): XenditGateway
    {
        return $this->resolve(Provider::Xendit, XenditGateway::class);
    }

    public function doku(): DokuGateway
    {
        return $this->resolve(Provider::Doku, DokuGateway::class);
    }

    public function paywuz(): PaywuzGateway
    {
        return $this->resolve(Provider::Paywuz, PaywuzGateway::class);
    }

    public function ipaymu(): IPaymuGateway
    {
        return $this->resolve(Provider::IPaymu, IPaymuGateway::class);
    }

    public function forgetGateways(): void
    {
        $this->gateways = [];
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResult
    {
        return $this->gateway()->createPayment($request);
    }

    public function createCheckout(CreateCheckoutRequest $request): CheckoutResult
    {
        return $this->gateway()->createCheckout($request);
    }

    public function getStatus(PaymentReference $reference): PaymentStatusResult
    {
        return $this->gateway($reference->provider())->getStatus($reference);
    }

    public function paymentMethods(): PaymentMethodCollection
    {
        return $this->gateway()->paymentMethods();
    }

    public function parseWebhook(WebhookRequest $request): WebhookEvent
    {
        return $this->gateway()->parseWebhook($request);
    }

    public function webhookResponse(WebhookEvent $event): WebhookResponse
    {
        return $this->gateway($event->reference->provider())->webhookResponse($event);
    }
}
