<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data\Concerns;

use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Validation\PayloadValidator;

/**
 * The payload of an event between the two firms of a transaction (rule E13): the envelope correlated to the
 * transaction, checked on the schema fixed in the package before it leaves, with the files the payload cites.
 */
trait IsEventBetweenFirms
{
    /**
     * The payload as the schema of the type wants it.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        /** @var array<string, mixed> */
        return $this->toArray();
    }

    /**
     * The envelope from a member of the system to the other firm of the transaction, with the typology of the
     * transaction. Keep it with EventOutbox::queue(), in the transaction of the database of the change.
     *
     * @throws PlatformRequestException
     */
    public function toEnvelope(string $sender, string $recipient, string $typology, ?string $eventId = null): EnvelopeData
    {
        $payload = $this->toPayload();

        $files = array_values(array_unique(PayloadValidator::validateSchema(PayloadValidator::schemaAt(Contract::schemaPath(self::TYPE, 1)), $payload)));

        return EnvelopeData::make(
            type: self::TYPE,
            schemaVersion: 1,
            sender: $sender,
            recipient: $recipient,
            typology: $typology,
            payload: $payload,
            correlationId: $this->transaction->id,
            eventId: $eventId,
            files: $files,
        );
    }
}
