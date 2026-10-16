<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Support;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The SHA-256 of a file of a disk of the system, read once with constant memory, in lower case hexadecimal (rule F3).
 *
 * @internal
 */
final class FileHash
{
    /**
     * @throws InvalidArgumentException when the disk cannot read the file
     */
    public static function of(string $disk, string $path): string
    {
        $stream = self::open($disk, $path);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        fclose($stream);

        return hash_final($hash);
    }

    /**
     * @return resource
     *
     * @throws InvalidArgumentException when the disk cannot read the file
     */
    public static function open(string $disk, string $path)
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (!is_resource($stream))
        {
            throw new InvalidArgumentException("Il file {$path} del disco {$disk} non si legge.");
        }

        return $stream;
    }
}
