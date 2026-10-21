<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ITuoiProfessionistiDigitali\Connector\Data\TransactionData;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\ProfessionalNotFoundException;
use ITuoiProfessionistiDigitali\Connector\Facades\Platform;

const CONSULTANT = '0199c2b0-0000-7000-8000-000000000001';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * A consultant as GET /professionals lists it.
 *
 * @return array<string, mixed>
 */
function listedConsultant(array $overrides = []): array
{
    return ['id' => CONSULTANT, 'first_name' => 'Mario', 'last_name' => 'Rossi', 'municipality' => 'Bari', 'province' => 'BA', 'typologies' => ['commercialisti'], 'tax_code_verified' => false, ...$overrides];
}

it('N1 and N3: searches the consultants with filters and cursor, without contacts', function (): void {
    withToken(['platform.test/api/v1/professionals*' => Http::response([
        'data' => [listedConsultant()],
        'links' => ['next' => 'https://platform.test/api/v1/professionals?cursor=abc', 'prev' => null],
        'meta' => ['per_page' => 25, 'next_cursor' => 'abc', 'prev_cursor' => null],
    ])]);

    $page = Platform::searchProfessionals(typology: 'commercialisti', province: 'BA', search: 'rossi', perPage: 10, cursor: 'start');

    expect($page->professionals[0])
        ->id->toBe(CONSULTANT)
        ->last_name->toBe('Rossi')
        ->typologies->toBe(['commercialisti'])
        ->tax_code_verified->toBeFalse()
        ->and(property_exists($page->professionals[0], 'email'))->toBeFalse()
        ->and($page->hasMore())->toBeTrue()
        ->and($page->previousCursor)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform.test/api/v1/professionals?typology=commercialisti&province=BA&search=rossi&per_page=10&cursor=start');
});

it('N1: reads the last page of the consultants', function (): void {
    withToken(['platform.test/api/v1/professionals*' => Http::response(['data' => [], 'meta' => ['next_cursor' => null, 'prev_cursor' => 'abc']])]);

    $page = Platform::searchProfessionals();

    expect($page->professionals)->toBe([])
        ->and($page->hasMore())->toBeFalse()
        ->and($page->previousCursor)->toBe('abc');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform.test/api/v1/professionals');
});

it('N4 and R3: reads a consultant with the data to assign, ready as the person counterparty', function (): void {
    withToken(['platform.test/api/v1/professionals/*' => Http::response(['data' => listedConsultant([
        'tax_code' => 'RSSMRA80A01H501U',
        'email' => 'mario.rossi@example.com',
        'mobile' => '+393331234567',
        'vat_number' => null,
    ])])]);

    $consultant = Platform::professional(CONSULTANT);

    expect($consultant)
        ->tax_code->toBe('RSSMRA80A01H501U')
        ->mobile->toBe('+393331234567')
        ->vat_number->toBeNull()
        ->and($consultant->toCounterparty()->toWire())->toBe(person())
        ->and(TransactionData::validateAndCreate(transaction(['counterparty' => $consultant->toCounterparty()->toWire()]))->counterparty->toWire())->toBe(person());
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform.test/api/v1/professionals/'.CONSULTANT);
});

it('N4: tells a consultant that is not among those to find from the other errors', function (): void {
    withToken(['platform.test/api/v1/professionals/*' => Http::response(['message' => 'Non trovato.'], 404)]);

    expect(fn () => Platform::professional(CONSULTANT))
        ->toThrow(fn (ProfessionalNotFoundException $exception) => expect($exception->id)->toBe(CONSULTANT));
});

it('N4: throws the other refusals of the platform as they are', function (): void {
    withToken(['platform.test/api/v1/professionals/*' => Http::response(['message' => 'Sospeso.', 'reason' => 'suspended'], 403)]);

    expect(fn () => Platform::professional(CONSULTANT))
        ->toThrow(fn (PlatformRequestException $exception) => expect($exception->status)->toBe(403));
});
