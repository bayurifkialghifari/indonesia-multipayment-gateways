<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Support;

use Bayurifkialghifari\Payments\Exceptions\ConfigurationException;

final class DokuSignature
{
    public static function nonSnap(string $method, string $path, string $body, string $clientId, string $requestId, string $timestamp, string $secret): string
    {
        $components = ['Client-Id:'.$clientId, 'Request-Id:'.$requestId, 'Request-Timestamp:'.$timestamp, 'Request-Target:'.$path];
        if (strtoupper($method) !== 'GET') {
            $components[] = 'Digest:'.base64_encode(hash('sha256', $body, true));
        }

        return 'HMACSHA256='.base64_encode(hash_hmac('sha256', implode("\n", $components), $secret, true));
    }

    public static function snap(string $method, string $path, string $body, string $token, string $timestamp, string $secret): string
    {
        return base64_encode(hash_hmac('sha512', strtoupper($method).':'.$path.':'.$token.':'.hash('sha256', self::minify($body)).':'.$timestamp, $secret, true));
    }

    public static function asymmetricPayload(string $method, string $path, string $body, string $timestamp): string
    {
        return strtoupper($method).':'.$path.':'.hash('sha256', self::minify($body)).':'.$timestamp;
    }

    public static function sign(string $value, string $pem, string $passphrase = ''): string
    {
        $key = openssl_pkey_get_private(str_replace('\\n', "\n", $pem), $passphrase);
        if ($key === false || ! openssl_sign($value, $signature, $key, OPENSSL_ALGO_SHA256) || ! is_string($signature)) {
            throw new ConfigurationException('Invalid DOKU private key or passphrase.');
        }

        return base64_encode($signature);
    }

    public static function verify(string $value, string $signature, string $pem): bool
    {
        $key = openssl_pkey_get_public(str_replace('\\n', "\n", $pem));
        if ($key === false) {
            throw new ConfigurationException('Invalid DOKU public key.');
        }
        $bytes = base64_decode($signature, true);

        return $bytes !== false && openssl_verify($value, $bytes, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public static function minify(string $body): string
    {
        Data::fromJson($body);
        $result = '';
        $quoted = false;
        $escaped = false;
        foreach (str_split($body) as $character) {
            if ($quoted) {
                $result .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $quoted = false;
                }
            } elseif ($character === '"') {
                $quoted = true;
                $result .= $character;
            } elseif (! in_array($character, [' ', "\t", "\r", "\n"], true)) {
                $result .= $character;
            }
        }

        return $result;
    }
}
