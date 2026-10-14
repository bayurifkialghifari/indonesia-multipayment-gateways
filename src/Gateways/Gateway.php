<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Gateways;

use Bayurifkialghifari\Payments\Contracts\PaymentGateway;
use Bayurifkialghifari\Payments\DTO\OrderRequest;
use Bayurifkialghifari\Payments\DTO\PaymentMethodCollection;
use Bayurifkialghifari\Payments\DTO\PaymentMethodInfo;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\WebhookEvent;
use Bayurifkialghifari\Payments\DTO\WebhookResponse;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\Environment;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Exceptions\ConfigurationException;
use Bayurifkialghifari\Payments\Exceptions\UnsupportedFeatureException;
use Bayurifkialghifari\Payments\Support\Transport;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

abstract class Gateway implements PaymentGateway
{
    protected readonly Transport $transport;

    /** @param array<string, mixed> $config */
    public function __construct(Factory $http, protected readonly array $config, int $timeout = 30, int $connectTimeout = 10)
    {
        if ($timeout < 1 || $connectTimeout < 1) {
            throw new ConfigurationException('HTTP timeouts must be positive.');
        }
        $this->transport = new Transport($http, $this->provider(), $timeout, $connectTimeout);
    }

    abstract public function provider(): Provider;

    protected function setting(string $key): string
    {
        $value = $this->config[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new ConfigurationException("Missing or invalid {$this->provider()->value}.{$key}.");
        }

        return $value;
    }

    protected function environment(): Environment
    {
        $value = $this->config['environment'] ?? 'sandbox';
        if (! is_string($value) || ($environment = Environment::tryFrom($value)) === null) {
            throw new ConfigurationException('Environment must be sandbox or production.');
        }

        return $environment;
    }

    protected function validate(OrderRequest $request): void
    {
        if ($request->options !== null && $request->options->provider() !== $this->provider()) {
            throw new InvalidArgumentException('Provider options belong to another gateway.');
        }
        if ($request->expiresAt !== null && $request->expiresAt->getTimestamp() <= Carbon::now()->getTimestamp()) {
            throw new InvalidArgumentException('expiresAt must be in the future.');
        }
    }

    protected function validateReference(PaymentReference $reference): void
    {
        if ($reference->provider() !== $this->provider()) {
            throw new InvalidArgumentException('Payment reference belongs to another gateway.');
        }
    }

    protected function expiryMinutes(OrderRequest $request): ?int
    {
        return $request->expiresAt === null ? null : (int) ceil(($request->expiresAt->getTimestamp() - Carbon::now()->getTimestamp()) / 60);
    }

    /** @return array<string, Bank> */
    abstract protected function banks(): array;

    protected function bankCode(?Bank $bank): string
    {
        $code = array_search($bank, $this->banks(), true);
        if ($code === false) {
            throw new UnsupportedFeatureException('This bank is not supported by the gateway.');
        }

        return $code;
    }

    public function paymentMethods(): PaymentMethodCollection
    {
        $items = [new PaymentMethodInfo('QRIS', 'QRIS', PaymentMethod::Qris)];
        foreach ($this->banks() as $code => $bank) {
            $items[] = new PaymentMethodInfo($code, strtoupper($bank->value).' VA', PaymentMethod::VirtualAccount, $bank);
        }

        return new PaymentMethodCollection($items);
    }

    public function webhookResponse(WebhookEvent $event): WebhookResponse
    {
        $this->validateReference($event->reference);

        return $event->acknowledgment ?? new WebhookResponse;
    }
}
