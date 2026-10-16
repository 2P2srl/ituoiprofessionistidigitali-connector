<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\ActivitiesAssignedData;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\OutgoingFileData;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformEvent;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformEventOutbox;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformEventOutboxFile;
use ITuoiProfessionistiDigitali\Connector\Outbox\EventOutbox;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;

uses(RefreshDatabase::class);

const GUIDE_CONTENT = "%PDF-1.7\nGuida\n";

const PORTAL_FILE_ID = '0199c1a0-0000-7000-8000-0000000000aa';

beforeEach(function (): void {
    Queue::fake();
    Sleep::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::disk('local')->put('guide/fatture.pdf', GUIDE_CONTENT);

    $this->file = OutgoingFileData::make('local', 'guide/fatture.pdf', 'Guida registrazione fatture.pdf', 'application/pdf');
    $payload = activitiesAssigned(['guides' => [['title' => 'Registrazione fatture', 'body' => 'Testo.', 'attachments' => [$this->file->ref]]]]);
    $this->envelope = ActivitiesAssignedData::from($payload)->toEnvelope('0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43', '0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55', 'commercialisti');
    $this->row = resolve(EventOutbox::class)->queue($this->envelope, [$this->file]);
});

function sendEventWithFiles(): void
{
    resolve(SendPlatformEvent::class, ['eventId' => test()->envelope->event_id])->handle(resolve(PlatformClient::class), resolve(ConnectorConfig::class));
}

/**
 * @return array<string, mixed>
 */
function uploaded(array $overrides = []): array
{
    return [
        'id' => PORTAL_FILE_ID,
        'upload_id' => '0199c19f-0000-7000-8000-000000000001',
        'sender' => '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43',
        'recipient' => '0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55',
        'name' => 'Guida registrazione fatture.pdf',
        'media_type' => 'application/pdf',
        'size' => mb_strlen(GUIDE_CONTENT, '8bit'),
        'sha256' => hash('sha256', GUIDE_CONTENT),
        'status' => 'uploaded',
        'citable_until' => '2026-10-17T10:00:00Z',
        'available_until' => null,
        ...$overrides,
    ];
}

/**
 * The platform answering the upload, the status of the file and the event, after the token and the catalogue.
 */
function platformTakingFiles(array $routes): void
{
    withToken([
        'platform.test/api/v1/event-types' => Http::response(['data' => catalogue([Contract::ACTIVITIES_ASSIGNED])]),
        ...$routes,
    ]);
}

function acceptedWithFiles(): array
{
    return ['data' => ['event_id' => test()->envelope->event_id, 'type' => Contract::ACTIVITIES_ASSIGNED, 'received_at' => '2026-10-16T08:30:00Z', 'status' => 'queued']];
}

it('F3: keeps the files of the event with their SHA-256, read when the event enters the outbox', function (): void {
    $file = PlatformEventOutboxFile::query()->sole();

    expect($file)
        ->event_id->toBe($this->envelope->event_id)
        ->ref->toBe($this->file->ref)
        ->sha256->toBe(hash('sha256', GUIDE_CONTENT))
        ->upload_id->toBeNull()
        ->file_id->toBeNull()
        ->and($this->row->envelope['files'])->toBe([$this->file->ref]);
});

it('E12: refuses files that are not the ones the envelope lists', function (array $files): void {
    $envelope = EnvelopeData::make(Contract::PING, 1, envelope()['sender'], envelope()['recipient'], 'commercialisti', ['challenge' => 'a1b2c3d4e5f6a7b8'], files: ['0199c1a0-0000-7000-8000-000000000009']);

    resolve(EventOutbox::class)->queue($envelope, $files);
})->with([
    'no file for an id of the envelope' => [[]],
    'a file the envelope does not list' => [fn (): array => [OutgoingFileData::make('local', 'guide/fatture.pdf', 'Guida.pdf', 'application/pdf')]],
])->throws(LogicException::class);

it('F1 and E12: uploads the files, then sends the event with the ids of the platform in place of the refs', function (): void {
    platformTakingFiles([
        'platform.test/api/v1/files' => Http::response(['data' => uploaded()], 201),
        'platform.test/api/v1/events' => Http::response(acceptedWithFiles(), 202),
    ]);

    sendEventWithFiles();

    $file = PlatformEventOutboxFile::query()->sole();
    expect($this->row->refresh()->status)->toBe(OutboxStatus::Sent)
        ->and($file->file_id)->toBe(PORTAL_FILE_ID)
        ->and($file->upload_id)->not->toBeNull()
        ->and($file->citable_until?->toIso8601ZuluString())->toBe('2026-10-17T10:00:00Z');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/files')
        && array_column($request->data(), 'contents', 'name')['upload_id'] === $file->upload_id
        && array_column($request->data(), 'contents', 'name')['recipient'] === '0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/events')
        && $request->data()['files'] === [PORTAL_FILE_ID]
        && $request->data()['payload']['processes'][0]['activities'][0]['guides'][0]['attachments'] === [PORTAL_FILE_ID]
        && !str_contains($request->body(), $this->file->ref));
});

