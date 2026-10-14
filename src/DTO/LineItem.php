<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Bayurifkialghifari\Payments\Support\Assert;

final readonly class LineItem
{
    public function __construct(public string $name, public int $price, public int $quantity = 1, public ?string $reference = null)
    {
        Assert::text($name, 'item.name');
        Assert::positive($price, 'item.price');
        Assert::positive($quantity, 'item.quantity');
    }
}
