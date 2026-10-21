<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ITuoiProfessionistiDigitali\Connector\Concerns\RecordsOnPlatform;
use ITuoiProfessionistiDigitali\Connector\Contracts\RecordsPlatformTransaction;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionData;

/**
 * A proposal of an external assignment as a system would keep it: a sending with its activities,
 * whose every save must reach the register.
 *
 * @property int $id
 * @property string|null $uuid
 * @property string $status
 */
final class Assignment extends Model implements RecordsPlatformTransaction
{
    use RecordsOnPlatform;

    public const string PRINCIPAL = '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43';

    protected $guarded = ['id'];

    /**
     * @return HasMany<AssignmentActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(AssignmentActivity::class);
    }

    public function platformTransactionReference(): ?string
    {
        return $this->uuid;
    }

    public function toPlatformTransaction(): TransactionData
    {
        $isAnswered = in_array($this->status, ['accepted', 'completed'], true);

        return TransactionData::from([
            'assignment_reference' => 'incarico-1',
            'principal' => self::PRINCIPAL,
            'counterparty' => ['type' => 'person', 'member_id' => null, 'tax_code' => 'RSSMRA80A01H501U', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario.rossi@example.com', 'vat_number' => null, 'municipality' => 'Bari', 'province' => 'BA'],
            'typology' => 'commercialisti',
            'title' => 'Contabilità ordinaria 2026',
            'description' => 'Registrazione delle fatture del 2026.',
            'status' => $this->status,
            'sent_at' => '2026-10-06T18:00:00+02:00',
            'responded_at' => $isAnswered ? '2026-10-07T09:00:00+02:00' : null,
            'signed_at' => $isAnswered ? '2026-10-07T10:30:00+02:00' : null,
            'closed_at' => in_array($this->status, ['completed', 'revoked'], true) ? '2026-10-20T09:00:00+02:00' : null,
            'activities' => $this->activities()->orderBy('id')->get()->map(static fn (AssignmentActivity $activity): array => [
                'reference' => (string) $activity->id,
                'compensation' => ['form' => 'hourly', 'hourly_rate_cents' => 4500],
                'estimated_minutes' => 120,
                'minutes_worked' => $activity->minutes,
                'status' => $activity->status,
                'closed_at' => $activity->status === 'open' ? null : '2026-10-20T09:00:00+02:00',
                'description' => ['process' => ['reference' => 'processo-1', 'name' => 'Contabilità ordinaria'], 'activity' => ['name' => 'Registrazione fatture'], 'deadline' => null],
            ])->all(),
        ]);
    }
}
