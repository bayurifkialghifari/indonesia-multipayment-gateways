<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Gateways;

use Bayurifkialghifari\Payments\DTO\CheckoutResult;
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Instructions\QrCode;
use Bayurifkialghifari\Payments\DTO\Instructions\VirtualAccount;
use Bayurifkialghifari\Payments\DTO\Options\IPaymuOptions;
use Bayurifkialghifari\Payments\DTO\OrderRequest;
use Bayurifkialghifari\Payments\DTO\PaymentMethodCollection;
use Bayurifkialghifari\Payments\DTO\PaymentMethodInfo;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\PaymentResult;
use Bayurifkialghifari\Payments\DTO\PaymentStatusResult;
use Bayurifkialghifari\Payments\DTO\References\IPaymuReference;
use Bayurifkialghifari\Payments\DTO\WebhookEvent;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\Environment;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Enums\WebhookSubject;
use Bayurifkialghifari\Payments\Exceptions\InvalidResponseException;
use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Bayurifkialghifari\Payments\Exceptions\ProviderException;
use Bayurifkialghifari\Payments\Exceptions\StatusUnavailableException;
use Bayurifkialghifari\Payments\Exceptions\UnsupportedFeatureException;
use Bayurifkialghifari\Payments\Support\Data;
use Bayurifkialghifari\Payments\Support\IPaymuSignature;
use Bayurifkialghifari\Payments\Support\Json;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class IPaymuGateway extends Gateway
{
    public function provider(): Provider
    {
        return Provider::IPaymu;
    }

    protected function banks(): array
    {
        return [
            'bag' => Bank::Bag,
            'bca' => Bank::Bca,
            'bpd_bali' => Bank::BpdBali,
            'bni' => Bank::Bni,
            'cimb' => Bank::Cimb,
            'mandiri' => Bank::Mandiri,
            'bmi' => Bank::Muamalat,
            'bri' => Bank::Bri,
            'bsi' => Bank::Bsi,
            'permata' => Bank::Permata,
            'danamon' => Bank::Danamon,
            'btn' => Bank::Btn,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function send(string $method, string $path, array $payload = []): Data
    {
        $body = $method === 'GET' ? '' : Json::encode($payload);
        $va = $this->setting('va');
        $headers = [
            'va' => $va,
            'signature' => IPaymuSignature::request($method, $va, $body, $this->setting('api_key')),
            'timestamp' => Carbon::now('Asia/Jakarta')->format('YmdHis'),
        ];
        $base = $this->environment() === Environment::Sandbox ? 'https://sandbox.ipaymu.com/api/v2' : 'https://my.ipaymu.com/api/v2';
        $data = $this->transport->send($method, $base.$path, $body, $headers);
        if ($data->string('Status') !== '200' || $data->optionalBool('Success') === false) {
            throw new ProviderException($this->provider(), 200);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function order(OrderRequest $request): array
    {
        $this->validate($request);
        if ($request->notifyUrl === null) {
            throw new InvalidArgumentException('iPaymu requires notifyUrl.');
        }
        $payload = [
            'referenceId' => $request->reference,
            'notifyUrl' => $request->notifyUrl,
            'product' => array_map(fn ($item) => $item->name, $request->lineItems()),
            'qty' => array_map(fn ($item) => $item->quantity, $request->lineItems()),
            'price' => array_map(fn ($item) => $item->price, $request->lineItems()),
        ];
        $options = $request->options;
        if ($options instanceof IPaymuOptions && $options->feeDirection !== null) {
            $payload['feeDirection'] = strtoupper($options->feeDirection->value);
        }
        if ($request->expiresAt !== null) {
            if ($request instanceof CreatePaymentRequest && ($request->method === PaymentMethod::Qris || $request->bank === Bank::Bca)) {
                throw new UnsupportedFeatureException('iPaymu QRIS and BCA VA have fixed expiry.');
            }
            $hours = (int) ceil(($this->expiryMinutes($request) ?? 0) / 60);
            if ($request instanceof CreatePaymentRequest && (($request->bank === Bank::Bri && $hours > 2) || ($request->bank === Bank::Bsi && $hours > 3))) {
                throw new UnsupportedFeatureException('iPaymu BRI VA expires within 2 hours; BSI within 3 hours.');
            }
            $payload['expired'] = $hours;
        }

        return $payload;
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResult
    {
        $payload = $this->order($request);
        $customer = $request->customer;
        if ($customer === null || $customer->email === null || $customer->phone === null) {
            throw new InvalidArgumentException('iPaymu direct requires customer name, email and phone.');
        }
        $payload += [
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'amount' => $request->amount,
            'paymentMethod' => $request->method->value,
            'paymentChannel' => $request->method === PaymentMethod::Qris ? 'mpm' : $this->bankCode($request->bank),
        ];
        $data = $this->send('POST', '/payment/direct', $payload)->object('Data');
        $instructions = $request->method === PaymentMethod::Qris ? new QrCode($data->string('QrString'), $data->optionalString('QrImage')) : new VirtualAccount($data->string('PaymentNo'), $request->requireBank());

        return new PaymentResult(
            new PaymentReference($request->reference, new IPaymuReference($data->string('TransactionId'), $data->optionalString('SessionId')), $request->method, $request->bank),
            $data->optionalAmount('SubTotal') ?? $request->amount,
            PaymentStatus::Pending,
            'pending',
            $instructions,
            $data->optionalAmount('Total'),
            $data->optionalAmount('Fee'),
            $data->date('Expired'),
        );
    }

    public function createCheckout(CreateCheckoutRequest $request): CheckoutResult
    {
        $payload = $this->order($request) + ['returnUrl' => $request->returnUrl, 'cancelUrl' => $request->cancelUrl];
        if ($request->customer !== null) {
            $payload += array_filter([
                'buyerName' => $request->customer->name,
                'buyerEmail' => $request->customer->email,
                'buyerPhone' => $request->customer->phone,
            ], fn ($value) => $value !== null);
        }
        $data = $this->send('POST', '/payment', $payload)->object('Data');

        return new CheckoutResult(
            new PaymentReference($request->reference, new IPaymuReference(sessionId: $data->string('SessionID'))),
            $request->amount,
            $data->string('Url'),
        );
    }

    public function getStatus(PaymentReference $reference): PaymentStatusResult
    {
        $this->validateReference($reference);
        $ids = $reference->identifiers;
        if (! $ids instanceof IPaymuReference) {
            throw new InvalidResponseException('Invalid iPaymu identifiers.');
        }
        $id = $ids->transactionId ?? throw new StatusUnavailableException('iPaymu hosted status requires TransactionId from the verified callback.');
        $data = $this->send('POST', '/transaction', ['transactionId' => $id])->object('Data');
        $status = $data->string('Status');

        return new PaymentStatusResult(
            $reference,
            $this->status($status),
            $status,
            amount: $data->optionalAmount('Amount'),
            paidAt: $data->date('SuccessDate'),
        );
    }

    public function paymentMethods(): PaymentMethodCollection
    {
        $items = [];
        foreach ($this->send('GET', '/payment-channels')->objects('Data') as $group) {
            $code = $group->string('Code');
            if (! in_array($code, ['qris', 'va'], true)) {
                continue;
            }
            foreach ($group->objects('Channels') as $channel) {
                $native = $channel->string('Code');
                $bank = $this->banks()[$native] ?? null;
                if (($code === 'qris' && $native !== 'mpm') || ($code === 'va' && $bank === null)) {
                    continue;
                }
                $items[] = new PaymentMethodInfo($native, $channel->string('Name'), $code === 'qris' ? PaymentMethod::Qris : PaymentMethod::VirtualAccount, $bank, $channel->string('FeatureStatus') === 'active' && $channel->string('HealthStatus') === 'online', instructionsUrl: $channel->optionalString('PaymentInstructionsDoc'));
            }
        }

        return new PaymentMethodCollection($items);
    }

    public function parseWebhook(WebhookRequest $request): WebhookEvent
    {
        $data = $request->data();
        if (! hash_equals(IPaymuSignature::callback($data, $this->setting('va')), $request->requiredHeader('X-Signature'))) {
            throw new InvalidWebhookException('Invalid iPaymu signature.');
        }
        $status = $data->string('status_code');

        return new WebhookEvent(
            new PaymentReference($data->string('reference_id'), new IPaymuReference($data->string('trx_id'), $data->optionalString('sid'))),
            $data->optionalString('status') ?? $status,
            WebhookSubject::Payment,
            $this->status($status),
            $status,
            $request->header('X-External-ID'),
            $data->optionalAmount('amount'),
        );
    }

    private function status(string $status): PaymentStatus
    {
        return match ($status) {
            '1',
            '6',
            '7' => PaymentStatus::Paid,
            '0' => PaymentStatus::Pending,
            '-2' => PaymentStatus::Expired,
            '2' => PaymentStatus::Cancelled,
            '3' => PaymentStatus::Refunded,
            '4',
            '5' => PaymentStatus::Failed,
            default => PaymentStatus::Unknown,
        };
    }
}
