<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Support;

use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Exceptions\ProviderException;
use Bayurifkialghifari\Payments\Exceptions\TransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

final readonly class Transport
{
    public function __construct(private Factory $http, private Provider $provider, private int $timeout = 30, private int $connectTimeout = 10) {}

    /** @param array<string, string> $headers */
    public function send(string $method, string $url, string $body = '', array $headers = []): Data
    {
        try {
            $request = $this->http->acceptJson()->withHeaders($headers)
                ->timeout($this->timeout)->connectTimeout($this->connectTimeout)
                ->withOptions(['allow_redirects' => false]);
            if ($body !== '') {
                $request = $request->withBody($body, 'application/json');
            }
            $response = $request->send($method, $url);
        } catch (ConnectionException) {
            throw new TransportException('Payment provider connection failed.');
        }
        if (! $response->successful()) {
            throw new ProviderException($this->provider, $response->status());
        }

        return Data::fromJson($response->body());
    }
}
