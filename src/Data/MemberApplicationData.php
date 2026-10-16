<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Carbon\CarbonImmutable;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Enums\ApplicationStatus;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * An application of a member of the system to a listing, as GET /applications returns it (rules L3 and L10).
 * A declined one means another selection or the listing withdrawn; a selected one arrives together with
 * transaction.assignment_received.
 */
final class MemberApplicationData extends Data
{
    /**
     * @param  string  $transaction  The id of the transaction, the same of GET /listings/{id}.
     * @param  string  $member  The member of the system that applied.
     * @param  int  $accepted_revision  The revision of the transaction whose conditions the application accepted.
     */
    public function __construct(
        public string $id,
        public string $transaction,
        public string $member,
        public ApplicationStatus $status,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public CarbonImmutable $applied_at,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public CarbonImmutable $terms_accepted_at,
        public int $accepted_revision,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public ?CarbonImmutable $closed_at,
    ) {}
}
