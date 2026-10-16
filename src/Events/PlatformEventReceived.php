<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Events;

use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\ReceivedFileData;

/**
 * An event delivered by the platform to the webhook, signature already verified.
 *
 * The same event can arrive more than once and in any order (rule W9): listeners are idempotent
 * on `event_id` and queue their work, because the platform waits ten seconds at most.
 *
 * An event with files reaches the application only after the package saved and confirmed them (rule F8): `files`
 * says where each one is.
 */
final readonly class PlatformEventReceived
{
    /**
     * @param  list<ReceivedFileData>  $files
     */
    public function __construct(public EnvelopeData $envelope, public array $files = []) {}
}
