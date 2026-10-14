<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Gateways;

use Bayurifkialghifari\Payments\DTO\CheckoutResult;
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Instructions\QrCode;
use Bayurifkialghifari\Payments\DTO\Instructions\VirtualAccount;
use Bayurifkialghifari\Payments\DTO\Options\PaywuzOptions;
use Bayurifkialghifari\Payments\DTO\OrderRequest;
use Bayurifkialghifari\Payments\DTO\PaymentMethodCollection;
use Bayurifkialghifari\Payments\DTO\PaymentMethodInfo;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\PaymentResult;
use Bayurifkialghifari\Payments\DTO\PaymentStatusResult;
use Bayurifkialghifari\Payments\DTO\References\PaywuzReference;
use Bayurifkialghifari\Payments\DTO\WebhookEvent;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Enums\WebhookSubject;
use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Bayurifkialghifari\Payments\Exceptions\ProviderException;
use Bayurifkialghifari\Payments\Exceptions\UnsupportedFeatureException;
use Bayurifkialghifari\Payments\Support\Data;
use Bayurifkialghifari\Payments\Support\Json;

final class PaywuzGateway extends Gateway
{
    public function provider(): Provider
    {
        return Provider::Paywuz;
    }

    protected function banks(): array
    {
        return ['BCAVA' => Bank::Bca, 'BNIVA' => Bank::Bni];
    }

    protected function bankCode(?Bank $bank): string
    {
        foreach ($this->paymentMethods() as $method) {
            if ($method->bank === $bank && $method->method === PaymentMethod::VirtualAccount && $method->enabled === true) {
                return $method->code;
            }
        }
        throw new UnsupportedFeatureException('This VA bank is not available in the Paywuz project.');
    }

    private function catalogBank(string $code, string $name): ?Bank
    {
        if (isset($this->banks()[$code])) {
            return $this->banks()[$code];
        }
        foreach (Bank::cases() as $bank) {
            $label = match ($bank) {
                Bank::Muamalat => 'muamalat', Bank::BpdBali => 'bpd bali', Bank::Bss => 'sahabat sampoerna', default => $bank->value
            };
            if (preg_match('/\b'.preg_quote($label, '/').'\b/i', $name) === 1) {
                return $bank;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function send(string $method, string $path, array $payload = []): Data
    {
        $data = $this->transport->send($method, 'https://api.paywuz.id/v1'.$path, $method === 'GET' ? '' : Json::encode($payload), ['Authorization' => 'Bearer '.$this->setting('api_key')]);
        if ($data->optionalBool('success') === false) {
            throw new ProviderException($this->provider(), 200);
        }

        return $data;
    }

    private function create(OrderRequest $request, string $method, ?string $redirect = null): Data
    {
        $this->validate($request);
        $options = $request->options instanceof PaywuzOptions ? $request->options : new PaywuzOptions;
        $payload = array_filter([
            'orderId' => $request->reference,
            'amount' => $request->amount,
            'paymentMethod' => $method,
            'expiryMinutes' => $this->expiryMinutes($request),
            'redirectUrl' => $redirect,
            'feeByMerchant' => $options->feeByMerchant,
            'paymentReminderEnabled' => $options->paymentReminderEnabled,
        ], fn ($value) => $value !== null);
        if ($request->customer !== null) {
            $payload['metadata'] = array_filter(['customerName' => $request->customer->name, 'email' => $request->customer->email, 'phone' => $request->customer->phone], fn ($value) => $value !== null);
        }

        return $this->send('POST', '/transactions', $payload)->object('data');
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResult
    {
        $this->validate($request);
        $data = $this->create($request, $request->method === PaymentMethod::Qris ? 'QRIS' : $this->bankCode($request->bank));
        $instructions = $request->method === PaymentMethod::Qris ? new QrCode($data->string('paymentNumber')) : new VirtualAccount($data->string('paymentNumber'), $request->requireBank());
        $status = $data->string('status');

        return new PaymentResult(
            new PaymentReference($request->reference, new PaywuzReference($data->string('id')), $request->method, $request->bank),
            $data->amount('amount'),
            $this->status($status),
            $status,
            $instructions,
            $data->optionalAmount('totalPayment'),
            $data->optionalAmount('fee'),
            $data->date('expiresAt'),
        );
    }

    public function createCheckout(CreateCheckoutRequest $request): CheckoutResult
    {
        $options = $request->options instanceof PaywuzOptions ? $request->options : new PaywuzOptions;
        $data = $this->create($request, $options->vaOnlyCheckout ? 'VA' : 'ALL', $request->returnUrl);

        return new CheckoutResult(
            new PaymentReference($request->reference, new PaywuzReference($data->string('id'))),
            $data->amount('amount'),
            $data->string('paymentUrl'),
            expiresAt: $data->date('expiresAt'),
        );
    }

    public function getStatus(PaymentReference $reference): PaymentStatusResult
    {
        $this->validateReference($reference);
        $data = $this->send('GET', '/transactions/'.rawurlencode($reference->merchantReference))->object('data');
        $status = $data->string('status');

        return new PaymentStatusResult($reference, $this->status($status), $status, amount: $data->amount('amount'));
    }

    public function paymentMethods(): PaymentMethodCollection
    {
        $items = [];
        foreach ($this->send('GET', '/payment-methods')->objects('data') as $method) {
            $code = $method->string('code');
            $type = $method->string('type');
            $name = $method->string('name');
            $bank = $type === 'virtual_account' ? $this->catalogBank($code, $name) : null;
            if ($type !== 'qris' && ($type !== 'virtual_account' || $bank === null)) {
                continue;
            }
            $items[] = new PaymentMethodInfo($code, $name, $type === 'qris' ? PaymentMethod::Qris : PaymentMethod::VirtualAccount, $bank, true, $method->optionalAmount('limits.minIdr'), $method->optionalAmount('limits.maxIdr'));
        }

        return new PaymentMethodCollection($items);
    }

    public function parseWebhook(WebhookRequest $request): WebhookEvent
    {
        $expected = 'sha256='.hash_hmac('sha256', $request->body, $this->setting('api_key'));
        if (! hash_equals($expected, $request->requiredHeader('X-Paywuz-Signature'))) {
            throw new InvalidWebhookException('Invalid Paywuz signature.');
        }
        $envelope = $request->data();
        $event = $envelope->string('event');
        if ($request->requiredHeader('X-Paywuz-Event') !== $event) {
            throw new InvalidWebhookException('Paywuz event header mismatch.');
        }
        $data = $envelope->object('data');
        $status = $data->string('status');

        return new WebhookEvent(
            new PaymentReference($data->string('orderId'), new PaywuzReference($data->string('id'))),
            $event,
            WebhookSubject::Payment,
            $this->status($status),
            $status,
            $request->requiredHeader('X-Paywuz-Delivery'),
            $data->amount('amount'),
        );
    }

    private function status(string $status): PaymentStatus
    {
        return match ($status) {
            'pending' => PaymentStatus::Pending,
            'settlement' => PaymentStatus::Processing,
            'success' => PaymentStatus::Paid,
            'failed' => PaymentStatus::Failed,
            'expired' => PaymentStatus::Expired,
            'cancelled' => PaymentStatus::Cancelled,
            default => PaymentStatus::Unknown,
        };
    }
}
