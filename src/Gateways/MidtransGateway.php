<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Gateways;

use Bayurifkialghifari\Payments\DTO\CheckoutResult;
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Instructions\QrCode;
use Bayurifkialghifari\Payments\DTO\Instructions\VirtualAccount;
use Bayurifkialghifari\Payments\DTO\Options\MidtransOptions;
use Bayurifkialghifari\Payments\DTO\OrderRequest;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\PaymentResult;
use Bayurifkialghifari\Payments\DTO\PaymentStatusResult;
use Bayurifkialghifari\Payments\DTO\References\MidtransReference;
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
use Bayurifkialghifari\Payments\Support\Data;
use Bayurifkialghifari\Payments\Support\Json;
use Illuminate\Support\Carbon;

final class MidtransGateway extends Gateway
{
    public function provider(): Provider
    {
        return Provider::Midtrans;
    }

    protected function banks(): array
    {
        return ['bca' => Bank::Bca, 'bni' => Bank::Bni, 'bri' => Bank::Bri, 'permata' => Bank::Permata, 'mandiri' => Bank::Mandiri];
    }

    private function base(bool $snap = false): string
    {
        return 'https://'.($snap ? 'app' : 'api').($this->environment() === Environment::Sandbox ? '.sandbox' : '').'.midtrans.com';
    }

