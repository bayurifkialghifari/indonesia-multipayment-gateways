<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;

final readonly class PaymentMethodInfo
{
    public function __construct(
        public string $code,
        public string $name,
        public PaymentMethod $method,
        public ?Bank $bank = null,
        public ?bool $enabled = null,
        public ?int $minimumAmount = null,
        public ?int $maximumAmount = null,
        public ?string $instructionsUrl = null,
    ) {}
}
