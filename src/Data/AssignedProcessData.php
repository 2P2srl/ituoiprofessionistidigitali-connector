<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * A process handed to the assigned firm, with the final client it is for and its activities.
 */
final class AssignedProcessData extends Data
{
    /**
     * @param  string  $reference  The process.reference of its activities in the transaction.
     * @param  string|null  $name  The name of the process in the catalogue of the principal, null when it has none.
     * @param  list<AssignedActivityData>  $activities
     */
    public function __construct(
        public string $reference,
        public ?string $name,
        public FinalClientData $client,
        #[DataCollectionOf(AssignedActivityData::class)]
        public array $activities,
    ) {}
}
