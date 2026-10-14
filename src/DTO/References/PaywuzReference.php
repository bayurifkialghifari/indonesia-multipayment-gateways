<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO\References;

use Bayurifkialghifari\Payments\Contracts\ProviderReference;
use Bayurifkialghifari\Payments\Enums\Provider;

final readonly class PaywuzReference implements ProviderReference
{
    public function __construct(public string $transactionId) {}

    public function provider(): Provider
    {
        return Provider::Paywuz;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return ['transactionId' => $this->transactionId];
    }
}
