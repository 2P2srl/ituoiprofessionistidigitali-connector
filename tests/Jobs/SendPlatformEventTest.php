<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformEvent;
use ITuoiProfessionistiDigitali\Connector\Outbox\EventOutbox;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->row = resolve(EventOutbox::class)->queue(EnvelopeData::from(envelope()));
});

function sendEvent(): void
{
    resolve(SendPlatformEvent::class, ['eventId' => envelope()['event_id']])->handle(resolve(PlatformClient::class), resolve(ConnectorConfig::class));
}

function platformAnsweringEvents(int $status, array $body = []): void
{
    withToken([
        'platform.test/api/v1/event-types' => Http::response(['data' => catalogue()]),
        'platform.test/api/v1/events' => Http::response($body, $status),
    ]);
}

function acceptedEvent(): array
{
    return ['data' => ['event_id' => envelope()['event_id'], 'type' => Contract::PING, 'received_at' => '2026-10-16T08:30:00Z', 'status' => 'queued']];
}

it('E8: sends the envelope kept and marks it sent', function (int $status): void {
    platformAnsweringEvents($status, acceptedEvent());

    sendEvent();

    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Sent)
        ->attempts->toBe(1)
        ->last_error->toBeNull()
        ->sent_at->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/events')
        && $request->data()['event_id'] === envelope()['event_id']
        && $request->data()['payload'] === envelope()['payload']);
})->with([202, 200]);

it('E2: sends again the same envelope after a network error', function (): void {
    withToken([
        'platform.test/api/v1/event-types' => Http::response(['data' => catalogue()]),
        'platform.test/api/v1/events' => Http::sequence()
            ->pushFailedConnection()
            ->pushFailedConnection()
            ->pushFailedConnection()
            ->push(acceptedEvent(), 200),
    ]);

    expect(fn () => sendEvent())->toThrow(ConnectionException::class);
    sendEvent();

    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Sent)
        ->attempts->toBe(2);
    Http::assertSentCount(6);
});

it('stops at a contract violation or a conflict, with the error kept', function (int $status): void {
    platformAnsweringEvents($status, ['message' => 'Rifiutato.', 'errors' => ['payload.challenge' => ['Troppo corta.']]]);

    sendEvent();

    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Failed)
        ->last_error->toContain('Rifiutato.')
        ->last_error->toContain('payload.challenge');
})->with([422, 409]);

it('E7: stops at a payload the catalogue refuses, before the request', function (): void {
    platformAnsweringEvents(202, acceptedEvent());
    $this->row->update(['envelope' => [...$this->row->envelope, 'payload' => ['challenge' => 'short']]]);

    sendEvent();

    expect($this->row->refresh()->status)->toBe(OutboxStatus::Failed);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/events'));
});

it('E1: stops at an envelope kept without a sender', function (): void {
    $this->row->update(['envelope' => [...$this->row->envelope, 'sender' => null]]);

    sendEvent();

    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Failed)
        ->last_error->toContain('sender');
});

it('A6: leaves a system not yet active to the next attempt', function (int $status): void {
    platformAnsweringEvents($status, ['message' => 'In attesa.', 'reason' => 'pending']);

    expect(fn () => sendEvent())->toThrow(PlatformRequestException::class);

    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Pending)
        ->last_error->toContain('In attesa.');
})->with([403, 503]);

it('waits while the system is not connected', function (): void {
    $this->app->instance(ConnectorConfig::class, new ConnectorConfig);

    sendEvent();

    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Pending)
        ->attempts->toBe(0)
        ->last_error->toBe('Il sistema non è collegato al portale.');
});

it('does nothing for an event already sent, failed or unknown', function (OutboxStatus $status): void {
    $this->row->update(['status' => $status]);

    sendEvent();
    resolve(SendPlatformEvent::class, ['eventId' => '0199b6f2-0000-7000-8000-000000000000'])->handle(resolve(PlatformClient::class), resolve(ConnectorConfig::class));

    expect($this->row->refresh()->attempts)->toBe(0);
})->with([OutboxStatus::Sent, OutboxStatus::Failed]);

it('T7: puts a throttled event back in the queue after the seconds of Retry-After', function (): void {
    withToken([
        'platform.test/api/v1/event-types' => Http::response(['data' => catalogue()]),
        'platform.test/api/v1/events' => Http::response(['message' => 'Troppe richieste.'], 429, ['Retry-After' => '120']),
    ]);
    $job = new SendPlatformEvent(envelope()['event_id'])->withFakeQueueInteractions();

    $job->handle(resolve(PlatformClient::class), resolve(ConnectorConfig::class));

    $job->assertReleased(delay: 120);
    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Pending)
        ->last_error->toContain('Troppe richieste.');
});
