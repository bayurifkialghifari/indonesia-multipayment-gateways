<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Bayurifkialghifari\Payments\Support\Assert;
use InvalidArgumentException;

final readonly class Customer
{
    public function __construct(public string $name, public ?string $email = null, public ?string $phone = null, public ?string $reference = null)
    {
        Assert::text($name, 'customer.name');
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid customer email.');
        }
        if ($phone !== null) {
            Assert::text($phone, 'customer.phone');
        }
    }
}
