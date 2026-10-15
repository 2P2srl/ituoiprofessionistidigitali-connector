<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Models\Concerns;

use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;

/**
 * The columns the outboxes share: the revision kept and the one confirmed, the payload, the status and the last error.
 *
 * @internal
 */
trait HasOutboxColumns
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'sent_revision' => 'integer',
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
