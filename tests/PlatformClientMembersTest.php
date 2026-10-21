<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ITuoiProfessionistiDigitali\Connector\Data\MemberData;
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
