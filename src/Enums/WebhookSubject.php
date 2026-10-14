<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Enums;

enum WebhookSubject: string
{
    case Payment = 'payment';
    case Checkout = 'checkout';
    case Unknown = 'unknown';
}
