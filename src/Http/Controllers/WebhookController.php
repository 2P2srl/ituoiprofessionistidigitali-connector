<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Http\Controllers;

use Exception;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\CounterpartySelectedData;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\RecordedTransactionData;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionWithdrawnData;
use ITuoiProfessionistiDigitali\Connector\Events\PlatformEventReceived;
use ITuoiProfessionistiDigitali\Connector\Jobs\ReceivePlatformFiles;
use ITuoiProfessionistiDigitali\Connector\Outbox\TransactionOutbox;
use TypeError;

/**
 * The webhook of the system: answers the verification of the platform and hands every other event to the application,
 * from a member or from the platform (rule L6).
 *
 * An event without sender of a type or version the package does not know comes from a newer platform: it gets a 2xx
 * and a warning in the log, so the platform does not repeat it for a day (rules W5 and W6), and it never reaches the
 * application. Its payload is never read.
 *
 * The selection of a counterparty and the withdrawal after the expiry reach the outbox before the listeners: the next
 * change of the model goes out with a revision higher than the one of the platform (rules L4 and L9).
 *
 * An event with files gets its 2xx at once and reaches the application from the queue, after its files are saved
 * and confirmed (rule F8).
 *
 * @internal
 */
final readonly class WebhookController
{
    public function __invoke(Request $request, Dispatcher $events, TransactionOutbox $outbox, ConnectorConfig $config): JsonResponse|Response
    {
        try
        {
            $envelope = EnvelopeData::from($request->json()->all());
            $isUnknown = $envelope->sender === null && !$this->isKnown($envelope);
            $changed = $envelope->sender === null && !$isUnknown ? $this->changedByPlatform($envelope) : null;
        }
        catch (Exception|TypeError)
        {
            return response()->json(['message' => 'Busta non valida.'], 400);
        }

        if ($envelope->isVerification())
        {
            return response()->json(['challenge' => $envelope->payload['challenge'] ?? null]);
        }

        if ($isUnknown)
        {
            Log::warning('Evento del portale di un tipo o di una versione sconosciuti al pacchetto: ignorato. Aggiorna ituoiprofessionistidigitali/connector.', [
                'event_id' => $envelope->event_id,
                'type' => $envelope->type,
                'schema_version' => $envelope->schema_version,
            ]);

            return response()->json(['message' => 'Tipo di evento sconosciuto: ignorato.'], 202);
        }

        if ($changed !== null)
        {
            $outbox->changedByPlatform($changed);
        }

        if ($envelope->fileReferences() === [])
        {
            $events->dispatch(new PlatformEventReceived($envelope));
        }
        else
        {
            dispatch(new ReceivePlatformFiles($envelope))->onQueue($config->queue);
        }

        return response()->noContent();
    }

    /**
     * Whether the package reads an event of the platform: a type it sends (rule L6), at a version whose schema this
     * release of the package fixes.
     */
    private function isKnown(EnvelopeData $envelope): bool
    {
        return $envelope->isFromPlatform() && is_file(Contract::schemaPath($envelope->type, $envelope->schema_version));
    }

    /**
     * The transaction an event of the platform carries when the platform changed it by itself: accepted with the
     * selection (rule L4) or withdrawn after its expiry (rule L9).
     */
    private function changedByPlatform(EnvelopeData $envelope): ?RecordedTransactionData
    {
        return match ($envelope->type)
        {
            Contract::COUNTERPARTY_SELECTED => CounterpartySelectedData::from($envelope->payload)->transaction,
            Contract::TRANSACTION_WITHDRAWN => TransactionWithdrawnData::from($envelope->payload)->transaction,
            default => null,
        };
    }
}
