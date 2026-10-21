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
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Events\PlatformEventReceived;
use ITuoiProfessionistiDigitali\Connector\Jobs\ReceivePlatformFiles;
use TypeError;

/**
 * The webhook of the system: answers the verification of the platform and hands every event of a member to the
 * application.
 *
 * Today the platform sends by itself only the verification ping. Any other event without sender comes from a newer
 * platform, with a type the package does not know: it gets a 2xx and a warning in the log, so the platform does not
 * repeat it for a day (rules W5 and W6), and it never reaches the application. Its payload is never read.
 *
 * An event with files gets its 2xx at once and reaches the application from the queue, after its files are saved
 * and confirmed (rule F8).
 *
 * @internal
 */
final readonly class WebhookController
{
    public function __invoke(Request $request, Dispatcher $events, ConnectorConfig $config): JsonResponse|Response
    {
        try
        {
            $envelope = EnvelopeData::from($request->json()->all());
        }
        catch (Exception|TypeError)
        {
            return response()->json(['message' => 'Busta non valida.'], 400);
        }

        if ($envelope->isVerification())
        {
            return response()->json(['challenge' => $envelope->payload['challenge'] ?? null]);
        }

        if ($envelope->sender === null)
        {
            Log::warning('Evento del portale di un tipo o di una versione sconosciuti al pacchetto: ignorato. Aggiorna ituoiprofessionistidigitali/connector.', [
                'event_id' => $envelope->event_id,
                'type' => $envelope->type,
                'schema_version' => $envelope->schema_version,
            ]);

            return response()->json(['message' => 'Tipo di evento sconosciuto: ignorato.'], 202);
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
}