it('F5: after an event that may have arrived, sends the same envelope again when its files are sent, without uploading them', function (): void {
    platformTakingFiles([
        'platform.test/api/v1/files' => Http::response(['data' => uploaded()], 201),
        'platform.test/api/v1/files/*/status' => Http::response(['data' => uploaded(['status' => 'sent'])]),
        'platform.test/api/v1/events' => Http::sequence()->push(['message' => 'Errore.'], 503)->push(['message' => 'Errore.'], 503)->push(['message' => 'Errore.'], 503)->push(acceptedWithFiles(), 200),
    ]);

    expect(fn () => sendEventWithFiles())->toThrow(ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException::class);
    sendEventWithFiles();

    expect($this->row->refresh()->status)->toBe(OutboxStatus::Sent);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/files')))->toHaveCount(1);
});

it('F5: uploads again with a new upload_id the files that expired before the event arrived', function (): void {
    platformTakingFiles([
        'platform.test/api/v1/files' => Http::sequence()->push(['data' => uploaded()], 201)->push(['data' => uploaded(['id' => '0199c1a0-0000-7000-8000-0000000000bb', 'upload_id' => '0199c19f-0000-7000-8000-000000000002'])], 201),
        'platform.test/api/v1/files/*/status' => Http::response(['data' => uploaded(['status' => 'expired', 'name' => null])]),
        'platform.test/api/v1/events' => Http::sequence()->pushFailedConnection()->pushFailedConnection()->pushFailedConnection()->push(acceptedWithFiles(), 202),
    ]);

    expect(fn () => sendEventWithFiles())->toThrow(Illuminate\Http\Client\ConnectionException::class);
    $firstUpload = PlatformEventOutboxFile::query()->sole()->upload_id;
    sendEventWithFiles();

    $file = PlatformEventOutboxFile::query()->sole();
    expect($file->file_id)->toBe('0199c1a0-0000-7000-8000-0000000000bb')
        ->and($file->upload_id)->not->toBe($firstUpload)
        ->and($this->row->refresh()->status)->toBe(OutboxStatus::Sent);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/events') && $request->data()['files'] === ['0199c1a0-0000-7000-8000-0000000000bb']);
});

it('F2: uploads with a new upload_id after a 410 of an orphaned upload', function (): void {
    platformTakingFiles([
        'platform.test/api/v1/files' => Http::sequence()->push(['message' => 'Caricamento scaduto.'], 410)->push(['data' => uploaded()], 201),
        'platform.test/api/v1/events' => Http::response(acceptedWithFiles(), 202),
    ]);

    sendEventWithFiles();

    $uploads = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/files'))->values();
    expect($this->row->refresh()->status)->toBe(OutboxStatus::Sent)
        ->and(array_column($uploads[0][0]->data(), 'contents', 'name')['upload_id'])->not->toBe(array_column($uploads[1][0]->data(), 'contents', 'name')['upload_id']);
});

it('F3: stops when the file changed or disappeared after the event entered the outbox', function (Closure $change): void {
    platformTakingFiles([]);
    $change();

    sendEventWithFiles();

    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Failed)
        ->last_error->toContain('guide/fatture.pdf');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/files') || str_ends_with($request->url(), '/events'));
})->with([
    'changed' => [fn () => Storage::disk('local')->put('guide/fatture.pdf', 'altro')],
    'deleted' => [fn () => Storage::disk('local')->delete('guide/fatture.pdf')],
]);

it('F4: stops at a file the platform refuses', function (): void {
    platformTakingFiles(['platform.test/api/v1/files' => Http::response(['message' => 'Tipo non ammesso.', 'errors' => ['media_type' => ['Tipo non ammesso.']]], 422)]);

    sendEventWithFiles();

    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Failed)
        ->last_error->toContain('media_type');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/events'));
});

it('T7 and F11: puts the event back in the queue when the quota of the files is full', function (): void {
    platformTakingFiles(['platform.test/api/v1/files' => Http::response(['message' => 'Quota piena.'], 429, ['Retry-After' => '900'])]);
    $job = new SendPlatformEvent($this->envelope->event_id)->withFakeQueueInteractions();

    $job->handle(resolve(PlatformClient::class), resolve(ConnectorConfig::class));

    $job->assertReleased(delay: 900);
    expect($this->row->refresh()->status)->toBe(OutboxStatus::Pending);
});

it('E2: keeps the same envelope with its files once', function (): void {
    resolve(EventOutbox::class)->queue($this->envelope, [$this->file]);

    expect(PlatformEventOutbox::query()->count())->toBe(1)
        ->and(PlatformEventOutboxFile::query()->count())->toBe(1);
});
