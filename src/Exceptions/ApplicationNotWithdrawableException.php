<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Exceptions;

use Throwable;

/**
 * The 422 of the withdrawal of an application on the status: already selected or declined (rule L3). Not to retry:
 * read the applications again.
 */
final class ApplicationNotWithdrawableException extends PlatformException
{
    /**
     * @param  list<string>  $messages  The messages of the platform on the status.
     */
    public function __construct(public readonly string $listingId, public readonly string $applicationId, public readonly array $messages, ?Throwable $previous = null)
    {
        parent::__construct('La candidatura non si può più ritirare.', previous: $previous);
    }
}
