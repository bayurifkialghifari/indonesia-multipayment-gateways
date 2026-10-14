<?php

declare(strict_types=1);

use Bayurifkialghifari\Payments\DTO\CreateCheckoutRequest;
use Bayurifkialghifari\Payments\DTO\CreatePaymentRequest;
use Bayurifkialghifari\Payments\DTO\Customer;
use Bayurifkialghifari\Payments\DTO\Instructions\QrCode;
use Bayurifkialghifari\Payments\DTO\Instructions\VirtualAccount;
use Bayurifkialghifari\Payments\DTO\LineItem;
use Bayurifkialghifari\Payments\DTO\PaymentMethodCollection;
use Bayurifkialghifari\Payments\DTO\PaymentMethodInfo;
use Bayurifkialghifari\Payments\DTO\PaymentReference;
use Bayurifkialghifari\Payments\DTO\References\DokuReference;
use Bayurifkialghifari\Payments\DTO\References\IPaymuReference;
use Bayurifkialghifari\Payments\DTO\References\MidtransReference;
use Bayurifkialghifari\Payments\DTO\References\PaywuzReference;
use Bayurifkialghifari\Payments\DTO\References\XenditReference;
use Bayurifkialghifari\Payments\DTO\WebhookRequest;
use Bayurifkialghifari\Payments\DTO\WebhookResponse;
use Bayurifkialghifari\Payments\Enums\Bank;
use Bayurifkialghifari\Payments\Enums\PaymentMethod;
use Bayurifkialghifari\Payments\Exceptions\InvalidWebhookException;
use Illuminate\Http\Request;

it('validates order input before sending payment requests', function (Closure $create): void {
    $create();
})->with([
    'empty reference' => fn () => new CreatePaymentRequest(' ', 10000, PaymentMethod::Qris),
    'non positive amount' => fn () => new CreatePaymentRequest('ORDER', 0, PaymentMethod::Qris),
    'mismatched items' => fn () => new CreatePaymentRequest('ORDER', 10000, PaymentMethod::Qris, items: [new LineItem('Item', 9000)]),
    'VA needs bank' => fn () => new CreatePaymentRequest('ORDER', 10000, PaymentMethod::VirtualAccount),
    'QRIS no bank' => fn () => new CreatePaymentRequest('ORDER', 10000, PaymentMethod::Qris, Bank::Bca),
    'invalid notification' => fn () => new CreatePaymentRequest('ORDER', 10000, PaymentMethod::Qris, notifyUrl: 'ftp://example.com/a'),
    'invalid return' => fn () => new CreateCheckoutRequest('ORDER', 10000, 'bad', 'https://example.com/cancel'),
    'invalid email' => fn () => new Customer('Bayu', 'bad-email'),
    'empty phone' => fn () => new Customer('Bayu', phone: ' '),
    'empty QR' => fn () => new QrCode,
    'empty VA' => fn () => new VirtualAccount('', Bank::Bca),
    'zero quantity' => fn () => new LineItem('Item', 10000, 0),
])->throws(InvalidArgumentException::class);

it('accepts item totals and constructs default line items', function (): void {
    $request = new CreatePaymentRequest('ORDER', 10000, PaymentMethod::Qris, items: [new LineItem('Item', 5000, 2, 'SKU-1')]);
    expect($request->lineItems()[0]->reference)->toBe('SKU-1')
        ->and($this->payment()->lineItems()[0]->price)->toBe(10000)
        ->and((new QrCode(imageUrl: 'https://example.com/qr.png'))->text)->toBeNull();
});

it('returns the required VA bank and rejects asking a QRIS payment for a bank', function (): void {
    expect($this->payment(PaymentMethod::VirtualAccount, Bank::Bca)->requireBank())->toBe(Bank::Bca);
    expect(fn () => $this->payment()->requireBank())->toThrow(InvalidArgumentException::class);
});

it('round trips payment references without losing zeroes or provider context', function (object $identifiers): void {
    $reference = new PaymentReference('ORDER-1', $identifiers, PaymentMethod::VirtualAccount, Bank::Bca);
    expect(PaymentReference::fromArray($reference->toArray()))->toEqual($reference);
})->with([
    new MidtransReference('ORDER-1', 'MID-1'), new XenditReference('PR-1', 'PS-1', 'PAY-1', 'BUS-1'),
    new DokuReference('REQ-1', 'QR-1', '000001', '   19008', '   19008000001'), new PaywuzReference('PW-1'), new IPaymuReference('123', 'SID-1'),
]);

it('round trips hosted references with no direct method', function (): void {
    $reference = new PaymentReference('ORDER-1', new IPaymuReference(sessionId: 'SID-1'));
    expect(PaymentReference::fromArray($reference->toArray()))->toEqual($reference);
});

it('iterates a typed payment method collection', function (): void {
    $item = new PaymentMethodInfo('QRIS', 'QRIS', PaymentMethod::Qris);
    $collection = new PaymentMethodCollection([$item]);
    expect(count($collection))->toBe(1)->and(iterator_to_array($collection))->toBe([$item]);
});

it('captures exact HTTP webhook bytes and case insensitive headers', function (): void {
    $body = '{ "id": "1" }';
    $http = Request::create('/webhooks/payments', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'sig'], content: $body);
    $request = WebhookRequest::fromRequest($http);
    expect($request->body)->toBe($body)->and($request->path)->toBe('/webhooks/payments')
        ->and($request->method)->toBe('POST')->and($request->requiredHeader('x-SIGNATURE'))->toBe('sig')
        ->and($request->data()->string('id'))->toBe('1')
        ->and((new WebhookResponse)->toResponse()->getData(true))->toBe(['status' => 'OK']);
});

it('parses form webhooks and rejects numeric keys', function (): void {
    $headers = ['content-type' => 'application/x-www-form-urlencoded; charset=UTF-8'];
    expect((new WebhookRequest('id=001', $headers, '/hook'))->data()->string('id'))->toBe('001');
    expect(fn () => (new WebhookRequest('1=bad', $headers, '/hook'))->data())->toThrow(InvalidWebhookException::class);
    expect(fn () => (new WebhookRequest('{}', [], '/hook'))->requiredHeader('signature'))->toThrow(InvalidWebhookException::class);
});
