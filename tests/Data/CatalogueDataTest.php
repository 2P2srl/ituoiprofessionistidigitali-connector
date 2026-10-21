<?php

declare(strict_types=1);

use ITuoiProfessionistiDigitali\Connector\Data\EventTypeData;
use ITuoiProfessionistiDigitali\Connector\Data\EventTypeVersionData;
use ITuoiProfessionistiDigitali\Connector\Data\ListedMemberData;
use ITuoiProfessionistiDigitali\Connector\Data\MemberPage;
use ITuoiProfessionistiDigitali\Connector\Data\SystemData;
use ITuoiProfessionistiDigitali\Connector\Enums\EventSender;
use ITuoiProfessionistiDigitali\Connector\Enums\SystemStatus;

it('C3: finds a version of an event type and the typologies it allows', function (): void {
    $forEveryone = EventTypeData::from(catalogue()[0]);
    $forSome = EventTypeData::from([...catalogue()[0], 'typologies' => ['avvocati']]);

    expect($forEveryone->version(1))->toBeInstanceOf(EventTypeVersionData::class)
        ->and($forEveryone->version(2))->toBeNull()
        ->and($forEveryone->allowsTypology('commercialisti'))->toBeTrue()
        ->and($forSome->allowsTypology('commercialisti'))->toBeFalse()
        ->and($forSome->allowsTypology('avvocati'))->toBeTrue();
});

it('C3: reads who sends the events of a type, the members when the catalogue does not say', function (): void {
    expect(EventTypeData::from(catalogue()[0])->sent_by)->toBe(EventSender::Members)
        ->and(EventTypeData::from([...catalogue()[0], 'sent_by' => 'members'])->sent_by)->toBe(EventSender::Members)
        ->and(EventTypeData::from([...catalogue()[0], 'name' => 'platform.notice', 'sent_by' => 'platform'])->sent_by)->toBe(EventSender::Platform);
});

it('S6: reads a system before and after its verification', function (): void {
    $pending = SystemData::from([
        'id' => '0199b6ef-2a41-7d1c-8b3e-1f0a9c4d2e77',
        'name' => 'Gestionale 2P2 staging',
        'status' => 'pending',
        'webhook_url' => null,
        'contract_versions' => null,
        'verified_at' => null,
        'last_verification' => null,
    ]);
    $active = SystemData::from([
        ...$pending->toArray(),
        'status' => 'active',
        'webhook_url' => 'https://a.test/platform/webhook',
        'contract_versions' => [1],
        'verified_at' => '2026-10-07T13:30:02Z',
        'last_verification' => ['attempted_at' => '2026-10-07T13:30:01Z', 'succeeded' => true, 'error' => null],
    ]);

    expect($pending->isActive())->toBeFalse()
        ->and($pending->status)->toBe(SystemStatus::Pending)
        ->and($active->isActive())->toBeTrue()
        ->and($active->last_verification?->succeeded)->toBeTrue()
        ->and($active->verified_at?->toIso8601ZuluString())->toBe('2026-10-07T13:30:02Z');
});

it('M13: tells whether a page of members has a next one', function (): void {
    $member = ListedMemberData::from(collect(member())->except(['external_ref', 'tax_code', 'listed'])->put('id', 'x')->all());

    expect(new MemberPage([$member], 'next', null)->hasMore())->toBeTrue()
        ->and(new MemberPage([], null, 'previous')->hasMore())->toBeFalse();
});
