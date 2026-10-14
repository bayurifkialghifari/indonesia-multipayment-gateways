<?php

declare(strict_types=1);

use Bayurifkialghifari\Payments\Exceptions\ConfigurationException;
use Bayurifkialghifari\Payments\Support\DokuSignature;

it('minifies DOKU JSON without modifying strings or numeric notation', function (): void {
    $body = "{\n \"name\": \"Bayu with spaces\", \"escaped\": \"a\\\"b\", \"url\": \"https:\\/\\/example.com\", \"amount\": 1.0 }";
    expect(DokuSignature::minify($body))->toBe('{"name":"Bayu with spaces","escaped":"a\\"b","url":"https:\/\/example.com","amount":1.0}');
});

it('preserves whitespace inside strings after escaped quotes and backslashes', function (): void {
    $fields = ['name' => 'Bayu "quoted name" with spaces', 'path' => 'C:\\payments\\', 'sequence' => '\\" trailing spaces'];
    $body = json_encode($fields, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    expect(DokuSignature::minify($body))->toBe(json_encode($fields, JSON_THROW_ON_ERROR));
});

it('accepts literal newline escape sequences in DOKU PEM credentials', function (): void {
    $private = str_replace("\n", '\\n', config('payments.gateways.doku.private_key'));
    $public = str_replace("\n", '\\n', $this->rsaPublicKey());
    $signature = DokuSignature::sign('payload', $private);
    expect(DokuSignature::verify('payload', $signature, $public))->toBeTrue();
});

it('validates RSA credentials and checks detached DOKU signatures', function (): void {
    $private = config('payments.gateways.doku.private_key');
    $signature = DokuSignature::sign('MCH-TEST|timestamp', $private);
    expect(DokuSignature::verify('MCH-TEST|timestamp', $signature, $this->rsaPublicKey()))->toBeTrue()
        ->and(DokuSignature::verify('tampered', $signature, $this->rsaPublicKey()))->toBeFalse()
        ->and(DokuSignature::verify('MCH-TEST|timestamp', 'not base64!', $this->rsaPublicKey()))->toBeFalse();
    expect(fn () => DokuSignature::sign('payload', 'invalid-private-key'))->toThrow(ConfigurationException::class);
    expect(fn () => DokuSignature::verify('payload', $signature, 'invalid-public-key'))->toThrow(ConfigurationException::class);
});
