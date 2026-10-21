<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ITuoiProfessionistiDigitali\Connector\Concerns\RecordsAffectedOnPlatform;
use ITuoiProfessionistiDigitali\Connector\Concerns\RecordsOnPlatform;
use ITuoiProfessionistiDigitali\Connector\Data\ProfessionalRecordData;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionActivityData;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionData;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Facades\Platform;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformTransaction;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformTransactionOutbox;
use ITuoiProfessionistiDigitali\Connector\Outbox\ProfessionalOutbox;
use ITuoiProfessionistiDigitali\Connector\Outbox\TransactionOutbox;
use ITuoiProfessionistiDigitali\Connector\Tests\Fixtures\Assignment;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

it('R9: keeps a draft out of the register', function (): void {
    $assignment = Assignment::query()->create(['status' => 'invited']);
    $assignment->activities()->create();

    expect(PlatformTransactionOutbox::query()->count())->toBe(0)
        ->and($assignment->isRecordedOnPlatform())->toBeFalse();
    Queue::assertNothingPushed();
});

it('R9: writes every save of the model in the outbox and queues it, with a new revision only on changes', function (): void {
    $assignment = sentAssignment();

    $row = PlatformTransactionOutbox::query()->sole();

    expect($row->reference)->toBe($assignment->uuid)
        ->and($row->revision)->toBe(1)
        ->and($row->status)->toBe(OutboxStatus::Pending)
        ->and($row->payload['status'])->toBe('invited')
        ->and($row->payload['activities'])->toHaveCount(1)
        ->and($row->payload)->not->toHaveKey('revision');
    Queue::assertPushed(SendPlatformTransaction::class, fn (SendPlatformTransaction $job): bool => $job->reference === $assignment->uuid);

    $assignment->touch();

    expect($row->refresh()->revision)->toBe(1);
    Queue::assertPushed(SendPlatformTransaction::class, 1);

    $assignment->update(['status' => 'accepted']);

    expect($row->refresh()->revision)->toBe(2)->and($row->payload['status'])->toBe('accepted');
    Queue::assertPushed(SendPlatformTransaction::class, 2);
});

it('R9: records the transaction again when an activity changes or goes, without saving the transaction', function (): void {
    $assignment = sentAssignment();
    $assignment->update(['status' => 'accepted']);
    $activity = $assignment->activities()->sole();
    $row = PlatformTransactionOutbox::query()->sole();

    $activity->update(['status' => 'completed', 'minutes' => 90]);

    expect($row->refresh()->revision)->toBe(3)
        ->and($row->payload['activities'][0]['status'])->toBe('completed')
        ->and($row->payload['activities'][0]['minutes_worked'])->toBe(90);

    $activity->touch();

    expect($row->refresh()->revision)->toBe(3);

    $activity->delete();

    expect($row->refresh()->revision)->toBe(4)->and($row->payload['activities'])->toBe([]);
});

it('R23: gives the transaction its id at the first save, a UUID v7 in lower case, the same at every revision', function (): void {
    $draft = Assignment::query()->create(['status' => 'invited']);

    expect($draft->platformTransactionId())->toBeNull();

    $assignment = sentAssignment();
    $id = $assignment->platformTransactionId();

    expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and(PlatformTransactionOutbox::query()->sole()->transaction_id)->toBe($id);

    $assignment->update(['status' => 'accepted']);

    expect(PlatformTransactionOutbox::query()->sole())->revision->toBe(2)->transaction_id->toBe($id)
        ->and(resolve(TransactionOutbox::class)->transactionId($assignment->uuid))->toBe($id)
        ->and(resolve(TransactionOutbox::class)->transactionId('unknown'))->toBeNull()
        ->and(new Assignment(['uuid' => (string) Str::uuid7()])->platformTransactionId())->toBeNull();
});

it('R23: has the id inside the database transaction of the save, before the platform confirms anything', function (): void {
    $id = DB::transaction(static function (): ?string {
        $assignment = Assignment::query()->create(['status' => 'invited']);
        $assignment->update(['uuid' => (string) Str::uuid7()]);

        return $assignment->platformTransactionId();
    });

    expect($id)->toBe(PlatformTransactionOutbox::query()->sole()->transaction_id);
});

it('R9: tells whether the platform confirmed the current version', function (): void {
    $assignment = sentAssignment();

    expect($assignment->isRecordedOnPlatform())->toBeFalse();

    PlatformTransactionOutbox::query()->update(['sent_revision' => 1]);

    expect($assignment->isRecordedOnPlatform())->toBeTrue();

    $assignment->update(['status' => 'accepted']);

    expect($assignment->isRecordedOnPlatform())->toBeFalse()
        ->and(new Assignment(['uuid' => (string) Str::uuid7()])->isRecordedOnPlatform())->toBeFalse();
});

