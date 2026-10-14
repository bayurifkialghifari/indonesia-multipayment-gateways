<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Support;

final class Json
{
    /** @param array<string, mixed> $payload */
    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
