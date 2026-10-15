<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Data\ProfessionalRecordData;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\ProfessionalNotAssignedException;
use ITuoiProfessionistiDigitali\Connector\Jobs\Concerns\SendsOutboxRow;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformProfessionalOutbox;
use ITuoiProfessionistiDigitali\Connector\Outbox\ProfessionalOutbox;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;

/**
 * Sends the latest declaration of the record of a professional (rule R18). A 404 waits while transactions to
 * the person wait to be registered, and is discarded otherwise; network errors and a system not yet active
 * are retried; a contract violation, found by the platform or by the package before the request, stops it, with
 * the error kept in the outbox. The record is read with validateAndCreate(): one kept before a rule changed, such
 * as a null email, fails as a contract violation instead of in the constructor.
 *
 * @internal
 */
final class SendPlatformProfessional implements ShouldQueue
{
    use Queueable;
    use SendsOutboxRow;

    public function __construct(public string $taxCode) {}

    public function handle(PlatformClient $client, ConnectorConfig $config, ProfessionalOutbox $outbox): void
    {
        $row = PlatformProfessionalOutbox::query()->where('tax_code', $this->taxCode)->first();

        if (!$row instanceof PlatformProfessionalOutbox || $row->status !== OutboxStatus::Pending || $this->waitsForConnection($config, $row))
        {
            return;
        }

        $revision = $row->revision;
        // Read before the request: a transaction confirmed after it sends the declaration again
        $isAwaited = $outbox->hasUnregisteredTransactions($this->taxCode);
        $row->update(['attempts' => $row->attempts + 1]);

        try
        {
            $client->declareProfessional($this->taxCode, ProfessionalRecordData::validateAndCreate($row->payload));
        }
        catch (ProfessionalNotAssignedException)
        {
            $this->notAssigned($row, $revision, $isAwaited);

            return;
        }
        catch (ValidationException $exception)
        {
            $this->refused($row, $exception, isFinal: true);

            return;
        }
        catch (PlatformRequestException $exception)
        {
            $this->refused($row, $exception, $exception->isContractViolation());

            return;
        }

        $this->confirmed($row, $revision);
    }

    /**
     * The 404: it waits for the transactions to the person still to register, and is discarded without them.
     */
    private function notAssigned(PlatformProfessionalOutbox $row, int $revision, bool $isAwaited): void
    {
        if ($isAwaited)
        {
            $row->update(['last_error' => __('In attesa della registrazione delle transazioni al professionista.')]);

            return;
        }

        PlatformProfessionalOutbox::query()->whereKey($row->id)->where('revision', $revision)->update([
            'status' => OutboxStatus::Discarded,
            'last_error' => __('Il sistema non ha transazioni registrate al professionista.'),
        ]);
        Log::info("Dichiarazione dell'anagrafica scartata: il portale non ha transazioni del sistema al professionista.", ['outbox_id' => $row->id]);
    }
}
