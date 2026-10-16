<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Exceptions;

/**
 * The 404 of the listings and of the applications to them: the listing is unknown, no longer open, not open to
 * firms or of a principal of the same system (rules L1 and L10). Not to retry: read the listings again.
 */
final class ListingNotFoundException extends PlatformException
{
    public function __construct(public readonly string $listingId)
    {
        parent::__construct("L'inserzione non è più visibile al sistema.");
    }
}
