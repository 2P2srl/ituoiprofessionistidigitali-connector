<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\FileData;
use ITuoiProfessionistiDigitali\Connector\Enums\FileStatus;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Jobs\Concerns\SendsOutboxRow;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformEventOutbox;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformEventOutboxFile;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;
use ITuoiProfessionistiDigitali\Connector\Support\FileHash;

/**
 * Sends an event kept in the outbox, always the same envelope: after a timeout the platform answers 200 without
 * a duplicate (rule E2). Network errors and a system not yet active are retried; a contract violation, found by the
 * platform or by the package before the request, or a conflict stop it, with the error kept in the outbox.
 *
 * The files of the event are uploaded first, and the envelope leaves with their ids in place of the refs (rule E12).
 * After an attempt that may have reached the platform, a file it let expire is uploaded again with a new upload_id,
 * while a sent one means the event arrived: the same envelope goes again (rule F5). A file changed or gone since the
 * event entered the outbox stops it (rule F3).
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

        $isRetry = $row->attempts > 0;
        $row->update(['attempts' => $row->attempts + 1]);

        try
        {
            $client->send($this->withFiles($client, EnvelopeData::from($row->envelope), $isRetry));
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
        catch (InvalidArgumentException $exception)
        {
            $row->update(['status' => OutboxStatus::Failed, 'last_error' => $exception->getMessage()]);

            return;
        }

        $row->update(['status' => OutboxStatus::Sent, 'sent_at' => CarbonImmutable::now(), 'last_error' => null]);
    }

    /**
     * The envelope with the ids of the platform in place of the refs of its files, uploaded first if needed.
     *
     * @throws PlatformRequestException|InvalidArgumentException
     */
    private function withFiles(PlatformClient $client, EnvelopeData $envelope, bool $isRetry): EnvelopeData
    {
        /** @var Collection<int, PlatformEventOutboxFile> $files */
        $files = PlatformEventOutboxFile::query()->where('event_id', $envelope->event_id)->orderBy('id')->get();

        if ($files->isEmpty())
        {
            return $envelope;
        }

        $ids = [];

        foreach ($files as $file)
        {
            if ($file->file_id !== null && $isRetry && $client->fileStatus($file->file_id)->status === FileStatus::Expired)
            {
                $file->update(['upload_id' => null, 'file_id' => null, 'citable_until' => null]);
            }

            $ids[$file->ref] = $file->file_id ?? $this->upload($client, $envelope, $file);
        }

        $payload = $envelope->payload;
        array_walk_recursive($payload, static function (mixed &$value) use ($ids): void {
            if (is_string($value) && isset($ids[$value]))
            {
                $value = $ids[$value];
            }
        });

        return EnvelopeData::from([
            ...$envelope->toArray(),
            'payload' => $payload,
            'files' => array_map(static fn (string $ref): string => $ids[$ref] ?? $ref, $envelope->fileIds()),
        ]);
    }

    /**
     * Uploads a file of the event, with a new upload_id after a 410 of an orphaned one (rule F2), and answers its id.
     *
     * @throws PlatformRequestException|InvalidArgumentException
     */
    private function upload(PlatformClient $client, EnvelopeData $envelope, PlatformEventOutboxFile $file): string
    {
        if (FileHash::of($file->disk, $file->path) !== $file->sha256)
        {
            throw new InvalidArgumentException("Il file {$file->path} del disco {$file->disk} è cambiato dopo l'ingresso dell'evento nella outbox.");
        }

        $upload = fn (): FileData => $client->uploadFile(
            uploadId: $file->upload_id ?? $this->newUploadId($file),
            sender: (string) $envelope->sender,
            recipient: (string) $envelope->recipient,
            disk: $file->disk,
            path: $file->path,
            name: $file->name,
            mediaType: $file->media_type,
        );

        try
        {
            $uploaded = $upload();
        }
        catch (PlatformRequestException $exception)
        {
            if ($exception->status !== 410)
            {
                throw $exception;
            }

            $this->newUploadId($file);
            $uploaded = $upload();
        }

        $file->update(['file_id' => $uploaded->id, 'citable_until' => $uploaded->citable_until]);

        return $uploaded->id;
    }

    private function newUploadId(PlatformEventOutboxFile $file): string
    {
        $file->update(['upload_id' => (string) Str::uuid7()]);

        return (string) $file->upload_id;
    }
}
