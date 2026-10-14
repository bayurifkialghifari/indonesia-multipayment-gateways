<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Bayurifkialghifari\Payments\Contracts\ProviderOptions;
use Bayurifkialghifari\Payments\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;

abstract readonly class OrderRequest
{
    /** @param list<LineItem> $items */
    public function __construct(
        public string $reference,
        public int $amount,
        public string $description = 'Payment',
        public ?Customer $customer = null,
        public array $items = [],
        public ?DateTimeImmutable $expiresAt = null,
        public ?string $notifyUrl = null,
        public ?ProviderOptions $options = null,
    ) {
        Assert::text($reference, 'reference');
        Assert::positive($amount, 'amount');
        Assert::text($description, 'description');
        Assert::url($notifyUrl, 'notifyUrl');
        $sum = 0;
        foreach ($items as $item) {
            $sum += $item->price * $item->quantity;
        }
        if ($items !== [] && $sum !== $amount) {
            throw new InvalidArgumentException('Item total must match amount.');
        }
    }

    /** @return list<LineItem> */
    public function lineItems(): array
    {
        return $this->items ?: [new LineItem($this->description, $this->amount, reference: $this->reference)];
    }
}
