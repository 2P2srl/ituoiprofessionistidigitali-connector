<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use ITuoiProfessionistiDigitali\Connector\Contract;
use LogicException;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Transformers\DateTimeInterfaceTransformer;
use stdClass;

/**
 * The envelope of an event: what a system sends with POST /events and what the platform delivers to a webhook.
 *
 * Build an outgoing event with make() or reply(): both validate it as POST /events does (rule E1).
 * Read an incoming one with from(): fields the package does not know are ignored (rule W2).
 *
 * The files have two forms (rule E12): an outgoing envelope lists their ids, a delivered one their metadata, read with
 * fileReferences(). An envelope without files has no files key on the wire.
 */
final class EnvelopeData extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>|list<array<string, mixed>>|null  $files
     */
    public function __construct(
        public string $event_id,
        public string $type,
        public int $schema_version,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        #[WithTransformer(DateTimeInterfaceTransformer::class, format: Contract::DATE_FORMAT)]
        public CarbonImmutable $occurred_at,
        public ?string $sender,
        public ?string $recipient,
        public ?string $typology,
        public ?string $correlation_id,
        public array $payload,
        public ?array $files = null,
    ) {}

    /**
     * A new event from one of the system's members, with a fresh UUID v7 and the current time.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $files  The ids of the files uploaded for the event, the ones the payload cites (rule E12).
     */
    public static function make(
        string $type,
        int $schemaVersion,
        string $sender,
        string $recipient,
        string $typology,
        array $payload,
        ?string $correlationId = null,
        ?string $eventId = null,
        array $files = [],
    ): self {
        return self::validateAndCreate([
            'event_id' => $eventId ?? (string) Str::uuid7(),
            'type' => $type,
            'schema_version' => $schemaVersion,
            'occurred_at' => CarbonImmutable::now()->format(Contract::DATE_FORMAT),
            'sender' => $sender,
            'recipient' => $recipient,
            'typology' => $typology,
            'correlation_id' => $correlationId,
            'payload' => $payload,
            'files' => $files === [] ? null : $files,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'type' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/'],
            'schema_version' => ['required', 'integer', 'min:1'],
            'occurred_at' => ['required', 'string', 'regex:'.Contract::DATE_PATTERN, 'date'],
            'sender' => ['required', 'uuid'],
            'recipient' => ['required', 'uuid'],
            'typology' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/'],
            'correlation_id' => ['nullable', 'uuid'],
            'payload' => ['present', 'array'],
            'files' => ['nullable', 'array', 'list', 'max:'.Contract::MAX_FILES_PER_EVENT],
            'files.*' => ['uuid', 'distinct'],
        ];
    }

    /**
     * The ids of the files of the envelope, in both forms.
     *
     * @return list<string>
     */
    public function fileIds(): array
    {
        return array_map(static fn (mixed $file): string => match (true)
        {
            is_string($file) => $file,
            is_string($file['id'] ?? null) => $file['id'],
            default => '',
        }, $this->files ?? []);
    }

    /**
     * The files of a delivered envelope, with what it takes to download them and check them (rule F8).
     *
     * @return list<FileReferenceData>
     */
    public function fileReferences(): array
    {
        return array_values(array_map(FileReferenceData::from(...), array_filter($this->files ?? [], is_array(...))));
    }

    /**
     * The answer to this event: from its recipient to its sender, same typology, correlated to it. It never carries
     * the files of the event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function reply(string $type, int $schemaVersion, array $payload, ?string $eventId = null): self
    {
        if ($this->sender === null || $this->recipient === null || $this->typology === null)
        {
            throw new LogicException('An event from the platform has nobody to reply to.');
        }

        return self::make(
            type: $type,
            schemaVersion: $schemaVersion,
            sender: $this->recipient,
            recipient: $this->sender,
            typology: $this->typology,
            payload: $payload,
            correlationId: $this->event_id,
            eventId: $eventId,
        );
    }

    /**
     * An event the platform generates itself, with no sender (rule L6): of a type it sends, or the verification ping.
     */
    public function isFromPlatform(): bool
    {
        return $this->sender === null && ($this->isVerification() || in_array($this->type, Contract::PLATFORM_EVENTS, true));
    }

    /**
     * A ping sent by the platform itself to verify the webhook, with no sender and no recipient.
     */
    public function isVerification(): bool
    {
        return $this->type === Contract::PING && $this->sender === null;
    }

    /**
     * The body sent on the wire: an empty payload is still a JSON object.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        /** @var array<string, mixed> $wire */
        $wire = $this->toArray();
        $wire['payload'] = $this->payload === [] ? new stdClass : $this->payload;
        unset($wire['files']);
        $files = $this->fileIds();

        return $files === [] ? $wire : [...$wire, 'files' => $files];
    }
}
