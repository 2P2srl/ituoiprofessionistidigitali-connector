<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Carbon\CarbonImmutable;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Enums\TransactionStatus;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * A transaction as the register of the platform returns it (rule R11).
 */
final class RecordedTransactionData extends Data
{
    /**
     * @param  list<RecordedTransactionActivityData>  $activities
     */
    public function __construct(
        public string $id,
        public ?string $reference,
        public ?string $assignment_reference,
        public string $principal,
        public CounterpartyData $counterparty,
        public string $typology,
        public string $title,
        public string $description,
        public TransactionStatus $status,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public CarbonImmutable $sent_at,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public ?CarbonImmutable $responded_at,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public ?CarbonImmutable $signed_at,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public ?CarbonImmutable $closed_at,
        public string $currency,
        public int $total_cents,
        public int $revision,
        public string $type,
        public int $schema_version,
        #[DataCollectionOf(RecordedTransactionActivityData::class)]
        public array $activities,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public ?CarbonImmutable $updated_at,
    ) {}
}
