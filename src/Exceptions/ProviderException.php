<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Exceptions;

use Bayurifkialghifari\Payments\Enums\Provider;

final class ProviderException extends PaymentException
{
    public function __construct(public readonly Provider $provider, public readonly int $httpStatus, public readonly ?string $providerCode = null)
    {
        parent::__construct("{$provider->value} rejected the request (HTTP {$httpStatus}, code ".($providerCode ?? 'unknown').').');
    }
}
