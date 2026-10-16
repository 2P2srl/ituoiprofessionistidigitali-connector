<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Jobs\Concerns\SendsOutboxRow;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformEventOutbox;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;

/**
 * Sends an event kept in the outbox, always the same envelope: after a timeout the platform answers 200 without
 * a duplicate (rule E2). Network errors and a system not yet active are retried; a contract violation, found by the
 * platform or by the package before the request, or a conflict stop it, with the error kept in the outbox.
 *
 * @internal
 */
final class SendPlatformEvent implements ShouldQueue
{
    use Queueable;
    use SendsOutboxRow;

    public function __construct(public string $eventId) {}

    public function handle(PlatformClient $client, ConnectorConfig $config): void
    {
        $row = PlatformEventOutbox::query()->where('event_id', $this->eventId)->first();

        if (!$row instanceof PlatformEventOutbox || $row->status !== OutboxStatus::Pending || $this->waitsForConnection($config, $row))
        {
            return;
        }

        $row->update(['attempts' => $row->attempts + 1]);

        try
        {
            $client->send(EnvelopeData::from($row->envelope));
        }
        catch (ValidationException $exception)
        {
            $this->refused($row, $exception, isFinal: true);

            return;
        }
        catch (PlatformRequestException $exception)
        {
            $this->refused($row, $exception, $exception->isContractViolation() || $exception->isConflict());

            return;
        }

        $row->update(['status' => OutboxStatus::Sent, 'sent_at' => CarbonImmutable::now(), 'last_error' => null]);
    }
}
