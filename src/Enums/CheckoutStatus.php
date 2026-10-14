<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Enums;

enum CheckoutStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
