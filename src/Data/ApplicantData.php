<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use ITuoiProfessionistiDigitali\Connector\Enums\CounterpartyType;
use Spatie\LaravelData\Data;

/**
 * Who applied, as the principal sees it before the selection (rules L7 and L10): a professional, with name, surname
 * and seat, or a firm, with its name and seat. Never the tax code, email, VAT number or mobile, nor the id of the
 * member: they come only with the selection.
 */
final class ApplicantData extends Data
{
    /**
     * @param  string|null  $first_name  Of a person; null for a firm.
     * @param  string|null  $last_name  Of a person; null for a firm.
     * @param  bool|null  $tax_code_verified  Of a person, whether the tax code was verified; otherwise it is the one
     *                                        declared by the professional, with the formal check only. Null for a firm.
     * @param  string|null  $name  Of a firm, its name; null for a person.
     */
    public function __construct(
        public CounterpartyType $type,
        public ?string $first_name,
        public ?string $last_name,
        public string $municipality,
        public string $province,
        public ?bool $tax_code_verified = null,
        public ?string $name = null,
    ) {}

    /**
     * Whether a firm applied, a member of a system.
     */
    public function isMember(): bool
    {
        return $this->type === CounterpartyType::Member;
    }
}
