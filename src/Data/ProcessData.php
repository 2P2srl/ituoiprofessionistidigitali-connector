<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The process an activity belongs to, for every kind of subject: line or staff process, project or agenda.
 * Every activity of the same subject carries the same reference, so the receiving firm groups them in one process.
 */
final class ProcessData extends Data
{
    public const int MAX_REFERENCE_LENGTH = 191;

    /**
     * @param  string  $reference  Opaque and stable for each instance of the subject in the system, never a name.
     * @param  string|null  $name  The name of the process in the catalogue, null when it has none, such as a
     *                             project or an agenda named after the client (rule R6).
     */
    public function __construct(
        public string $reference,
        public ?string $name = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $process = is_array($context->payload) ? $context->payload : [];

        // An empty name is no name: it must be null, as the schema wants it
        return [
            'reference' => ['required', 'string', 'max:'.self::MAX_REFERENCE_LENGTH],
            'name' => ['present', Rule::requiredIf(($process['name'] ?? null) === ''), 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{reference: string, name: string|null}
     */
    public function toWire(): array
    {
        return ['reference' => $this->reference, 'name' => $this->name];
    }
}
