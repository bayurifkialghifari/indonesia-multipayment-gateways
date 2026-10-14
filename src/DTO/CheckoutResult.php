<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Bayurifkialghifari\Payments\Enums\CheckoutStatus;
use Bayurifkialghifari\Payments\Support\Assert;
use DateTimeImmutable;

final readonly class CheckoutResult
{
    public function __construct(
        public PaymentReference $reference,
        public int $amount,
        public string $checkoutUrl,
        public CheckoutStatus $status = CheckoutStatus::Open,
        public ?string $token = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        Assert::url($checkoutUrl, 'checkoutUrl');
    }
}