    /** @param array<string, mixed> $payload */
    private function send(string $method, string $path, array $payload = [], bool $snap = false, ?string $notifyUrl = null): Data
    {
        $headers = ['Authorization' => 'Basic '.base64_encode($this->setting('server_key').':')];
        if ($notifyUrl !== null) {
            $headers['X-Override-Notification'] = $notifyUrl;
        }
        $data = $this->transport->send($method, $this->base($snap).$path, $method === 'GET' ? '' : Json::encode($payload), $headers);
        $code = $data->optionalString('status_code');
        if ($code !== null && ! in_array($code, ['200', '201', '202'], true)) {
            throw new ProviderException($this->provider(), 200, preg_match('/^[0-9]{3}$/D', $code) === 1 ? $code : null);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function order(OrderRequest $request): array
    {
        $this->validate($request);
        $payload = ['transaction_details' => ['order_id' => $request->reference, 'gross_amount' => $request->amount],
            'item_details' => array_map(fn ($item) => ['id' => $item->reference ?? $request->reference, 'name' => $item->name, 'price' => $item->price, 'quantity' => $item->quantity], $request->lineItems())];
        if ($request->customer !== null) {
            $payload['customer_details'] = array_filter(['first_name' => $request->customer->name, 'email' => $request->customer->email, 'phone' => $request->customer->phone], fn ($value) => $value !== null);
        }

        return $payload;
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResult
    {
        $payload = $this->order($request);
        $options = $request->options instanceof MidtransOptions ? $request->options : new MidtransOptions;
        if ($request->method === PaymentMethod::Qris) {
            $payload['payment_type'] = 'qris';
            $payload['qris'] = ['acquirer' => $options->acquirer->value];
        } elseif ($this->bankCode($request->bank) === 'mandiri') {
            $payload['payment_type'] = 'echannel';
            $payload['echannel'] = ['bill_info1' => 'Payment for', 'bill_info2' => $request->description];
        } else {
            $payload['payment_type'] = 'bank_transfer';
            $payload['bank_transfer'] = array_filter(['bank' => $this->bankCode($request->bank), 'va_number' => $options->virtualAccountNumber], fn ($value) => $value !== null);
        }
        if ($request->expiresAt !== null) {
            $payload['custom_expiry'] = ['order_time' => Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s O'), 'expiry_duration' => $this->expiryMinutes($request), 'unit' => 'minute'];
        }
        $data = $this->send('POST', '/v2/charge', $payload, notifyUrl: $request->notifyUrl);
        $reference = new PaymentReference($request->reference, new MidtransReference($data->string('order_id'), $data->optionalString('transaction_id')), $request->method, $request->bank);
        if ($request->method === PaymentMethod::Qris) {
            $image = null;
            foreach (($data->all()['actions'] ?? null) === null ? [] : $data->objects('actions') as $action) {
                if (in_array($action->string('name'), ['generate-qr-code', 'generate-qr-code-v2'], true)) {
                    $image = $action->string('url');
                }
            }
            $text = $data->optionalString('qr_string');
            if ($text === null && $image === null) {
                throw new InvalidResponseException('Missing QRIS instructions.');
            }
            $instructions = new QrCode($text, $image);
        } elseif ($request->bank === Bank::Mandiri) {
            $instructions = new VirtualAccount($data->string('bill_key'), Bank::Mandiri, billerCode: $data->string('biller_code'));
        } elseif ($request->bank === Bank::Permata) {
            $instructions = new VirtualAccount($data->string('permata_va_number'), Bank::Permata);
        } else {
            $account = $data->objects('va_numbers')[0] ?? throw new InvalidResponseException('Missing virtual account.');
            $instructions = new VirtualAccount($account->string('va_number'), $request->requireBank());
        }
        $status = $data->string('transaction_status');

        return new PaymentResult(
            $reference,
            $data->amount('gross_amount'),
            $this->status($status, $data->optionalString('fraud_status')),
            $status,
            $instructions,
            expiresAt: $data->date('expiry_time'),
        );
    }

    public function createCheckout(CreateCheckoutRequest $request): CheckoutResult
    {
        $payload = $this->order($request);
        $payload['callbacks'] = ['finish' => $request->returnUrl, 'error' => $request->cancelUrl];
        if ($request->expiresAt !== null) {
            $payload['expiry'] = ['start_time' => Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s O'), 'duration' => $this->expiryMinutes($request), 'unit' => 'minute'];
        }
        $data = $this->send('POST', '/snap/v1/transactions', $payload, true, $request->notifyUrl);

        return new CheckoutResult(
            new PaymentReference($request->reference, new MidtransReference($request->reference)),
            $request->amount,
            $data->string('redirect_url'),
            token: $data->string('token'),
            expiresAt: $request->expiresAt,
        );
    }

    public function getStatus(PaymentReference $reference): PaymentStatusResult
    {
        $this->validateReference($reference);
        $ids = $reference->identifiers;
        if (! $ids instanceof MidtransReference) {
            throw new InvalidResponseException('Invalid Midtrans identifiers.');
        }
        $data = $this->send('GET', '/v2/'.rawurlencode($ids->orderId).'/status');
        $status = $data->string('transaction_status');

        return new PaymentStatusResult(
            $reference,
            $this->status($status, $data->optionalString('fraud_status')),
            $status,
            amount: $data->optionalAmount('gross_amount'),
            paidAt: $data->date('settlement_time'),
        );
    }

    public function parseWebhook(WebhookRequest $request): WebhookEvent
    {
        $data = $request->data();
        $signature = hash('sha512', $data->string('order_id').$data->string('status_code').$data->string('gross_amount').$this->setting('server_key'));
        if (! hash_equals($signature, $data->string('signature_key'))) {
            throw new InvalidWebhookException('Invalid Midtrans signature.');
        }
        $status = $data->string('transaction_status');

        return new WebhookEvent(
            new PaymentReference($data->string('order_id'), new MidtransReference($data->string('order_id'), $data->optionalString('transaction_id'))),
            $status,
            WebhookSubject::Payment,
            $this->status($status, $data->optionalString('fraud_status')),
            $status,
            amount: $data->amount('gross_amount'),
        );
    }

    private function status(string $status, ?string $fraud = null): PaymentStatus
    {
        return match ($status) {
            'settlement' => PaymentStatus::Paid,
            'capture' => $this->captureStatus($fraud),
            'pending' => PaymentStatus::Pending,
            'authorize' => PaymentStatus::Processing,
            'deny', 'failure' => PaymentStatus::Failed,
            'expire' => PaymentStatus::Expired,
            'cancel' => PaymentStatus::Cancelled,
            default => PaymentStatus::Unknown,
        };
    }

    private function captureStatus(?string $fraud): PaymentStatus
    {
        return match ($fraud) {
            'accept' => PaymentStatus::Paid,
            'challenge' => PaymentStatus::Processing,
            'deny' => PaymentStatus::Failed,
            default => PaymentStatus::Unknown,
        };
    }
}
