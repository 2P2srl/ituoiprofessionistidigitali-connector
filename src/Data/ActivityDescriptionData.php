<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Closure;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The work of an activity: the process it belongs to, always, and the names of the catalogue of the system, null
 * where the catalogue has none. Never a name that may hold the client, such as an agenda or a project area named
 * after it (rule R6).
 *
 * The activity reads both its own flat form and the form of the schema assignment v1, `{"activity": {"name": "…"}}`.
 */
final class ActivityDescriptionData extends Data
{
    public function __construct(
        public ProcessData $process,
        public ?string $activity = null,
        public ?string $deadline = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        if (is_array($properties['activity'] ?? null))
        {
            $properties['activity'] = $properties['activity']['name'] ?? null;
        }

        return $properties;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $description = is_array($context->payload) ? $context->payload : [];

        // An empty name is no name: it must be null, as the schema wants it
        return [
            'process' => ['required', 'array:reference,name'],
            'activity' => ['present', Rule::requiredIf(($description['activity'] ?? null) === ''), 'nullable', self::name(...)],
            'deadline' => ['present', 'nullable', 'string', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The description as the schema assignment v1 wants it.
     *
     * @return array{process: array{reference: string, name: string|null}, activity: array{name: string}|null, deadline: string|null}
     */
    public function toWire(): array
    {
        return [
            'process' => $this->process->toWire(),
            'activity' => $this->activity === null ? null : ['name' => $this->activity],
            'deadline' => $this->deadline,
        ];
    }

    /**
     * A name from 1 to 255 characters, flat or as `{"name": "…"}`.
     *
     * @param  Closure(string): mixed  $fail
     */
    private static function name(string $attribute, mixed $value, Closure $fail): void
    {
        $name = is_array($value) && array_keys($value) === ['name'] ? $value['name'] : $value;

        if (!is_string($name) || $name === '' || mb_strlen($name) > 255)
        {
            $fail('Il nome deve essere un testo da 1 a 255 caratteri del catalogo.');
        }
    }
}
