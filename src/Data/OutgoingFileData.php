<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * A file of the system to send with an event through the outbox. Cite its ref in the payload and in the files of the
 * envelope, where the schema wants the id of a file: the outbox uploads it and puts the id of the platform in its
 * place before sending the event (rules E12 and F5).
 *
 * @param  string  $ref  A UUID chosen by the package, that stands for the file until its upload.
 */
final class OutgoingFileData extends Data
{
    /**
     * @param  string  $ref  A UUID chosen by the package, that stands for the file until its upload.
     */
    public function __construct(
        public string $ref,
        public string $disk,
        public string $path,
        public string $name,
        public string $media_type,
    ) {}

    public static function make(string $disk, string $path, string $name, string $mediaType): self
    {
        return new self((string) Str::uuid7(), $disk, $path, $name, $mediaType);
    }
}
