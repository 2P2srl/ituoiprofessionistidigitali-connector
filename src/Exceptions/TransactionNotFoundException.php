<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Exceptions;

/**
 * GET /transactions?id= answered no transaction: the id does not exist, or the system cannot see it, not as principal
 * nor as counterparty (rule R11). For an offer just received it means the register is not aligned yet: retry later.
 */
final class TransactionNotFoundException extends PlatformException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct('La transazione non è nel registro, o il sistema non la vede.');
    }
}
