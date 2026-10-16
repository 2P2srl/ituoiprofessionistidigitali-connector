<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Exceptions;

/**
 * The 404 of the withdrawal of an application: unknown, or of another system (rule L3).
 */
final class ApplicationNotFoundException extends PlatformException
{
    public function __construct(public readonly string $listingId, public readonly string $applicationId)
    {
        parent::__construct('La candidatura non è del sistema.');
    }
}
