<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Outbox;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\OutgoingFileData;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformEvent;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformEventOutbox;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformEventOutboxFile;
use ITuoiProfessionistiDigitali\Connector\Support\FileHash;
use LogicException;

/**
 * Keeps every event of a member before it leaves, and sends it after the commit, the same envelope at every attempt
 * (rules E2 and E8). Call queue() in the transaction of the database of the change that produces the event: the event
 * leaves only if the change is saved.
 *
 * An event with files takes them as OutgoingFileData, cited by their ref in the payload and in the envelope: the job
 * uploads them and sends the event with the ids of the platform in their place (rules E12 and F5).
 */
final readonly class EventOutbox
{
    public function __construct(private ConnectorConfig $config) {}

    /**
     * Keeps the envelope and queues it. The same envelope again is the same event: it is kept once, whatever its
     * status. The same event_id with another content is a mistake in the code, as the 409 of the platform (rule E2).
     * The SHA-256 of each file is read now: a file that changes before its upload stops the event (rule F3).
     *
     * @param  list<OutgoingFileData>  $files  The files the envelope lists by their ref, all of them.
     *
     * @throws ValidationException|LogicException|InvalidArgumentException
     */
    public function queue(EnvelopeData $envelope, array $files = []): PlatformEventOutbox
    {
        // As the JSON column gives it back, so the same envelope again compares equal
        /** @var array<string, mixed> $kept */
        $kept = json_decode(json_encode($envelope->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        EnvelopeData::validate($kept);

        $refs = array_map(static fn (OutgoingFileData $file): string => $file->ref, $files);
        $listed = $envelope->fileIds();
        sort($refs);
        sort($listed);

        if ($refs !== $listed)
        {
            throw new LogicException("The files of the event {$envelope->event_id} are not the ones its envelope lists by ref.");
        }

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

        foreach ($files as $file)
        {
            PlatformEventOutboxFile::query()->create([
                'event_id' => $envelope->event_id,
                'ref' => $file->ref,
                'disk' => $file->disk,
                'path' => $file->path,
                'name' => $file->name,
                'media_type' => $file->media_type,
                'sha256' => FileHash::of($file->disk, $file->path),
            ]);
        }

        dispatch(new SendPlatformEvent($envelope->event_id))->onQueue($this->config->queue)->afterCommit();

        return $row;
    }

    /**
     * Queues again the events still waiting for the transaction they cite, now that the platform confirmed it: an
     * event that follows a write in the register leaves after it (rule E13).
     *
     * @internal
     */
    public function resume(string $transactionId): void
    {
        /** @var list<string> $eventIds */
        $eventIds = PlatformEventOutbox::query()
            ->where('status', OutboxStatus::Pending)
            ->where('envelope->correlation_id', $transactionId)
            ->pluck('event_id')
            ->all();

        foreach ($eventIds as $eventId)
        {
            dispatch(new SendPlatformEvent($eventId))->onQueue($this->config->queue)->afterCommit();
        }
    }
}
