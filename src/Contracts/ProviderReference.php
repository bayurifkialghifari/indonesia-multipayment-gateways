<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Contracts;

use Bayurifkialghifari\Payments\Enums\Provider;

interface ProviderReference
{
    public function provider(): Provider;

    /** @return array<string, string|null> */
    public function toArray(): array;
}