it('refuses the traits on a model without their interface', function (string $trait): void {
    $model = match ($trait)
    {
        'records' => fn (): Model => new class extends Model {
            use RecordsOnPlatform;

            public function platformTransactionReference(): ?string
            {
                return null;
            }
        },
        default => fn (): Model => new class extends Model {
            use RecordsAffectedOnPlatform;
        },
    };

    expect($model)->toThrow(LogicException::class, 'non implementa');
})->with(['records', 'affects']);

it('R10 and R18: keeps the history registered in batch as confirmed, so the outbox knows every person assigned', function (): void {
    Http::preventStrayRequests();
    withToken([
        'platform.test/api/v1/transactions/batch' => Http::response(['data' => array_map(
            static fn (string $reference, string $result): array => ['reference' => $reference, 'result' => $result, 'transaction' => $result === 'invalid' ? null : recordedTransaction(['reference' => $reference]), 'errors' => null],
            ['created', 'updated', 'unchanged', 'stale', 'invalid', 'kept'],
            ['created', 'updated', 'unchanged', 'stale', 'invalid', 'updated'],
        )]),
    ]);
    $kept = PlatformTransactionOutbox::query()->create(['reference' => 'kept', 'revision' => 5, 'payload' => [], 'status' => OutboxStatus::Pending]);
    $transaction = TransactionData::from(transaction());

    Platform::recordTransactions(array_map(
        static fn (string $reference): array => ['reference' => $reference, 'revision' => 2, 'transaction' => $transaction],
        ['created', 'updated', 'unchanged', 'stale', 'invalid', 'kept'],
    ));

    $rows = PlatformTransactionOutbox::query()->orderBy('reference')->get()->keyBy('reference');

    expect($rows->keys()->all())->toBe(['created', 'invalid', 'kept', 'stale', 'unchanged', 'updated'])
        ->and($rows['created'])
        ->revision->toBe(2)
        ->sent_revision->toBe(2)
        ->status->toBe(OutboxStatus::Sent)
        ->sent_at->not->toBeNull()
        ->payload->toBe(resolve(TransactionOutbox::class)->payloadOf($transaction))
        ->and($rows->only(['invalid', 'stale']))->each(fn ($row) => $row->revision->toBe(2)->sent_revision->toBeNull()->status->toBe(OutboxStatus::Pending))
        ->and($rows['kept'])->revision->toBe(5)->status->toBe(OutboxStatus::Pending)->transaction_id->toBe($kept->transaction_id)
        ->and(resolve(ProfessionalOutbox::class)->declare('RSSMRA80A01H501U', ProfessionalRecordData::from(professional(['declared_at' => '2026-10-06T18:00:00+02:00']))))->not->toBeNull();
    Queue::assertNotPushed(SendPlatformTransaction::class);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/transactions/batch') && collect($request->data()['transactions'])->pluck('id', 'reference')->sortKeys()->all() === $rows->map->transaction_id->all());
});

/**
 * The names of the date properties of a DTO, as they are the keys of its body.
 *
 * @return list<string>
 */
function dateProperties(string $class): array
{
    return array_values(array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        array_filter(new ReflectionClass($class)->getProperties(), static fn (ReflectionProperty $property): bool => str_contains((string) $property->getType(), CarbonImmutable::class)),
    ));
}

it('T2: keeps every date in UTC, so the same instant in another time zone is the same version', function (): void {
    $outbox = resolve(TransactionOutbox::class);
    $local = '2026-10-20T09:00:00+02:00';
    $payload = $outbox->payloadOf(TransactionData::from(transaction([
        ...array_fill_keys(dateProperties(TransactionData::class), $local),
        'activities' => [activity(array_fill_keys(dateProperties(TransactionActivityData::class), $local))],
    ])));

    expect(dateProperties(TransactionData::class))->toContain('sent_at', 'closed_at')
        ->and(dateProperties(TransactionActivityData::class))->toBe(['closed_at'])
        ->and(Arr::only($payload, dateProperties(TransactionData::class)))->each->toBe('2026-10-20T07:00:00+00:00')
        ->and(Arr::only($payload['activities'][0], dateProperties(TransactionActivityData::class)))->each->toBe('2026-10-20T07:00:00+00:00')
        ->and($outbox->payloadOf(TransactionData::from(transaction(['sent_at' => '2026-10-06T16:00:00Z']))))->toBe($outbox->payloadOf(TransactionData::from(transaction())));
});
