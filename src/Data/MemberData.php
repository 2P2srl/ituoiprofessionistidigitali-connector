<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Illuminate\Validation\Rule;
use ITuoiProfessionistiDigitali\Connector\Enums\SubjectType;
use ITuoiProfessionistiDigitali\Connector\Validation\Rules\ItalianTaxCode;
use ITuoiProfessionistiDigitali\Connector\Validation\Rules\ItalianVatNumber;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * A member as the system publishes it with PUT /members.
 *
 * The rules repeat those of the platform (M3, M4), so a mistake shows up while developing instead of as a 422.
 * Whether the typologies exist and are active is checked by the platform only (M5).
 */
final class MemberData extends Data
{
    /**
     * @param  list<string>  $typologies
     */
    public function __construct(
        public string $external_ref,
        public SubjectType $subject_type,
        public string $name,
        public ?string $vat_number,
        public ?string $tax_code,
        public string $municipality,
        public string $province,
        public array $typologies,
        public bool $listed,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $payload = is_array($context->payload) ? $context->payload : [];
        $subjectType = $payload['subject_type'] ?? null;
        $isPerson = $subjectType === SubjectType::Person->value || $subjectType === SubjectType::Person;

        return [
            'external_ref' => ['required', 'string', 'min:1', 'max:191'],
            'subject_type' => ['required', Rule::enum(SubjectType::class)],
            'name' => ['required', 'string', 'max:255'],
            'vat_number' => [
                Rule::requiredIf(!$isPerson && blank($payload['tax_code'] ?? null)),
                'nullable',
                'string',
                new ItalianVatNumber,
            ],
            'tax_code' => [
                Rule::requiredIf($isPerson),
                'nullable',
                'string',
                new ItalianTaxCode(personOnly: $isPerson),
            ],
            'municipality' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'typologies' => ['required', 'array', 'min:1'],
            'typologies.*' => ['required', 'string', 'distinct'],
            'listed' => ['required', 'boolean'],
        ];
    }
}
