<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Enums;

/**
 * Where an assignment sent to a counterparty is (rule R8).
 */
enum TransactionStatus: string
{
    case Invited = 'invited';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Revoked = 'revoked';
    case Completed = 'completed';

    /**
     * The statuses that end the transaction with a closed_at: done or revoked.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Revoked], true);
    }

    /**
     * The statuses after an answer of the counterparty, with a responded_at.
     */
    public function isAnswered(): bool
    {
        return in_array($this, [self::Accepted, self::Declined, self::Completed], true);
    }

    /**
     * The statuses before any activity is entrusted: every activity is still open and nothing is signed (rules R8 and R14).
     */
    public function isBeforeAgreement(): bool
    {
        return in_array($this, [self::Invited, self::Declined], true);
    }
}
