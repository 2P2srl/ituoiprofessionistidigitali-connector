<?php

declare(strict_types=1);

use ITuoiProfessionistiDigitali\Connector\Contract;
use Opis\JsonSchema\Validator;

it('C4: fixes a valid JSON Schema 2020-12 for every event type version', function (string $type): void {
    $path = Contract::schemaPath($type, 1);
    $schema = json_decode((string) file_get_contents($path));

    expect($schema->{'$schema'})->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and($schema->{'$id'})->toBe("https://ituoiprofessionistidigitali.it/contract/event-types/{$type}/1.json")
        ->and(new Validator()->validate((object) ['challenge' => 'a1b2c3d4e5f6a7b8'], $schema)->isValid())->toBeTrue();
})->with([Contract::PING, Contract::PONG]);

it('C5: keeps the challenge of ping and pong between 16 and 128 safe characters, with nothing else', function (string $type, mixed $payload, bool $isValid): void {
    $schema = json_decode((string) file_get_contents(Contract::schemaPath($type, 1)));

    expect(new Validator()->validate($payload, $schema)->isValid())->toBe($isValid);
})->with([Contract::PING, Contract::PONG])->with([
    'sixteen characters' => [(object) ['challenge' => str_repeat('a', 16)], true],
    'one hundred twenty-eight characters' => [(object) ['challenge' => str_repeat('A', 128)], true],
    'dash and underscore' => [(object) ['challenge' => 'abc-def_ghi-jkl_'], true],
    'too short' => [(object) ['challenge' => str_repeat('a', 15)], false],
    'too long' => [(object) ['challenge' => str_repeat('a', 129)], false],
    'other characters' => [(object) ['challenge' => 'a1b2c3d4e5f6a7b8!'], false],
    'missing' => [(object) [], false],
    'one more field' => [(object) ['challenge' => 'a1b2c3d4e5f6a7b8', 'extra' => 1], false],
]);

it('R6: fixes a valid JSON Schema 2020-12 for the description of an activity, with its process and without free text', function (): void {
    $schema = json_decode((string) file_get_contents(Contract::transactionSchemaPath('assignment', 1)));
    $process = (object) ['reference' => 'processo-1', 'name' => 'Contabilità'];
    $valid = (object) ['process' => $process, 'activity' => (object) ['name' => 'Registrazione fatture'], 'deadline' => '2026-11-30'];
    $withoutNames = (object) ['process' => (object) ['reference' => 'agenda-7', 'name' => null], 'activity' => null, 'deadline' => null];
    $withoutProcess = (object) ['process' => null, 'activity' => null, 'deadline' => null];
    $withoutReference = (object) ['process' => (object) ['name' => 'Contabilità'], 'activity' => null, 'deadline' => null];
    $withText = (object) ['process' => $process, 'activity' => null, 'deadline' => null, 'notes' => 'Per il cliente Rossi'];

    expect($schema->{'$id'})->toBe('https://ituoiprofessionistidigitali.it/contract/transaction-types/assignment/1.json')
        ->and(new Validator()->validate($valid, $schema)->isValid())->toBeTrue()
        ->and(new Validator()->validate($withoutNames, $schema)->isValid())->toBeTrue()
        ->and(new Validator()->validate($withoutProcess, $schema)->isValid())->toBeFalse()
        ->and(new Validator()->validate($withoutReference, $schema)->isValid())->toBeFalse()
        ->and(new Validator()->validate($withText, $schema)->isValid())->toBeFalse();
});

/**
 * Whether a payload matches the schema of version 1 of an event type, fixed in the package.
 */
function matchesSchema(string $type, array $payload): bool
{
    $schema = json_decode((string) file_get_contents(Contract::schemaPath($type, 1)));

    return new Validator()->validate(json_decode((string) json_encode($payload)), $schema)->isValid();
}

it('C4 and L6: fixes a valid JSON Schema 2020-12 for every event of the platform, accepting what it sends', function (string $type, array $payload): void {
    $schema = json_decode((string) file_get_contents(Contract::schemaPath($type, 1)));

    expect($schema->{'$schema'})->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and($schema->{'$id'})->toBe("https://ituoiprofessionistidigitali.it/contract/event-types/{$type}/1.json")
        ->and(matchesSchema($type, $payload))->toBeTrue();
})->with([
    'application received' => [Contract::APPLICATION_RECEIVED, applicationEvent()],
    'application withdrawn' => [Contract::APPLICATION_WITHDRAWN, applicationEvent(['status' => 'withdrawn', 'closed_at' => '2026-10-11T08:00:00Z'])],
    'counterparty selected' => [Contract::COUNTERPARTY_SELECTED, counterpartySelected()],
    'counterparty selected, with a new field of the transaction' => [Contract::COUNTERPARTY_SELECTED, counterpartySelected(['settlements' => []])],
    'transaction withdrawn' => [Contract::TRANSACTION_WITHDRAWN, transactionWithdrawn()],
    'transaction withdrawn, open to members, with a new field of the transaction' => [Contract::TRANSACTION_WITHDRAWN, transactionWithdrawn(['audience' => 'member', 'settlements' => []])],
    'application of a firm received' => [Contract::APPLICATION_RECEIVED, applicationEvent(['applicant' => firmApplicant()])],
    'application of a firm withdrawn' => [Contract::APPLICATION_WITHDRAWN, applicationEvent(['status' => 'withdrawn', 'closed_at' => '2026-10-11T08:00:00Z', 'applicant' => firmApplicant()])],
    'firm selected' => [Contract::COUNTERPARTY_SELECTED, firmSelected()],
    'assignment received' => [Contract::ASSIGNMENT_RECEIVED, assignmentReceived()],
    'assignment received, open to anyone, with a new field of the transaction' => [Contract::ASSIGNMENT_RECEIVED, assignmentReceived(['audience' => 'any', 'settlements' => []])],
]);

