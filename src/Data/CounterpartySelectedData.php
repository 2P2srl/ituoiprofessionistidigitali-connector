<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Data;

/**
 * The payload of transaction.counterparty_selected v1: the transaction accepted, as GET /transactions returns it,
 * the selected application and, for a person, the mobile (rules L4 and L6). The counterparty is a person or a member,
 * the firm selected, of any system: send it back identical at every revision (rule R13). It always reaches the system
 * of the principal, also after a selection made with the API: handle it once per event_id and per reference.
 */
final class CounterpartySelectedData extends Data
{
    public function __construct(
        public RecordedTransactionData $transaction,
        public ApplicationData $application,
        public ?ContactData $contact,
    ) {}
}
