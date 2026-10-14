<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO\Instructions;

use Bayurifkialghifari\Payments\Support\Assert;
use InvalidArgumentException;

final readonly class QrCode
{
    public function __construct(public ?string $text = null, public ?string $imageUrl = null)
    {
        if (($text === null || $text === '') && ($imageUrl === null || $imageUrl === '')) {
            throw new InvalidArgumentException('QRIS requires QR text or an image URL.');
        }
        Assert::url($imageUrl, 'qr.imageUrl');
    }
}
