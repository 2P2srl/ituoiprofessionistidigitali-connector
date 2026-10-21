<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Enums;

/**
 * Who sends the events of a type: the members with POST /events, or the platform only (rules C3 and E5).
 */
enum EventSender: string
{
    case Members = 'members';
    case Platform = 'platform';
}
