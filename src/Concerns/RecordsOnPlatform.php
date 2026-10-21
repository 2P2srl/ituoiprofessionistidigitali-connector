<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Concerns;

use ITuoiProfessionistiDigitali\Connector\Contracts\RecordsPlatformTransaction;
use ITuoiProfessionistiDigitali\Connector\Outbox\TransactionOutbox;
use LogicException;

/**
 * For the models that implement RecordsPlatformTransaction: every save goes to the outbox.
 *
 * Mass updates (`Model::query()->update()`) skip the models and the outbox: use them never on these tables.
 */
trait RecordsOnPlatform
{
    public static function bootRecordsOnPlatform(): void
    {
        // Checked on the list of interfaces: the trait must not be used without its contract
        throw_unless(in_array(RecordsPlatformTransaction::class, class_implements(static::class), true), LogicException::class,
            static::class.' usa RecordsOnPlatform ma non implementa RecordsPlatformTransaction.');

        static::saved(static function (RecordsPlatformTransaction $model): void {
            resolve(TransactionOutbox::class)->record($model);
        });
    }

    /**
     * The id of the transaction on the platform, fixed at its first save and the same at every revision (rule R23):
     * the events between firms cite it. It exists as soon as the model is saved, inside the same database
     * transaction, before the platform confirms anything. Null for a draft.
     */
    public function platformTransactionId(): ?string
    {
        $reference = $this->platformTransactionReference();

        return $reference === null ? null : resolve(TransactionOutbox::class)->transactionId($reference);
    }

    /**
     * Whether the platform confirmed the current version of the transaction. The work does not wait for it.
     */
    public function isRecordedOnPlatform(): bool
    {
        $reference = $this->platformTransactionReference();

        return $reference !== null && resolve(TransactionOutbox::class)->isRecorded($reference);
    }
}
