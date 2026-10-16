<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Exceptions;

/**
 * A downloaded file whose SHA-256 is not the one of the envelope: nothing is saved nor confirmed (rule F8).
 */
final class FileIntegrityException extends PlatformException
{
    public function __construct(public readonly string $fileId)
    {
        parent::__construct('Il file scaricato non corrisponde al suo hash.');
    }
}
