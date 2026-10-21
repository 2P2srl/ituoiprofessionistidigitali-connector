<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Models\Concerns\HasOutboxColumns;

/**
 * The last version of a transaction to send to the register, and what the platform answered.
 *
 * @property int $id
 * @property string $reference
 * @property string $transaction_id The id of the transaction on the platform, chosen at the first registration (rule R23).
 * @property int $revision
 * @property int|null $sent_revision
 * @property array<string, mixed> $payload
 * @property OutboxStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PlatformTransactionOutbox extends Model
{
    use HasOutboxColumns;
    use HasUuids;

    protected $table = 'platform_transaction_outbox';

    protected $guarded = ['id'];

    /**
     * The id of the transaction, given at the first save and kept at every revision: a UUID v7 in lower case
     * (rules T3 and R23). The primary key stays the incrementing id.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['transaction_id'];
    }

    /**
     * The rows sent to the person with this tax code: the outbox keeps the counterparty of every sending (rule R18).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeToPerson(Builder $query, string $taxCode): Builder
    {
        return $query->where('payload->counterparty->tax_code', $taxCode);
    }
}
