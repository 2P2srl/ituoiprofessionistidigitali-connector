<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Data;

/**
 * An activity of a listing: what an application accepts, with the total the platform computed (rule R15).
 */
final class ListingActivityData extends Data
{
    public function __construct(
        public string $reference,
        public CompensationData $compensation,
        public int $estimated_minutes,
        public int $total_cents,
        public ActivityDescriptionData $description,
    ) {}
}
