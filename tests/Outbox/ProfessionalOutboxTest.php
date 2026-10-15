<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use ITuoiProfessionistiDigitali\Connector\Data\ProfessionalRecordData;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformProfessional;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformProfessionalOutbox;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformTransactionOutbox;
use ITuoiProfessionistiDigitali\Connector\Outbox\ProfessionalOutbox;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:20:00+02:00'));
    $this->outbox = resolve(ProfessionalOutbox::class);
    sentAssignment();
});

function declareProfessional(array $overrides = []): ?PlatformProfessionalOutbox
{
    return resolve(ProfessionalOutbox::class)->declare('RSSMRA80A01H501U', ProfessionalRecordData::from(professional($overrides)));
}

it('R18: keeps the declaration and queues it', function (): void {
    $row = declareProfessional();

    expect($row?->refresh())
        ->tax_code->toBe('RSSMRA80A01H501U')
        ->revision->toBe(1)
        ->sent_revision->toBeNull()
        ->status->toBe(OutboxStatus::Pending)
        ->payload->toBe(professional());
    Queue::assertPushed(SendPlatformProfessional::class, fn (SendPlatformProfessional $job): bool => $job->taxCode === 'RSSMRA80A01H501U');
});

it('R19: keeps only the latest declaration, with a new revision when the record changes', function (): void {
    declareProfessional();
    PlatformProfessionalOutbox::query()->update(['sent_revision' => 1, 'status' => OutboxStatus::Sent, 'last_error' => 'x']);

    $row = declareProfessional(['municipality' => 'Bari', 'province' => 'BA', 'declared_at' => '2026-10-08T10:18:00+02:00']);

    expect($row?->refresh())
        ->revision->toBe(2)
        ->status->toBe(OutboxStatus::Pending)
        ->last_error->toBeNull()
        ->payload->municipality->toBe('Bari');
    Queue::assertPushed(SendPlatformProfessional::class, 2);
});

it('R19: ignores an older declaration, and a newer one with the same record', function (array $overrides): void {
    declareProfessional();

    $row = declareProfessional($overrides);

    expect($row?->refresh())->revision->toBe(1)->payload->toBe(professional());
    Queue::assertPushed(SendPlatformProfessional::class, 1);
})->with([
    'older' => [['municipality' => 'Bari', 'declared_at' => '2026-10-08T10:00:00+02:00']],
    'same record' => [['declared_at' => '2026-10-08T10:19:00+02:00']],
]);

it('R18: declares nothing for a person the system never assigned, in any status of its transactions', function (): void {
    $row = $this->outbox->declare('BNCLRA85T41F205Y', ProfessionalRecordData::from(professional()));

    expect($row)->toBeNull()
        ->and(PlatformProfessionalOutbox::query()->count())->toBe(0);
    Queue::assertNotPushed(SendPlatformProfessional::class);

    PlatformTransactionOutbox::query()->update(['status' => OutboxStatus::Failed]);

    expect(declareProfessional())->not->toBeNull();
    Queue::assertPushed(SendPlatformProfessional::class, 1);
});

it('refuses a declaration the platform would refuse for a person the system assigned, before keeping it', function (array $overrides): void {
    expect(fn () => declareProfessional($overrides))->toThrow(ValidationException::class);

    expect(PlatformProfessionalOutbox::query()->count())->toBe(0);
    Queue::assertNotPushed(SendPlatformProfessional::class);
})->with([
    'without email' => [['email' => '']],
]);

it('R18: checks nothing for a person the system never assigned, so every change can be declared', function (string $taxCode, array $overrides): void {
    $row = $this->outbox->declare($taxCode, ProfessionalRecordData::from(professional($overrides)));

    expect($row)->toBeNull()
        ->and(PlatformProfessionalOutbox::query()->count())->toBe(0);
    Queue::assertNotPushed(SendPlatformProfessional::class);
})->with([
    'without email' => ['BNCLRA85T41F205Y', ['email' => '']],
    'with the tax code of an organization' => ['01234567897', []],
]);

it('R18: declares the person the system assigned whatever the case of the tax code', function (): void {
    $row = $this->outbox->declare(' rssmra80a01h501u ', ProfessionalRecordData::from(professional()));

    expect($row?->tax_code)->toBe('RSSMRA80A01H501U');
    Queue::assertPushed(SendPlatformProfessional::class, 1);
});

it('R18: queues again a declaration only while it waits', function (): void {
    declareProfessional();

    $this->outbox->resume('RSSMRA80A01H501U');
    $this->outbox->resume('BNCLRA85T41F205Y');

    Queue::assertPushed(SendPlatformProfessional::class, 2);

    PlatformProfessionalOutbox::query()->update(['status' => OutboxStatus::Discarded]);
    $this->outbox->resume('RSSMRA80A01H501U');

    Queue::assertPushed(SendPlatformProfessional::class, 2);
});

it('R18: knows the transactions to the person never confirmed by the platform', function (): void {
    $row = PlatformTransactionOutbox::query()->sole();

    expect($this->outbox->hasUnregisteredTransactions('RSSMRA80A01H501U'))->toBeTrue()
        ->and($this->outbox->hasUnregisteredTransactions('BNCLRA85T41F205Y'))->toBeFalse();

    $row->update(['status' => OutboxStatus::Failed]);
    expect($this->outbox->hasUnregisteredTransactions('RSSMRA80A01H501U'))->toBeFalse();

    $row->update(['status' => OutboxStatus::Pending, 'sent_revision' => 1, 'revision' => 2]);
    expect($this->outbox->hasUnregisteredTransactions('RSSMRA80A01H501U'))->toBeFalse();
});
