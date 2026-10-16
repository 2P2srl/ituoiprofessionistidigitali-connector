<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Exceptions;

/**
 * The 410 of a download: the file expired, or the recipient already confirmed it (rules F8 and F9). If the document
 * is still needed, the sender uploads it again with a new event.
 */
final class FileGoneException extends PlatformException
{
    public function __construct(public readonly string $fileId)
    {
        parent::__construct('Il file non è più sul portale.');
    }
}
