<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Contracts;

use Bayurifkialghifari\Payments\Enums\Provider;

interface ProviderOptions
{
    public function provider(): Provider;
}
