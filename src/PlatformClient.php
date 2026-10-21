<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ITuoiProfessionistiDigitali\Connector\Data\AcceptedEventData;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\EventTypeData;
use ITuoiProfessionistiDigitali\Connector\Data\FileData;
use ITuoiProfessionistiDigitali\Connector\Data\FileReferenceData;
use ITuoiProfessionistiDigitali\Connector\Data\ListedMemberData;
use ITuoiProfessionistiDigitali\Connector\Data\MemberData;
use ITuoiProfessionistiDigitali\Connector\Data\MemberPage;
use ITuoiProfessionistiDigitali\Connector\Data\ProfessionalRecordData;
use ITuoiProfessionistiDigitali\Connector\Data\RecordedTransactionData;
use ITuoiProfessionistiDigitali\Connector\Data\RegisteredMemberData;
use ITuoiProfessionistiDigitali\Connector\Data\SystemData;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionData;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionOutcomeData;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionPage;
use ITuoiProfessionistiDigitali\Connector\Data\TypologyData;
use ITuoiProfessionistiDigitali\Connector\Enums\SystemStatus;
use ITuoiProfessionistiDigitali\Connector\Exceptions\FileGoneException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\FileIntegrityException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformNotConfiguredException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\ProfessionalNotAssignedException;
use ITuoiProfessionistiDigitali\Connector\Outbox\TransactionOutbox;
use ITuoiProfessionistiDigitali\Connector\Support\FileHash;
use ITuoiProfessionistiDigitali\Connector\Validation\PayloadValidator;
use Throwable;

/**
 * Talks to the platform on behalf of the system whose credentials are configured.
 *
 * Every method throws PlatformNotConfiguredException without credentials, and PlatformRequestException
 * when the platform refuses the request.
 */
