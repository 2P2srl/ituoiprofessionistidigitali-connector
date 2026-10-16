<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;

/**
 * An event of a member of the system to send to the platform, kept before sending it, and what the platform answered.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property array<string, mixed> $envelope
 * @property OutboxStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PlatformEventOutbox extends Model
{
    protected $table = 'platform_event_outbox';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'envelope' => 'array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
