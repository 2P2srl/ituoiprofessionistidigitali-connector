<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Data;

/**
 * The payload of transaction.assignment_received v1: the published assignment given to a firm of the system with the
 * selection, as GET /transactions returns it, with the member as counterparty, and the principal, revealed only now
 * (rules L4, L6 and L7). Only the platform sends it, to the system of the firm selected: handle it once per event_id
 * and per transaction. The transaction is of the principal: the system of the firm never registers it.
 */
final class AssignmentReceivedData extends Data
{
    public function __construct(
        public RecordedTransactionData $transaction,
        public PrincipalData $principal,
    ) {}
}
