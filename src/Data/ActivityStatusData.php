<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Carbon\CarbonImmutable;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\Concerns\IsEventBetweenFirms;
use ITuoiProfessionistiDigitali\Connector\Enums\ActivityStatusChange;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Transformers\DateTimeInterfaceTransformer;

/**
 * The payload of coworking.activity_status v1: the assigned firm tells the principal that an activity was completed or
 * reopened, and when. The principal brings it into the PUTs of the transaction: only it registers them (rule R2).
 */
final class ActivityStatusData extends Data
{
    use IsEventBetweenFirms;

    public const string TYPE = Contract::ACTIVITY_STATUS;

    /**
     * @param  string  $activity  The reference of the activity in the transaction.
     */
    public function __construct(
        public TransactionIdData $transaction,
        public string $activity,
        public ActivityStatusChange $status,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        #[WithTransformer(DateTimeInterfaceTransformer::class, format: Contract::DATE_FORMAT)]
        public CarbonImmutable $occurred_at,
    ) {}
}
