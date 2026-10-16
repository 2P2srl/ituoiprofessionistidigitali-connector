<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\ProfessionalRecordData;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformTransactionOutbox;
use ITuoiProfessionistiDigitali\Connector\Outbox\EventOutbox;
use ITuoiProfessionistiDigitali\Connector\Outbox\ProfessionalOutbox;
use ITuoiProfessionistiDigitali\Connector\Testing\PlatformOutbox;
use ITuoiProfessionistiDigitali\Connector\Tests\Fixtures\Assignment;
use PHPUnit\Framework\AssertionFailedError;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

it('R9: passes when the outbox has the current version, and only then', function (): void {
    $assignment = sentAssignment();

    PlatformOutbox::assertRecorded($assignment);

    PlatformTransactionOutbox::query()->update(['payload' => transaction()]);

    expect(fn () => PlatformOutbox::assertRecorded($assignment))->toThrow(AssertionFailedError::class, 'versione corrente');

    PlatformTransactionOutbox::query()->delete();

    expect(fn () => PlatformOutbox::assertRecorded($assignment))->toThrow(AssertionFailedError::class, 'non è nella outbox')
        ->and(fn () => PlatformOutbox::assertRecorded(new Assignment(['status' => 'invited'])))->toThrow(AssertionFailedError::class, 'bozza');
});

it('R9: tells that a draft never reached the outbox', function (): void {
    $draft = Assignment::query()->create(['status' => 'invited']);

    PlatformOutbox::assertNotRecorded($draft);

    $sent = sentAssignment();

    expect(fn () => PlatformOutbox::assertNotRecorded($sent))->toThrow(AssertionFailedError::class, 'è nella outbox');
});

it('R18: passes when the outbox has the declaration, and only then', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:20:00+02:00'));
    $record = ProfessionalRecordData::from(professional());
    sentAssignment();

    expect(fn () => PlatformOutbox::assertDeclared('RSSMRA80A01H501U', $record))->toThrow(AssertionFailedError::class, 'non è nella outbox');

    resolve(ProfessionalOutbox::class)->declare('RSSMRA80A01H501U', $record);

    PlatformOutbox::assertDeclared('RSSMRA80A01H501U', $record);

    expect(fn () => PlatformOutbox::assertDeclared('RSSMRA80A01H501U', ProfessionalRecordData::from(professional(['province' => 'BA']))))
        ->toThrow(AssertionFailedError::class, 'ultima dichiarazione');
});

it('E2: passes when the outbox keeps an event of the type, matching the callback, and only then', function (): void {
    resolve(EventOutbox::class)->queue(EnvelopeData::from(envelope()));

    PlatformOutbox::assertEventQueued(Contract::PING);
    PlatformOutbox::assertEventQueued(Contract::PING, fn (EnvelopeData $envelope): bool => $envelope->payload === envelope()['payload']);
    PlatformOutbox::assertEventNotQueued(Contract::PONG);

    expect(fn () => PlatformOutbox::assertEventQueued(Contract::PONG))->toThrow(AssertionFailedError::class, 'platform.pong')
        ->and(fn () => PlatformOutbox::assertEventQueued(Contract::PING, fn (EnvelopeData $envelope): bool => $envelope->recipient === null))->toThrow(AssertionFailedError::class, 'platform.ping')
        ->and(fn () => PlatformOutbox::assertEventNotQueued(Contract::PING))->toThrow(AssertionFailedError::class, 'platform.ping');
});
