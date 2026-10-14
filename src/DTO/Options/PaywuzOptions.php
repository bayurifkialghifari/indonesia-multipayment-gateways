<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO\Options;

use Bayurifkialghifari\Payments\Contracts\ProviderOptions;
use Bayurifkialghifari\Payments\Enums\Provider;

final readonly class PaywuzOptions implements ProviderOptions
{
    public function __construct(public ?bool $feeByMerchant = null, public ?bool $paymentReminderEnabled = null, public bool $vaOnlyCheckout = false) {}

    public function provider(): Provider
    {
        return Provider::Paywuz;
    }
}
