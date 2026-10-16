<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Carbon\CarbonImmutable;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Enums\Audience;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * A published assignment the members of the system may apply to, as GET /listings returns it (rule L1): open to
 * firms, of a principal of another system, which stays anonymous but for its seat (rule L7).
 */
final class ListingData extends Data
{
    /**
     * @param  string  $id  The id of the transaction, the same of the events.
     * @param  int  $revision  The revision whose conditions an application accepts (accepted_revision).
     * @param  list<ListingActivityData>  $activities
     */
    public function __construct(
        public string $id,
        public string $typology,
        public Audience $audience,
        public string $title,
        public string $description,
        public ListingPrincipalData $principal,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public CarbonImmutable $sent_at,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public CarbonImmutable $expires_at,
        public int $revision,
        public string $currency,
        public int $total_cents,
        public string $type,
        public int $schema_version,
        #[DataCollectionOf(ListingActivityData::class)]
        public array $activities,
    ) {}
}
