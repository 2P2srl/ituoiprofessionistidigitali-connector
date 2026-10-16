<?php

declare(strict_types=1);

use ITuoiProfessionistiDigitali\Connector\Data\ApplicationData;
use ITuoiProfessionistiDigitali\Connector\Data\ApplicationPage;
use ITuoiProfessionistiDigitali\Connector\Data\ApplicationReceivedData;
use ITuoiProfessionistiDigitali\Connector\Data\ApplicationWithdrawnData;
use ITuoiProfessionistiDigitali\Connector\Data\CounterpartyData;
use ITuoiProfessionistiDigitali\Connector\Data\CounterpartySelectedData;
use ITuoiProfessionistiDigitali\Connector\Enums\ApplicationStatus;
use ITuoiProfessionistiDigitali\Connector\Enums\Audience;
use ITuoiProfessionistiDigitali\Connector\Enums\CounterpartyType;
use ITuoiProfessionistiDigitali\Connector\Enums\TransactionStatus;

it('L7: reads an application with the consent to the conditions, and the applicant without the data of the selection', function (): void {
    $application = ApplicationData::from(application());

    expect($application)
        ->status->toBe(ApplicationStatus::Pending)
        ->accepted_revision->toBe(1)
        ->closed_at->toBeNull()
        ->and($application->terms_accepted_at->toIso8601ZuluString())->toBe('2026-10-10T08:00:00Z')
        ->and($application->applicant)
        ->type->toBe(CounterpartyType::Person)
        ->last_name->toBe('Rossi')
        ->province->toBe('BA')
        ->tax_code_verified->toBeFalse();
});

it('L6: reads the payloads of the applications received and withdrawn', function (): void {
    $received = ApplicationReceivedData::from(applicationEvent());
    $withdrawn = ApplicationWithdrawnData::from(applicationEvent(['status' => 'withdrawn', 'closed_at' => '2026-10-11T08:00:00Z']));

    expect($received->transaction->reference)->toBe('invio-1')
        ->and($received->application->status)->toBe(ApplicationStatus::Pending)
        ->and($withdrawn->transaction->id)->toBe(recordedTransaction()['id'])
        ->and($withdrawn->application->status)->toBe(ApplicationStatus::Withdrawn)
        ->and($withdrawn->application->closed_at?->toIso8601ZuluString())->toBe('2026-10-11T08:00:00Z');
});

it('L4 and L6: reads the selection, with the transaction accepted, the person and the mobile outside the counterparty', function (): void {
    $selection = CounterpartySelectedData::from(counterpartySelected(['settlements' => []]));

    expect($selection->transaction)
        ->status->toBe(TransactionStatus::Accepted)
        ->audience->toBe(Audience::Any)
        ->revision->toBe(2)
        ->signed_at->toBeNull()
        ->and($selection->transaction->counterparty?->tax_code)->toBe('RSSMRA80A01H501U')
        ->and($selection->application->status)->toBe(ApplicationStatus::Selected)
        ->and($selection->contact->mobile)->toBe('+393331234567');
});

it('L7: tells whether a page of applications has a next one', function (): void {
    expect(new ApplicationPage([ApplicationData::from(application())], 'next')->hasMore())->toBeTrue()
        ->and(new ApplicationPage([], null)->hasMore())->toBeFalse();
});

it('L7 and L10: reads a firm that applied, with its name and seat only', function (): void {
    $applicant = ApplicationData::from(application(['applicant' => firmApplicant()]))->applicant;

    expect($applicant)
        ->type->toBe(CounterpartyType::Member)
        ->name->toBe('Studio Bianchi')
        ->municipality->toBe('Lecce')
        ->province->toBe('LE')
        ->first_name->toBeNull()
        ->last_name->toBeNull()
        ->tax_code_verified->toBeNull()
        ->and($applicant->isMember())->toBeTrue()
        ->and(ApplicationData::from(application())->applicant->isMember())->toBeFalse()
        ->and(ApplicationData::from(application())->applicant->name)->toBeNull();
});

it('L4: reads the selection of a firm, with the member as counterparty and no contact', function (): void {
    $selection = CounterpartySelectedData::from(firmSelected());

    expect($selection->transaction)
        ->audience->toBe(Audience::Member)
        ->and($selection->transaction->counterparty)
        ->type->toBe(CounterpartyType::Member)
        ->member_id->toBe('0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55')
        ->tax_code->toBeNull()
        ->and($selection->application->applicant->isMember())->toBeTrue()
        ->and($selection->contact)->toBeNull()
        ->and($selection->transaction->toTransaction()->counterparty)->toEqual(CounterpartyData::member('0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55'));
});
