<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Contracts;

use ITuoiProfessionistiDigitali\Connector\Data\TransactionData;

/**
 * A model that is a transaction of the register: every change must reach the platform (rule R9).
 *
 * Implement it together with the RecordsOnPlatform trait: the trait writes each change in the outbox,
 * in the same database transaction, and the package sends it. The models that change it without being it,
 * like its activities or the assignment that holds it, implement AffectsPlatformTransactions.
 */
interface RecordsPlatformTransaction
{
    /**
     * The stable identifier of the sending in this system, never reused: it becomes the {reference} of the route.
     * Null while it was never sent: a draft does not reach the register.
     */
    public function platformTransactionReference(): ?string;

    /**
     * How the model looks in the register now, with its activities. The revision is kept by the outbox.
     */
    public function toPlatformTransaction(): TransactionData;
}
