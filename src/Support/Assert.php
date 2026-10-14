<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Support;

use InvalidArgumentException;

/** @internal */
final class Assert
{
    public static function text(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("{$field} must not be empty.");
        }
    }

    public static function positive(int $value, string $field): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$field} must be positive.");
        }
    }

    public static function url(?string $value, string $field): void
    {
        if ($value !== null && (filter_var($value, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($value, PHP_URL_SCHEME), ['https', 'http'], true))) {
            throw new InvalidArgumentException("{$field} must be an HTTP(S) URL.");
        }
    }
}
