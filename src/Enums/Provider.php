<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Enums;

enum Provider: string
{
    case Midtrans = 'midtrans';
    case Xendit = 'xendit';
    case Doku = 'doku';
    case Paywuz = 'paywuz';
    case IPaymu = 'ipaymu';
}
