<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformEvent;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformProfessional;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformTransaction;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformEventOutbox;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformProfessionalOutbox;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformTransactionOutbox;

uses(RefreshDatabase::class);

function outboxRow(string $reference, array $state): PlatformTransactionOutbox
{
    return PlatformTransactionOutbox::query()->create([
        'reference' => $reference,
        'revision' => 2,
        'sent_revision' => null,
        'payload' => transaction(),
        'status' => OutboxStatus::Pending,
        ...$state,
    ]);
}

it('queues again only the versions not yet confirmed', function (): void {
    Queue::fake();
    outboxRow('never-sent', []);
    outboxRow('behind', ['sent_revision' => 1]);
    outboxRow('confirmed', ['sent_revision' => 2, 'status' => OutboxStatus::Sent]);
    outboxRow('failed', ['status' => OutboxStatus::Failed]);

    $this->artisan('platform:send-outbox')->expectsOutputToContain('Transazioni rimesse in coda: 2.')->assertSuccessful();

    Queue::assertPushed(SendPlatformTransaction::class, 2);
    Queue::assertPushed(SendPlatformTransaction::class, fn (SendPlatformTransaction $job): bool => $job->reference === 'behind');
});

it('R18: queues again the declarations still pending', function (): void {
    Queue::fake();
    foreach (['RSSMRA80A01H501U' => OutboxStatus::Pending, 'BNCLRA85T41F205Y' => OutboxStatus::Discarded, 'VRDGPP70A01F205Y' => OutboxStatus::Sent] as $taxCode => $status)
    {
        PlatformProfessionalOutbox::query()->create(['tax_code' => $taxCode, 'revision' => 1, 'payload' => professional(), 'status' => $status]);
    }

    $this->artisan('platform:send-outbox')->expectsOutputToContain('Anagrafiche rimesse in coda: 1.')->assertSuccessful();

    Queue::assertPushed(SendPlatformProfessional::class, fn (SendPlatformProfessional $job): bool => $job->taxCode === 'RSSMRA80A01H501U');
    Queue::assertPushed(SendPlatformProfessional::class, 1);
});

it('E2: queues again the events still pending', function (): void {
    Queue::fake();
    foreach (['0199b6f2-0000-7000-8000-000000000001' => OutboxStatus::Pending, '0199b6f2-0000-7000-8000-000000000002' => OutboxStatus::Sent, '0199b6f2-0000-7000-8000-000000000003' => OutboxStatus::Failed] as $eventId => $status)
    {
        PlatformEventOutbox::query()->create(['event_id' => $eventId, 'type' => 'platform.ping', 'envelope' => envelope(['event_id' => $eventId]), 'status' => $status]);
    }

    $this->artisan('platform:send-outbox')->expectsOutputToContain('Eventi rimessi in coda: 1.')->assertSuccessful();

    Queue::assertPushed(SendPlatformEvent::class, fn (SendPlatformEvent $job): bool => $job->eventId === '0199b6f2-0000-7000-8000-000000000001');
    Queue::assertPushed(SendPlatformEvent::class, 1);
});

it('does nothing while the system is not connected', function (): void {
    Queue::fake();
    outboxRow('never-sent', []);
    $this->app->instance(ConnectorConfig::class, new ConnectorConfig);

    $this->artisan('platform:send-outbox')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('runs every five minutes', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'platform:send-outbox'));

    expect($event?->expression)->toBe('*/5 * * * *');
});
