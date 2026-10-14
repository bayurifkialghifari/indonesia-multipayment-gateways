<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO\References;

use Bayurifkialghifari\Payments\Contracts\ProviderReference;
use Bayurifkialghifari\Payments\Enums\Provider;

final readonly class IPaymuReference implements ProviderReference
{
    public function __construct(public ?string $transactionId = null, public ?string $sessionId = null) {}

    public function provider(): Provider
    {
        return Provider::IPaymu;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return ['transactionId' => $this->transactionId, 'sessionId' => $this->sessionId];
    }
}
