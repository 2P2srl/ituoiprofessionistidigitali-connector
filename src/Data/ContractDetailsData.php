<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\Concerns\IsEventBetweenFirms;
use Spatie\LaravelData\Data;

/**
 * The payload of coworking.contract_details v1: the details for the contracts of the coworking, that each of the two
 * firms sends to the other right after the selection.
 */
final class ContractDetailsData extends Data
{
    use IsEventBetweenFirms;

    public const string TYPE = Contract::CONTRACT_DETAILS;

    public function __construct(
        public TransactionIdData $transaction,
        public PartyData $party,
        public SignatoryData $signatory,
    ) {}
}
