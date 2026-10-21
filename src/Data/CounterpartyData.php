<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Illuminate\Validation\Rule;
use ITuoiProfessionistiDigitali\Connector\Enums\CounterpartyType;
use ITuoiProfessionistiDigitali\Connector\Validation\Rules\ItalianTaxCode;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Who receives the work: a professional with the details of the sending, or a member by its id, of the same system or
 * of another one (rules R3 and R13). It has one form on the wire, with every key: null where the type does not use it.
 * The email of a person is kept in lower case, as the platform stores and returns it (T6).
 */
final class CounterpartyData extends Data
{
    /**
     * The fields of a person: none of them belongs to a member.
     */
    public const array PERSON_FIELDS = ['tax_code', 'first_name', 'last_name', 'email', 'vat_number', 'municipality', 'province'];

    public function __construct(
        public CounterpartyType $type,
        public ?string $member_id = null,
        public ?string $tax_code = null,
        public ?string $first_name = null,
        public ?string $last_name = null,
        public ?string $email = null,
        public ?string $vat_number = null,
        public ?string $municipality = null,
        public ?string $province = null,
    ) {
        $this->email = $email === null ? null : mb_strtolower($email);
    }

    public static function person(
        string $taxCode,
        string $firstName,
        string $lastName,
        string $email,
        ?string $vatNumber = null,
        ?string $municipality = null,
        ?string $province = null,
    ): self {
        return new self(
            CounterpartyType::Person,
            tax_code: $taxCode,
            first_name: $firstName,
            last_name: $lastName,
            email: $email,
            vat_number: $vatNumber,
            municipality: $municipality,
            province: $province,
        );
    }

    public static function member(string $memberId): self
    {
        return new self(CounterpartyType::Member, member_id: $memberId);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $counterparty = is_array($context->payload) ? $context->payload : [];
        $isPerson = TransactionActivityData::value($counterparty['type'] ?? null) === CounterpartyType::Person->value;
        // An optional field of a person given empty is an error, not a missing value
        $person = static fn (string $field, mixed ...$rules): array => self::personRules($counterparty, $isPerson, $field, $rules);
        $details = ProfessionalRecordData::detailRules();

        return [
            'type' => ['required', Rule::enum(CounterpartyType::class)],
            'member_id' => ['present', Rule::requiredIf(!$isPerson), Rule::prohibitedIf($isPerson), 'nullable', 'uuid'],
            'tax_code' => [Rule::requiredIf($isPerson), ...$person('tax_code', 'string', new ItalianTaxCode(personOnly: true))],
            'first_name' => [Rule::requiredIf($isPerson), ...$person('first_name', ...$details['first_name'])],
            'last_name' => [Rule::requiredIf($isPerson), ...$person('last_name', ...$details['last_name'])],
            'email' => [Rule::requiredIf($isPerson), ...$person('email', ...$details['email'])],
            'vat_number' => $person('vat_number', ...$details['vat_number']),
            'municipality' => $person('municipality', ...$details['municipality']),
            'province' => $person('province', ...$details['province']),
        ];
    }

    /**
     * The counterparty as PUT /transactions/{reference} carries it, with every key.
     *
     * @return array{type: string, member_id: string|null, tax_code: string|null, first_name: string|null, last_name: string|null, email: string|null, vat_number: string|null, municipality: string|null, province: string|null}
     */
    public function toWire(): array
    {
        return [
            'type' => $this->type->value,
            'member_id' => $this->member_id,
            'tax_code' => $this->tax_code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'vat_number' => $this->vat_number,
            'municipality' => $this->municipality,
            'province' => $this->province,
        ];
    }

    /**
     * The rules of a field of a person: always present, null for a member, and an error when given empty.
     *
     * @param  array<array-key, mixed>  $counterparty
     * @param  array<array-key, mixed>  $rules
     * @return list<mixed>
     */
    private static function personRules(array $counterparty, bool $isPerson, string $field, array $rules): array
    {
        return ['present', Rule::requiredIf(($counterparty[$field] ?? null) === ''), Rule::prohibitedIf(!$isPerson), 'nullable', ...array_values($rules)];
    }
}