it('C4: fixes a schema for every event type of the contract, with its name in the $id', function (string $type): void {
    $schema = json_decode((string) file_get_contents(Contract::schemaPath($type, 1)));

    expect($schema->{'$schema'})->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and($schema->{'$id'})->toBe("https://ituoiprofessionistidigitali.it/contract/event-types/{$type}/1.json")
        ->and($schema->title)->toBe("{$type} v1");
})->with([Contract::CONTRACT_DETAILS, Contract::ACTIVITIES_ASSIGNED, Contract::ACTIVITY_STATUS]);

it('L6: counts the assignment received among the events only the platform sends, and the events between firms out of them', function (): void {
    expect(Contract::PLATFORM_EVENTS)->toContain(Contract::ASSIGNMENT_RECEIVED)
        ->not->toContain(Contract::CONTRACT_DETAILS)
        ->not->toContain(Contract::ACTIVITIES_ASSIGNED)
        ->not->toContain(Contract::ACTIVITY_STATUS);
});

it('L9: takes in transaction.withdrawn only a published transaction withdrawn by the platform, and why', function (array $payload): void {
    expect(matchesSchema(Contract::TRANSACTION_WITHDRAWN, $payload))->toBeFalse();
})->with([
    'with a counterparty' => [transactionWithdrawn(['counterparty' => recordedTransaction()['counterparty']])],
    'not withdrawn' => [transactionWithdrawn(['status' => 'published', 'closed_at' => null])],
    'without closing' => [transactionWithdrawn(['closed_at' => null])],
    'without expiry' => [transactionWithdrawn(['expires_at' => null])],
    'answered' => [transactionWithdrawn(['responded_at' => '2026-10-12T09:00:00Z'])],
    'at the first revision' => [transactionWithdrawn(['revision' => 1])],
    'with a closed activity' => [transactionWithdrawn(['activities' => [[...recordedTransaction()['activities'][0], 'status' => 'revoked', 'closed_at' => '2026-11-30T23:00:00Z']]])],
    'without reason' => [['transaction' => withdrawnTransaction()]],
    'with an unknown reason' => [[...transactionWithdrawn(), 'reason' => 'operator']],
    'one more field' => [[...transactionWithdrawn(), 'notes' => 'Testo libero']],
]);

it('L7: keeps the tax code, the email, the VAT number and the mobile out of an application, and nothing else in the events', function (string $type, array $payload): void {
    expect(matchesSchema($type, $payload))->toBeFalse();
})->with([
    'tax code of the applicant' => [Contract::APPLICATION_RECEIVED, applicationEvent(['applicant' => [...application()['applicant'], 'tax_code' => 'RSSMRA80A01H501U']])],
    'email of the applicant' => [Contract::APPLICATION_WITHDRAWN, applicationEvent(['status' => 'withdrawn', 'closed_at' => '2026-10-11T08:00:00Z', 'applicant' => [...application()['applicant'], 'email' => 'mario.rossi@example.com']])],
    'received not pending' => [Contract::APPLICATION_RECEIVED, applicationEvent(['status' => 'selected'])],
    'withdrawn without closing' => [Contract::APPLICATION_WITHDRAWN, applicationEvent(['status' => 'withdrawn'])],
    'one more field' => [Contract::APPLICATION_RECEIVED, [...applicationEvent(), 'notes' => 'Testo libero']],
    'selection without mobile' => [Contract::COUNTERPARTY_SELECTED, [...counterpartySelected(), 'contact' => []]],
    'mobile not in E.164' => [Contract::COUNTERPARTY_SELECTED, [...counterpartySelected(), 'contact' => ['mobile' => '3331234567']]],
    'selection already signed' => [Contract::COUNTERPARTY_SELECTED, counterpartySelected(['signed_at' => '2026-10-12T10:00:00Z'])],
    'firm with a VAT number before the selection' => [Contract::APPLICATION_RECEIVED, applicationEvent(['applicant' => [...firmApplicant(), 'vat_number' => '01234567897']])],
    'firm with the fields of a person' => [Contract::APPLICATION_RECEIVED, applicationEvent(['applicant' => [...firmApplicant(), 'first_name' => 'Mario']])],
    'member counterparty with a tax code' => [Contract::COUNTERPARTY_SELECTED, [...firmSelected(), 'transaction' => [...firmSelected()['transaction'], 'counterparty' => [...firmSelected()['transaction']['counterparty'], 'tax_code' => 'RSSMRA80A01H501U']]]],
]);
