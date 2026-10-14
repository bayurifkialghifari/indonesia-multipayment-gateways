<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO\References;

use Bayurifkialghifari\Payments\Contracts\ProviderReference;
use Bayurifkialghifari\Payments\Enums\Provider;

final readonly class DokuReference implements ProviderReference
{
    public function __construct(
        public ?string $requestId = null,
        public ?string $referenceNo = null,
        public ?string $customerNo = null,
        public ?string $partnerServiceId = null,
        public ?string $virtualAccountNo = null,
    ) {}

    public function provider(): Provider
    {
        return Provider::Doku;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'requestId' => $this->requestId,
            'referenceNo' => $this->referenceNo,
            'customerNo' => $this->customerNo,
            'partnerServiceId' => $this->partnerServiceId,
            'virtualAccountNo' => $this->virtualAccountNo,
        ];
    }
}
