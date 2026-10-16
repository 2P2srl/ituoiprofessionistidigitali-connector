<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use ITuoiProfessionistiDigitali\Connector\Validation\Rules\ItalianTaxCode;
use ITuoiProfessionistiDigitali\Connector\Validation\Rules\ItalianVatNumber;
use Spatie\LaravelData\Data;

/**
 * The firm that sends its details for the contracts: who the other firm writes as its client or supplier.
 */
final class PartyData extends Data
{
    /**
     * @param  string  $name  The company name.
     * @param  AddressData  $address  The registered office.
     * @param  string|null  $recipient_code  The recipient code for electronic invoicing.
     */
    public function __construct(
        public string $name,
        public string $vat_number,
        public ?string $tax_code,
        public AddressData $address,
        public ?string $pec,
        public ?string $recipient_code,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'vat_number' => ['required', 'string', new ItalianVatNumber],
            'tax_code' => ['present', 'nullable', 'string', new ItalianTaxCode],
            'pec' => ['present', 'nullable', 'string', 'email', 'max:255'],
            'recipient_code' => ['present', 'nullable', 'string', 'regex:/^[A-Z0-9]{7}$/'],
        ];
    }
}
