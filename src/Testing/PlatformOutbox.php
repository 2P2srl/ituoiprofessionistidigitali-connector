<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Testing;

use Closure;
use Illuminate\Support\Collection;
use ITuoiProfessionistiDigitali\Connector\Contracts\RecordsPlatformTransaction;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\ProfessionalRecordData;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformEventOutbox;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformProfessionalOutbox;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformTransactionOutbox;
use ITuoiProfessionistiDigitali\Connector\Outbox\TransactionOutbox;
use PHPUnit\Framework\Assert;

/**
 * Assertions for the tests of a system: every action that changes a transaction leaves its current version in the outbox,
 * every change of the record of a professional leaves its declaration, and every event to another firm its envelope.
 */
final class PlatformOutbox
{
    /**
     * The current version of the model is in the outbox, ready to be sent.
     */
    public static function assertRecorded(RecordsPlatformTransaction $model): void
    {
        $reference = $model->platformTransactionReference();
        Assert::assertNotNull($reference, 'La transazione non ha un riferimento: è una bozza, che non si registra.');

        $row = PlatformTransactionOutbox::query()->where('reference', $reference)->first();
        Assert::assertInstanceOf(PlatformTransactionOutbox::class, $row, "La transazione {$reference} non è nella outbox.");
        Assert::assertSame(resolve(TransactionOutbox::class)->payload($model), $row->payload, "La outbox non ha la versione corrente della transazione {$reference}.");
    }

    /**
     * The record is the latest declaration in the outbox for the tax code (rule R18).
     */
    public static function assertDeclared(string $taxCode, ProfessionalRecordData $record): void
    {
        $row = PlatformProfessionalOutbox::query()->where('tax_code', $taxCode)->first();
        Assert::assertInstanceOf(PlatformProfessionalOutbox::class, $row, "La dichiarazione dell'anagrafica non è nella outbox.");
        Assert::assertSame($record->toWire(), $row->payload, 'La outbox non ha questa anagrafica come ultima dichiarazione.');
    }

    /**
     * Nothing of the model reached the outbox: a draft, never sent or published.
     */
    public static function assertNotRecorded(RecordsPlatformTransaction $model): void
    {
        $reference = $model->platformTransactionReference();

        Assert::assertTrue(
            $reference === null || !PlatformTransactionOutbox::query()->where('reference', $reference)->exists(),
            "La transazione {$reference} è nella outbox.",
        );
    }

    /**
     * The outbox of the events keeps an event of the type, in any status, matching the callback if given.
     *
     * @param  (Closure(EnvelopeData): bool)|null  $callback
     */
    public static function assertEventQueued(string $type, ?Closure $callback = null): void
    {
        Assert::assertTrue(self::queuedEvents($type)->contains(fn (EnvelopeData $envelope): bool => $callback === null || $callback($envelope)), "Nessun evento {$type} nella outbox degli eventi.");
    }

    /**
     * The outbox of the events keeps no event of the type.
     */
    public static function assertEventNotQueued(string $type): void
    {
        Assert::assertTrue(self::queuedEvents($type)->isEmpty(), "Un evento {$type} è nella outbox degli eventi.");
    }

    /**
     * @return Collection<int, EnvelopeData>
     */
    private static function queuedEvents(string $type): Collection
    {
        return PlatformEventOutbox::query()->where('type', $type)->get()->map(fn (PlatformEventOutbox $row): EnvelopeData => EnvelopeData::from($row->envelope))->values()->toBase();
    }
}
