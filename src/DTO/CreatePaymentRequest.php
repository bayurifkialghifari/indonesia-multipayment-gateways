<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Bayurifkialghifari\Payments\Contracts\ProviderOptions;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CreatePaymentRequest extends OrderRequest
{
    /** @param list<LineItem> $items */
    public function __construct(
        string $reference,
        int $amount,
        public PaymentMethod $method,
        public ?Bank $bank = null,
        string $description = 'Payment',
        ?Customer $customer = null,
        array $items = [],
        ?DateTimeImmutable $expiresAt = null,
        ?string $notifyUrl = null,
        ?ProviderOptions $options = null,
    ) {
        parent::__construct($reference, $amount, $description, $customer, $items, $expiresAt, $notifyUrl, $options);
        if (($method === PaymentMethod::VirtualAccount) !== ($bank !== null)) {
            throw new InvalidArgumentException('VA requires a bank; QRIS must not have a bank.');
        }
    }

    public function requireBank(): Bank
    {
        return $this->bank ?? throw new InvalidArgumentException('This payment has no bank.');
    }
}
