<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use ITuoiProfessionistiDigitali\Connector\Data\FileReferenceData;
use ITuoiProfessionistiDigitali\Connector\Enums\FileStatus;
use ITuoiProfessionistiDigitali\Connector\Exceptions\FileGoneException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\FileIntegrityException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Facades\Platform;

const FILE_ID = '0199c1a0-0000-7000-8000-000000000001';

const UPLOAD_ID = '0199c19f-0000-7000-8000-000000000001';

const FILE_CONTENT = "%PDF-1.7\nGuida registrazione fatture\n";

beforeEach(function (): void {
    Http::preventStrayRequests();
    Sleep::fake();
    Storage::fake('local');
    Storage::disk('local')->put('guide/fatture.pdf', FILE_CONTENT);
});

/**
 * A file as POST /files and GET /files/{id}/status return it.
 *
 * @return array<string, mixed>
 */
function platformFile(array $overrides = []): array
{
    return [
        'id' => FILE_ID,
        'upload_id' => UPLOAD_ID,
        'sender' => '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43',
        'recipient' => '0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55',
        'name' => 'Guida registrazione fatture.pdf',
        'media_type' => 'application/pdf',
        'size' => mb_strlen(FILE_CONTENT, '8bit'),
        'sha256' => hash('sha256', FILE_CONTENT),
        'status' => 'uploaded',
        'citable_until' => '2026-10-17T10:00:00Z',
        'available_until' => null,
        ...$overrides,
    ];
}

/**
 * A file as the envelope delivered to the webhook lists it.
 */
function fileReference(array $overrides = []): FileReferenceData
{
    return FileReferenceData::from([
        'id' => FILE_ID,
        'name' => 'Guida registrazione fatture.pdf',
        'media_type' => 'application/pdf',
        'size' => mb_strlen(FILE_CONTENT, '8bit'),
        'sha256' => hash('sha256', FILE_CONTENT),
        'available_until' => '2026-11-15T10:00:00Z',
        ...$overrides,
    ]);
}

function uploadGuide(): ITuoiProfessionistiDigitali\Connector\Data\FileData
{
    return Platform::uploadFile(
        uploadId: UPLOAD_ID,
        sender: '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43',
        recipient: '0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55',
        disk: 'local',
        path: 'guide/fatture.pdf',
        name: 'Guida registrazione fatture.pdf',
        mediaType: 'application/pdf',
    );
}

it('F2 and F3: uploads a file as multipart, with the fields before the content and its SHA-256', function (int $status): void {
    withToken(['platform.test/api/v1/files' => Http::response(['data' => platformFile()], $status)]);

    $file = uploadGuide();

    expect($file)
        ->id->toBe(FILE_ID)
        ->status->toBe(FileStatus::Uploaded)
        ->size->toBe(mb_strlen(FILE_CONTENT, '8bit'))
        ->available_until->toBeNull()
        ->and($file->citable_until?->toIso8601ZuluString())->toBe('2026-10-17T10:00:00Z');
    Http::assertSent(function (Request $request): bool {
        $parts = array_column($request->data(), 'name');

        return $request->method() === 'POST'
            && $request->url() === 'https://platform.test/api/v1/files'
            && $request->isMultipart()
            && $parts === ['upload_id', 'sender', 'recipient', 'name', 'media_type', 'sha256', 'file']
            && array_column($request->data(), 'contents', 'name')['sha256'] === hash('sha256', FILE_CONTENT)
            && str_contains($request->body(), FILE_CONTENT)
            && $request->hasHeader('Authorization', 'Bearer token');
    });
})->with([201, 200]);

it('F3: sends the whole content again at every attempt, reopening the file', function (): void {
    withToken(['platform.test/api/v1/files' => Http::sequence()
        ->pushFailedConnection()
        ->push(['message' => 'Errore.'], 503)
        ->push(['data' => platformFile()], 201),
    ]);

    expect(uploadGuide()->id)->toBe(FILE_ID);

    $uploads = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/files'));
    expect($uploads)->toHaveCount(3)
        ->and(str_contains($uploads[2][0]->body(), FILE_CONTENT))->toBeTrue();
});

it('F3: gives up after the retries', function (): void {
    withToken(['platform.test/api/v1/files' => Http::sequence()->pushFailedConnection()->pushFailedConnection()->pushFailedConnection()]);

    uploadGuide();
})->throws(ConnectionException::class);

it('T7 and F11: waits for a short Retry-After, and leaves a long one to the caller', function (): void {
    withToken(['platform.test/api/v1/files' => Http::sequence()
        ->push(['message' => 'Troppe richieste.'], 429, ['Retry-After' => '2'])
        ->push(['data' => platformFile()], 201)
        ->push(['message' => 'Quota piena.'], 429, ['Retry-After' => '600']),
    ]);

    expect(uploadGuide()->id)->toBe(FILE_ID);
    Sleep::assertSlept(fn (Carbon\CarbonInterval $duration): bool => (int) $duration->totalSeconds === 2);

    try
    {
        uploadGuide();
        $this->fail('The platform should answer 429.');
    }
    catch (PlatformRequestException $exception)
    {
        expect($exception->isThrottled())->toBeTrue()
            ->and($exception->retryAfter)->toBe(600);
    }
});

