<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO\Instructions;

use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Support\Assert;

final readonly class VirtualAccount
{
    public function __construct(public string $number, public Bank $bank, public ?string $name = null, public ?string $billerCode = null)
    {
        Assert::text($number, 'va.number');
    }
}
