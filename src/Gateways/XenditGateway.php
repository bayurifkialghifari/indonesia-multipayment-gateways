<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments\Gateways;

use Bayurifkialghifari\Payments\DTO\CheckoutResult;
use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Instructions\QrCode;
use Bayurifkialghifari\Payments\DTO\Instructions\VirtualAccount;
use Bayurifkialghifari\Payments\DTO\Options\XenditOptions;
use Bayurifkialghifari\Payments\DTO\OrderRequest;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\PaymentResult;
use Bayurifkialghifari\Payments\DTO\PaymentStatusResult;
use Bayurifkialghifari\Payments\DTO\References\XenditReference;
use Bayurifkialghifari\Payments\DTO\WebhookEvent;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\CheckoutStatus;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Enums\PaymentStatus;
use Bayurifkialghifari\Payments\Enums\Provider;
use Bayurifkialghifari\Payments\Enums\WebhookSubject;
use Bayurifkialghifari\Payments\Exceptions\InvalidResponseException;
use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Bayurifkialghifari\Payments\Exceptions\StatusUnavailableException;
use Bayurifkialghifari\Payments\Support\Data;
use Bayurifkialghifari\Payments\Support\Json;

final class XenditGateway extends Gateway
{
    public function provider(): Provider
    {
        return Provider::Xendit;
    }

