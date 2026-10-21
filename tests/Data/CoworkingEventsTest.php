<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\ActivitiesAssignedData;
use ITuoiProfessionistiDigitali\Connector\Data\ActivityStatusData;
use ITuoiProfessionistiDigitali\Connector\Data\AssignmentAcceptedData;
use ITuoiProfessionistiDigitali\Connector\Data\AssignmentDeclinedData;
use ITuoiProfessionistiDigitali\Connector\Data\AssignmentOfferedData;
use ITuoiProfessionistiDigitali\Connector\Data\AssignmentRevokedData;
use ITuoiProfessionistiDigitali\Connector\Data\ContractDetailsData;
use ITuoiProfessionistiDigitali\Connector\Enums\ActivityStatusChange;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;

const COWORKING_TRANSACTION = '0199b6f5-0000-7000-8000-000000000001';

const PRINCIPAL_MEMBER = '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43';

const SELECTED_MEMBER = '0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55';

/**
 * @return array<string, mixed>
 */
function contractDetails(array $party = [], array $signatory = []): array
{
    return [
        'transaction' => ['id' => COWORKING_TRANSACTION],
        'party' => [
            'name' => 'Studio Rossi e Associati',
            'vat_number' => '01234567897',
            'tax_code' => null,
            'address' => ['street' => 'Via Sparano 10', 'postal_code' => '70121', 'municipality' => 'Bari', 'province' => 'BA'],
            'pec' => 'studiorossi@pec.example',
            'recipient_code' => 'M5UXCR1',
            ...$party,
        ],
        'signatory' => [
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'tax_code' => 'RSSMRA80A01H501U',
            'email' => 'mario.rossi@example.com',
            'mobile' => '+393331234567',
            ...$signatory,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function activityStatus(array $overrides = []): array
{
    return ['transaction' => ['id' => COWORKING_TRANSACTION], 'activity' => '43', 'status' => 'completed', 'occurred_at' => '2026-11-20T09:30:00+00:00', ...$overrides];
}

it('E13: builds the envelope of an event between firms, correlated to the transaction, with a payload of its schema', function (string $type, string $data, array $payload): void {
    $envelope = $data::validateAndCreate($payload)->toEnvelope(sender: SELECTED_MEMBER, recipient: PRINCIPAL_MEMBER, typology: 'commercialisti');

    expect($envelope)
        ->type->toBe($type)
        ->schema_version->toBe(1)
        ->sender->toBe(SELECTED_MEMBER)
        ->recipient->toBe(PRINCIPAL_MEMBER)
        ->typology->toBe('commercialisti')
        ->correlation_id->toBe(COWORKING_TRANSACTION)
        ->payload->toBe($payload)
        ->and(matchesSchema($type, $envelope->payload))->toBeTrue()
        ->and($data::from($envelope->payload)->toPayload())->toBe($payload);
})->with([
    'contract details' => [Contract::CONTRACT_DETAILS, ContractDetailsData::class, contractDetails()],
    'contract details of a firm with a tax code, without PEC and recipient code' => [Contract::CONTRACT_DETAILS, ContractDetailsData::class, contractDetails(['tax_code' => 'RSSMRA80A01H501U', 'pec' => null, 'recipient_code' => null])],
    'activities assigned' => [Contract::ACTIVITIES_ASSIGNED, ActivitiesAssignedData::class, activitiesAssigned()],
    'activities assigned without names, deadline and guides' => [Contract::ACTIVITIES_ASSIGNED, ActivitiesAssignedData::class, activitiesAssigned(['name' => null, 'deadline' => null, 'guides' => []])],
    'activity completed' => [Contract::ACTIVITY_STATUS, ActivityStatusData::class, activityStatus()],
    'activity reopened' => [Contract::ACTIVITY_STATUS, ActivityStatusData::class, activityStatus(['status' => 'reopened'])],
    'assignment offered' => [Contract::ASSIGNMENT_OFFERED, AssignmentOfferedData::class, assignmentEvent(Contract::ASSIGNMENT_OFFERED)],
    'assignment accepted' => [Contract::ASSIGNMENT_ACCEPTED, AssignmentAcceptedData::class, assignmentEvent(Contract::ASSIGNMENT_ACCEPTED)],
    'assignment declined' => [Contract::ASSIGNMENT_DECLINED, AssignmentDeclinedData::class, assignmentEvent(Contract::ASSIGNMENT_DECLINED)],
    'assignment declined without reason' => [Contract::ASSIGNMENT_DECLINED, AssignmentDeclinedData::class, assignmentEvent(Contract::ASSIGNMENT_DECLINED, ['reason' => null])],
    'assignment revoked' => [Contract::ASSIGNMENT_REVOKED, AssignmentRevokedData::class, assignmentEvent(Contract::ASSIGNMENT_REVOKED)],
]);

it('E12: lists in the envelope the files the payload cites, once each', function (): void {
    $guide = ['title' => 'Altra guida', 'body' => 'Testo.', 'attachments' => ['0199c1a0-0000-7000-8000-000000000001', '0199c1a0-0000-7000-8000-000000000002']];
    $payload = activitiesAssigned(['guides' => [...activitiesAssigned()['processes'][0]['activities'][0]['guides'], $guide]]);

    expect(ActivitiesAssignedData::from($payload)->toEnvelope(PRINCIPAL_MEMBER, SELECTED_MEMBER, 'commercialisti')->fileIds())
        ->toBe(['0199c1a0-0000-7000-8000-000000000001', '0199c1a0-0000-7000-8000-000000000002'])
        ->and(ActivityStatusData::from(activityStatus())->toEnvelope(SELECTED_MEMBER, PRINCIPAL_MEMBER, 'commercialisti')->fileIds())->toBe([]);
});

it('E2: keeps the event_id given to the envelope', function (): void {
    $envelope = ActivityStatusData::from(activityStatus())->toEnvelope(SELECTED_MEMBER, PRINCIPAL_MEMBER, 'commercialisti', eventId: '0199b6f2-0000-7000-8000-000000000042');

    expect($envelope->event_id)->toBe('0199b6f2-0000-7000-8000-000000000042');
});

it('reads the details, the work and the status sent by the other firm', function (): void {
    $details = ContractDetailsData::from(contractDetails());
    $work = ActivitiesAssignedData::from(activitiesAssigned());
    $status = ActivityStatusData::from(activityStatus(['occurred_at' => '2026-11-20T10:30:00+01:00']));

    expect($details->transaction->id)->toBe(COWORKING_TRANSACTION)
        ->and($details->party->address->postal_code)->toBe('70121')
        ->and($details->signatory->mobile)->toBe('+393331234567')
        ->and($work->processes[0]->client->name)->toBe('Bianchi Srl')
        ->and($work->processes[0]->activities[0]->guides[0]->attachments)->toBe(['0199c1a0-0000-7000-8000-000000000001'])
        ->and($status->status)->toBe(ActivityStatusChange::Completed)
        ->and($status->occurred_at->toIso8601ZuluString())->toBe('2026-11-20T09:30:00Z')
        ->and($status->toPayload()['occurred_at'])->toBe('2026-11-20T10:30:00+01:00');
});

it('M4: refuses the details of a firm or of a signatory with a wrong check character', function (array $payload, string $field): void {
    try
    {
        ContractDetailsData::validateAndCreate($payload);
        $this->fail('The details should be refused.');
    }
    catch (ValidationException $exception)
    {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'VAT number' => [contractDetails(['vat_number' => '01234567890']), 'party.vat_number'],
    'tax code of the firm' => [contractDetails(['tax_code' => '01234567890']), 'party.tax_code'],
    'tax code of the signatory' => [contractDetails(signatory: ['tax_code' => 'RSSMRA80A01H501A']), 'signatory.tax_code'],
    'tax code of an organization for the signatory' => [contractDetails(signatory: ['tax_code' => '01234567897']), 'signatory.tax_code'],
    'mobile not in E.164' => [contractDetails(signatory: ['mobile' => '3331234567']), 'signatory.mobile'],
    'email of the signatory' => [contractDetails(signatory: ['email' => 'mario']), 'signatory.email'],
    'PEC' => [contractDetails(['pec' => 'pec']), 'party.pec'],
]);

it('M4: refuses a final client with a wrong tax code', function (): void {
    ActivitiesAssignedData::validateAndCreate([...activitiesAssigned(), 'processes' => [[...activitiesAssigned()['processes'][0], 'client' => ['name' => 'Bianchi Srl', 'tax_code' => '01234567890']]]]);
})->throws(ValidationException::class);

it('E7: refuses to build an envelope whose payload is outside the schema', function (): void {
    ActivitiesAssignedData::from(activitiesAssigned(['reference' => '']))->toEnvelope(PRINCIPAL_MEMBER, SELECTED_MEMBER, 'commercialisti');
})->throws(PlatformRequestException::class);

it('R24: reads the answer of the firm with its instant, to register it as responded_at', function (): void {
    $accepted = AssignmentAcceptedData::from(assignmentEvent(Contract::ASSIGNMENT_ACCEPTED, ['answered_at' => '2026-10-21T11:00:00+02:00']));
    $declined = AssignmentDeclinedData::from(assignmentEvent(Contract::ASSIGNMENT_DECLINED));
    $revoked = AssignmentRevokedData::from(assignmentEvent(Contract::ASSIGNMENT_REVOKED));

    expect($accepted->transaction->id)->toBe(COWORKING_TRANSACTION)
        ->and($accepted->answered_at->toIso8601ZuluString())->toBe('2026-10-21T09:00:00Z')
        ->and($declined->reason)->toBe('Non abbiamo disponibilità a novembre.')
        ->and($revoked->revoked_at->toIso8601ZuluString())->toBe('2026-10-22T09:00:00Z');
});

it('E7: refuses an answer outside its schema', function (string $type, array $payload): void {
    expect(matchesSchema($type, $payload))->toBeFalse();
})->with([
    'offer with more than the id' => [Contract::ASSIGNMENT_OFFERED, assignmentEvent(Contract::ASSIGNMENT_OFFERED, ['title' => 'Contabilità'])],
    'acceptance without its instant' => [Contract::ASSIGNMENT_ACCEPTED, assignmentEvent(Contract::ASSIGNMENT_OFFERED)],
    'refusal with an empty reason' => [Contract::ASSIGNMENT_DECLINED, assignmentEvent(Contract::ASSIGNMENT_DECLINED, ['reason' => ''])],
    'refusal with a reason too long' => [Contract::ASSIGNMENT_DECLINED, assignmentEvent(Contract::ASSIGNMENT_DECLINED, ['reason' => str_repeat('a', 1001)])],
    'revocation without its instant' => [Contract::ASSIGNMENT_REVOKED, assignmentEvent(Contract::ASSIGNMENT_OFFERED)],
]);
