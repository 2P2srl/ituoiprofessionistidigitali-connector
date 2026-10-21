<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Events\PlatformEventReceived;
use ITuoiProfessionistiDigitali\Connector\Jobs\ReceivePlatformFiles;
use ITuoiProfessionistiDigitali\Connector\Signature\WebhookSignature;
use ITuoiProfessionistiDigitali\Connector\Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Event::fake();
});

/**
 * Posts a body to the webhook as the platform does, signed with the configured secret.
 */
function deliver(TestCase $test, string $body): TestResponse
{
    $timestamp = CarbonImmutable::now()->getTimestamp();

    return $test->call('POST', route('platform.webhook'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PLATFORM_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_PLATFORM_SIGNATURE' => WebhookSignature::sign($body, $timestamp, 'signing-secret'),
    ], content: $body);
}

it('S7: answers the verification ping with its challenge', function (): void {
    deliver($this, json_encode(envelope(['sender' => null, 'recipient' => null, 'typology' => null])))
        ->assertOk()
        ->assertExactJson(['challenge' => 'a1b2c3d4e5f6a7b8']);

    Event::assertNotDispatched(PlatformEventReceived::class);
});

it('W5: hands an event to the application and answers 2xx', function (): void {
    deliver($this, json_encode(envelope(['type' => 'coworking.request', 'payload' => ['unknown' => true]])))
        ->assertNoContent();

    Event::assertDispatched(
        PlatformEventReceived::class,
        fn (PlatformEventReceived $event): bool => $event->envelope->type === 'coworking.request',
    );
});

it('W1: answers 400 to a body that is not an envelope', function (string $body): void {
    deliver($this, $body)->assertBadRequest();

    Event::assertNotDispatched(PlatformEventReceived::class);
})->with([
    'not json' => ['not json'],
    'missing fields' => [json_encode(['event_id' => 'x'])],
    'wrong type' => [json_encode(envelope(['schema_version' => 'one']))],
]);

it('W5: answers 2xx to an event without sender, which the package does not know, so the platform does not repeat it', function (string $type, int $version): void {
    Log::spy();

    deliver($this, json_encode(envelope(['type' => $type, 'schema_version' => $version, 'sender' => null, 'payload' => ['reference' => 'A-1']])))
        ->assertAccepted()
        ->assertExactJson(['message' => 'Tipo di evento sconosciuto: ignorato.']);

    Event::assertNotDispatched(PlatformEventReceived::class);
    Log::shouldHaveReceived('warning')->once()->with(
        'Evento del portale di un tipo o di una versione sconosciuti al pacchetto: ignorato. Aggiorna ituoiprofessionistidigitali/connector.',
        ['event_id' => envelope()['event_id'], 'type' => $type, 'schema_version' => $version],
    );
})->with([
    'a newer type of the platform' => ['transaction.unknown', 1],
    'a type of the members' => [Contract::PONG, 1],
    'an event between firms' => [Contract::CONTRACT_DETAILS, 1],
]);

it('F8: answers 2xx to an event with files and hands it to the application only after the download, in the queue', function (): void {
    Queue::fake();
    $files = [['id' => '0199c1a0-0000-7000-8000-000000000001', 'name' => 'Guida.pdf', 'media_type' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('a', 64), 'available_until' => '2026-11-15T10:00:00Z']];

    deliver($this, json_encode(envelope(['type' => Contract::ACTIVITIES_ASSIGNED, 'payload' => activitiesAssigned(), 'files' => $files])))
        ->assertNoContent();

    Event::assertNotDispatched(PlatformEventReceived::class);
    Queue::assertPushed(ReceivePlatformFiles::class, fn (ReceivePlatformFiles $job): bool => $job->envelope->event_id === envelope()['event_id'] && $job->uniqueId() === envelope()['event_id']);
});
