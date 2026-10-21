<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Outbox;

use Carbon\CarbonImmutable;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Contracts\RecordsPlatformTransaction;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionActivityData;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionData;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformTransaction;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformTransactionOutbox;

/**
 * Keeps the last version of every transaction and sends it after the commit, with a new revision
 * only when something changed (rules R7 and R9). Only the last version is needed: every PUT is the
 * complete picture, so the platform accepts a first record in any status consistent with its dates
 * and activities.
 *
 * It keeps one row per reference, forever: the rows are also the list of the persons the system assigned,
 * which the declarations of their records rely on (rule R18). A pruning, if one ever comes, must keep the
 * tax code of the counterparty of every row it removes.
 */
final readonly class TransactionOutbox
{
    public function __construct(private ConnectorConfig $config) {}

    /**
     * Null for a transaction never sent: a draft does not reach the register.
     *
     * @internal
     */
    public function record(RecordsPlatformTransaction $model): ?PlatformTransactionOutbox
    {
        $reference = $model->platformTransactionReference();

        if ($reference === null)
        {
            return null;
        }

        $payload = $this->payload($model);
        $row = PlatformTransactionOutbox::query()->firstOrNew(['reference' => $reference]);

        if ($row->exists && $row->payload === $payload)
        {
            return $row;
        }

        $row->fill([
            'payload' => $payload,
            'revision' => $row->exists ? $row->revision + 1 : 1,
            'status' => OutboxStatus::Pending,
            'last_error' => null,
        ])->save();

        dispatch(new SendPlatformTransaction($reference))->onQueue($this->config->queue)->afterCommit();

        return $row;
    }

    /**
     * Transactions the platform registered outside the outbox, from the history in batch: kept as confirmed,
     * each unless the outbox already has a newer revision (rule R10). One read and one database transaction.
     *
     * @param  list<array{reference: string, revision: int, transaction: TransactionData}>  $transactions
     *
     * @internal
     */
    public function confirmed(array $transactions): void
    {
        $rows = PlatformTransactionOutbox::query()->whereIn('reference', array_column($transactions, 'reference'))->get()->keyBy('reference');

        PlatformTransactionOutbox::query()->getConnection()->transaction(function () use ($transactions, $rows): void {
            foreach ($transactions as $item)
            {
                $row = $rows->get($item['reference']) ?? new PlatformTransactionOutbox(['reference' => $item['reference']]);

                if ($row->exists && $row->revision > $item['revision'])
                {
                    continue;
                }

                $row->fill([
                    'payload' => $this->payloadOf($item['transaction']),
                    'revision' => $item['revision'],
                    'sent_revision' => $item['revision'],
                    'status' => OutboxStatus::Sent,
                    'last_error' => null,
                    'sent_at' => CarbonImmutable::now(),
                ])->save();
            }
        });
    }

    /**
     * The current version of the model as the outbox keeps it.
     *
     * @return array<string, mixed>
     */
    public function payload(RecordsPlatformTransaction $model): array
    {
        return $this->payloadOf($model->toPlatformTransaction());
    }

    /**
     * The transaction as the outbox keeps it: the body of PUT without the revision, with the dates in UTC as the
     * platform answers (rule T2), in the form its JSON column reads back. The same instant written in another time
     * zone is the same version.
     *
     * @return array<string, mixed>
     */
    public function payloadOf(TransactionData $transaction): array
    {
        $payload = $this->inUtc($transaction)->toWire(revision: 0);
        unset($payload['revision']);

        /** @var array<string, mixed> */
        return json_decode((string) json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Whether the platform confirmed the current revision.
     */
    public function isRecorded(string $reference): bool
    {
        $row = PlatformTransactionOutbox::query()->where('reference', $reference)->first();

        return $row instanceof PlatformTransactionOutbox && $row->sent_revision === $row->revision;
    }

    /**
     * A copy of the transaction with its dates, and those of its activities, in UTC.
     */
    private function inUtc(TransactionData $transaction): TransactionData
    {
        $utc = clone $transaction;
        $utc->sent_at = $transaction->sent_at->utc();
        $utc->responded_at = $transaction->responded_at?->utc();
        $utc->signed_at = $transaction->signed_at?->utc();
        $utc->closed_at = $transaction->closed_at?->utc();
        $utc->activities = array_map(static function (TransactionActivityData $activity): TransactionActivityData {
            $copy = clone $activity;
            $copy->closed_at = $activity->closed_at?->utc();

            return $copy;
        }, $transaction->activities);

        return $utc;
    }
}
