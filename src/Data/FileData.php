<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Carbon\CarbonImmutable;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Enums\FileStatus;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * A file on the platform, as POST /files and GET /files/{id}/status return it (rules F1–F9).
 */
final class FileData extends Data
{
    /**
     * @param  string|null  $name  Null after the confirmation or the expiry.
     * @param  CarbonImmutable|null  $citable_until  Until when an event may cite it (rule F5).
     * @param  CarbonImmutable|null  $available_until  Until when the recipient may download it, from the delivery (rule F9).
     */
    public function __construct(
        public string $id,
        public string $upload_id,
        public string $sender,
        public string $recipient,
        public ?string $name,
        public string $media_type,
        public int $size,
        public string $sha256,
        public FileStatus $status,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public ?CarbonImmutable $citable_until,
        #[WithCast(DateTimeInterfaceCast::class, format: Contract::DATE_INPUT_FORMATS)]
        public ?CarbonImmutable $available_until,
    ) {}
}
