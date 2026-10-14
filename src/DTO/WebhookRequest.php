<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Bayurifkialghifari\Payments\Support\Data;
use Illuminate\Http\Request;

final readonly class WebhookRequest
{
    /** @param array<string, string> $headers */
    public function __construct(public string $body, public array $headers, public string $path, public string $method = 'POST') {}

    public static function fromRequest(Request $request): self
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return new self($request->getContent(), $headers, $request->getPathInfo(), $request->getMethod());
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($name, $key) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function requiredHeader(string $name): string
    {
        return $this->header($name) ?? throw new InvalidWebhookException("Missing webhook header: {$name}.");
    }

    public function data(): Data
    {
        if (str_contains(strtolower($this->header('Content-Type') ?? ''), 'application/x-www-form-urlencoded')) {
            parse_str($this->body, $values);
            $fields = [];
            foreach ($values as $key => $value) {
                if (! is_string($key)) {
                    throw new InvalidWebhookException('Webhook fields must have string keys.');
                }
                $fields[$key] = $value;
            }

            return new Data($fields);
        }

        return Data::fromJson($this->body);
    }
}
