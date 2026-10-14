<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\DTO;

use Bayurifkialghifari\Payments\Contracts\ProviderReference;
use Bayurifkialghifari\Payments\DTO\References\DokuReference;
use Bayurifkialghifari\Payments\DTO\References\IPaymuReference;
use Bayurifkialghifari\Payments\DTO\References\MidtransReference;
use Bayurifkialghifari\Payments\DTO\References\PaywuzReference;
use Bayurifkialghifari\Payments\DTO\References\XenditReference;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Support\Assert;
use Bayurifkialghifari\Payments\Support\Data;

final readonly class PaymentReference
{
    public function __construct(
        public string $merchantReference,
        public ProviderReference $identifiers,
        public ?PaymentMethod $method = null,
        public ?Bank $bank = null,
    ) {
        Assert::text($merchantReference, 'merchantReference');
    }

    public function provider(): Provider
    {
        return $this->identifiers->provider();
    }

    /** @return array{provider: string, merchant_reference: string, method: string|null, bank: string|null, identifiers: array<string, string|null>} */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider()->value,
            'merchant_reference' => $this->merchantReference,
            'method' => $this->method?->value,
            'bank' => $this->bank?->value,
            'identifiers' => $this->identifiers->toArray(),
        ];
    }

    /** @param array{provider: string, merchant_reference: string, method: string|null, bank: string|null, identifiers: array<string, string|null>} $value */
    public static function fromArray(array $value): self
    {
        $data = new Data($value);
        $ids = $data->object('identifiers');
        $provider = Provider::from($data->string('provider'));
        $native = match ($provider) {
            Provider::Midtrans => new MidtransReference($ids->string('orderId'), $ids->optionalString('transactionId')),
            Provider::Xendit => new XenditReference($ids->optionalString('paymentRequestId'), $ids->optionalString('paymentSessionId'), $ids->optionalString('paymentId'), $ids->optionalString('businessId')),
            Provider::Doku => new DokuReference($ids->optionalString('requestId'), $ids->optionalString('referenceNo'), $ids->optionalString('customerNo'), $ids->optionalString('partnerServiceId'), $ids->optionalString('virtualAccountNo')),
            Provider::Paywuz => new PaywuzReference($ids->string('transactionId')),
            Provider::IPaymu => new IPaymuReference($ids->optionalString('transactionId'), $ids->optionalString('sessionId')),
        };
        $method = $data->optionalString('method');
        $bank = $data->optionalString('bank');

        return new self(
            $data->string('merchant_reference'),
            $native,
            $method === null ? null : PaymentMethod::from($method),
            $bank === null ? null : Bank::from($bank),
        );
    }
}
