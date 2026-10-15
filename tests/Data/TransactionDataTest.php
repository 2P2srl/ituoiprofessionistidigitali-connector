<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use ITuoiProfessionistiDigitali\Connector\Data\ActivityDescriptionData;
use ITuoiProfessionistiDigitali\Connector\Data\CompensationData;
use ITuoiProfessionistiDigitali\Connector\Data\CounterpartyData;
use ITuoiProfessionistiDigitali\Connector\Data\RecordedTransactionData;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionActivityData;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionData;
use ITuoiProfessionistiDigitali\Connector\Enums\Audience;
use ITuoiProfessionistiDigitali\Connector\Enums\TransactionActivityStatus;
use ITuoiProfessionistiDigitali\Connector\Enums\TransactionStatus;
use ITuoiProfessionistiDigitali\Connector\Tests\Fixtures\NestedActivitiesData;
use Spatie\LaravelData\Data;

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
 * A member of the same system as the counterparty of an assignment to a firm.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function firm(array $overrides = []): array
{
    return ['type' => 'member', 'member_id' => '0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55', 'tax_code' => null, 'first_name' => null, 'last_name' => null, 'email' => null, 'vat_number' => null, 'municipality' => null, 'province' => null, ...$overrides];
}

/**
 * The activities and dates of a transaction in each status, consistent with rules R8 and R14.
 *
 * @return array<string, mixed>
 */
function transactionIn(string $status): array
{
    return transaction(['status' => $status, ...match ($status)
    {
        'accepted', 'declined' => ['responded_at' => '2026-10-07T09:00:00Z'],
        'completed' => ['responded_at' => '2026-10-07T09:00:00Z', 'signed_at' => '2026-10-07T10:30:00Z', 'closed_at' => '2026-10-20T09:00:00Z', 'activities' => [activity(['status' => 'completed', 'closed_at' => '2026-10-20T09:00:00Z', 'minutes_worked' => 90])]],
        'revoked' => ['closed_at' => '2026-10-20T09:00:00Z', 'activities' => [activity(['status' => 'revoked', 'closed_at' => '2026-10-20T09:00:00Z'])]],
        'published' => ['audience' => 'any', 'counterparty' => null, 'title' => 'Contabilità di una srl', 'description' => 'Registrazione delle fatture **mensile**.', 'expires_at' => '2026-11-06T18:00:00+01:00'],
        'withdrawn' => ['audience' => 'person', 'counterparty' => null, 'title' => 'Contabilità di una srl', 'description' => 'Registrazione delle fatture.', 'expires_at' => '2026-11-06T18:00:00+01:00', 'closed_at' => '2026-10-20T09:00:00Z'],
        default => [],
    }]);
}

/**
 * The errors of the activities of a body as a class validates it: every key under activities, with its messages,
 * sorted by key. The nested rules of Spatie gave first the error of an activity that is not an object.
 *
 * @param  class-string<Data>  $class
 * @param  array<string, mixed>  $body
 * @return array<string, array<int, string>>
 */
function activityErrors(string $class, array $body): array
{
    try
    {
        $class::validate($body);

        return [];
    }
    catch (ValidationException $exception)
    {
        $errors = array_filter($exception->errors(), static fn (string $key): bool => $key === 'activities' || str_starts_with($key, 'activities.'), ARRAY_FILTER_USE_KEY);
        ksort($errors);

        return $errors;
    }
}

/**
 * A transaction with this many activities, all valid and each with its own reference.
 *
 * @return array<string, mixed>
 */
function transactionWith(int $activities): array
{
    return transaction(['activities' => array_map(static fn (int $index): array => activity(['reference' => "riga-{$index}"]), range(1, $activities))]);
}

