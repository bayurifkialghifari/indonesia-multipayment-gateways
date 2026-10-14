<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Contracts;

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

interface PaymentGateway
{
    public function createPayment(CreatePaymentRequest $request): PaymentResult;

    public function createCheckout(CreateCheckoutRequest $request): CheckoutResult;

    public function getStatus(PaymentReference $reference): PaymentStatusResult;

    public function paymentMethods(): PaymentMethodCollection;

    public function parseWebhook(WebhookRequest $request): WebhookEvent;

    public function webhookResponse(WebhookEvent $event): WebhookResponse;
}
