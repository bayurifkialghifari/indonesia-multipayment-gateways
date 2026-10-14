<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO\Options;

use Bayurifkialghifari\Payments\Contracts\ProviderOptions;
use Bayurifkialghifari\Payments\Enums\Provider;

final readonly class XenditOptions implements ProviderOptions
{
    public function __construct(public ?string $virtualAccountNumber = null, public ?string $displayName = null, public ?string $businessId = null) {}

    public function provider(): Provider
    {
        return Provider::Xendit;
    }
}
