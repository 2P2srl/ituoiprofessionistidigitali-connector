<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * An activity handed to the assigned firm: its reference in the transaction, the agreed amount and its guides.
 */
final class AssignedActivityData extends Data
{
    /**
     * @param  string|null  $name  The name of the activity in the catalogue of the principal, null when it has none.
     * @param  string|null  $deadline  Y-m-d.
     * @param  list<GuideData>  $guides
     */
    public function __construct(
        public string $reference,
        public ?string $name,
        public ?string $deadline,
        public int $fixed_amount_cents,
        public int $estimated_minutes,
        #[DataCollectionOf(GuideData::class)]
        public array $guides,
    ) {}
}
