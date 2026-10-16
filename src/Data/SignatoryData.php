<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use ITuoiProfessionistiDigitali\Connector\Validation\Rules\ItalianTaxCode;
use Spatie\LaravelData\Data;

/**
 * Who signs the contracts of the coworking for a firm.
 */
final class SignatoryData extends Data
{
    /**
     * @param  string  $mobile  In E.164, such as +393331234567: for the digital signature.
     */
    public function __construct(
        public string $first_name,
        public string $last_name,
        public string $tax_code,
        public string $email,
        public string $mobile,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'tax_code' => ['required', 'string', new ItalianTaxCode(personOnly: true)],
            'email' => ['required', 'string', 'email', 'max:255'],
            'mobile' => ['required', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/'],
        ];
    }
}
