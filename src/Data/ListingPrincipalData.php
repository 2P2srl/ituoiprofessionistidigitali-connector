<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Data;

/**
 * The principal of a listing as the applicants see it: only the seat, never the id, the name or the system (rule L7).
 */
final class ListingPrincipalData extends Data
{
    public function __construct(
        public string $municipality,
        public string $province,
    ) {}
}
