<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Data;

/**
 * The transaction between two firms an event is about, by its id on the platform: also the correlation_id of the event.
 */
final class TransactionIdData extends Data
{
    public function __construct(
        public string $id,
    ) {}
}