    protected function banks(): array
    {
        return [
            'BCA_VIRTUAL_ACCOUNT' => Bank::Bca,
            'BNI_VIRTUAL_ACCOUNT' => Bank::Bni,
            'BRI_VIRTUAL_ACCOUNT' => Bank::Bri,
            'MANDIRI_VIRTUAL_ACCOUNT' => Bank::Mandiri,
            'PERMATA_VIRTUAL_ACCOUNT' => Bank::Permata,
            'CIMB_VIRTUAL_ACCOUNT' => Bank::Cimb,
            'BSI_VIRTUAL_ACCOUNT' => Bank::Bsi,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function send(string $method, string $path, array $payload = [], ?string $businessId = null): Data
    {
        $headers = ['Authorization' => 'Basic '.base64_encode($this->setting('secret_key').':'), 'api-version' => '2024-11-11'];
        if ($businessId !== null) {
            $headers['for-user-id'] = $businessId;
        }

        return $this->transport->send($method, 'https://api.xendit.co'.$path, $method === 'GET' ? '' : Json::encode($payload), $headers);
    }

    /** @return array<string, mixed> */
    private function customer(OrderRequest $request): array
    {
        if ($request->customer === null) {
            return [];
        }
        $customer = $request->customer;

        return ['customer' => array_filter(['reference_id' => $customer->reference ?? $request->reference, 'type' => 'INDIVIDUAL', 'email' => $customer->email, 'mobile_number' => $customer->phone, 'individual_detail' => ['given_names' => $customer->name]], fn ($value) => $value !== null)];
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResult
    {
        $this->validate($request);
        $options = $request->options instanceof XenditOptions ? $request->options : new XenditOptions;
        $properties = array_filter([
            'expires_at' => $request->expiresAt?->format(DATE_ATOM),
            'display_name' => $options->displayName ?? $request->customer?->name,
            'virtual_account_number' => $options->virtualAccountNumber,
        ], fn ($value) => $value !== null);
        if ($request->method === PaymentMethod::Qris) {
            $properties = array_filter(['expires_at' => $request->expiresAt?->format(DATE_ATOM)], fn ($value) => $value !== null);
        }
        $payload = [
            'reference_id' => $request->reference,
            'type' => 'PAY',
            'country' => 'ID',
            'currency' => 'IDR',
            'request_amount' => $request->amount,
            'channel_code' => $request->method === PaymentMethod::Qris ? 'QRIS' : $this->bankCode($request->bank),
            'description' => $request->description,
        ] + $this->customer($request);
        if ($properties !== []) {
            $payload['channel_properties'] = $properties;
        }
        $data = $this->send('POST', '/v3/payment_requests', $payload, $options->businessId);
        $value = null;
        $descriptor = $request->method === PaymentMethod::Qris ? 'QR_STRING' : 'VIRTUAL_ACCOUNT_NUMBER';
        foreach ($data->objects('actions') as $action) {
            if ($action->string('descriptor') === $descriptor) {
                $value = $action->string('value');
            }
        }
        if ($value === null) {
            throw new InvalidResponseException('Missing Xendit payment instructions.');
        }
        $instructions = $request->method === PaymentMethod::Qris ? new QrCode($value) : new VirtualAccount($value, $request->requireBank());
        $status = $data->string('status');

        return new PaymentResult(
            new PaymentReference($request->reference, new XenditReference($data->string('payment_request_id'), businessId: $options->businessId), $request->method, $request->bank),
            $data->amount('request_amount'),
            $this->status($status),
            $status,
            $instructions,
            expiresAt: $request->expiresAt,
        );
    }

    public function createCheckout(CreateCheckoutRequest $request): CheckoutResult
    {
        $this->validate($request);
        $options = $request->options instanceof XenditOptions ? $request->options : new XenditOptions;
        $payload = array_filter([
            'reference_id' => $request->reference,
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'country' => 'ID',
            'currency' => 'IDR',
            'amount' => $request->amount,
            'description' => $request->description,
            'success_return_url' => $request->returnUrl,
            'cancel_return_url' => $request->cancelUrl,
            'expires_at' => $request->expiresAt?->format(DATE_ATOM),
        ], fn ($value) => $value !== null) + $this->customer($request);
        $data = $this->send('POST', '/sessions', $payload, $options->businessId);

        return new CheckoutResult(
            new PaymentReference($request->reference, new XenditReference(paymentSessionId: $data->string('payment_session_id'), businessId: $options->businessId)),
            $data->amount('amount'),
            $data->string('payment_link_url'),
            $this->checkoutStatus($data->string('status')),
            expiresAt: $data->date('expires_at'),
        );
    }

    public function getStatus(PaymentReference $reference): PaymentStatusResult
    {
        $this->validateReference($reference);
        $ids = $reference->identifiers;
        if (! $ids instanceof XenditReference) {
            throw new InvalidResponseException('Invalid Xendit identifiers.');
        }
        if ($ids->paymentSessionId !== null) {
            $data = $this->send('GET', '/sessions/'.rawurlencode($ids->paymentSessionId), businessId: $ids->businessId);
            $status = $data->string('status');
            $checkout = $this->checkoutStatus($status);
            $payment = match ($checkout) {
                CheckoutStatus::Open => PaymentStatus::Pending, CheckoutStatus::Completed => PaymentStatus::Paid, CheckoutStatus::Expired => PaymentStatus::Expired, CheckoutStatus::Cancelled => PaymentStatus::Cancelled, CheckoutStatus::Unknown => PaymentStatus::Unknown
            };

            return new PaymentStatusResult($reference, $payment, $status, $checkout, $data->optionalAmount('amount'));
        }
        $id = $ids->paymentRequestId ?? throw new StatusUnavailableException('Xendit paymentRequestId or paymentSessionId is required.');
        $data = $this->send('GET', '/v3/payment_requests/'.rawurlencode($id), businessId: $ids->businessId);
        $status = $data->string('status');

        return new PaymentStatusResult($reference, $this->status($status), $status, amount: $data->optionalAmount('request_amount'));
    }

    public function parseWebhook(WebhookRequest $request): WebhookEvent
    {
        if (! hash_equals($this->setting('webhook_token'), $request->requiredHeader('x-callback-token'))) {
            throw new InvalidWebhookException('Invalid Xendit callback token.');
        }
        $envelope = $request->data();
        $event = $envelope->string('event');
        $data = $envelope->object('data');
        $sessionId = $data->optionalString('payment_session_id');
        $subject = str_starts_with($event, 'payment_session.') ? WebhookSubject::Checkout : WebhookSubject::Payment;
        $status = $data->optionalString('status') ?? $event;
        $paymentStatus = match ($event) {
            'payment.capture',
            'payment.succeeded',
            'payment_session.completed' => PaymentStatus::Paid,
            'payment.failure',
            'payment.failed' => PaymentStatus::Failed,
            'payment_request.expiry',
            'payment_session.expired' => PaymentStatus::Expired,
            default => $this->status($status),
        };
        $checkout = null;
        if ($subject === WebhookSubject::Checkout) {
            $checkout = match ($event) {
                'payment_session.completed' => CheckoutStatus::Completed,
                'payment_session.expired' => CheckoutStatus::Expired,
                default => $this->checkoutStatus($status),
            };
        }
        if ($sessionId !== null && $subject === WebhookSubject::Payment && $paymentStatus === PaymentStatus::Failed) {
            $checkout = CheckoutStatus::Open;
        }

        return new WebhookEvent(
            new PaymentReference($data->string('reference_id'), new XenditReference($data->optionalString('payment_request_id'), $sessionId, $data->optionalString('payment_id'), $envelope->optionalString('business_id'))),
            $event,
            $subject,
            $paymentStatus,
            $status,
            $request->header('webhook-id'),
            $data->optionalAmount('capture_amount') ?? $data->optionalAmount('request_amount') ?? $data->optionalAmount('amount'),
            $checkout,
        );
    }

    private function status(string $status): PaymentStatus
    {
        return match (strtoupper($status)) {
            'REQUIRES_ACTION',
            'ACCEPTING_PAYMENTS',
            'PENDING' => PaymentStatus::Pending,
            'AUTHORIZED',
            'PENDING_CAPTURE' => PaymentStatus::Processing,
            'SUCCEEDED',
            'COMPLETED' => PaymentStatus::Paid,
            'FAILED' => PaymentStatus::Failed,
            'EXPIRED' => PaymentStatus::Expired,
            'CANCELED',
            'CANCELLED' => PaymentStatus::Cancelled,
            default => PaymentStatus::Unknown,
        };
    }

    private function checkoutStatus(string $status): CheckoutStatus
    {
        return match (strtoupper($status)) {
            'ACTIVE',
            'OPEN' => CheckoutStatus::Open,
            'COMPLETED' => CheckoutStatus::Completed,
            'EXPIRED' => CheckoutStatus::Expired,
            'CANCELED',
            'CANCELLED' => CheckoutStatus::Cancelled,
            default => CheckoutStatus::Unknown,
        };
    }
}
