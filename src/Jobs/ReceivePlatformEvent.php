<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\AssignmentOfferedData;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\FileReferenceData;
use ITuoiProfessionistiDigitali\Connector\Data\ReceivedFileData;
use ITuoiProfessionistiDigitali\Connector\Events\PlatformEventReceived;
use ITuoiProfessionistiDigitali\Connector\Exceptions\FileGoneException;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;
use ITuoiProfessionistiDigitali\Connector\Support\FileHash;

/**
 * Prepares, out of the ten seconds of the webhook, what an event needs before the application sees it: its files, and
 * the transaction of an offer.
 *
 * Its files: downloads each one to
 * `platform-files/{event_id}/{file_id}` of the disk of the files, checks it on the hash of the envelope, confirms it,
 * and only then hands the event to the application (rule F8). The path uses the ids, never the name of the file.
 *
 * A file already saved with the right hash is not downloaded again: a repeated delivery only confirms it again.
 * A file the platform no longer has reaches the application marked as missing (rule F9). The job retries until the
 * files are available, one per event.
 *
 * The transaction of an offer (coworking.assignment_offered): the terms are those of the register, which the package
 * reads with GET /transactions?id= and hands to the application with the event. No transaction yet means a register
 * not aligned: the job retries (rule E13).
 *
 * @internal
 */
final class ReceivePlatformEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public EnvelopeData $envelope) {}

    public function uniqueId(): string
    {
        return $this->envelope->event_id;
    }

    /**
     * The first expiry of its files, or 30 days from now (rule F9).
     */
    public function retryUntil(): CarbonImmutable
    {
        $expiries = array_filter(array_map(static fn (FileReferenceData $file): ?CarbonImmutable => $file->available_until, $this->envelope->fileReferences()));

        return $expiries === [] ? CarbonImmutable::now()->addDays(30) : min($expiries);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(PlatformClient $client, ConnectorConfig $config, Dispatcher $events): void
    {
        $files = array_map(
            fn (FileReferenceData $file): ReceivedFileData => $this->receive($client, $config->filesDisk, $this->envelope->event_id, $file),
            $this->envelope->fileReferences(),
        );

        $transaction = $this->envelope->type === Contract::ASSIGNMENT_OFFERED
            ? $client->transaction(AssignmentOfferedData::from($this->envelope->payload)->transaction->id)
            : null;

        $events->dispatch(new PlatformEventReceived($this->envelope, $files, $transaction));
    }

    private function receive(PlatformClient $client, string $disk, string $eventId, FileReferenceData $file): ReceivedFileData
    {
        $path = "platform-files/{$eventId}/{$file->id}";
        $isSaved = Storage::disk($disk)->exists($path) && FileHash::of($disk, $path) === $file->sha256;

        if (!$isSaved)
        {
            try
            {
                $client->downloadFile($file, $disk, $path);
            }
            catch (FileGoneException)
            {
                return ReceivedFileData::of($file, $disk, null);
            }
        }

        $client->confirmFile($file->id, $file->sha256);

        return ReceivedFileData::of($file, $disk, $path);
    }
}
