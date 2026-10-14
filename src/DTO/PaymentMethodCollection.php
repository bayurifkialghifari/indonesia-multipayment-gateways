<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, PaymentMethodInfo> */
final readonly class PaymentMethodCollection implements Countable, IteratorAggregate
{
    /** @param list<PaymentMethodInfo> $items */
    public function __construct(public array $items) {}

    public function count(): int
    {
        return count($this->items);
    }

    /** @return Traversable<int, PaymentMethodInfo> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
