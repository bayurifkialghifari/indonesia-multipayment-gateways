<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Support;

use Bayurifkialghifari\Payments\Exceptions\InvalidResponseException;
use DateTimeImmutable;
use JsonException;
use Throwable;

/** @internal */
final readonly class Data
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values) {}

    public static function fromJson(string $body): self
    {
        try {
            $values = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw new InvalidResponseException('Invalid JSON response.', previous: $e);
        }
        if (! is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new InvalidResponseException('Expected a JSON object.');
        }

        /** @var array<string, mixed> $values */
        return new self($values);
    }

    private function value(string $path): mixed
    {
        $value = $this->values;
        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public function string(string $path): string
    {
        $value = $this->optionalString($path);
        if ($value === null || $value === '') {
            throw new InvalidResponseException("Missing string: {$path}.");
        }

        return $value;
    }

    public function optionalString(string $path): ?string
    {
        $value = $this->value($path);
        if ($value === null) {
            return null;
        }
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidResponseException("Invalid string: {$path}.");
        }

        return (string) $value;
    }

    public function amount(string $path): int
    {
        return $this->optionalAmount($path) ?? throw new InvalidResponseException("Missing amount: {$path}.");
    }

    public function optionalAmount(string $path): ?int
    {
        $value = $this->value($path);
        if ($value === null) {
            return null;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && $value >= 0 && $value <= 9007199254740991 && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^([0-9]+)(?:\.0+)?$/D', $value, $matches) === 1) {
            $integer = filter_var(ltrim($matches[1], '0') ?: '0', FILTER_VALIDATE_INT);
            if (is_int($integer)) {
                return $integer;
            }
        }
        throw new InvalidResponseException("Invalid integer Rupiah amount: {$path}.");
    }

    public function optionalBool(string $path): ?bool
    {
        $value = $this->value($path);
        if ($value === null) {
            return null;
        }
        if (! is_bool($value)) {
            throw new InvalidResponseException("Invalid boolean: {$path}.");
        }

        return $value;
    }

    public function object(string $path): self
    {
        $value = $this->value($path);
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidResponseException("Missing object: {$path}.");
        }

        /** @var array<string, mixed> $value */
        return new self($value);
    }

    /** @return list<self> */
    public function objects(string $path): array
    {
        $value = $this->value($path);
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidResponseException("Missing list: {$path}.");
        }
        $result = [];
        foreach ($value as $item) {
            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                throw new InvalidResponseException("Invalid list item: {$path}.");
            }
            /** @var array<string, mixed> $item */
            $result[] = new self($item);
        }

        return $result;
    }

    public function date(string $path): ?DateTimeImmutable
    {
        $value = $this->optionalString($path);
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new \DateTimeZone('Asia/Jakarta'));
        } catch (Throwable $e) {
            throw new InvalidResponseException("Invalid date: {$path}.", previous: $e);
        }
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
