<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Enums;

enum FeeDirection: string
{
    case Buyer = 'BUYER';
    case Merchant = 'MERCHANT';
}