it('R3, R5 and R8: sends a valid transaction with exactly the body of the platform', function (): void {
    $wire = TransactionData::validateAndCreate(transaction())->toWire(revision: 3);

    expect(array_keys($wire))->toBe([
        'assignment_reference', 'audience', 'principal', 'counterparty', 'typology', 'title', 'description', 'status', 'sent_at', 'expires_at',
        'responded_at', 'signed_at', 'closed_at', 'currency', 'revision', 'type', 'schema_version', 'activities',
    ])->and($wire['signed_at'])->toBeNull()
        ->and($wire['revision'])->toBe(3)
        ->and($wire['sent_at'])->toBe('2026-10-06T18:00:00+02:00')
        ->and($wire['currency'])->toBe('EUR')
        ->and($wire['type'])->toBe('assignment')
        ->and($wire['schema_version'])->toBe(1)
        ->and($wire['audience'])->toBe('person')
        ->and($wire['counterparty'])->toBe(['type' => 'person', 'member_id' => null, 'tax_code' => 'RSSMRA80A01H501U', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario.rossi@example.com', 'vat_number' => null, 'municipality' => 'Bari', 'province' => 'BA'])
        ->and($wire['activities'][0])->toBe([
            'reference' => 'riga-1',
            'compensation' => ['form' => 'hourly', 'hourly_rate_cents' => 4500, 'fixed_amount_cents' => null],
            'estimated_minutes' => 120,
            'minutes_worked' => null,
            'status' => 'open',
            'closed_at' => null,
            'description' => ['process' => ['name' => 'Contabilità ordinaria'], 'activity' => ['name' => 'Registrazione fatture'], 'deadline' => '2026-11-30'],
        ]);
});

it('R3 and R16: sends a publication without counterparty, open to whom it says', function (): void {
    $wire = TransactionData::validateAndCreate(transactionIn('published'))->toWire(revision: 1);

    expect($wire['audience'])->toBe('any')
        ->and($wire['counterparty'])->toBeNull()
        ->and(array_slice(array_keys($wire), 3, 4))->toBe(['counterparty', 'typology', 'title', 'description'])
        ->and($wire['title'])->toBe('Contabilità di una srl')
        ->and($wire['description'])->toBe('Registrazione delle fatture **mensile**.')
        ->and($wire['expires_at'])->toBe('2026-11-06T18:00:00+01:00');
});

it('builds the same body from code, with the typed DTOs', function (): void {
    $fromCode = new TransactionData(
        assignment_reference: 'incarico-1',
        audience: Audience::Person,
        principal: '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43',
        counterparty: CounterpartyData::person('RSSMRA80A01H501U', 'Mario', 'Rossi', 'mario.rossi@example.com', municipality: 'Bari', province: 'BA'),
        typology: 'commercialisti',
        title: 'Contabilità ordinaria 2026',
        description: 'Registrazione delle fatture del 2026.',
        status: TransactionStatus::Invited,
        sent_at: CarbonImmutable::parse('2026-10-06T18:00:00+02:00'),
        activities: [new TransactionActivityData(
            reference: 'riga-1',
            compensation: CompensationData::hourly(4500),
            estimated_minutes: 120,
            status: TransactionActivityStatus::Open,
            description: new ActivityDescriptionData('Contabilità ordinaria', 'Registrazione fatture', '2026-11-30'),
        )],
    );

    expect($fromCode->toWire(1))->toBe(TransactionData::validateAndCreate(transaction())->toWire(1));
});

it('R3: sends the optional details of a person as null when the system has none', function (): void {
    expect(CounterpartyData::person('RSSMRA80A01H501U', 'Mario', 'Rossi', 'mario.rossi@example.com')->toWire())->toBe([
        'type' => 'person', 'member_id' => null, 'tax_code' => 'RSSMRA80A01H501U', 'first_name' => 'Mario', 'last_name' => 'Rossi',
        'email' => 'mario.rossi@example.com', 'vat_number' => null, 'municipality' => null, 'province' => null,
    ])->and(TransactionData::validateAndCreate(transaction(['counterparty' => person(['vat_number' => '01234567897', 'municipality' => null, 'province' => null])]))->counterparty?->vat_number)
        ->toBe('01234567897');
});

it('R6: takes the texts of the firm in a direct assignment too, with HTML in the Markdown', function (): void {
    $wire = TransactionData::validateAndCreate(transaction(['title' => 'Bilancio 2026', 'description' => "## Cosa serve\n\n<b>entro</b> marzo"]))->toWire(1);

    expect($wire['title'])->toBe('Bilancio 2026')
        ->and($wire['description'])->toBe("## Cosa serve\n\n<b>entro</b> marzo")
        ->and(fn () => TransactionData::validateAndCreate(transaction(['title' => null])))->toThrow(ValidationException::class);
});

it('R3: sends a member counterparty and a fixed price with every key, null where they do not apply', function (): void {
    $wire = TransactionData::validateAndCreate(transaction([
        'audience' => 'member',
        'counterparty' => CounterpartyData::member('0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55')->toWire(),
        'activities' => [activity(['compensation' => CompensationData::fixed(150000)->toWire()])],
    ]))->toWire(1);

    expect($wire['counterparty'])->toBe(firm())
        ->and($wire['activities'][0]['compensation'])->toBe(['form' => 'fixed', 'hourly_rate_cents' => null, 'fixed_amount_cents' => 150000]);
});

it('R6: reads a description flat or in the form of the schema, and sends missing names as null', function (): void {
    $flat = TransactionData::validateAndCreate(transaction(['activities' => [activity(['description' => ['process' => 'Contabilità ordinaria', 'activity' => null, 'deadline' => null]])]]));

    expect($flat->activities[0]->description->process)->toBe('Contabilità ordinaria')
        ->and($flat->toWire(1)['activities'][0]['description'])->toBe(['process' => ['name' => 'Contabilità ordinaria'], 'activity' => null, 'deadline' => null])
        ->and(TransactionData::from(transaction(['activities' => [activity(['description' => []])]]))->toWire(1)['activities'][0]['description'])
        ->toBe(['process' => null, 'activity' => null, 'deadline' => null]);
});

it('accepts every status with its activities and dates', function (string $status): void {
    expect(TransactionData::validateAndCreate(transactionIn($status))->status)->toBe(TransactionStatus::from($status));
})->with(['invited', 'accepted', 'declined', 'completed', 'revoked', 'published', 'withdrawn']);

it('R8 and R22: accepts the signature where the contract allows it, and the work after it', function (array $body): void {
    expect(TransactionData::validateAndCreate($body)->status)->toBe(TransactionStatus::from($body['status']));
})->with([
    'accepted, waiting for the signature' => [transactionIn('accepted')],
    'accepted and signed' => [[...transactionIn('accepted'), 'signed_at' => '2026-10-07T10:30:00Z']],
    'signed when answered' => [[...transactionIn('accepted'), 'signed_at' => '2026-10-07T09:00:00Z']],
    'accepted and signed, with an activity done after the signature' => [[...transactionIn('accepted'), 'signed_at' => '2026-10-07T10:30:00Z', 'activities' => [
        activity(['status' => 'completed', 'closed_at' => '2026-10-07T10:30:00Z', 'minutes_worked' => 90]),
        activity(['reference' => 'riga-2']),
    ]]],
    'completed, signed when closed' => [[...transactionIn('completed'), 'signed_at' => '2026-10-20T09:00:00Z']],
    'revoked before the signature, without minutes' => [[...transactionIn('revoked'), 'responded_at' => '2026-10-07T09:00:00Z', 'activities' => [activity(['status' => 'revoked', 'closed_at' => '2026-10-20T09:00:00Z', 'minutes_worked' => 0])]]],
    'revoked after the signature, with the minutes worked' => [[...transactionIn('revoked'), 'responded_at' => '2026-10-07T09:00:00Z', 'signed_at' => '2026-10-07T10:30:00Z', 'activities' => [activity(['status' => 'revoked', 'closed_at' => '2026-10-20T09:00:00Z', 'minutes_worked' => 30])]]],
]);

it('R15: computes the totals as the platform does, by the hour with the half cent up', function (int $rate, int $minutes, int $total): void {
    $transaction = TransactionData::from(transaction(['activities' => [
        activity(['compensation' => ['form' => 'hourly', 'hourly_rate_cents' => $rate], 'estimated_minutes' => $minutes]),
        activity(['reference' => 'riga-2', 'compensation' => ['form' => 'fixed', 'fixed_amount_cents' => 150000], 'estimated_minutes' => 600]),
    ]]));

    expect($transaction->activities[0]->totalCents())->toBe($total)
        ->and($transaction->activities[1]->totalCents())->toBe(150000)
        ->and($transaction->totalCents())->toBe($total + 150000);
})->with([
    'whole' => [4500, 120, 9000],
    'below the half' => [4529, 1, 75],
    'the half' => [4530, 1, 76],
]);

it('R16 and L4: keeps whom a published assignment was open to, and its expiry, after the selection of a person', function (string $audience): void {
    $wire = TransactionData::validateAndCreate([...transactionIn('accepted'), 'audience' => $audience, 'expires_at' => '2026-10-31T22:59:59Z'])->toWire(revision: 3);

    expect($wire['audience'])->toBe($audience)
        ->and($wire['counterparty'])->toBe(person())
        ->and($wire['expires_at'])->toBe('2026-10-31T22:59:59+00:00');
})->with(['any', 'person']);

it('L4: sends again the transaction the platform accepted with the selection, as it returned it', function (): void {
    $recorded = RecordedTransactionData::from(selectedTransaction(['activities' => [
        [...activity(['compensation' => ['form' => 'fixed', 'hourly_rate_cents' => null, 'fixed_amount_cents' => 90000], 'description' => ['process' => null, 'activity' => null, 'deadline' => null]]), 'total_cents' => 90000],
    ]]));

    $wire = TransactionData::validateAndCreate($recorded->toTransaction()->toWire(revision: 3))->toWire(revision: 3);

    expect($wire)->toBe([
        ...Arr::except(selectedTransaction(), ['id', 'origin', 'reference', 'total_cents', 'updated_at']),
        'sent_at' => '2026-10-06T16:00:00+00:00',
        'expires_at' => '2026-10-31T22:59:59+00:00',
        'responded_at' => '2026-10-12T09:00:00+00:00',
        'revision' => 3,
        'activities' => [activity(['compensation' => ['form' => 'fixed', 'hourly_rate_cents' => null, 'fixed_amount_cents' => 90000], 'description' => ['process' => null, 'activity' => null, 'deadline' => null]])],
    ]);
});

it('refuses what the register would refuse', function (array $body, string $field): void {
    try
    {
        TransactionData::validateAndCreate($body);
        $this->fail('The transaction should not be valid.');
    }
    catch (ValidationException $exception)
    {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'R3 member without id' => [transaction(['audience' => 'member', 'counterparty' => firm(['member_id' => null])]), 'counterparty.member_id'],
    'R3 person with a member id' => [transaction(['counterparty' => person(['member_id' => '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43'])]), 'counterparty.member_id'],
    'R3 direct to anyone' => [transaction(['audience' => 'any']), 'audience'],
    'R3 audience other than the counterparty' => [transaction(['audience' => 'member']), 'counterparty.type'],
    'R3 a key of the person missing' => [transaction(['counterparty' => Arr::except(person(), 'email')]), 'counterparty.email'],
    'R3 the member id missing' => [transaction(['counterparty' => Arr::except(person(), 'member_id')]), 'counterparty.member_id'],
    'R3 wrong tax code' => [transaction(['counterparty' => person(['tax_code' => 'RSSMRA80A01H501A'])]), 'counterparty.tax_code'],
    'R3 person without first name' => [transaction(['counterparty' => person(['first_name' => null])]), 'counterparty.first_name'],
    'R3 person without last name' => [transaction(['counterparty' => person(['last_name' => null])]), 'counterparty.last_name'],
    'R3 last name too long' => [transaction(['counterparty' => person(['last_name' => str_repeat('a', 256)])]), 'counterparty.last_name'],
    'R3 person without email' => [transaction(['counterparty' => person(['email' => null])]), 'counterparty.email'],
    'R3 wrong email' => [transaction(['counterparty' => person(['email' => 'mario'])]), 'counterparty.email'],
    'R3 email too long' => [transaction(['counterparty' => person(['email' => str_repeat('a', 244).'@example.com'])]), 'counterparty.email'],
    'R3 member with an email' => [transaction(['audience' => 'member', 'counterparty' => firm(['email' => 'mario.rossi@example.com'])]), 'counterparty.email'],
    'R3 wrong vat number' => [transaction(['counterparty' => person(['vat_number' => '01234567890'])]), 'counterparty.vat_number'],
    'R3 empty municipality' => [transaction(['counterparty' => person(['municipality' => ''])]), 'counterparty.municipality'],
    'R3 province in lower case' => [transaction(['counterparty' => person(['province' => 'ba'])]), 'counterparty.province'],
    'R3 member with a name' => [transaction(['audience' => 'member', 'counterparty' => firm(['last_name' => 'Rossi'])]), 'counterparty.last_name'],
    'R3 sent without counterparty' => [transaction(['counterparty' => null]), 'counterparty'],
    'R6 a field of the client' => [transaction(['activities' => [activity(['description' => ['process' => ['name' => 'x', 'client' => 'Rossi'], 'activity' => null, 'deadline' => null]])]]), 'activities.0.description.process'],
    'R6 an empty name' => [transaction(['activities' => [activity(['description' => ['process' => '', 'activity' => null, 'deadline' => null]])]]), 'activities.0.description.process'],
    'R6 a deadline that is not a date' => [transaction(['activities' => [activity(['description' => ['process' => null, 'activity' => null, 'deadline' => 'domani']])]]), 'activities.0.description.deadline'],
    'R16 without audience' => [[...transactionIn('published'), 'audience' => null], 'audience'],
    'R16 open to a bank' => [[...transactionIn('published'), 'audience' => 'bank'], 'audience'],
    'R16 sent without audience' => [transaction(['audience' => null]), 'audience'],
    'R16 selected for firms, with a person' => [[...transactionIn('accepted'), 'audience' => 'member', 'expires_at' => '2026-10-31T22:59:59Z'], 'counterparty.type'],
    'R3 published with a counterparty' => [[...transactionIn('published'), 'counterparty' => person()], 'counterparty'],
    'R5 both amounts' => [transaction(['activities' => [activity(['compensation' => ['form' => 'hourly', 'hourly_rate_cents' => 4500, 'fixed_amount_cents' => 1]])]]), 'activities.0.compensation.fixed_amount_cents'],
    'R5 fixed without amount' => [transaction(['activities' => [activity(['compensation' => ['form' => 'fixed']])]]), 'activities.0.compensation.fixed_amount_cents'],
    'R5 another currency' => [transaction(['currency' => 'USD']), 'currency'],
    'R5 no estimated minutes' => [transaction(['activities' => [activity(['estimated_minutes' => null])]]), 'activities.0.estimated_minutes'],
    'R5 minutes of an open activity' => [transaction(['activities' => [activity(['minutes_worked' => 0])]]), 'activities.0.minutes_worked'],
    'R5 completed activity without minutes' => [[...transactionIn('completed'), 'activities' => [activity(['status' => 'completed', 'closed_at' => '2026-10-20T09:00:00Z'])]], 'activities.0.minutes_worked'],
    'R5 minutes of a firm' => [[...transactionIn('completed'), 'audience' => 'member', 'counterparty' => firm()], 'activities.0.minutes_worked'],
    'R6 no activities' => [transaction(['activities' => []]), 'activities'],
    'R8 accepted without answer' => [transaction(['status' => 'accepted']), 'responded_at'],
    'R8 completed without closing' => [[...transactionIn('completed'), 'closed_at' => null], 'closed_at'],
    'R8 invited with an answer' => [transaction(['responded_at' => '2026-10-07T09:00:00Z']), 'responded_at'],
    'R8 published with an answer' => [[...transactionIn('published'), 'responded_at' => '2026-10-07T09:00:00Z'], 'responded_at'],
    'R8 completed without signature' => [[...transactionIn('completed'), 'signed_at' => null], 'signed_at'],
    'R8 invited and signed' => [transaction(['signed_at' => '2026-10-07T10:30:00Z']), 'signed_at'],
    'R8 declined and signed' => [[...transactionIn('declined'), 'signed_at' => '2026-10-07T10:30:00Z'], 'signed_at'],
    'R8 published and signed' => [[...transactionIn('published'), 'signed_at' => '2026-10-07T10:30:00Z'], 'signed_at'],
    'R8 withdrawn and signed' => [[...transactionIn('withdrawn'), 'signed_at' => '2026-10-07T10:30:00Z'], 'signed_at'],
    'R8 signed without answer' => [[...transactionIn('revoked'), 'signed_at' => '2026-10-07T10:30:00Z'], 'signed_at'],
    'R8 signed before the answer' => [[...transactionIn('accepted'), 'signed_at' => '2026-10-07T08:59:59Z'], 'signed_at'],
    'R8 signed after the closing' => [[...transactionIn('completed'), 'signed_at' => '2026-10-20T09:00:01Z'], 'signed_at'],
    'R22 an activity completed without signature' => [[...transactionIn('accepted'), 'activities' => [activity(['status' => 'completed', 'closed_at' => '2026-10-20T09:00:00Z', 'minutes_worked' => 90]), activity(['reference' => 'riga-2'])]], 'activities.0.status'],
    'R22 an activity completed before the signature' => [[...transactionIn('completed'), 'activities' => [activity(['status' => 'completed', 'closed_at' => '2026-10-07T10:29:59Z', 'minutes_worked' => 90])]], 'activities.0.closed_at'],
    'R22 minutes worked without signature' => [[...transactionIn('revoked'), 'activities' => [activity(['status' => 'revoked', 'closed_at' => '2026-10-20T09:00:00Z', 'minutes_worked' => 30])]], 'activities.0.minutes_worked'],
    'R8 closed activity without closing' => [[...transactionIn('revoked'), 'activities' => [activity(['status' => 'revoked'])]], 'activities.0.closed_at'],
    'R14 invited with a closed activity' => [[...transactionIn('invited'), 'activities' => transactionIn('revoked')['activities']], 'status'],
    'R14 accepted without open activities' => [[...transactionIn('accepted'), 'activities' => transactionIn('completed')['activities']], 'status'],
    'R14 completed with an open activity' => [[...transactionIn('completed'), 'activities' => [...transactionIn('completed')['activities'], activity(['reference' => 'riga-2'])]], 'status'],
    'R14 revoked with a completed activity' => [[...transactionIn('revoked'), 'activities' => transactionIn('completed')['activities']], 'status'],
    'R16 published without expiry' => [[...transactionIn('published'), 'expires_at' => null], 'expires_at'],
    'R16 expiry before the sending' => [[...transactionIn('published'), 'expires_at' => '2026-10-01T09:00:00Z'], 'expires_at'],
    'R16 invited with an expiry' => [transaction(['expires_at' => '2026-11-06T18:00:00+01:00']), 'expires_at'],
    'R16 invited to anyone with an expiry' => [transaction(['audience' => 'any', 'expires_at' => '2026-11-06T18:00:00+01:00']), 'expires_at'],
    'R16 declined with an expiry' => [[...transactionIn('declined'), 'expires_at' => '2026-11-06T18:00:00+01:00'], 'expires_at'],
    'unknown status' => [transaction(['status' => 'rejected']), 'status'],
    'typology in upper case' => [transaction(['typology' => 'Commercialisti']), 'typology'],
    'R6 published without title' => [[...transactionIn('published'), 'title' => null], 'title'],
    'R6 sent without title' => [transaction(['title' => null]), 'title'],
    'R6 sent without description' => [transaction(['description' => null]), 'description'],
    'without the key of the counterparty' => [Arr::except(transaction(), 'counterparty'), 'counterparty'],
    'without the key of the expiry' => [Arr::except(transaction(), 'expires_at'), 'expires_at'],
    'without the key of the answer' => [Arr::except(transaction(), 'responded_at'), 'responded_at'],
    'without the key of the signature' => [Arr::except(transaction(), 'signed_at'), 'signed_at'],
    'without the key of the closing' => [Arr::except(transaction(), 'closed_at'), 'closed_at'],
    'an activity without the key of the minutes worked' => [transaction(['activities' => [Arr::except(activity(), 'minutes_worked')]]), 'activities.0.minutes_worked'],
    'an activity without the key of the closing' => [transaction(['activities' => [Arr::except(activity(), 'closed_at')]]), 'activities.0.closed_at'],
    'a compensation without the key of the fixed amount' => [transaction(['activities' => [activity(['compensation' => ['form' => 'hourly', 'hourly_rate_cents' => 4500]])]]), 'activities.0.compensation.fixed_amount_cents'],
    'a description without the key of the deadline' => [transaction(['activities' => [activity(['description' => ['process' => null, 'activity' => null]])]]), 'activities.0.description.deadline'],
    'R6 published without description' => [[...transactionIn('published'), 'description' => null], 'description'],
    'R6 title too long' => [transaction(['title' => str_repeat('a', 256)]), 'title'],
    'R6 empty title' => [transaction(['title' => '']), 'title'],
    'R6 empty description' => [transaction(['description' => '']), 'description'],
    'R6 description too long' => [transaction(['description' => str_repeat('a', TransactionData::MAX_DESCRIPTION_LENGTH + 1)]), 'description'],
]);

it('R10: refuses the activities as the nested rules of Spatie did, with the same keys and messages', function (array $body, bool $refused): void {
    $errors = activityErrors(TransactionData::class, $body);

    expect($errors)->toBe(activityErrors(NestedActivitiesData::class, $body))
        ->and($errors !== [])->toBe($refused);
})->with([
    'valid activities' => [transactionWith(2), false],
    'an activity without fields' => [transaction(['activities' => [[]]]), true],
    'an activity that is not an object' => [transaction(['activities' => ['riga-1', activity(['reference' => 'riga-2'])]]), true],
    'a compensation of an unknown form' => [transaction(['activities' => [activity(['compensation' => ['form' => 'monthly', 'hourly_rate_cents' => 4500, 'fixed_amount_cents' => null]])]]), true],
    'a compensation that is not an object' => [transaction(['activities' => [activity(['compensation' => 'hourly'])]]), true],
    'R5 both amounts' => [transaction(['activities' => [activity(['compensation' => ['form' => 'hourly', 'hourly_rate_cents' => 4500, 'fixed_amount_cents' => 1]])]]), true],
    'R6 a description outside the schema' => [transaction(['activities' => [activity(['description' => ['process' => ['name' => 'x', 'client' => 'Rossi'], 'activity' => null, 'deadline' => null]])]]), true],
    'R6 a deadline that is not a date' => [transaction(['activities' => [activity(['description' => ['process' => null, 'activity' => null, 'deadline' => 'domani']])]]), true],
    'an unknown status' => [transaction(['activities' => [activity(['status' => 'paused'])]]), true],
    'R5 minutes of a firm, read from the counterparty' => [[...transactionIn('completed'), 'audience' => 'member', 'counterparty' => firm()], true],
    'R22 completed without signature, read from the header' => [[...transactionIn('accepted'), 'activities' => [activity(['status' => 'completed', 'closed_at' => '2026-10-20T09:00:00Z', 'minutes_worked' => 90]), activity(['reference' => 'riga-2'])]], true],
    'errors in several activities' => [transaction(['activities' => [activity(), activity(['reference' => null]), 'riga-3', activity(['reference' => 'riga-4', 'estimated_minutes' => -1, 'status' => 'paused'])]]), true],
    'activities that are not a list' => [transaction(['activities' => ['riga-1' => activity()]]), true],
    'without activities' => [Arr::except(transaction(), 'activities'), true],
]);

/**
 * Not in the equivalence above: the nested rules also refused each activity, and with 501 of them they take seconds.
 */
it('R10: answers only the count over the limit of the activities', function (): void {
    $errors = activityErrors(TransactionData::class, transaction(['activities' => array_fill(0, TransactionData::MAX_ACTIVITIES + 1, [])]));

    expect(array_keys($errors))->toBe(['activities'])
        ->and($errors['activities'])->toHaveCount(1);
});

it('R10: validates the activities in a time that grows with their number, not with its square', function (): void {
    $seconds = static function (int $activities): float {
        $wire = TransactionData::from(transactionWith($activities))->toWire(revision: 1);
        $start = hrtime(true);
        TransactionData::validate($wire);

        return (hrtime(true) - $start) / 1e9;
    };
    $seconds(1);
    $quarter = $seconds(TransactionData::MAX_ACTIVITIES / 4);
    $full = $seconds(TransactionData::MAX_ACTIVITIES);

    // Four times the activities: about 4 when linear, 9 measured with the nested rules
    expect($full)->toBeLessThan(8.0)
        ->and($full / $quarter)->toBeLessThan(6.5);
});

it('R8 and R14: checks an already built transaction, with enums', function (): void {
    TransactionData::validate(TransactionData::from(transactionIn('completed'))->toWire(revision: 1));

    expect(fn () => TransactionData::validateAndCreate(transaction(['status' => TransactionStatus::Accepted])))
        ->toThrow(ValidationException::class);
});

it('T6: keeps the email of a person in lower case, however the counterparty is built', function (Closure $build): void {
    expect($build('Mario.Rossi@Example.com')->email)->toBe(person()['email']);
})->with([
    'person' => [static fn (string $email): CounterpartyData => CounterpartyData::person('RSSMRA80A01H501U', 'Mario', 'Rossi', $email)],
    'from' => [static fn (string $email): ?CounterpartyData => TransactionData::from(transaction(['counterparty' => person(['email' => $email])]))->counterparty],
]);
