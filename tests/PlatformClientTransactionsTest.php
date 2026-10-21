<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionData;
use ITuoiProfessionistiDigitali\Connector\Enums\CounterpartyType;
use ITuoiProfessionistiDigitali\Connector\Enums\TransactionActivityStatus;
use ITuoiProfessionistiDigitali\Connector\Enums\TransactionResult;
use ITuoiProfessionistiDigitali\Connector\Enums\TransactionStatus;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Facades\Platform;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('R7: registers a transaction with its revision under its reference', function (): void {
    withToken(['platform.test/api/v1/transactions/*' => Http::response(['data' => recordedTransaction()], 201)]);

    $recorded = Platform::recordTransaction('invio 1/a', TransactionData::from(transaction()), 2);

    expect($recorded->status)->toBe(TransactionStatus::Invited)
        ->and($recorded->id)->toBe(recordedTransaction()['id'])
        ->and($recorded->counterparty->last_name)->toBe('Rossi')
        ->and($recorded->counterparty->member_id)->toBeNull()
        ->and($recorded->counterparty->province)->toBe('BA')
        ->and($recorded->total_cents)->toBe(9000)
        ->and($recorded->activities[0]->total_cents)->toBe(9000)
        ->and($recorded->activities[0]->status)->toBe(TransactionActivityStatus::Open);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://platform.test/api/v1/transactions/invio%201%2Fa'
        && $request->data()['revision'] === 2
        && $request->data()['principal'] === transaction()['principal']
        && $request->data()['activities'][0]['reference'] === 'riga-1');
});

it('T6: sends the email of a person counterparty in lower case', function (): void {
    withToken(['platform.test/api/v1/transactions/*' => Http::response(['data' => recordedTransaction()], 201)]);
    $counterparty = [...transaction()['counterparty'], 'email' => 'Mario.Rossi@Example.com'];

    Platform::recordTransaction('invio-1', TransactionData::from(transaction(['counterparty' => $counterparty])), 1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->data()['counterparty'] === transaction()['counterparty']);
});

it('R6: checks every description against the schema fixed in the package before sending', function (): void {
    withToken(['platform.test/api/v1/transactions/*' => Http::response(['data' => recordedTransaction()], 201)]);

    Platform::recordTransaction('invio-1', TransactionData::from(transaction(['activities' => [
        activity(['description' => ['process' => ['reference' => 'agenda-7', 'name' => null], 'activity' => null, 'deadline' => null]]),
    ]])), 1);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/transactions/invio-1')
        && $request->data()['activities'][0]['description'] === ['process' => ['reference' => 'agenda-7', 'name' => null], 'activity' => null, 'deadline' => null]);
});

