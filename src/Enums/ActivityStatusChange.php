<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Enums;

/**
 * What the assigned firm tells the principal about an activity with coworking.activity_status.
 */
enum ActivityStatusChange: string
{
    case Completed = 'completed';
    case Reopened = 'reopened';
}
