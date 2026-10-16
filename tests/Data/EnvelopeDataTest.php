<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;

it('E1: makes an event with a fresh UUID v7 and the current time', function (): void {
    CarbonImmutable::setTestNow('2026-10-07 15:30:00');

    $event = EnvelopeData::make(
        type: Contract::PING,
        schemaVersion: 1,
        sender: envelope()['sender'],
        recipient: envelope()['recipient'],
        typology: 'commercialisti',
        payload: ['challenge' => 'a1b2c3d4e5f6a7b8'],
    );

    expect(Str::isUuid($event->event_id))->toBeTrue()
        ->and($event->event_id[14])->toBe('7')
        ->and($event->occurred_at->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($event->correlation_id)->toBeNull();
});

it('E1: refuses an envelope that is incomplete or malformed', function (array $overrides, string $field): void {
    try
    {
        EnvelopeData::validateAndCreate(envelope($overrides));
        $this->fail('The envelope should not be valid.');
    }
    catch (ValidationException $exception)
    {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'event_id not a uuid' => [['event_id' => 'abc'], 'event_id'],
    'type without namespace' => [['type' => 'ping'], 'type'],
    'type in upper case' => [['type' => 'Platform.Ping'], 'type'],
    'schema_version zero' => [['schema_version' => 0], 'schema_version'],
    'occurred_at without offset' => [['occurred_at' => '2026-10-07 15:30:00'], 'occurred_at'],
    'no sender' => [['sender' => null], 'sender'],
    'no recipient' => [['recipient' => null], 'recipient'],
    'no typology' => [['typology' => null], 'typology'],
    'typology with spaces' => [['typology' => 'dottori commercialisti'], 'typology'],
    'correlation_id not a uuid' => [['correlation_id' => 'ping'], 'correlation_id'],
    'payload not an object' => [['payload' => 'challenge'], 'payload'],
]);

it('E1: accepts RFC 3339 dates with an offset, Z or fractions of a second', function (string $occurredAt): void {
    $event = EnvelopeData::validateAndCreate(envelope(['occurred_at' => $occurredAt]));

    expect($event->occurred_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-07 13:30:00');
})->with(['2026-10-07T15:30:00+02:00', '2026-10-07T13:30:00Z', '2026-10-07T13:30:00.250Z']);

it('W2: reads a delivered envelope ignoring the fields it does not know', function (): void {
    $event = EnvelopeData::from(envelope(['delivered_with' => 'a field added later']));

    expect($event->event_id)->toBe(envelope()['event_id'])
        ->and($event->isVerification())->toBeFalse();
});

it('S7: recognises the verification ping of the platform', function (): void {
    $verification = EnvelopeData::from(envelope(['sender' => null, 'recipient' => null, 'typology' => null]));
    $pongWithoutSender = EnvelopeData::from(envelope(['type' => Contract::PONG, 'sender' => null]));

    expect($verification->isVerification())->toBeTrue()
        ->and($pongWithoutSender->isVerification())->toBeFalse();
});

it('P1: replies from the recipient to the sender, same typology, correlated to the event', function (): void {
    $ping = EnvelopeData::from(envelope());

    $pong = $ping->reply(Contract::PONG, 1, ['challenge' => 'a1b2c3d4e5f6a7b8'], eventId: '0199b6f3-0000-7000-8000-000000000001');

    expect($pong)
        ->event_id->toBe('0199b6f3-0000-7000-8000-000000000001')
        ->type->toBe(Contract::PONG)
        ->sender->toBe($ping->recipient)
        ->recipient->toBe($ping->sender)
        ->typology->toBe('commercialisti')
        ->correlation_id->toBe($ping->event_id)
        ->payload->toBe(['challenge' => 'a1b2c3d4e5f6a7b8']);
});

it('L6: recognises an event of the platform, without sender, only of a type the platform sends', function (array $overrides, bool $isFromPlatform): void {
    expect(EnvelopeData::from(envelope($overrides))->isFromPlatform())->toBe($isFromPlatform);
})->with([
    'verification' => [['sender' => null, 'recipient' => null, 'typology' => null], true],
    'selection' => [['type' => Contract::COUNTERPARTY_SELECTED, 'sender' => null, 'correlation_id' => recordedTransaction()['id'], 'payload' => counterpartySelected()], true],
    'withdrawal' => [['type' => Contract::TRANSACTION_WITHDRAWN, 'sender' => null, 'correlation_id' => recordedTransaction()['id'], 'payload' => transactionWithdrawn()], true],
    'pong without sender' => [['type' => Contract::PONG, 'sender' => null], false],
    'ping from a member' => [[], false],
    'selection with a sender' => [['type' => Contract::COUNTERPARTY_SELECTED], false],
]);

it('P1 and L6: has nobody to reply to on an event of the platform', function (array $overrides): void {
    EnvelopeData::from(envelope($overrides))->reply(Contract::PONG, 1, ['challenge' => 'a1b2c3d4e5f6a7b8']);
})->throws(LogicException::class)->with([
    'verification' => [['sender' => null, 'recipient' => null, 'typology' => null]],
    'application received' => [['type' => Contract::APPLICATION_RECEIVED, 'sender' => null, 'payload' => []]],
]);

it('E1: sends the date in RFC 3339 and an empty payload as a JSON object', function (): void {
    $wire = EnvelopeData::from(envelope(['occurred_at' => '2026-10-07T13:30:00.250Z', 'payload' => []]))->toWire();

    expect($wire['occurred_at'])->toBe('2026-10-07T13:30:00+00:00')
        ->and(json_encode($wire['payload']))->toBe('{}')
        ->and(array_keys($wire))->toBe(array_keys(envelope()));
});

it('E12: makes an event with the ids of its files, and sends no files key without files', function (): void {
    $withFiles = EnvelopeData::make(Contract::PING, 1, envelope()['sender'], envelope()['recipient'], 'commercialisti', ['challenge' => 'a1b2c3d4e5f6a7b8'], files: ['0199c1a0-0000-7000-8000-000000000001']);
    $withoutFiles = EnvelopeData::make(Contract::PING, 1, envelope()['sender'], envelope()['recipient'], 'commercialisti', ['challenge' => 'a1b2c3d4e5f6a7b8']);

    expect($withFiles->toWire()['files'])->toBe(['0199c1a0-0000-7000-8000-000000000001'])
        ->and($withFiles->fileIds())->toBe(['0199c1a0-0000-7000-8000-000000000001'])
        ->and($withoutFiles->toWire())->not->toHaveKey('files')
        ->and($withoutFiles->fileIds())->toBe([])
        ->and($withoutFiles->fileReferences())->toBe([]);
});

it('E12: reads the files of a delivered envelope, with what it takes to download and check them', function (): void {
    $delivered = EnvelopeData::from(envelope(['files' => [[
        'id' => '0199c1a0-0000-7000-8000-000000000001',
        'name' => 'Guida.pdf',
        'media_type' => 'application/pdf',
        'size' => 482113,
        'sha256' => str_repeat('a', 64),
        'available_until' => '2026-11-15T10:00:00Z',
    ]]]));

    expect($delivered->fileIds())->toBe(['0199c1a0-0000-7000-8000-000000000001'])
        ->and($delivered->fileReferences()[0])
        ->name->toBe('Guida.pdf')
        ->size->toBe(482113)
        ->and($delivered->fileReferences()[0]->available_until?->toIso8601ZuluString())->toBe('2026-11-15T10:00:00Z')
        ->and($delivered->toWire()['files'])->toBe(['0199c1a0-0000-7000-8000-000000000001'])
        ->and($delivered->reply(Contract::PONG, 1, ['challenge' => 'a1b2c3d4e5f6a7b8'])->fileIds())->toBe([]);
});

it('E12: refuses files that are not a list of distinct ids, or more than 20', function (array $files, string $field): void {
    try
    {
        EnvelopeData::make(Contract::PING, 1, envelope()['sender'], envelope()['recipient'], 'commercialisti', ['challenge' => 'a1b2c3d4e5f6a7b8'], files: $files);
        $this->fail('The envelope should be refused.');
    }
    catch (ValidationException $exception)
    {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'not an id' => [['guida.pdf'], 'files.0'],
    'the same file twice' => [['0199c1a0-0000-7000-8000-000000000001', '0199c1a0-0000-7000-8000-000000000001'], 'files.1'],
    '21 files' => [array_map(static fn (int $index): string => sprintf('0199c1a0-0000-7000-8000-%012d', $index), range(1, 21)), 'files'],
]);