final readonly class PlatformClient
{
    /**
     * The seconds of a 429 without a readable Retry-After (rule T7).
     */
    public const int DEFAULT_RETRY_AFTER_SECONDS = 60;

    private const string CATALOGUE_CACHE_KEY = 'platform.event-types';

    /**
     * The longest Retry-After the client waits by itself; a longer one is the caller's (rule T7).
     */
    private const int MAX_WAIT_SECONDS = 10;

    public function __construct(
        private Http $http,
        private Cache $cache,
        private ConnectorConfig $config,
        private TransactionOutbox $outbox,
    ) {}

    /**
     * GET /system: the system as the platform sees it.
     */
    public function system(): SystemData
    {
        return SystemData::from($this->data($this->request('GET', 'system')));
    }

    /**
     * PUT /system: where the system receives webhooks; starts the verification when the URL changes.
     *
     * @param  list<int>  $contractVersions
     */
    public function present(string $webhookUrl, array $contractVersions = [Contract::VERSION]): SystemData
    {
        return SystemData::from($this->data($this->request('PUT', 'system', [
            'webhook_url' => $webhookUrl,
            'contract_versions' => $contractVersions,
        ])));
    }

    /**
     * GET /typologies: the active typologies.
     *
     * @return list<TypologyData>
     */
    public function typologies(): array
    {
        return array_map(TypologyData::from(...), $this->list($this->request('GET', 'typologies')));
    }

    /**
     * GET /event-types: the catalogue of messages, always fresh.
     *
     * @return list<EventTypeData>
     */
    public function eventTypes(): array
    {
        return array_map(EventTypeData::from(...), $this->list($this->request('GET', 'event-types')));
    }

    /**
     * PUT /members: the complete list of the system's members. Those left out become inactive. Two requests of the
     * system at the same moment are applied one after the other, and the last wins (rule M6).
     *
     * @param  list<MemberData>  $members
     * @return list<RegisteredMemberData>
     *
     * @throws ValidationException
     */
    public function syncMembers(array $members): array
    {
        $payload = array_map(static fn (MemberData $member): array => $member->toArray(), $members);

        validator(
            ['members' => $payload],
            ['members' => ['max:'.Contract::MAX_MEMBERS]],
            ['members.max' => 'Al massimo :max aderenti in una sola richiesta.'],
        )->validate();

        foreach ($payload as $member)
        {
            MemberData::validate($member);
        }

        return array_map(RegisteredMemberData::from(...), $this->list($this->request('PUT', 'members', ['members' => $payload])));
    }

    /**
     * GET /members: the listed members of the other systems.
     */
    public function searchMembers(?string $typology = null, ?string $search = null, ?int $perPage = null, ?string $cursor = null): MemberPage
    {
        $response = $this->request('GET', 'members', $this->query([
            'typology' => $typology,
            'search' => $search,
            'per_page' => $perPage,
            'cursor' => $cursor,
        ]));

        return new MemberPage(
            members: array_map(ListedMemberData::from(...), $this->list($response)),
            nextCursor: $this->cursor($response, 'next_cursor'),
            previousCursor: $this->cursor($response, 'prev_cursor'),
        );
    }

    /**
     * POST /events. Sending the same envelope again is safe: the platform answers with the event it already has.
     */
    public function send(EnvelopeData $envelope): AcceptedEventData
    {
        EnvelopeData::validate($envelope->toArray());

        if ($this->config->validatePayloads)
        {
            PayloadValidator::validate($envelope, $this->catalogue());
        }

        return AcceptedEventData::from($this->data($this->request('POST', 'events', $envelope->toWire())));
    }

    /**
     * PUT /transactions/{reference}: registers or updates a transaction of the system, with the id chosen at its first
     * registration and the same at every revision (rule R23). Prefer the outbox of the package
     * (RecordsPlatformTransaction), which keeps the id and the revision, and retries.
     */
    public function recordTransaction(string $reference, string $id, TransactionData $transaction, int $revision): RecordedTransactionData
    {
        // Generated by the system: a UUID in lower case (rules T3 and R23)
        validator(['id' => $id], ['id' => ['uuid', 'lowercase']])->validate();
        $this->checkTransaction($transaction);

        return RecordedTransactionData::from($this->data(
            $this->request('PUT', 'transactions/'.rawurlencode($reference), ['id' => $id, ...$transaction->toWire($revision)]),
        ));
    }

    /**
     * POST /transactions/batch: the history, up to 500 transactions, each with its outcome (rule R10).
     * The outbox gives each one its id before the request (rule R23), and keeps every transaction the platform has
     * as sent as confirmed: the outbox then knows every person the system assigned (rule R18).
     *
     * @param  list<array{reference: string, revision: int, transaction: TransactionData}>  $transactions
     * @return list<TransactionOutcomeData>
     */
    public function recordTransactions(array $transactions): array
    {
        if (count($transactions) > Contract::MAX_TRANSACTIONS)
        {
            throw new PlatformRequestException(
                message: 'Troppe transazioni in una sola richiesta.',
                status: 422,
                errors: ['transactions' => ['Al massimo '.Contract::MAX_TRANSACTIONS.' transazioni.']],
            );
        }

        foreach ($transactions as $item)
        {
            $this->checkTransaction($item['transaction']);
        }

        $ids = $this->outbox->prepare($transactions);
        $body = array_map(
            static fn (array $item): array => ['id' => $ids[$item['reference']], 'reference' => $item['reference'], ...$item['transaction']->toWire($item['revision'])],
            $transactions,
        );

        $outcomes = array_map(
            TransactionOutcomeData::from(...),
            $this->list($this->request('POST', 'transactions/batch', ['transactions' => $body])),
        );

        $sent = array_column($transactions, null, 'reference');
        $recorded = [];

        foreach ($outcomes as $outcome)
        {
            if (is_string($outcome->reference) && $outcome->result->isRecorded() && isset($sent[$outcome->reference]))
            {
                $recorded[] = $sent[$outcome->reference];
            }
        }

        $this->outbox->confirmed($recorded);

        return $outcomes;
    }

    /**
     * PUT /professionals/{tax_code}: declares the record of a professional when it changes in the system (rule R18).
     * The platform answers 204 also when it keeps its record, older or locked (rules R19 and R20). Prefer the outbox
     * of the package (ProfessionalOutbox), which repeats a 404 while transactions to the person wait to be registered.
     *
     * @throws ProfessionalNotAssignedException
     */
    public function declareProfessional(string $taxCode, ProfessionalRecordData $record): void
    {
        ProfessionalRecordData::check($taxCode, $record);

        try
        {
            $this->request('PUT', 'professionals/'.rawurlencode($taxCode), $record->toWire());
        }
        catch (PlatformRequestException $exception)
        {
            throw $exception->status === 404 ? new ProfessionalNotAssignedException($taxCode) : $exception;
        }
    }

    /**
     * GET /transactions: the transactions of the members of the system (rule R11).
     */
    public function transactions(?string $status = null, ?DateTimeInterface $updatedSince = null, ?int $perPage = null, ?string $cursor = null): TransactionPage
    {
        $response = $this->request('GET', 'transactions', $this->query([
            'status' => $status,
            'updated_since' => $updatedSince === null ? null : CarbonImmutable::instance($updatedSince)->format(Contract::DATE_FORMAT),
            'per_page' => $perPage,
            'cursor' => $cursor,
        ]));

        return new TransactionPage(
            transactions: array_map(RecordedTransactionData::from(...), $this->list($response)),
            nextCursor: $this->cursor($response, 'next_cursor'),
        );
    }

    /**
     * POST /files: uploads a file of a disk of the system for an event from sender to recipient, a member of another
     * system (rules F1–F4). The SHA-256 is computed reading the file, with constant memory. The same upload_id again
     * answers with the file already uploaded (rule F2); after a 410 the file needs a new upload_id. Network errors,
     * 5xx and a short 429 are retried here, reopening the file at every attempt. Pass the SHA-256 when it is already
     * known, to spare a reading of the file.
     *
     * @throws InvalidArgumentException when the disk cannot read the file
     */
    public function uploadFile(string $uploadId, string $sender, string $recipient, string $disk, string $path, string $name, string $mediaType, ?string $sha256 = null): FileData
    {
        $fields = [
            'upload_id' => $uploadId,
            'sender' => $sender,
            'recipient' => $recipient,
            'name' => $name,
            'media_type' => $mediaType,
            'sha256' => $sha256 ?? FileHash::of($disk, $path),
        ];

        return FileData::from($this->data($this->upload($fields, $disk, $path, $name)));
    }

    /**
     * GET /files/{id}/status: the file and its status, for its sender and its recipient (rule F5).
     */
    public function fileStatus(string $fileId): FileData
    {
        return FileData::from($this->data($this->request('GET', 'files/'.rawurlencode($fileId).'/status')));
    }

    /**
     * GET /files/{id}: downloads a file of an envelope delivered to the system and saves it on a disk, only if its
     * SHA-256 is the one of the envelope (rules F7 and F8). Confirm it after with confirmFile().
     *
     * @throws FileGoneException
     * @throws FileIntegrityException
     */
    public function downloadFile(FileReferenceData $file, string $disk, string $path): void
    {
        try
        {
            $response = $this->request('GET', 'files/'.rawurlencode($file->id), options: ['stream' => true, 'timeout' => $this->config->fileTimeout]);
        }
        catch (PlatformRequestException $exception)
        {
            throw $exception->status === 410 ? new FileGoneException($file->id) : $exception;
        }

        $body = $response->toPsrResponse()->getBody();
        $copy = tmpfile();
        $hash = hash_init('sha256');

        try
        {
            while (!$body->eof())
            {
                $chunk = $body->read(1_048_576);
                hash_update($hash, $chunk);
                fwrite($copy, $chunk);
            }

            if (hash_final($hash) !== $file->sha256)
            {
                throw new FileIntegrityException($file->id);
            }

            rewind($copy);
            Storage::disk($disk)->writeStream($path, $copy);
        }
        finally
        {
            fclose($copy);
        }
    }

    /**
     * POST /files/{id}/receipt: confirms a file saved and checked, and the platform deletes it (rule F8).
     */
    public function confirmFile(string $fileId, string $sha256): FileData
    {
        return FileData::from($this->data($this->request('POST', 'files/'.rawurlencode($fileId).'/receipt', ['sha256' => $sha256])));
    }

    /**
     * The milliseconds to wait before repeating a failed request, or null when it is not repeated: 200 after a 5xx,
     * Retry-After after a 429 that asks no more than MAX_WAIT_SECONDS (rule T7).
     */
    private static function retryDelay(Response $response): ?int
    {
        return match (true)
        {
            $response->serverError() => 200,
            $response->status() === 429 && self::retryAfter($response) <= self::MAX_WAIT_SECONDS => self::retryAfter($response) * 1000,
            default => null,
        };
    }

    /**
     * The seconds of Retry-After; a date or no header count as DEFAULT_RETRY_AFTER_SECONDS.
     */
    private static function retryAfter(Response $response): int
    {
        $seconds = $response->header('Retry-After');

        return ctype_digit($seconds) ? (int) $seconds : self::DEFAULT_RETRY_AFTER_SECONDS;
    }

    /**
     * The multipart request of an upload, with the fields before the content (rule F4), and its own retries: the
     * retries of the HTTP client would send again a stream already read. Guzzle closes each stream it releases.
     *
     * @param  array<string, string>  $fields
     */
    private function upload(array $fields, string $disk, string $path, string $name): Response
    {
        $url = $this->config->apiUrl('files');
        $parts = array_map(static fn (string $field, string $value): array => ['name' => $field, 'contents' => $value], array_keys($fields), $fields);
        $post = fn (): Response => $this->http->timeout($this->config->fileTimeout)->acceptJson()->withToken($this->accessToken())->asMultipart()
            ->post($url, [...$parts, ['name' => 'file', 'contents' => FileHash::open($disk, $path), 'filename' => $name]]);

        for ($attempt = 1; ; $attempt++)
        {
            $isLast = $attempt > $this->config->retries;

            try
            {
                $response = $post();

                if ($response->status() === 401)
                {
                    // The cached token may have expired early or belong to a rotated secret.
                    $this->cache->forget($this->tokenCacheKey());
                    $response = $post();
                }
            }
            catch (ConnectionException $exception)
            {
                if ($isLast)
                {
                    throw $exception;
                }

                Sleep::usleep(200_000);

                continue;
            }

            $delay = $response->failed() ? self::retryDelay($response) : null;

            if ($delay === null || $isLast)
            {
                return $response->failed() ? throw $this->exception($response, 'files') : $response;
            }

            Sleep::usleep($delay * 1000);
        }
    }

    /**
     * The rules of the DTO, and the schema fixed in the package for the description of every activity (rules R3–R6 and R14).
     */
    private function checkTransaction(TransactionData $transaction): void
    {
        TransactionData::validate($transaction->toWire(revision: 1));

        $path = Contract::transactionSchemaPath($transaction->type, $transaction->schema_version);

        if ($this->config->validatePayloads && is_file($path))
        {
            $schema = PayloadValidator::schemaAt($path);

            foreach ($transaction->activities as $index => $activity)
            {
                PayloadValidator::validateSchema($schema, $activity->description->toWire(), "activities.{$index}.description");
            }
        }
    }

    /**
     * The catalogue used by the local validation, cached for `catalog_ttl` seconds.
     *
     * @return list<EventTypeData>
     */
    private function catalogue(): array
    {
        $cached = $this->cache->get(self::CATALOGUE_CACHE_KEY);

        if (is_array($cached))
        {
            return array_values(array_map(EventTypeData::from(...), $cached));
        }

        $catalogue = $this->eventTypes();

        $this->cache->put(
            self::CATALOGUE_CACHE_KEY,
            array_map(static fn (EventTypeData $eventType): array => $eventType->toArray(), $catalogue),
            $this->config->catalogTtl,
        );

        return $catalogue;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $options  Options of Guzzle, such as stream for a download.
     *
     * @throws PlatformNotConfiguredException
     * @throws PlatformRequestException
     */
    private function request(string $method, string $path, array $body = [], array $options = []): Response
    {
        $url = $this->config->apiUrl($path);

        $response = $this->pending()->withToken($this->accessToken())->send($method, $url, [...$this->options($method, $body), ...$options]);

        if ($response->status() === 401)
        {
            // The cached token may have expired early or belong to a rotated secret.
            $this->cache->forget($this->tokenCacheKey());

            $response = $this->pending()->withToken($this->accessToken())->send($method, $url, [...$this->options($method, $body), ...$options]);
        }

        if ($response->failed())
        {
            throw $this->exception($response, $path);
        }

        return $response;
    }

    private function pending(): PendingRequest
    {
        return $this->http
            ->timeout($this->config->timeout)
            ->acceptJson()
            ->retry(
                times: $this->config->retries + 1,
                sleepMilliseconds: static fn (int $attempt, mixed $exception): int => $exception instanceof RequestException ? self::retryDelay($exception->response) ?? 200 : 200,
                when: static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && self::retryDelay($exception->response) !== null),
                throw: false,
            );
    }

    /**
     * The body as the query of a GET or as JSON. An empty body is not sent at all, not even as an empty JSON array.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function options(string $method, array $body): array
    {
        if ($body === [])
        {
            return [];
        }

        return $method === 'GET' ? ['query' => $body] : ['json' => $body];
    }

    private function accessToken(): string
    {
        $cached = $this->cache->get($this->tokenCacheKey());

        if (is_string($cached) && $cached !== '')
        {
            return $cached;
        }

        $response = $this->http
            ->timeout($this->config->timeout)
            ->acceptJson()
            ->post($this->config->tokenUrl(), [
                'grant_type' => 'client_credentials',
                'client_id' => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
                'scope' => Contract::SCOPE,
            ]);

        $token = $response->json('access_token');

        if ($response->failed() || !is_string($token) || $token === '')
        {
            throw new PlatformRequestException(
                message: 'Credenziali del portale non valide.',
                status: $response->status(),
            );
        }

        $expiresIn = $response->json('expires_in');

        $this->cache->put($this->tokenCacheKey(), $token, max(60, (is_int($expiresIn) ? $expiresIn : 3600) - 60));

        return $token;
    }

    private function tokenCacheKey(): string
    {
        return 'platform.access-token.'.sha1((string) $this->config->clientId);
    }

    private function exception(Response $response, string $path): PlatformRequestException
    {
        $message = $response->json('message');
        $reason = $response->json('reason');
        $existing = $response->status() === 409 ? $response->json('data') : null;

        return new PlatformRequestException(
            message: is_string($message) ? $message : 'Richiesta al portale non riuscita.',
            status: $response->status(),
            errors: $this->errors($response->json('errors')),
            reason: is_string($reason) ? SystemStatus::tryFrom($reason) : null,
            existingEvent: is_array($existing) && $path === 'events' ? AcceptedEventData::from($existing) : null,
            existingTransaction: is_array($existing) && str_starts_with($path, 'transactions/') ? RecordedTransactionData::from($existing) : null,
            retryAfter: $response->status() === 429 ? self::retryAfter($response) : null,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function errors(mixed $errors): array
    {
        if (!is_array($errors))
        {
            return [];
        }

        $normalized = [];

        foreach ($errors as $key => $messages)
        {
            $normalized[(string) $key] = array_values(array_filter((array) $messages, is_string(...)));
        }

        return $normalized;
    }

    /**
     * The filters of a GET without those not given.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function query(array $filters): array
    {
        return array_filter($filters, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * A cursor of a page in `meta`, null on the first or the last page.
     */
    private function cursor(Response $response, string $key): ?string
    {
        $cursor = $response->json('meta.'.$key);

        return is_string($cursor) ? $cursor : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        $data = $response->json('data');

        /** @var array<string, mixed> */
        return is_array($data) ? $data : [];
    }

    /**
     * @return list<mixed>
     */
    private function list(Response $response): array
    {
        $data = $response->json('data');

        return is_array($data) ? array_values($data) : [];
    }
}
