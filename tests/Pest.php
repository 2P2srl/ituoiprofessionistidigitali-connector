<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Tests\TestCase;

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
        'email' => 'segreteria@studiorossi.example',
        ...$overrides,
    ];
}

/**
 * The catalogue as GET /event-types publishes it, with the schemas fixed in the package.
 *
 * @return list<array<string, mixed>>
 */
function catalogue(): array
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
    ], [Contract::PING, Contract::PONG]);
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
        'audience' => 'person',
        'principal' => '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43',
        'counterparty' => ['type' => 'person', 'member_id' => null, 'tax_code' => 'RSSMRA80A01H501U', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario.rossi@example.com', 'vat_number' => null, 'municipality' => 'Bari', 'province' => 'BA'],
        'typology' => 'commercialisti',
        'title' => 'Contabilità ordinaria 2026',
        'description' => 'Registrazione delle fatture del 2026.',
        'status' => 'invited',
        'sent_at' => '2026-10-06T18:00:00+02:00',
        'expires_at' => null,
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
        'origin' => 'system',
        'reference' => 'invio-1',
        'assignment_reference' => 'incarico-1',
        'audience' => 'person',
        'principal' => '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43',
        'counterparty' => ['type' => 'person', 'member_id' => null, 'tax_code' => 'RSSMRA80A01H501U', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario.rossi@example.com', 'vat_number' => null, 'municipality' => 'Bari', 'province' => 'BA'],
        'typology' => 'commercialisti',
        'title' => 'Contabilità ordinaria 2026',
        'description' => 'Registrazione delle fatture del 2026.',
        'status' => 'invited',
        'sent_at' => '2026-10-06T16:00:00Z',
        'expires_at' => null,
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
 * An application as the platform returns it: pending, without the data that come with the selection (rule L7).
 *
 * @return array<string, mixed>
 */
function application(array $overrides = []): array
{
    return [
        'id' => '0199b6f9-0000-7000-8000-000000000001',
        'status' => 'pending',
        'applied_at' => '2026-10-10T08:00:00Z',
        'terms_accepted_at' => '2026-10-10T08:00:00Z',
        'accepted_revision' => 1,
        'closed_at' => null,
        'applicant' => ['type' => 'person', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'municipality' => 'Bari', 'province' => 'BA', 'tax_code_verified' => false],
        ...$overrides,
    ];
}

/**
 * The payload of transaction.application_received or transaction.application_withdrawn v1, on the published
 * transaction invio-1.
 *
 * @return array<string, mixed>
 */
function applicationEvent(array $application = []): array
{
    return [
        'transaction' => ['id' => recordedTransaction()['id'], 'reference' => 'invio-1'],
        'application' => application($application),
    ];
}

/**
 * A published transaction after the selection of its counterparty, as the platform returns it (rule L4):
 * accepted at revision 2, open to anyone, with its expiry, without signature.
 *
 * @return array<string, mixed>
 */
function selectedTransaction(array $overrides = []): array
{
    return recordedTransaction([
        'origin' => 'platform',
        'audience' => 'any',
        'status' => 'accepted',
        'expires_at' => '2026-10-31T22:59:59Z',
        'responded_at' => '2026-10-12T09:00:00Z',
        'revision' => 2,
        ...$overrides,
    ]);
}

/**
 * The payload of transaction.assignment_received v1: the transaction accepted with the selection of a firm, and the
 * principal revealed to it.
 *
 * @return array<string, mixed>
 */
function assignmentReceived(array $transaction = []): array
{
    return [
        'transaction' => selectedTransaction([
            'audience' => 'member',
            'counterparty' => ['type' => 'member', 'member_id' => '0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55', 'tax_code' => null, 'first_name' => null, 'last_name' => null, 'email' => null, 'vat_number' => null, 'municipality' => null, 'province' => null],
            ...$transaction,
        ]),
        'principal' => ['id' => '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43', 'name' => 'Studio Rossi e Associati', 'municipality' => 'Bari', 'province' => 'BA'],
    ];
}

/**
 * The payload of transaction.counterparty_selected v1.
 *
 * @return array<string, mixed>
 */
function counterpartySelected(array $transaction = []): array
{
    return [
        'transaction' => selectedTransaction($transaction),
        'application' => application(['status' => 'selected', 'closed_at' => '2026-10-12T09:00:00Z']),
        'contact' => ['mobile' => '+393331234567'],
    ];
}

/**
 * A published transaction the platform withdrew by itself after its expiry (rule L9): withdrawn at revision 2,
 * open to anyone, without counterparty, with its activities still open.
 *
 * @return array<string, mixed>
 */
function withdrawnTransaction(array $overrides = []): array
{
    return recordedTransaction([
        'origin' => 'platform',
        'audience' => 'any',
        'counterparty' => null,
        'status' => 'withdrawn',
        'expires_at' => '2026-10-31T22:59:59Z',
        'closed_at' => '2026-11-30T23:00:00Z',
        'revision' => 2,
        ...$overrides,
    ]);
}

/**
 * The payload of transaction.withdrawn v1.
 *
 * @return array<string, mixed>
 */
function transactionWithdrawn(array $transaction = []): array
{
    return [
        'transaction' => withdrawnTransaction($transaction),
        'reason' => 'expired',
    ];
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
    ITuoiProfessionistiDigitali\Connector\Models\PlatformTransactionOutbox::query()->update(['sent_revision' => 1, 'status' => ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus::Sent]);

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
