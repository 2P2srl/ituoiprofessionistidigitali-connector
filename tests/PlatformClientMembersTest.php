<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ITuoiProfessionistiDigitali\Connector\Data\MemberData;
use ITuoiProfessionistiDigitali\Connector\Exceptions\MemberNotFoundException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Facades\Platform;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('M6: keeps the refusals of the members as they are, with nothing to retry', function (array $errors, int $status): void {
    withToken(['platform.test/api/v1/members' => Http::response(['message' => 'Dati non validi.', 'errors' => $errors], $status)]);

    expect(fn () => Platform::syncMembers([MemberData::validateAndCreate(member()), MemberData::validateAndCreate(member(['external_ref' => 'struttura-2']))]))
        ->toThrow(fn (PlatformRequestException $exception) => expect($exception->status)->toBe($status)
            ->and($exception->errors)->toBe($errors));
})->with([
    'a field of a member' => [['members.0.typologies.0' => ['Tipologia non attiva.']], 422],
    'the whole list' => [['members' => ['Al massimo 1000 aderenti.']], 422],
    'no errors' => [[], 422],
]);

it('M16: reads a member of another system by its id, as the search shows it', function (): void {
    withToken(['platform.test/api/v1/members/*' => Http::response(['data' => ['id' => firm()['member_id'], 'subject_type' => 'organization', 'name' => 'Studio Bianchi', 'vat_number' => '09876543217', 'municipality' => 'Lecce', 'province' => 'LE', 'typologies' => ['commercialisti']]])]);

    $member = Platform::member(firm()['member_id']);

    expect($member)->name->toBe('Studio Bianchi')->province->toBe('LE');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform.test/api/v1/members/'.firm()['member_id']);
});

it('M16: tells a member the system does not see from the other errors', function (int $status, string $exception): void {
    withToken(['platform.test/api/v1/members/*' => Http::response(['message' => 'No.'], $status)]);

    expect(fn () => Platform::member(firm()['member_id']))->toThrow($exception);
})->with([
    'not visible' => [404, MemberNotFoundException::class],
    'suspended' => [403, PlatformRequestException::class],
]);
