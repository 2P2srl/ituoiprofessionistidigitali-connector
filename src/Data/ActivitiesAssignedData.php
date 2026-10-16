<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\Concerns\IsEventBetweenFirms;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * The payload of coworking.activities_assigned v1: the work the principal hands to the selected firm after the
 * signature of the documents of the coworking. For every process the final client of the principal, for every
 * activity its data and its guides, with the files attached. Each process carries the process.reference of the
 * activities of the transaction, so the firm groups them as the principal does.
 */
final class ActivitiesAssignedData extends Data
{
    use IsEventBetweenFirms;

    public const string TYPE = Contract::ACTIVITIES_ASSIGNED;

    /**
     * @param  list<AssignedProcessData>  $processes
     */
    public function __construct(
        public TransactionIdData $transaction,
        #[DataCollectionOf(AssignedProcessData::class)]
        public array $processes,
    ) {}
}
