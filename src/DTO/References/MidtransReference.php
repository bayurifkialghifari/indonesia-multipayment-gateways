<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO\References;

use Bayurifkialghifari\Payments\Contracts\ProviderReference;
use Bayurifkialghifari\Payments\Enums\Provider;

final readonly class MidtransReference implements ProviderReference
{
    public function __construct(public string $orderId, public ?string $transactionId = null) {}

    public function provider(): Provider
    {
        return Provider::Midtrans;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return ['orderId' => $this->orderId, 'transactionId' => $this->transactionId];
    }
}
