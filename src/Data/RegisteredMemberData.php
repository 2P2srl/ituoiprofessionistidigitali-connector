<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use ITuoiProfessionistiDigitali\Connector\Enums\SubjectType;
use Spatie\LaravelData\Data;

/**
 * One of the system's own members, as PUT /members answers it: with the id given by the platform (rule M7).
 */
final class RegisteredMemberData extends Data
{
    /**
     * @param  list<string>  $typologies
     */
    public function __construct(
        public string $id,
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
}
