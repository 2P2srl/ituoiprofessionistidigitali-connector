<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Events\PlatformEventReceived;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;
use Ramsey\Uuid\Uuid;

/**
 * Answers a ping from a member with a pong: inverted, same typology, correlated, same challenge (rule P1).
 *
 * The pong's event_id derives from the ping's, so a ping delivered twice yields one pong:
 * the platform answers the second one with 200 or 409 and nothing is sent twice.
 *
 * @internal
 */
final readonly class ReplyToPing implements ShouldQueue
{
    private const string PONG_NAMESPACE = '6f1d2c4a-8b7e-4f3a-9c5d-2e1b0a9f8c7d';

    public function __construct(private PlatformClient $client) {}

    public function shouldQueue(PlatformEventReceived $event): bool
    {
        return $event->envelope->type === Contract::PING && !$event->envelope->isVerification();
    }

    /**
     * Laravel calls it on an instance built without the constructor, so the configuration comes from the container.
     */
    public function viaQueue(): ?string
    {
        return resolve(ConnectorConfig::class)->queue;
    }

    public function handle(PlatformEventReceived $event): void
    {
        if (!$this->shouldQueue($event))
        {
            return;
        }

        $ping = $event->envelope;

        $pong = $ping->reply(
            type: Contract::PONG,
            schemaVersion: 1,
            payload: ['challenge' => $ping->payload['challenge'] ?? null],
            eventId: Uuid::uuid5(self::PONG_NAMESPACE, $ping->event_id)->toString(),
        );

        try
        {
            $this->client->send($pong);
        }
        catch (PlatformRequestException $exception)
        {
            if (!$exception->isConflict())
            {
                throw $exception;
            }
        }
    }
}
