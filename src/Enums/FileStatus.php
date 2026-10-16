<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Enums;

/**
 * Where a file is in its passage through the platform (rules F5, F8 and F9).
 */
enum FileStatus: string
{
    /**
     * Uploaded, to cite in an event within citable_until.
     */
    case Uploaded = 'uploaded';

    /**
     * Cited by an event the platform accepted.
     */
    case Sent = 'sent';

    /**
     * Confirmed by the recipient: content and name are deleted.
     */
    case Received = 'received';

    /**
     * Deleted without a confirmation, or never cited.
     */
    case Expired = 'expired';
}
