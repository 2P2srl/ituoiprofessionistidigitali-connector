<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Data;

/**
 * A consultant as GET /professionals/{id} shows it, with the data to offer an assignment (rule N4). The platform keeps
 * every reading (rule N5). The contacts are personal data: never cache them, log them or keep them outside the record
 * the system creates for the assignment.
 */
final class ProfessionalData extends Data
{
    /**
     * @param  list<string>  $typologies
     * @param  string|null  $mobile  In E.164, or null.
     */
    public function __construct(
        public string $id,
        public string $first_name,
        public string $last_name,
        public string $municipality,
        public string $province,
        public array $typologies,
        public bool $tax_code_verified,
        public string $tax_code,
        public string $email,
        public ?string $mobile,
        public ?string $vat_number,
    ) {}

    /**
     * The consultant as the person counterparty of a transaction, with its record as the platform shows it: locked
     * for a registered consultant (rules R3 and R20). The mobile does not go in the register.
     */
    public function toCounterparty(): CounterpartyData
    {
        return CounterpartyData::person(
            taxCode: $this->tax_code,
            firstName: $this->first_name,
            lastName: $this->last_name,
            email: $this->email,
            vatNumber: $this->vat_number,
            municipality: $this->municipality,
            province: $this->province,
        );
    }
}
