<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Outbox;

use Illuminate\Validation\ValidationException;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformEvent;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformEventOutbox;
use LogicException;

/**
 * Keeps every event of a member before it leaves, and sends it after the commit, the same envelope at every attempt
 * (rules E2 and E8). Call queue() in the transaction of the database of the change that produces the event: the event
 * leaves only if the change is saved.
 */
final readonly class EventOutbox
{
    public function __construct(private ConnectorConfig $config) {}

    /**
     * Keeps the envelope and queues it. The same envelope again is the same event: it is kept once, whatever its
     * status. The same event_id with another content is a mistake in the code, as the 409 of the platform (rule E2).
     *
     * @throws ValidationException|LogicException
     */
    public function queue(EnvelopeData $envelope): PlatformEventOutbox
    {
        $kept = $envelope->toArray();
        EnvelopeData::validate($kept);

        $row = PlatformEventOutbox::query()->where('event_id', $envelope->event_id)->first();

        if ($row instanceof PlatformEventOutbox)
        {
            if ($row->envelope !== $kept)
            {
                throw new LogicException("The event {$envelope->event_id} is already kept with another content: a new event needs a new event_id.");
            }

            return $row;
        }

        $row = PlatformEventOutbox::query()->create([
            'event_id' => $envelope->event_id,
            'type' => $envelope->type,
            'envelope' => $kept,
            'status' => OutboxStatus::Pending,
        ]);

        dispatch(new SendPlatformEvent($envelope->event_id))->onQueue($this->config->queue)->afterCommit();

        return $row;
    }
}
