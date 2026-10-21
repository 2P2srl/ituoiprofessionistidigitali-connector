<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Data;

/**
 * A consultant registered on the platform who agreed to be found by the firms, as GET /professionals lists it: no
 * contacts, no tax code, no VAT number (rules N1–N3). tax_code_verified false means a tax code declared at the
 * registration, checked only in its form.
 */
final class ListedProfessionalData extends Data
{
    /**
     * @param  list<string>  $typologies
     */
    public function __construct(
        public string $id,
        public string $first_name,
        public string $last_name,
        public string $municipality,
        public string $province,
        public array $typologies,
        public bool $tax_code_verified,
    ) {}
}
