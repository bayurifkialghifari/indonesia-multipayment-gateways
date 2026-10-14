<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Enums;

enum PaymentMethod: string
{
    case Qris = 'qris';
    case VirtualAccount = 'va';
}
