<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Facades;

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
use Bayurifkialghifari\Payments\Gateways\DokuGateway;
use Bayurifkialghifari\Payments\Gateways\IPaymuGateway;
use Bayurifkialghifari\Payments\Gateways\MidtransGateway;
use Bayurifkialghifari\Payments\Gateways\PaywuzGateway;
use Bayurifkialghifari\Payments\Gateways\XenditGateway;
use Bayurifkialghifari\Payments\PaymentsManager;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

final class Payments extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaymentsManager::class;
    }

    private static function manager(): PaymentsManager
    {
        $manager = self::getFacadeRoot();
        if (! $manager instanceof PaymentsManager) {
            throw new RuntimeException('Payments facade requires a registered PaymentsManager.');
        }

        return $manager;
    }

    public static function gateway(Provider|string|null $provider = null): DokuGateway|IPaymuGateway|MidtransGateway|PaywuzGateway|XenditGateway
    {
        return self::manager()->gateway($provider);
    }

    public static function midtrans(): MidtransGateway
    {
        return self::manager()->midtrans();
    }

    public static function xendit(): XenditGateway
    {
        return self::manager()->xendit();
    }

    public static function doku(): DokuGateway
    {
        return self::manager()->doku();
    }

    public static function paywuz(): PaywuzGateway
    {
        return self::manager()->paywuz();
    }

    public static function ipaymu(): IPaymuGateway
    {
        return self::manager()->ipaymu();
    }

    public static function createPayment(CreatePaymentRequest $request): PaymentResult
    {
        return self::manager()->createPayment($request);
    }

    public static function createCheckout(CreateCheckoutRequest $request): CheckoutResult
    {
        return self::manager()->createCheckout($request);
    }

    public static function getStatus(PaymentReference $reference): PaymentStatusResult
    {
        return self::manager()->getStatus($reference);
    }

    public static function paymentMethods(): PaymentMethodCollection
    {
        return self::manager()->paymentMethods();
    }

    public static function parseWebhook(WebhookRequest $request): WebhookEvent
    {
        return self::manager()->parseWebhook($request);
    }

    public static function webhookResponse(WebhookEvent $event): WebhookResponse
    {
        return self::manager()->webhookResponse($event);
    }

    public static function forgetGateways(): void
    {
        self::manager()->forgetGateways();
    }
}
