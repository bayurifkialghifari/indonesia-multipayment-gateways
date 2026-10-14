<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO\Options;

use Bayurifkialghifari\Payments\Contracts\ProviderOptions;
use Bayurifkialghifari\Payments\Enums\FeeDirection;
use Bayurifkialghifari\Payments\Enums\Provider;

final readonly class IPaymuOptions implements ProviderOptions
{
    public function __construct(public ?FeeDirection $feeDirection = null) {}

    public function provider(): Provider
    {
        return Provider::IPaymu;
    }
}
