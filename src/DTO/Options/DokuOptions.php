<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO\Options;

use Bayurifkialghifari\Payments\Contracts\ProviderOptions;
use Bayurifkialghifari\Payments\Enums\Provider;

final readonly class DokuOptions implements ProviderOptions
{
    public function __construct(public ?string $customerNumber = null, public ?string $virtualAccountNumber = null) {}

    public function provider(): Provider
    {
        return Provider::Doku;
    }
}
