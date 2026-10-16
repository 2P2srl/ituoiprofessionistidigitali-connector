<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Exceptions;

use Throwable;

/**
 * The 422 of POST /listings/{id}/applications on the member: not an active member of the system, without the
 * typology of the assignment, or with its application already withdrawn (rule L10). Not to retry: show the messages.
 */
final class ApplicationRefusedException extends PlatformException
{
    /**
     * @param  list<string>  $messages  The messages of the platform on the member.
     */
    public function __construct(public readonly string $listingId, public readonly string $memberId, public readonly array $messages, ?Throwable $previous = null)
    {
        parent::__construct('Lo studio non si può candidare.', previous: $previous);
    }
}
