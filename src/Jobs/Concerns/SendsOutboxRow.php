<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Jobs\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformProfessionalOutbox;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformTransactionOutbox;

/**
 * What the jobs of the outboxes share: the retries, the wait for the connection, the refusals and the confirmation
 * of a revision.
 *
 * @internal
 */
trait SendsOutboxRow
{
    public int $tries = 10;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    /**
     * Whether the system is not connected yet: the row waits, with the reason kept.
     */
    private function waitsForConnection(ConnectorConfig $config, PlatformTransactionOutbox|PlatformProfessionalOutbox $row): bool
    {
        if ($config->isConnected())
        {
            return false;
        }

        $row->update(['last_error' => __('Il sistema non è collegato al portale.')]);

        return true;
    }

    /**
     * Keeps a refusal, of the platform or of the package before any request: a final one stops the row, any other
     * goes back to the queue to be retried.
     *
     * @throws PlatformRequestException|ValidationException
     */
    private function refused(PlatformTransactionOutbox|PlatformProfessionalOutbox $row, PlatformRequestException|ValidationException $exception, bool $isFinal): void
    {
        $errors = $exception instanceof ValidationException ? $exception->errors() : $exception->errors;

        $row->update([
            'status' => $isFinal ? OutboxStatus::Failed : OutboxStatus::Pending,
            'last_error' => mb_trim($exception->getMessage().' '.json_encode($errors, JSON_UNESCAPED_UNICODE)),
        ]);

        if (!$isFinal)
        {
            throw $exception;
        }
    }

    /**
     * The platform confirmed the revision. A newer one may have arrived meanwhile: it has its own job and stays pending.
     */
    private function confirmed(PlatformTransactionOutbox|PlatformProfessionalOutbox $row, int $revision): void
    {
        $row->newQuery()->whereKey($row->getKey())->update([
            'sent_revision' => $revision,
            'sent_at' => CarbonImmutable::now(),
            'last_error' => null,
        ]);
        $row->newQuery()->whereKey($row->getKey())->where('revision', $revision)->update(['status' => OutboxStatus::Sent]);
    }
}
