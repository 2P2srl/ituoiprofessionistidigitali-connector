<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Exceptions;

/**
 * The 404 of GET /members/{id}: not a listed member of another active system, nor one linked to the system by a
 * transaction; a member of the system itself or an unknown id answer the same (rule M16).
 */
final class MemberNotFoundException extends PlatformException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct('L’aderente non è visibile al sistema.');
    }
}
