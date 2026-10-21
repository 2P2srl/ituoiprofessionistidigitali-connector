<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use ITuoiProfessionistiDigitali\Connector\Enums\EventSender;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * An entry of the catalogue of messages (rule C3).
 */
final class EventTypeData extends Data
{
    /**
     * @param  list<string>|null  $typologies  Null when the type is allowed for every typology.
     * @param  list<EventTypeVersionData>  $versions
     * @param  EventSender  $sent_by  Members when absent, as in a catalogue cached before the field existed.
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public ?array $typologies,
        #[DataCollectionOf(EventTypeVersionData::class)]
        public array $versions,
        public EventSender $sent_by = EventSender::Members,
    ) {}

    public function version(int $version): ?EventTypeVersionData
    {
        return array_find($this->versions, fn (EventTypeVersionData $candidate): bool => $candidate->version === $version);
    }

    public function allowsTypology(string $typology): bool
    {
        return $this->typologies === null || in_array($typology, $this->typologies, true);
    }
}
