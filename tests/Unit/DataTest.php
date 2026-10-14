<?php

declare(strict_types=1);

use Bayurifkialghifari\Payments\Exceptions\InvalidResponseException;
use Bayurifkialghifari\Payments\Support\Data;
use Bayurifkialghifari\Payments\Support\Json;

it('reads safe integral Rupiah values', function (mixed $value, int $expected): void {
    expect((new Data(['amount' => $value]))->amount('amount'))->toBe($expected);
})->with([[10000, 10000], [10000.0, 10000], ['10000.00', 10000], ['00001', 1], ['0.00', 0]]);

it('rejects invalid Rupiah values', function (mixed $value): void {
    (new Data(['amount' => $value]))->amount('amount');
})->with([[-1], [true], [1.25], [INF], ['1e4'], ['10000.50'], ['9223372036854775808'], [[]], [null]])->throws(InvalidResponseException::class);

it('rejects JSON that is malformed or is not an object', function (string $body): void {
    Data::fromJson($body);
})->with(['{', 'null', 'true', '[1]', '"secret"'])->throws(InvalidResponseException::class);

it('preserves numeric identifiers as strings', function (): void {
    $data = Data::fromJson('{"id":123456789012345678901,"other":123,"amount":null,"empty":""}');
    expect($data->string('id'))->toBe('123456789012345678901')
        ->and($data->string('other'))->toBe('123')
        ->and($data->optionalAmount('amount'))->toBeNull()
        ->and($data->optionalString('missing.path'))->toBeNull()
        ->and($data->optionalString('empty'))->toBe('')
        ->and(Data::fromJson('{}')->all())->toBe([]);
});

it('rejects non string fields', function (): void {
    (new Data(['id' => false]))->string('id');
})->throws(InvalidResponseException::class);

it('rejects missing or empty required strings', function (array $values): void {
    (new Data($values))->string('id');
})->with([[[]], [['id' => '']]])->throws(InvalidResponseException::class);

it('reads typed nested values', function (): void {
    $data = new Data(['enabled' => false, 'details' => ['number' => '001'], 'list' => [['code' => 'qris'], []], 'date' => '2026-10-14 10:00:00', 'emptyDate' => '']);
    expect($data->optionalBool('enabled'))->toBeFalse()
        ->and($data->optionalBool('missing'))->toBeNull()
        ->and($data->object('details')->string('number'))->toBe('001')
        ->and($data->objects('list')[0]->string('code'))->toBe('qris')
        ->and($data->objects('list')[1]->all())->toBe([])
        ->and($data->date('date')->format(DATE_ATOM))->toBe('2026-10-14T10:00:00+07:00')
        ->and($data->date('missing'))->toBeNull()
        ->and($data->date('emptyDate'))->toBeNull();
});

it('rejects invalid structural response fields', function (string $method, mixed $value): void {
    (new Data(['field' => $value]))->{$method}('field');
})->with([
    ['optionalBool', 1], ['object', null], ['object', ['list']], ['objects', null], ['objects', ['key' => 'value']], ['objects', [1]], ['objects', [['bad-list']]], ['date', 'not-a-date'],
])->throws(InvalidResponseException::class);

it('serializes one exact request body and rejects invalid UTF8', function (): void {
    expect(Json::encode(['url' => 'https://example.com', 'text' => 'Bayu']))->toBe('{"url":"https:\/\/example.com","text":"Bayu"}');
    expect(fn () => Json::encode(['text' => "\xB1\x31"]))->toThrow(JsonException::class);
});
