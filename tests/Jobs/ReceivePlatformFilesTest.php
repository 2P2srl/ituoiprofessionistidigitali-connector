<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Events\PlatformEventReceived;
use ITuoiProfessionistiDigitali\Connector\Exceptions\FileIntegrityException;
use ITuoiProfessionistiDigitali\Connector\Jobs\ReceivePlatformFiles;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;

const RECEIVED_CONTENT = "%PDF-1.7\nGuida ricevuta\n";

const RECEIVED_FILE = '0199c1a0-0000-7000-8000-000000000001';

beforeEach(function (): void {
    Event::fake();
    Sleep::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-16T10:00:00Z'));
    $this->envelope = envelope(['type' => Contract::ACTIVITIES_ASSIGNED, 'payload' => activitiesAssigned(), 'files' => [[
        'id' => RECEIVED_FILE,
        'name' => 'Guida registrazione fatture.pdf',
        'media_type' => 'application/pdf',
        'size' => mb_strlen(RECEIVED_CONTENT, '8bit'),
        'sha256' => hash('sha256', RECEIVED_CONTENT),
        'available_until' => '2026-11-15T10:00:00Z',
    ]]]);
});

function receive(): void
{
    new ReceivePlatformFiles(test()->envelope)->handle(resolve(PlatformClient::class), resolve(ITuoiProfessionistiDigitali\Connector\ConnectorConfig::class), resolve(Illuminate\Contracts\Events\Dispatcher::class));
}

function platformServing(mixed $download, mixed $receipt = null): void
{
    withToken([
        'platform.test/api/v1/files/*/receipt' => $receipt ?? Http::response(['data' => ['id' => RECEIVED_FILE, 'upload_id' => '0199c19f-0000-7000-8000-000000000001', 'sender' => envelope()['sender'], 'recipient' => envelope()['recipient'], 'name' => null, 'media_type' => 'application/pdf', 'size' => 1, 'sha256' => hash('sha256', RECEIVED_CONTENT), 'status' => 'received', 'citable_until' => null, 'available_until' => null]]),
        'platform.test/api/v1/files/*' => $download,
    ]);
}

function storedPath(): string
{
    return 'platform-files/'.envelope()['event_id'].'/'.RECEIVED_FILE;
}

it('F8: downloads, checks and saves the files, confirms them, then hands the event to the application', function (): void {
    platformServing(Http::response(RECEIVED_CONTENT));

    receive();

    expect(Storage::disk('local')->get(storedPath()))->toBe(RECEIVED_CONTENT);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/receipt') && $request->data() === ['sha256' => hash('sha256', RECEIVED_CONTENT)]);
    Event::assertDispatched(PlatformEventReceived::class, function (PlatformEventReceived $event): bool {
        $file = $event->files[0];

        return $event->envelope->event_id === envelope()['event_id']
            && $file->id === RECEIVED_FILE
            && $file->disk === 'local'
            && $file->path === storedPath()
            && $file->name === 'Guida registrazione fatture.pdf'
            && $file->sha256 === hash('sha256', RECEIVED_CONTENT)
            && !$file->isMissing();
    });
});

it('W9: takes a file already saved without downloading it again, and confirms it again', function (): void {
    Storage::disk('local')->put(storedPath(), RECEIVED_CONTENT);
    platformServing(Http::response(['message' => 'Già confermato.'], 410));

    receive();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
    Event::assertDispatched(PlatformEventReceived::class, fn (PlatformEventReceived $event): bool => !$event->files[0]->isMissing());
});

it('F9: hands the event with the file marked as missing when the platform no longer has it', function (): void {
    platformServing(Http::response(['message' => 'Scaduto.'], 410));

    receive();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/receipt'));
    Event::assertDispatched(PlatformEventReceived::class, fn (PlatformEventReceived $event): bool => $event->files[0]->isMissing() && $event->files[0]->path === null);
});

it('F8: saves and confirms nothing, and does not hand the event, when the download does not match the envelope', function (): void {
    platformServing(Http::response('altro'));

    expect(fn () => receive())->toThrow(FileIntegrityException::class);

    expect(Storage::disk('local')->exists(storedPath()))->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/receipt'));
    Event::assertNotDispatched(PlatformEventReceived::class);
});

it('F9: keeps trying until the files are available, one job per event', function (): void {
    $job = new ReceivePlatformFiles($this->envelope);

    expect($job->uniqueId())->toBe(envelope()['event_id'])
        ->and($job->backoff())->toBe([60, 300, 900, 1800, 3600])
        ->and($job->retryUntil()->toIso8601ZuluString())->toBe('2026-11-15T10:00:00Z')
        ->and(new ReceivePlatformFiles(envelope(['files' => [[...$this->envelope['files'][0], 'available_until' => null]]]))->retryUntil()->toIso8601ZuluString())->toBe('2026-11-15T10:00:00Z');
});
