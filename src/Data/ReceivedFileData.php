<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Data;

/**
 * A file of an event received, saved by the package and confirmed to the platform before the event reached the
 * application (rule F8). Move it to the storage of the system: the package keeps it only for the passage.
 */
final class ReceivedFileData extends Data
{
    /**
     * @param  string|null  $path  Where it is saved on the disk; null when the platform no longer had it (rule F9):
     *                             ask the sender for it again.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $media_type,
        public int $size,
        public string $sha256,
        public string $disk,
        public ?string $path,
    ) {}

    public static function of(FileReferenceData $file, string $disk, ?string $path): self
    {
        return new self($file->id, $file->name, $file->media_type, $file->size, $file->sha256, $disk, $path);
    }

    public function isMissing(): bool
    {
        return $this->path === null;
    }
}
