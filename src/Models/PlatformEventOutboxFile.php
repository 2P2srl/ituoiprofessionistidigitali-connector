<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A file of an event in the outbox: where it is in the system, its SHA-256 when the event was kept, and the file the
 * platform made of it. The envelope cites it by ref until the upload gives it its id.
 *
 * @property int $id
 * @property string $event_id
 * @property string $ref
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string $media_type
 * @property string $sha256
 * @property string|null $upload_id
 * @property string|null $file_id
 * @property CarbonImmutable|null $citable_until
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @internal
 */
final class PlatformEventOutboxFile extends Model
{
    protected $table = 'platform_event_outbox_files';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'citable_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
