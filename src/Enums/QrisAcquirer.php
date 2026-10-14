<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Enums;

enum QrisAcquirer: string
{
    case GoPay = 'gopay';
    case ShopeePay = 'airpay shopee';
}
