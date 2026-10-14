<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Illuminate\Http\JsonResponse;

final readonly class WebhookResponse
{
    /** @param array<string, string|int|array<string, string>> $body */
    public function __construct(public array $body = ['status' => 'OK'], public int $statusCode = 200) {}

    public function toResponse(): JsonResponse
    {
        return new JsonResponse($this->body, $this->statusCode);
    }
}
