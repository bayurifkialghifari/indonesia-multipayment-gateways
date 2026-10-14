<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Enums;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';
}
