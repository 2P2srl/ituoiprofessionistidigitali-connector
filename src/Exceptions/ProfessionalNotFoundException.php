<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Exceptions;

/**
 * The 404 of GET /professionals/{id}: the id is unknown, or the consultant is not among those the firms may find, for
 * instance because the consent was withdrawn (rule N4). The platform does not say which: show an error, do not retry.
 */
final class ProfessionalNotFoundException extends PlatformException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct('Il consulente non è fra quelli consultabili dagli studi.');
    }
}
