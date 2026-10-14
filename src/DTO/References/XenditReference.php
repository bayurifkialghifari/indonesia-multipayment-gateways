<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO\References;

use Bayurifkialghifari\Payments\Contracts\ProviderReference;
use Bayurifkialghifari\Payments\Enums\Provider;

final readonly class XenditReference implements ProviderReference
{
    public function __construct(
        public ?string $paymentRequestId = null,
        public ?string $paymentSessionId = null,
        public ?string $paymentId = null,
        public ?string $businessId = null,
    ) {}

    public function provider(): Provider
    {
        return Provider::Xendit;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'paymentRequestId' => $this->paymentRequestId,
            'paymentSessionId' => $this->paymentSessionId,
            'paymentId' => $this->paymentId,
            'businessId' => $this->businessId,
        ];
    }
}
