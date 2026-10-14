<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Bayurifkialghifari\Payments\Contracts\ProviderOptions;
use Bayurifkialghifari\Payments\Support\Assert;
use DateTimeImmutable;

final readonly class CreateCheckoutRequest extends OrderRequest
{
    /** @param list<LineItem> $items */
    public function __construct(
        string $reference,
        int $amount,
        public string $returnUrl,
        public string $cancelUrl,
        string $description = 'Payment',
        ?Customer $customer = null,
        array $items = [],
        ?DateTimeImmutable $expiresAt = null,
        ?string $notifyUrl = null,
        ?ProviderOptions $options = null,
    ) {
        parent::__construct($reference, $amount, $description, $customer, $items, $expiresAt, $notifyUrl, $options);
        Assert::url($returnUrl, 'returnUrl');
        Assert::url($cancelUrl, 'cancelUrl');
    }
}
