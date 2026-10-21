<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Data\ProfessionalRecordData;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformProfessional;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformTransaction;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformProfessionalOutbox;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformTransactionOutbox;
use ITuoiProfessionistiDigitali\Connector\Outbox\EventOutbox;
use ITuoiProfessionistiDigitali\Connector\Outbox\ProfessionalOutbox;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:20:00+02:00'));
    // A transaction to the person already confirmed: the platform knows the professional
    confirmedAssignment();
    $this->row = resolve(ProfessionalOutbox::class)->declare('RSSMRA80A01H501U', ProfessionalRecordData::from(professional()));
});

function declareOnPlatform(string $taxCode = 'RSSMRA80A01H501U'): void
{
    resolve(SendPlatformProfessional::class, ['taxCode' => $taxCode])->handle(
        resolve(PlatformClient::class),
        resolve(ConnectorConfig::class),
        resolve(ProfessionalOutbox::class),
    );
}

it('R18: sends the last declaration and marks it confirmed', function (): void {
    platformAnsweringProfessionals(204);

    declareOnPlatform();

    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Sent)
        ->sent_revision->toBe(1)
        ->attempts->toBe(1)
        ->last_error->toBeNull()
        ->sent_at->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->data() === professional());
});

it('R19: leaves pending a newer declaration that arrived while sending', function (): void {
    withToken([
        'platform.test/api/v1/professionals/*' => function () {
            PlatformProfessionalOutbox::query()->update(['revision' => 2]);

            return Http::response(null, 204);
        },
    ]);

    declareOnPlatform();

    expect($this->row->refresh())->sent_revision->toBe(1)->status->toBe(OutboxStatus::Pending);
});

it('R18: waits on a 404 while the outbox has transactions to the person never confirmed', function (): void {
    PlatformTransactionOutbox::query()->update(['sent_revision' => null, 'status' => OutboxStatus::Pending]);
    platformAnsweringProfessionals(404, ['message' => 'Professionista non trovato.']);

    declareOnPlatform();

    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Pending)
        ->sent_revision->toBeNull()
        ->last_error->not->toBeNull();
});

it('R18: discards on a 404 a declaration with no transactions to wait for, with a log', function (): void {
    Log::spy();
    platformAnsweringProfessionals(404, ['message' => 'Professionista non trovato.']);

    declareOnPlatform();

    expect($this->row->refresh())->status->toBe(OutboxStatus::Discarded)->sent_revision->toBeNull();
    Log::shouldHaveReceived('info')->once();
});

it('R18: repeats a 404 until the transactions to the person are registered, then the record is updated', function (): void {
    $assignment = sentAssignment();
    $registered = false;
    withToken([
        'platform.test/api/v1/transactions/*' => function () use (&$registered) {
            $registered = true;

            return Http::response(['data' => recordedTransaction()], 201);
        },
        'platform.test/api/v1/professionals/*' => function () use (&$registered) {
            return $registered ? Http::response(null, 204) : Http::response(['message' => 'Professionista non trovato.'], 404);
        },
    ]);

    declareOnPlatform();
    expect($this->row->refresh()->status)->toBe(OutboxStatus::Pending);

    resolve(SendPlatformTransaction::class, ['reference' => $assignment->uuid])->handle(
        resolve(PlatformClient::class),
        resolve(ConnectorConfig::class),
        resolve(ProfessionalOutbox::class),
        resolve(EventOutbox::class),
    );
    Queue::assertPushed(SendPlatformProfessional::class, 2);

    declareOnPlatform();

    expect($this->row->refresh())->status->toBe(OutboxStatus::Sent)->sent_revision->toBe(1);
    Http::assertSentCount(4);
});

it('stops on a contract violation, keeping the error', function (): void {
    platformAnsweringProfessionals(422, ['message' => 'Rifiutata.', 'errors' => ['province' => ['Non è una provincia.']]]);

    declareOnPlatform();

    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Failed)
        ->last_error->toContain('Rifiutata.')
        ->last_error->toContain('Non è una provincia.');
});

it('stops a declaration that breaks the contract before any request, and the command does not queue it again', function (): void {
    $this->row->update(['payload' => professional(['email' => null])]);
    Http::fake();

    declareOnPlatform();

    expect($this->row->refresh())
        ->status->toBe(OutboxStatus::Failed)
        ->sent_revision->toBeNull()
        ->last_error->toContain('email');
    Http::assertNothingSent();
    $this->artisan('platform:send-outbox')->expectsOutputToContain('Anagrafiche rimesse in coda: 0.')->assertSuccessful();
});

it('retries a server error or a system not yet active', function (int $status): void {
    platformAnsweringProfessionals($status, ['message' => 'Non ora.']);

    expect(fn () => declareOnPlatform())->toThrow(PlatformRequestException::class);
    expect($this->row->refresh())->status->toBe(OutboxStatus::Pending)->last_error->toContain('Non ora.');
})->with([503, 403]);

it('waits while the system is not connected', function (): void {
    $this->app->instance(ConnectorConfig::class, new ConnectorConfig);
    Http::fake();

    declareOnPlatform();

    expect($this->row->refresh())->status->toBe(OutboxStatus::Pending)->last_error->not->toBeNull();
    Http::assertNothingSent();
});

it('does nothing for a declaration no longer pending or a tax code it does not know', function (): void {
    $this->row->update(['status' => OutboxStatus::Discarded]);
    Http::fake();

    declareOnPlatform();
    declareOnPlatform('BNCLRA85T41F205Y');

    Http::assertNothingSent();
});

it('retries ten times with a growing backoff', function (): void {
    $job = new SendPlatformProfessional('RSSMRA80A01H501U');

    expect($job->tries)->toBe(10)->and($job->backoff())->toBe([60, 300, 900, 1800, 3600]);
});
