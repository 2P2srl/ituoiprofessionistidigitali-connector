<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\AssignmentOfferedData;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformEventOutbox;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformTransactionOutbox;
use ITuoiProfessionistiDigitali\Connector\Outbox\EventOutbox;
use ITuoiProfessionistiDigitali\Connector\Tests\TestCase;
use Opis\JsonSchema\Validator;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * @return array<string, mixed>
 */
function envelope(array $overrides = []): array
{
    return [
        'event_id' => '0199b6f2-6d7e-7c41-9a3e-5f2b8c1d4e60',
        'type' => Contract::PING,
        'schema_version' => 1,
        'occurred_at' => '2026-10-07T15:30:00+02:00',
        'sender' => '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43',
        'recipient' => '0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55',
        'typology' => 'commercialisti',
        'correlation_id' => null,
        'payload' => ['challenge' => 'a1b2c3d4e5f6a7b8'],
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function member(array $overrides = []): array
{
    return [
        'external_ref' => 'struttura-1',
        'subject_type' => 'organization',
        'name' => 'Studio Rossi e Associati',
        'vat_number' => '01234567897',
        'tax_code' => null,
        'municipality' => 'Bari',
        'province' => 'BA',
        'typologies' => ['commercialisti'],
        'listed' => true,
        ...$overrides,
    ];
}

/**
 * The catalogue as GET /event-types publishes it, with the schemas fixed in the package.
 *
 * @return list<array<string, mixed>>
 */
function catalogue(array $types = [Contract::PING, Contract::PONG]): array
{
    return array_map(static fn (string $type): array => [
        'name' => $type,
        'description' => null,
        'typologies' => null,
        'versions' => [[
            'version' => 1,
            'supported' => true,
            'schema' => json_decode((string) file_get_contents(Contract::schemaPath($type, 1)), true),
        ]],
    ], $types);
}

/**
 * An activity as a system sends it: open, by the hour, 120 minutes at 45 euro.
 *
 * @return array<string, mixed>
 */
function activity(array $overrides = []): array
{
    return [
        'reference' => 'riga-1',
        'compensation' => ['form' => 'hourly', 'hourly_rate_cents' => 4500, 'fixed_amount_cents' => null],
        'estimated_minutes' => 120,
        'minutes_worked' => null,
        'status' => 'open',
        'closed_at' => null,
        'description' => ['process' => ['reference' => 'processo-1', 'name' => 'Contabilità ordinaria'], 'activity' => ['name' => 'Registrazione fatture'], 'deadline' => '2026-11-30'],
        ...$overrides,
    ];
}

/**
 * A transaction as a system registers it: an assignment to a person, just sent, with one activity and every key.
 *
 * @return array<string, mixed>
 */
function transaction(array $overrides = []): array
{
    return [
        'assignment_reference' => 'incarico-1',
        'principal' => '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43',
        'counterparty' => person(),
        'typology' => 'commercialisti',
        'title' => 'Contabilità ordinaria 2026',
        'description' => 'Registrazione delle fatture del 2026.',
        'status' => 'invited',
        'sent_at' => '2026-10-06T18:00:00+02:00',
        'responded_at' => null,
        'signed_at' => null,
        'closed_at' => null,
        'activities' => [activity()],
        ...$overrides,
    ];
}

/**
 * A transaction as the register returns it.
 *
 * @return array<string, mixed>
 */
function recordedTransaction(array $overrides = []): array
{
    return [
        'id' => '0199b6f5-0000-7000-8000-000000000001',
        'reference' => 'invio-1',
        'assignment_reference' => 'incarico-1',
        'principal' => '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43',
        'counterparty' => person(),
        'typology' => 'commercialisti',
        'title' => 'Contabilità ordinaria 2026',
        'description' => 'Registrazione delle fatture del 2026.',
        'status' => 'invited',
        'sent_at' => '2026-10-06T16:00:00Z',
        'responded_at' => null,
        'signed_at' => null,
        'closed_at' => null,
        'currency' => 'EUR',
        'total_cents' => 9000,
        'revision' => 1,
        'type' => 'assignment',
        'schema_version' => 1,
        'activities' => [[...activity(), 'total_cents' => 9000]],
        'updated_at' => '2026-10-07T13:30:00Z',
        ...$overrides,
    ];
}

/**
 * A professional as the counterparty of an assignment to a person.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function person(array $overrides = []): array
{
    return ['type' => 'person', 'member_id' => null, 'tax_code' => 'RSSMRA80A01H501U', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario.rossi@example.com', 'vat_number' => null, 'municipality' => 'Bari', 'province' => 'BA', ...$overrides];
}

/**
 * A member, of the same system or of another one, as the counterparty of an assignment to a firm.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function firm(array $overrides = []): array
{
    return ['type' => 'member', 'member_id' => '0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55', 'tax_code' => null, 'first_name' => null, 'last_name' => null, 'email' => null, 'vat_number' => null, 'municipality' => null, 'province' => null, ...$overrides];
}

/**
 * The payload of an event of the assignment between two systems, on the transaction of recordedTransaction().
 *
 * @return array<string, mixed>
 */
function assignmentEvent(string $type, array $overrides = []): array
{
    return [
        'transaction' => ['id' => recordedTransaction()['id']],
        ...match ($type)
        {
            Contract::ASSIGNMENT_ACCEPTED => ['answered_at' => '2026-10-21T09:00:00+00:00'],
            Contract::ASSIGNMENT_DECLINED => ['answered_at' => '2026-10-21T09:00:00+00:00', 'reason' => 'Non abbiamo disponibilità a novembre.'],
            Contract::ASSIGNMENT_REVOKED => ['revoked_at' => '2026-10-22T09:00:00+00:00'],
            default => [],
        },
        ...$overrides,
    ];
}

/**
 * The envelope of an event of the assignment between two systems, correlated to the transaction of
 * recordedTransaction().
 *
 * @return array<string, mixed>
 */
function assignmentEnvelope(string $type): array
{
    return envelope(['type' => $type, 'correlation_id' => recordedTransaction()['id'], 'payload' => assignmentEvent($type)]);
}

/**
 * The offer of a transaction of the outbox of the transactions, kept in the outbox of the events.
 */
function queuedOffer(PlatformTransactionOutbox $transaction): PlatformEventOutbox
{
    return resolve(EventOutbox::class)->queue(AssignmentOfferedData::from(['transaction' => ['id' => $transaction->transaction_id]])
        ->toEnvelope(envelope()['sender'], envelope()['recipient'], 'commercialisti'));
}

/**
 * A proposal prepared as a draft with one activity, then sent: the outbox has its revision 1.
 */
function sentAssignment(): ITuoiProfessionistiDigitali\Connector\Tests\Fixtures\Assignment
{
    $assignment = ITuoiProfessionistiDigitali\Connector\Tests\Fixtures\Assignment::query()->create(['status' => 'invited']);
    $assignment->activities()->create();
    $assignment->update(['uuid' => (string) Illuminate\Support\Str::uuid7()]);

    return $assignment;
}

/**
 * A proposal sent, whose revision 1 the platform confirmed.
 */
function confirmedAssignment(): ITuoiProfessionistiDigitali\Connector\Tests\Fixtures\Assignment
{
    $assignment = sentAssignment();
    PlatformTransactionOutbox::query()->update(['sent_revision' => 1, 'status' => ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus::Sent]);

    return $assignment;
}

/**
 * The record of a professional as a system declares it with PUT /professionals/{tax_code}.
 *
 * @return array<string, mixed>
 */
function professional(array $overrides = []): array
{
    return [
        'first_name' => 'Mario',
        'last_name' => 'Rossi',
        'email' => 'mario.rossi@example.com',
        'vat_number' => '01234567897',
        'municipality' => 'Lecce',
        'province' => 'LE',
        'declared_at' => '2026-10-08T10:15:00+02:00',
        ...$overrides,
    ];
}

/**
 * Fakes these routes of the platform, after the token of the client.
 */
function withToken(array $routes): void
{
    Http::fake(['platform.test/oauth/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600]), ...$routes]);
}

/**
 * The platform answering PUT /professionals/{tax_code} with this status, after the token.
 */
function platformAnsweringProfessionals(int $status, array $body = []): void
{
    withToken([
        'platform.test/api/v1/professionals/*' => Http::response($status === 204 ? null : $body, $status),
    ]);
}

/**
 * The platform answering GET /transactions with these transactions on a single page, after the token.
 */
function platformListingTransactions(array $transactions): void
{
    withToken(['platform.test/api/v1/transactions*' => Http::response(['data' => $transactions, 'meta' => ['next_cursor' => null]])]);
}

/**
 * Whether a payload matches the schema of version 1 of an event type, fixed in the package.
 */
function matchesSchema(string $type, array $payload): bool
{
    $schema = json_decode((string) file_get_contents(Contract::schemaPath($type, 1)));

    return new Validator()->validate(json_decode((string) json_encode($payload)), $schema)->isValid();
}

/**
 * The payload of coworking.activities_assigned v1: one process with its final client, one activity with a guide
 * and its file.
 *
 * @return array<string, mixed>
 */
function activitiesAssigned(array $activity = []): array
{
    return [
        'transaction' => ['id' => '0199b6f5-0000-7000-8000-000000000001'],
        'processes' => [[
            'reference' => 'pratica-812',
            'name' => 'Contabilità ordinaria',
            'client' => ['name' => 'Bianchi Srl', 'tax_code' => '01234567897'],
            'activities' => [[
                'reference' => '43',
                'name' => 'Registrazione fatture',
                'deadline' => '2026-11-30',
                'fixed_amount_cents' => 90000,
                'estimated_minutes' => 1200,
                'guides' => [['title' => 'Registrazione fatture', 'body' => 'Le fatture **passive** prima.', 'attachments' => ['0199c1a0-0000-7000-8000-000000000001']]],
                ...$activity,
            ]],
        ]],
    ];
}
