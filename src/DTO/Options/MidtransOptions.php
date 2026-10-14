<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO\Options;

use Bayurifkialghifari\Payments\Contracts\ProviderOptions;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Enums\QrisAcquirer;

final readonly class MidtransOptions implements ProviderOptions
{
    public function __construct(public QrisAcquirer $acquirer = QrisAcquirer::GoPay, public ?string $virtualAccountNumber = null) {}

    public function provider(): Provider
    {
        return Provider::Midtrans;
    }
}
