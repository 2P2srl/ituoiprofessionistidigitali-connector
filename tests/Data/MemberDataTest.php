<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use ITuoiProfessionistiDigitali\Connector\Data\MemberData;
use ITuoiProfessionistiDigitali\Connector\Enums\SubjectType;

it('M3: accepts an organization with a VAT number or a tax code, and a person with a personal tax code', function (array $overrides): void {
    expect(MemberData::validateAndCreate(member($overrides)))->toBeInstanceOf(MemberData::class);
})->with([
    'organization with vat number' => [[]],
    'organization with numeric tax code' => [['vat_number' => null, 'tax_code' => '01234567897']],
    'organization with both' => [['tax_code' => '01234567897']],
    'person with tax code' => [['subject_type' => 'person', 'vat_number' => null, 'tax_code' => 'RSSMRA80A01H501U']],
    'person with tax code and vat number' => [['subject_type' => 'person', 'tax_code' => 'RSSMRA80A01H501U']],
]);

it('M3: refuses a subject without the identifiers its type requires', function (array $overrides, string $field): void {
    try
    {
        MemberData::validateAndCreate(member($overrides));
        $this->fail('The member should not be valid.');
    }
    catch (ValidationException $exception)
    {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'organization without identifiers' => [['vat_number' => null, 'tax_code' => null], 'vat_number'],
    'person without tax code' => [['subject_type' => 'person', 'tax_code' => null], 'tax_code'],
    'person with numeric tax code' => [['subject_type' => 'person', 'tax_code' => '01234567897'], 'tax_code'],
]);

it('M4: refuses identifiers with a wrong check character', function (array $overrides, string $field): void {
    try
    {
        MemberData::validateAndCreate(member($overrides));
        $this->fail('The member should not be valid.');
    }
    catch (ValidationException $exception)
    {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'vat number' => [['vat_number' => '01234567890'], 'vat_number'],
    'tax code' => [['subject_type' => 'person', 'tax_code' => 'RSSMRA80A01H501A'], 'tax_code'],
]);

it('M3: refuses the other malformed fields', function (array $overrides, string $field): void {
    try
    {
        MemberData::validateAndCreate(member($overrides));
        $this->fail('The member should not be valid.');
    }
    catch (ValidationException $exception)
    {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'empty external_ref' => [['external_ref' => ''], 'external_ref'],
    'long external_ref' => [['external_ref' => str_repeat('a', 192)], 'external_ref'],
    'unknown subject_type' => [['subject_type' => 'company'], 'subject_type'],
    'long name' => [['name' => str_repeat('a', 256)], 'name'],
    'province in lower case' => [['province' => 'ba'], 'province'],
    'no typologies' => [['typologies' => []], 'typologies'],
    'repeated typology' => [['typologies' => ['commercialisti', 'commercialisti']], 'typologies.0'],
    'listed not boolean' => [['listed' => 'sometimes'], 'listed'],
]);

it('M3: reads the subject type as an enum', function (): void {
    expect(MemberData::validateAndCreate(member())->subject_type)->toBe(SubjectType::Organization);
});
