<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformEvent;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformEventOutbox;
use ITuoiProfessionistiDigitali\Connector\Outbox\EventOutbox;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->outbox = resolve(EventOutbox::class);
});

it('E2: keeps the envelope before sending it and queues it', function (): void {
    $envelope = EnvelopeData::from(envelope());

    $row = $this->outbox->queue($envelope);

    expect($row->refresh())
        ->event_id->toBe($envelope->event_id)
        ->type->toBe('platform.ping')
        ->envelope->toBe($envelope->toArray())
        ->status->toBe(OutboxStatus::Pending)
        ->attempts->toBe(0)
        ->sent_at->toBeNull();
    Queue::assertPushed(SendPlatformEvent::class, fn (SendPlatformEvent $job): bool => $job->eventId === $envelope->event_id);
});

it('E2: keeps the same envelope once, whatever its status', function (): void {
    $envelope = EnvelopeData::from(envelope());
    $this->outbox->queue($envelope);
    PlatformEventOutbox::query()->update(['status' => OutboxStatus::Sent]);

    $row = $this->outbox->queue(EnvelopeData::from(envelope()));

    expect(PlatformEventOutbox::query()->count())->toBe(1)
        ->and($row->status)->toBe(OutboxStatus::Sent);
    Queue::assertPushed(SendPlatformEvent::class, 1);
});

it('E2: refuses an event_id kept with another content: it is a new event, with a new event_id', function (): void {
    $this->outbox->queue(EnvelopeData::from(envelope()));

    $this->outbox->queue(EnvelopeData::from(envelope(['payload' => ['challenge' => 'f6e5d4c3b2a1f0e9']])));
})->throws(LogicException::class);

it('E1: refuses an envelope built without validation', function (): void {
    $this->outbox->queue(EnvelopeData::from(envelope(['sender' => null])));
})->throws(ValidationException::class);

it('keeps the envelope in the transaction of the caller and sends it only after the commit', function (): void {
    try
    {
        DB::transaction(function (): void {
            $this->outbox->queue(EnvelopeData::from(envelope()));

            throw new RuntimeException('rollback');
        });
    }
    catch (RuntimeException)
    {
    }

    expect(PlatformEventOutbox::query()->count())->toBe(0);
    // The fake of the queue ignores afterCommit: the job carries it to the real one
    Queue::assertPushed(SendPlatformEvent::class, fn (SendPlatformEvent $job): bool => $job->afterCommit === true);
});
