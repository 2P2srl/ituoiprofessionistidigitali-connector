<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Data;

/**
 * The principal of an assignment, revealed only to the firm selected (rule L7).
 */
final class PrincipalData extends Data
{
    /**
     * @param  string  $id  The id of the member: the recipient of the events to the principal.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $municipality,
        public string $province,
    ) {}
}