it('A1: asks a new token once when the platform refuses the one in cache', function (): void {
    Http::fake([
        'platform.test/oauth/token' => Http::sequence()->push(['access_token' => 'old', 'expires_in' => 3600])->push(['access_token' => 'new', 'expires_in' => 3600]),
        'platform.test/api/v1/files' => Http::sequence()->push(['message' => 'Non autenticato.'], 401)->push(['data' => platformFile()], 201),
    ]);

    expect(uploadGuide()->id)->toBe(FILE_ID);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/files') && $request->hasHeader('Authorization', 'Bearer new'));
});

it('F2 and F4: leaves the refusals of an upload to the caller, without retrying them', function (int $status): void {
    withToken(['platform.test/api/v1/files' => Http::response(['message' => 'Rifiutato.', 'errors' => ['media_type' => ['Tipo non ammesso.']]], $status)]);

    try
    {
        uploadGuide();
        $this->fail('The platform should refuse the file.');
    }
    catch (PlatformRequestException $exception)
    {
        expect($exception->status)->toBe($status);
    }

    Http::assertSentCount(2);
})->with([422, 409, 410, 413]);

it('refuses a file the disk cannot read, before any request', function (): void {
    Http::fake();

    Platform::uploadFile(UPLOAD_ID, '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43', '0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55', 'local', 'guide/assente.pdf', 'Assente.pdf', 'application/pdf');
})->throws(InvalidArgumentException::class);

it('F5: reads the status of a file', function (): void {
    withToken(['platform.test/api/v1/files/*/status' => Http::response(['data' => platformFile(['status' => 'received', 'name' => null, 'available_until' => '2026-11-15T10:00:00Z'])])]);

    $file = Platform::fileStatus(FILE_ID);

    expect($file)
        ->status->toBe(FileStatus::Received)
        ->name->toBeNull()
        ->and($file->available_until?->toIso8601ZuluString())->toBe('2026-11-15T10:00:00Z');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform.test/api/v1/files/'.FILE_ID.'/status');
});

it('F7 and F8: downloads a file, checks it on the hash of the envelope and saves it', function (): void {
    withToken(['platform.test/api/v1/files/*' => Http::response(FILE_CONTENT, 200, ['Content-Type' => 'application/pdf', 'X-Content-SHA256' => hash('sha256', FILE_CONTENT)])]);

    Platform::downloadFile(fileReference(), 'local', 'ricevuti/guida.pdf');

    expect(Storage::disk('local')->get('ricevuti/guida.pdf'))->toBe(FILE_CONTENT);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'https://platform.test/api/v1/files/'.FILE_ID);
});

it('F8: saves nothing when the content does not match the hash of the envelope', function (): void {
    withToken(['platform.test/api/v1/files/*' => Http::response('altro contenuto')]);

    try
    {
        Platform::downloadFile(fileReference(), 'local', 'ricevuti/guida.pdf');
        $this->fail('The download should be refused.');
    }
    catch (FileIntegrityException $exception)
    {
        expect($exception->fileId)->toBe(FILE_ID);
    }

    expect(Storage::disk('local')->exists('ricevuti/guida.pdf'))->toBeFalse();
});

it('F9: tells a file expired or already confirmed with its own exception', function (): void {
    withToken(['platform.test/api/v1/files/*' => Http::response(['message' => 'File non più disponibile.'], 410)]);

    try
    {
        Platform::downloadFile(fileReference(), 'local', 'ricevuti/guida.pdf');
        $this->fail('The platform should answer 410.');
    }
    catch (FileGoneException $exception)
    {
        expect($exception->fileId)->toBe(FILE_ID);
    }
});

it('F7: leaves any other refusal of a download as it is', function (): void {
    withToken(['platform.test/api/v1/files/*' => Http::response(['message' => 'Non trovato.'], 404)]);

    Platform::downloadFile(fileReference(), 'local', 'ricevuti/guida.pdf');
})->throws(PlatformRequestException::class);

it('F8: confirms a file with its hash', function (): void {
    withToken(['platform.test/api/v1/files/*/receipt' => Http::response(['data' => platformFile(['status' => 'received', 'name' => null])])]);

    expect(Platform::confirmFile(FILE_ID, hash('sha256', FILE_CONTENT))->status)->toBe(FileStatus::Received);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://platform.test/api/v1/files/'.FILE_ID.'/receipt'
        && $request->data() === ['sha256' => hash('sha256', FILE_CONTENT)]);
});
