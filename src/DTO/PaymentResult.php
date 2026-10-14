<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Bayurifkialghifari\Payments\DTO\Instructions\QrCode;
use Bayurifkialghifari\Payments\DTO\Instructions\VirtualAccount;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use DateTimeImmutable;

final readonly class PaymentResult
{
    public function __construct(
        public PaymentReference $reference,
        public int $amount,
        public PaymentStatus $status,
        public string $providerStatus,
        public QrCode|VirtualAccount $instructions,
        public ?int $totalPayment = null,
        public ?int $fee = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}
}
