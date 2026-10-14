<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Bayurifkialghifari\Payments\Enums\CheckoutStatus;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use DateTimeImmutable;

final readonly class PaymentStatusResult
{
    public function __construct(
        public PaymentReference $reference,
        public PaymentStatus $status,
        public string $providerStatus,
        public ?CheckoutStatus $checkoutStatus = null,
        public ?int $amount = null,
        public ?DateTimeImmutable $paidAt = null,
    ) {}
}
