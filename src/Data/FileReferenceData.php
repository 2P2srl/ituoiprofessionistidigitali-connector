<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Carbon\CarbonImmutable;
use ITuoiProfessionistiDigitali\Connector\Contract;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * A file as the envelope delivered to the webhook lists it: what it takes to download it and check it. The hash of
 * the envelope, which is signed, is the one a download is checked on (rule F8).
 */
final class FileReferenceData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $media_type,
        public int $size,
        public string $sha256,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public ?CarbonImmutable $available_until,
    ) {}
}