it('R5 and R14: does not send what the rules of the register refuse', function (): void {
    Http::fake();

    expect(fn () => Platform::recordTransaction('invio-1', TransactionData::from(transaction(['activities' => [activity(['minutes_worked' => 30])]])), 1))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('R6: leaves the payload to the platform when the local validation is off or the schema is unknown', function (array $config, array $overrides): void {
    $this->app->instance(ConnectorConfig::class, ConnectorConfig::fromArray([...config('platform'), ...$config]));
    $this->app->forgetInstance(PlatformClient::class);
    Platform::clearResolvedInstances();
    withToken(['platform.test/api/v1/transactions/*' => Http::response(['data' => recordedTransaction()], 201)]);

    Platform::recordTransaction('invio-1', TransactionData::from(transaction($overrides)), 1);

    Http::assertSentCount(2);
})->with([
    'validation off' => [['validate_payloads' => false], ['activities' => [activity(['description' => ['process' => ['reference' => 'processo-1', 'name' => null], 'anything' => true]])]]],
    'schema not in the package' => [[], ['type' => 'loan', 'activities' => [activity(['description' => ['process' => ['reference' => 'processo-1', 'name' => null], 'anything' => true]])]]],
]);

it('R7: reads the transaction already registered from a conflict', function (): void {
    withToken(['platform.test/api/v1/transactions/*' => Http::response(['message' => 'Revisione già registrata.', 'data' => recordedTransaction(['status' => 'accepted', 'responded_at' => '2026-10-07T09:00:00Z'])], 409)]);

    try
    {
        Platform::recordTransaction('invio-1', TransactionData::from(transaction()), 1);
        $this->fail('The platform should answer 409.');
    }
    catch (PlatformRequestException $exception)
    {
        expect($exception->isConflict())->toBeTrue()
            ->and($exception->existingTransaction?->status)->toBe(TransactionStatus::Accepted)
            ->and($exception->existingEvent)->toBeNull();
    }
});

it('R10: loads the history and reads each outcome', function (): void {
    withToken(['platform.test/api/v1/transactions/batch' => Http::response(['data' => [
        ['reference' => 'a', 'result' => 'created', 'transaction' => recordedTransaction(['reference' => 'a']), 'errors' => null],
        ['reference' => 'b', 'result' => 'invalid', 'transaction' => null, 'errors' => ['principal' => ['Non è un aderente.']]],
    ]])]);

    $outcomes = Platform::recordTransactions([
        ['reference' => 'a', 'revision' => 1, 'transaction' => TransactionData::from(transaction())],
        ['reference' => 'b', 'revision' => 1, 'transaction' => TransactionData::from(transaction())],
    ]);

    expect($outcomes[0]->result)->toBe(TransactionResult::Created)
        ->and($outcomes[0]->transaction?->reference)->toBe('a')
        ->and($outcomes[1]->result)->toBe(TransactionResult::Invalid)
        ->and($outcomes[1]->errors)->toBe(['principal' => ['Non è un aderente.']]);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/transactions/batch')
        && $request->data()['transactions'][1]['reference'] === 'b'
        && $request->data()['transactions'][1]['revision'] === 1);
});

it('R10: does not send more than 500 transactions', function (): void {
    Http::fake();
    $item = ['reference' => 'a', 'revision' => 1, 'transaction' => TransactionData::from(transaction())];

    expect(fn () => Platform::recordTransactions(array_fill(0, Contract::MAX_TRANSACTIONS + 1, $item)))
        ->toThrow(PlatformRequestException::class);
    Http::assertNothingSent();
});

it('R11: reads the transactions of the system with filters and cursor', function (): void {
    withToken(['platform.test/api/v1/transactions*' => Http::response([
        'data' => [recordedTransaction()],
        'meta' => ['next_cursor' => 'abc'],
    ])]);

    $page = Platform::transactions(status: 'accepted', updatedSince: new DateTimeImmutable('2026-10-07T12:00:00+02:00'), perPage: 10, cursor: 'start');

    expect($page->transactions[0]->reference)->toBe('invio-1')
        ->and($page->nextCursor)->toBe('abc')
        ->and($page->hasMore())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform.test/api/v1/transactions?status=accepted&updated_since=2026-10-07T12%3A00%3A00%2B02%3A00&per_page=10&cursor=start');
});

it('R3 and R11: reads a transaction given to a member of another system, as its counterparty sees it', function (): void {
    withToken(['platform.test/api/v1/transactions*' => Http::response(['data' => [recordedTransaction([
        'reference' => null,
        'assignment_reference' => null,
        'counterparty' => firm(),
    ])], 'meta' => ['next_cursor' => null]])]);

    $received = Platform::transactions()->transactions[0];

    expect($received->counterparty->type)->toBe(CounterpartyType::Member)
        ->and($received->counterparty->member_id)->toBe(firm()['member_id'])
        ->and($received->counterparty->tax_code)->toBeNull()
        ->and($received->reference)->toBeNull()
        ->and($received->title)->toBe('Contabilità ordinaria 2026');
});

it('R11 and R22: reads the signature of an accepted transaction, null while it waits for it', function (): void {
    withToken(['platform.test/api/v1/transactions*' => Http::response(['data' => [
        recordedTransaction(['status' => 'accepted', 'responded_at' => '2026-10-07T09:00:00Z', 'signed_at' => '2026-10-07T10:30:00Z']),
        recordedTransaction(['reference' => 'invio-2', 'status' => 'accepted', 'responded_at' => '2026-10-07T09:00:00Z']),
    ], 'meta' => ['next_cursor' => null]])]);

    [$signed, $waiting] = Platform::transactions()->transactions;

    expect($signed->signed_at?->toIso8601ZuluString())->toBe('2026-10-07T10:30:00Z')
        ->and($waiting->signed_at)->toBeNull();
});

it('R11: tells when the last page is read', function (): void {
    withToken(['platform.test/api/v1/transactions*' => Http::response(['data' => [], 'meta' => ['next_cursor' => null]])]);

    expect(Platform::transactions()->hasMore())->toBeFalse();
});
