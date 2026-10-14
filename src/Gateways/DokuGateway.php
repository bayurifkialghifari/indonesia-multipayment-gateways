<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Gateways;

use Bayurifkialghifari\Payments\DTO\CheckoutResult;
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Instructions\QrCode;
use Bayurifkialghifari\Payments\DTO\Instructions\VirtualAccount;
use Bayurifkialghifari\Payments\DTO\Options\DokuOptions;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\PaymentResult;
use Bayurifkialghifari\Payments\DTO\PaymentStatusResult;
use Bayurifkialghifari\Payments\DTO\References\DokuReference;
use Bayurifkialghifari\Payments\DTO\WebhookEvent;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\DTO\WebhookResponse;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\CheckoutStatus;
use Bayurifkialghifari\Payments\Enums\Environment;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Enums\WebhookSubject;
use Bayurifkialghifari\Payments\Exceptions\ConfigurationException;
use Bayurifkialghifari\Payments\Exceptions\InvalidResponseException;
use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Bayurifkialghifari\Payments\Exceptions\ProviderException;
use Bayurifkialghifari\Payments\Exceptions\StatusUnavailableException;
use Bayurifkialghifari\Payments\Support\Data;
use Bayurifkialghifari\Payments\Support\DokuSignature;
use Bayurifkialghifari\Payments\Support\Json;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class DokuGateway extends Gateway
{
    private ?string $accessToken = null;

    private int $tokenExpiresAt = 0;

    public function provider(): Provider
    {
        return Provider::Doku;
    }

    protected function banks(): array
    {
        return [
            'VIRTUAL_ACCOUNT_BCA' => Bank::Bca,
            'VIRTUAL_ACCOUNT_BRI' => Bank::Bri,
            'VIRTUAL_ACCOUNT_BANK_MANDIRI' => Bank::Mandiri,
            'VIRTUAL_ACCOUNT_BNI' => Bank::Bni,
            'VIRTUAL_ACCOUNT_BANK_PERMATA' => Bank::Permata,
            'VIRTUAL_ACCOUNT_BANK_DANAMON' => Bank::Danamon,
            'VIRTUAL_ACCOUNT_BANK_CIMB' => Bank::Cimb,
            'VIRTUAL_ACCOUNT_BSI' => Bank::Bsi,
            'VIRTUAL_ACCOUNT_BNC' => Bank::Bnc,
            'VIRTUAL_ACCOUNT_BTN' => Bank::Btn,
            'VIRTUAL_ACCOUNT_BSS' => Bank::Bss,
            'VIRTUAL_ACCOUNT_BANK_BJB' => Bank::Bjb,
            'VIRTUAL_ACCOUNT_SINARMAS' => Bank::Sinarmas,
        ];
    }

    private function base(): string
    {
        return $this->environment() === Environment::Sandbox ? 'https://api-sandbox.doku.com' : 'https://api.doku.com';
    }

    private function token(): string
    {
        if ($this->accessToken !== null && $this->tokenExpiresAt > Carbon::now()->getTimestamp()) {
            return $this->accessToken;
        }
        $timestamp = Carbon::now('Asia/Jakarta')->toIso8601String();
        $passphrase = $this->config['private_key_passphrase'] ?? '';
        if (! is_string($passphrase)) {
            throw new ConfigurationException('DOKU private_key_passphrase must be a string.');
        }
        $headers = [
            'X-CLIENT-KEY' => $this->setting('client_id'),
            'X-TIMESTAMP' => $timestamp,
            'X-SIGNATURE' => DokuSignature::sign($this->setting('client_id').'|'.$timestamp, $this->setting('private_key'), $passphrase),
        ];
        $data = $this->transport->send('POST', $this->base().'/authorization/v1/access-token/b2b', Json::encode(['grantType' => 'client_credentials']), $headers);
        $this->checkSnap($data);
        $this->tokenExpiresAt = Carbon::now()->getTimestamp() + max(0, $data->amount('expiresIn') - 30);

        return $this->accessToken = $data->string('accessToken');
    }

    private function checkSnap(Data $data): void
    {
        $code = $data->string('responseCode');
        if (! str_starts_with($code, '200')) {
            throw new ProviderException($this->provider(), 200, preg_match('/^[0-9]{7}$/D', $code) === 1 ? $code : null);
        }
    }

    /** @param array<string, mixed> $payload */
    private function snap(string $path, array $payload): Data
    {
        $body = Json::encode($payload);
        $token = $this->token();
        $timestamp = Carbon::now('Asia/Jakarta')->toIso8601String();
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-PARTNER-ID' => $this->setting('client_id'),
            'X-EXTERNAL-ID' => Carbon::now()->format('YmdHis').random_int(100000000, 999999999),
            'CHANNEL-ID' => $this->setting('channel_id'),
            'X-TIMESTAMP' => $timestamp,
            'X-SIGNATURE' => DokuSignature::snap('POST', $path, $body, $token, $timestamp, $this->setting('secret_key')),
        ];
        $data = $this->transport->send('POST', $this->base().$path, $body, $headers);
        $this->checkSnap($data);

        return $data;
    }

    /** @param array<string, mixed> $payload */
    private function nonSnap(string $method, string $path, array $payload = [], ?string $requestId = null): Data
    {
        $body = $method === 'GET' ? '' : Json::encode($payload);
        $id = $requestId ?? (string) Str::uuid();
        $timestamp = Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $clientId = $this->setting('client_id');
        $headers = [
            'Client-Id' => $clientId,
            'Request-Id' => $id,
            'Request-Timestamp' => $timestamp,
            'Signature' => DokuSignature::nonSnap($method, $path, $body, $clientId, $id, $timestamp, $this->setting('secret_key')),
        ];

        return $this->transport->send($method, $this->base().$path, $body, $headers);
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResult
    {
        $this->validate($request);
        if ($request->method === PaymentMethod::Qris) {
            $payload = [
                'partnerReferenceNo' => $request->reference,
                'amount' => ['value' => $request->amount.'.00', 'currency' => 'IDR'],
                'merchantId' => $this->setting('merchant_id'),
                'terminalId' => $this->setting('terminal_id'),
                'additionalInfo' => ['postalCode' => $this->setting('postal_code'), 'feeType' => '1'],
            ];
            if ($request->expiresAt !== null) {
                $payload['validityPeriod'] = $request->expiresAt->format(DATE_ATOM);
            }
            $data = $this->snap('/snap-adapter/b2b/v1.0/qr/qr-mpm-generate', $payload);

            return new PaymentResult(
                new PaymentReference($request->reference, new DokuReference(referenceNo: $data->string('referenceNo')), PaymentMethod::Qris),
                $request->amount,
                PaymentStatus::Pending,
                'pending',
                new QrCode($data->string('qrContent')),
                expiresAt: $data->date('additionalInfo.validityPeriod'),
            );
        }
        $channel = $this->bankCode($request->bank);
        $bank = $request->requireBank();
        $banks = $this->config['banks'] ?? [];
        if (! is_array($banks) || ! is_array($bankConfig = $banks[$bank->value] ?? null)) {
            throw new ConfigurationException('Configure DOKU banks.'.$bank->value.' with your assigned VA identifiers.');
        }
        $service = $bankConfig['partner_service_id'] ?? null;
        $options = $request->options instanceof DokuOptions ? $request->options : new DokuOptions;
        $customer = $options->customerNumber ?? ($bankConfig['customer_number'] ?? null);
        if (! is_string($service) || preg_match('/^[0-9]{1,8}$/D', trim($service)) !== 1 || ! is_string($customer) || preg_match('/^[0-9]{1,20}$/D', $customer) !== 1) {
            throw new ConfigurationException('DOKU VA identifiers must be numeric strings of the documented lengths.');
        }
        $service = str_pad(trim($service), 8, ' ', STR_PAD_LEFT);
        $number = $options->virtualAccountNumber ?? $service.$customer;
        $payload = [
            'partnerServiceId' => $service,
            'customerNo' => $customer,
            'virtualAccountNo' => $number,
            'virtualAccountName' => $request->customer->name ?? $request->description,
            'trxId' => $request->reference,
            'virtualAccountTrxType' => 'C',
            'totalAmount' => ['value' => $request->amount.'.00', 'currency' => 'IDR'],
            'additionalInfo' => ['channel' => $channel, 'virtualAccountConfig' => ['reusableStatus' => false]],
        ];
        if ($request->customer?->email !== null) {
            $payload['virtualAccountEmail'] = $request->customer->email;
        }
        if ($request->customer?->phone !== null) {
            $payload['virtualAccountPhone'] = $request->customer->phone;
        }
        if ($request->expiresAt !== null) {
            $payload['expiredDate'] = $request->expiresAt->format(DATE_ATOM);
        }
        $data = $this->snap('/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va', $payload)->object('virtualAccountData');
        $reference = new PaymentReference($request->reference, new DokuReference(customerNo: $data->string('customerNo'), partnerServiceId: $data->string('partnerServiceId'), virtualAccountNo: $data->string('virtualAccountNo')), PaymentMethod::VirtualAccount, $bank);

        return new PaymentResult(
            $reference,
            $data->amount('totalAmount.value'),
            PaymentStatus::Pending,
            'pending',
            new VirtualAccount(ltrim($data->string('virtualAccountNo')), $bank, $data->optionalString('virtualAccountName')),
            expiresAt: $data->date('expiredDate'),
        );
    }

    public function createCheckout(CreateCheckoutRequest $request): CheckoutResult
    {
        $this->validate($request);
        $id = (string) Str::uuid();
        $payload = ['order' => ['amount' => $request->amount, 'invoice_number' => $request->reference, 'currency' => 'IDR', 'callback_url' => $request->returnUrl, 'callback_url_cancel' => $request->cancelUrl, 'line_items' => array_map(fn ($item) => ['name' => $item->name, 'price' => $item->price, 'quantity' => $item->quantity], $request->lineItems())], 'payment' => ['payment_due_date' => $this->expiryMinutes($request) ?? 60]];
        if ($request->customer !== null) {
            $payload['customer'] = array_filter(['id' => $request->customer->reference, 'name' => $request->customer->name, 'email' => $request->customer->email, 'phone' => $request->customer->phone], fn ($value) => $value !== null);
        }
        if ($request->notifyUrl !== null) {
            $payload['additional_info'] = ['override_notification_url' => $request->notifyUrl];
        }
        $data = $this->nonSnap('POST', '/checkout/v1/payment', $payload, $id)->object('response');

        return new CheckoutResult(
            new PaymentReference($request->reference, new DokuReference(requestId: $id)),
            $data->amount('order.amount'),
            $data->string('payment.url'),
            token: $data->optionalString('payment.token_id'),
            expiresAt: $data->date('payment.expired_date'),
        );
    }

    public function getStatus(PaymentReference $reference): PaymentStatusResult
    {
        $this->validateReference($reference);
        $ids = $reference->identifiers;
        if (! $ids instanceof DokuReference) {
            throw new InvalidResponseException('Invalid DOKU identifiers.');
        }
        if ($reference->method === PaymentMethod::Qris) {
            $id = $ids->referenceNo ?? throw new StatusUnavailableException('DOKU QRIS referenceNo is required.');
            $data = $this->snap('/snap-adapter/b2b/v1.0/qr/qr-mpm-query', [
                'originalReferenceNo' => $id,
                'originalPartnerReferenceNo' => $reference->merchantReference,
                'serviceCode' => '47',
                'merchantId' => $this->setting('merchant_id'),
            ]);
            $status = $data->string('latestTransactionStatus');

            return new PaymentStatusResult(
                $reference,
                $this->status($status),
                $status,
                amount: $data->optionalAmount('amount.value'),
                paidAt: $data->date('paidTime'),
            );
        }
        if ($reference->method === PaymentMethod::VirtualAccount) {
            if ($ids->partnerServiceId === null || $ids->customerNo === null || $ids->virtualAccountNo === null) {
                throw new StatusUnavailableException('DOKU VA status requires partnerServiceId, customerNo and virtualAccountNo.');
            }
            $data = $this->snap('/orders/v1.0/transfer-va/status', [
                'partnerServiceId' => $ids->partnerServiceId,
                'customerNo' => $ids->customerNo,
                'virtualAccountNo' => $ids->virtualAccountNo,
            ]);
            $status = $data->string('virtualAccountData.paymentFlagReason.english');

            return new PaymentStatusResult($reference, $this->status($status), $status, amount: $data->optionalAmount('virtualAccountData.paidAmount.value'));
        }
        $data = $this->nonSnap('GET', '/orders/v1/status/'.rawurlencode($reference->merchantReference));
        $status = $data->string('transaction.status');
        $payment = $this->status($status);
        $checkout = match ($payment) {
            PaymentStatus::Paid => CheckoutStatus::Completed, PaymentStatus::Expired => CheckoutStatus::Expired, PaymentStatus::Cancelled => CheckoutStatus::Cancelled, PaymentStatus::Unknown, PaymentStatus::Refunded => CheckoutStatus::Unknown, default => CheckoutStatus::Open
        };

        return new PaymentStatusResult($reference, $payment, $status, $checkout, $data->optionalAmount('order.amount'));
    }

    public function parseWebhook(WebhookRequest $request): WebhookEvent
    {
        if ($request->header('Signature') !== null) {
            $client = $request->requiredHeader('Client-Id');
            $expected = DokuSignature::nonSnap($request->method, $request->path, $request->body, $client, $request->requiredHeader('Request-Id'), $request->requiredHeader('Request-Timestamp'), $this->setting('secret_key'));
            if ($client !== $this->setting('client_id') || ! hash_equals($expected, $request->requiredHeader('Signature'))) {
                throw new InvalidWebhookException('Invalid DOKU non-SNAP signature.');
            }
            $data = $request->data();
            $status = $data->string('transaction.status');
            $method = match ($data->optionalString('service.id')) {
                'QRIS' => PaymentMethod::Qris, 'VIRTUAL_ACCOUNT' => PaymentMethod::VirtualAccount, default => null
            };

            return new WebhookEvent(
                new PaymentReference($data->string('order.invoice_number'), new DokuReference(requestId: $data->optionalString('transaction.original_request_id')), $method),
                $status,
                WebhookSubject::Payment,
                $this->status($status),
                $status,
                $request->requiredHeader('Request-Id'),
                $data->amount('order.amount'),
            );
        }
        $timestamp = $request->requiredHeader('X-TIMESTAMP');
        $signature = $request->requiredHeader('X-SIGNATURE');
        $mode = $this->config['snap_webhook_signature'] ?? 'symmetric';
        if ($mode === 'asymmetric') {
            $valid = DokuSignature::verify(DokuSignature::asymmetricPayload($request->method, $request->path, $request->body, $timestamp), $signature, $this->setting('public_key'));
        } elseif ($mode === 'symmetric') {
            $authorization = $request->requiredHeader('Authorization');
            if (! str_starts_with($authorization, 'Bearer ') || strlen($authorization) <= 7) {
                throw new InvalidWebhookException('Missing DOKU webhook bearer token.');
            }
            $valid = hash_equals(DokuSignature::snap($request->method, $request->path, $request->body, substr($authorization, 7), $timestamp, $this->setting('secret_key')), $signature);
        } else {
            throw new ConfigurationException('DOKU snap_webhook_signature must be symmetric or asymmetric.');
        }
        if (! $valid || $request->requiredHeader('X-PARTNER-ID') !== $this->setting('client_id')) {
            throw new InvalidWebhookException('Invalid DOKU SNAP signature.');
        }
        $data = $request->data();
        $identity = [];
        foreach (['partnerServiceId', 'customerNo', 'virtualAccountNo', 'virtualAccountName', 'trxId', 'paymentRequestId'] as $field) {
            $identity[$field] = $data->string($field);
        }
        $ack = new WebhookResponse(['responseCode' => '2002500', 'responseMessage' => 'Success', 'virtualAccountData' => $identity]);

        return new WebhookEvent(
            new PaymentReference($identity['trxId'], new DokuReference(customerNo: $identity['customerNo'], partnerServiceId: $identity['partnerServiceId'], virtualAccountNo: $identity['virtualAccountNo']), PaymentMethod::VirtualAccount),
            'virtual_account.paid',
            WebhookSubject::Payment,
            PaymentStatus::Paid,
            'SUCCESS',
            $request->requiredHeader('X-EXTERNAL-ID'),
            $data->amount('paidAmount.value'),
            acknowledgment: $ack,
        );
    }

    private function status(string $status): PaymentStatus
    {
        return match (strtoupper($status)) {
            '00',
            'SUCCESS',
            'PAID',
            'SUCCESSFUL' => PaymentStatus::Paid,
            '02',
            'TIMEOUT',
            'REDIRECT' => PaymentStatus::Processing,
            '01',
            '03',
            'PENDING' => PaymentStatus::Pending,
            '04',
            'REFUNDED' => PaymentStatus::Refunded,
            '05',
            'CANCELLED',
            'CANCELED' => PaymentStatus::Cancelled,
            '06',
            'FAILED' => PaymentStatus::Failed,
            'EXPIRED' => PaymentStatus::Expired,
            default => PaymentStatus::Unknown,
        };
    }
}
