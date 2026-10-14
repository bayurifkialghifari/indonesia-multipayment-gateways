<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Support;

use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;

final class IPaymuSignature
{
    public static function request(string $method, string $va, string $body, string $apiKey): string
    {
        return hash_hmac('sha256', strtoupper($method).':'.$va.':'.hash('sha256', $body).':'.$apiKey, $apiKey);
    }

    public static function callback(Data $data, string $va): string
    {
        $values = [];
        foreach ($data->all() as $key => $value) {
            if ($key === 'signature') {
                continue;
            }
            if ($key === 'is_escrow') {
                $values[$key] = in_array($value, [true, 'true', '1', 1], true);
            } elseif (in_array($key, ['trx_id', 'status_code', 'transaction_status_code', 'paid_off'], true)) {
                $integer = filter_var($value, FILTER_VALIDATE_INT);
                if (! is_int($integer)) {
                    throw new InvalidWebhookException('Invalid iPaymu integer field.');
                }
                $values[$key] = $integer;
            } elseif ($key === 'additional_info') {
                $values[$key] = $value === '[]' ? [] : $value;
            } else {
                if (! is_scalar($value) && $value !== null) {
                    throw new InvalidWebhookException('Invalid iPaymu callback field.');
                }
                $values[$key] = match ($value) {
                    null => 'null', true => 'true', false => 'false', default => (string) $value
                };
            }
        }
        $values += ['additional_info' => []];
        ksort($values, SORT_STRING);

        return hash_hmac('sha256', Json::encode($values), $va);
    }
}
