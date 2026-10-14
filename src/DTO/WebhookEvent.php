<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Bayurifkialghifari\Payments\Enums\CheckoutStatus;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Enums\WebhookSubject;

final readonly class WebhookEvent
{
    public function __construct(
        public PaymentReference $reference,
        public string $providerEvent,
        public WebhookSubject $subject,
        public PaymentStatus $status,
        public string $providerStatus,
        public ?string $deliveryId = null,
        public ?int $amount = null,
        public ?CheckoutStatus $checkoutStatus = null,
        public ?WebhookResponse $acknowledgment = null,
    ) {}
}
