<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Events\PlatformEventReceived;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformTransactionOutbox;
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
    'selection with a broken payload' => [json_encode(envelope(['type' => Contract::COUNTERPARTY_SELECTED, 'sender' => null, 'payload' => ['transaction' => []]]))],
    'withdrawal with a broken payload' => [json_encode(envelope(['type' => Contract::TRANSACTION_WITHDRAWN, 'sender' => null, 'payload' => ['transaction' => withdrawnTransaction(), 'reason' => 'operator']]))],
]);

it('W5: answers 2xx to an event without sender of a type or version the package does not know, so the platform does not repeat it', function (string $type, int $version): void {
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
    'a newer version of a type of the platform' => [Contract::COUNTERPARTY_SELECTED, 2],
    'a type of the members' => [Contract::PONG, 1],
]);

it('L6: hands the events of the platform, without sender, to the application', function (string $type, array $payload): void {
    deliver($this, json_encode(envelope(['type' => $type, 'sender' => null, 'correlation_id' => recordedTransaction()['id'], 'payload' => $payload])))
        ->assertNoContent();

    Event::assertDispatched(PlatformEventReceived::class, fn (PlatformEventReceived $event): bool => $event->envelope->type === $type && $event->envelope->isFromPlatform());
})->with([
    'application received' => [Contract::APPLICATION_RECEIVED, applicationEvent()],
    'application withdrawn' => [Contract::APPLICATION_WITHDRAWN, applicationEvent(['status' => 'withdrawn', 'closed_at' => '2026-10-11T08:00:00Z'])],
    'counterparty selected' => [Contract::COUNTERPARTY_SELECTED, counterpartySelected()],
    'transaction withdrawn' => [Contract::TRANSACTION_WITHDRAWN, transactionWithdrawn()],
]);

it('L4 and L9: keeps the selection or the withdrawal of the platform in the outbox before the listeners of the application run', function (string $type, callable $payload, string $status): void {
    // The real dispatcher: the outbox follows the events of the models, and the listener must run
    $events = Event::getFacadeRoot()->dispatcher;
    Event::swap($events);
    Model::setEventDispatcher($events);
    Queue::fake();
    $assignment = confirmedAssignment();
    $seen = null;
    Event::listen(PlatformEventReceived::class, function () use (&$seen): void {
        $seen = PlatformTransactionOutbox::query()->sole()->only(['revision', 'sent_revision']);
    });

    deliver($this, json_encode(envelope(['type' => $type, 'sender' => null, 'payload' => $payload(['reference' => $assignment->uuid])])))
        ->assertNoContent();

    expect($seen)->toBe(['revision' => 2, 'sent_revision' => 2])
        ->and(PlatformTransactionOutbox::query()->sole()->payload['status'])->toBe($status);
})->with([
    'selection' => [Contract::COUNTERPARTY_SELECTED, 'counterpartySelected', 'accepted'],
    'withdrawal' => [Contract::TRANSACTION_WITHDRAWN, 'transactionWithdrawn', 'withdrawn'],
]);
