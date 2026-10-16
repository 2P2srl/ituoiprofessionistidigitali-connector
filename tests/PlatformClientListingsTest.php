<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ITuoiProfessionistiDigitali\Connector\Data\ProcessData;
use ITuoiProfessionistiDigitali\Connector\Enums\Audience;
use ITuoiProfessionistiDigitali\Connector\Enums\CompensationForm;
use ITuoiProfessionistiDigitali\Connector\Exceptions\ListingNotFoundException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Facades\Platform;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * A listing as GET /listings returns it: open to anyone, with the seat of the principal only (rules L1 and L7).
 *
 * @return array<string, mixed>
 */
function listing(array $overrides = []): array
{
    return [
        'id' => '0199b6f8-0000-7000-8000-000000000001',
        'typology' => 'commercialisti',
        'audience' => 'any',
        'title' => 'Contabilità ordinaria per una società di servizi',
        'description' => 'Registrazione delle fatture di **ottobre** e chiusura del mese.',
        'principal' => ['municipality' => 'Bari', 'province' => 'BA'],
        'sent_at' => '2026-10-08T09:00:00Z',
        'expires_at' => '2026-10-31T22:59:59Z',
        'revision' => 1,
        'currency' => 'EUR',
        'total_cents' => 90000,
        'type' => 'assignment',
        'schema_version' => 1,
        'activities' => [[
            'reference' => '43',
            'compensation' => ['form' => 'fixed', 'hourly_rate_cents' => null, 'fixed_amount_cents' => 90000],
            'estimated_minutes' => 1200,
            'total_cents' => 90000,
            'description' => ['process' => ['reference' => 'pratica-812', 'name' => 'Contabilità ordinaria'], 'activity' => null, 'deadline' => '2026-11-30'],
        ]],
        ...$overrides,
    ];
}

it('L1: reads the listings open to the members of the system, with filter and cursor', function (): void {
    withToken(['platform.test/api/v1/listings*' => Http::response([
        'data' => [listing(), listing(['id' => '0199b6f8-0000-7000-8000-000000000002', 'audience' => 'member', 'settlements' => []])],
        'links' => [],
        'meta' => ['per_page' => 2, 'next_cursor' => 'abc', 'prev_cursor' => null],
    ])]);

    $page = Platform::listings('commercialisti', perPage: 2, cursor: 'xyz');

    expect($page->listings)->toHaveCount(2)
        ->and($page->listings[1]->audience)->toBe(Audience::Member)
        ->and($page->nextCursor)->toBe('abc')
        ->and($page->hasMore())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform.test/api/v1/listings?typology=commercialisti&per_page=2&cursor=xyz');
});

it('L1 and L7: reads a listing with the seat of the principal only and the revision the application accepts', function (): void {
    withToken(['platform.test/api/v1/listings/0199b6f8-0000-7000-8000-000000000001' => Http::response(['data' => listing()])]);

    $listing = Platform::listing('0199b6f8-0000-7000-8000-000000000001');

    expect($listing)
        ->id->toBe('0199b6f8-0000-7000-8000-000000000001')
        ->audience->toBe(Audience::Any)
        ->revision->toBe(1)
        ->total_cents->toBe(90000)
        ->and($listing->principal)
        ->municipality->toBe('Bari')
        ->province->toBe('BA')
        ->and($listing->sent_at->toIso8601ZuluString())->toBe('2026-10-08T09:00:00Z')
        ->and($listing->expires_at->toIso8601ZuluString())->toBe('2026-10-31T22:59:59Z')
        ->and($listing->activities[0])
        ->reference->toBe('43')
        ->estimated_minutes->toBe(1200)
        ->total_cents->toBe(90000)
        ->and($listing->activities[0]->compensation->form)->toBe(CompensationForm::Fixed)
        ->and($listing->activities[0]->description->process)->toEqual(new ProcessData('pratica-812', 'Contabilità ordinaria'))
        ->and($listing->activities[0]->description->deadline)->toBe('2026-11-30');
});

it('L1: reads the last page of listings, without filters', function (): void {
    withToken(['platform.test/api/v1/listings' => Http::response(['data' => [], 'meta' => ['next_cursor' => null]])]);

    $page = Platform::listings();

    expect($page->listings)->toBe([])
        ->and($page->hasMore())->toBeFalse();
});

it('L1: tells a listing no longer visible with its own exception', function (): void {
    withToken(['platform.test/api/v1/listings/*' => Http::response(['message' => 'Inserzione non trovata.'], 404)]);

    try
    {
        Platform::listing('0199b6f8-0000-7000-8000-000000000009');
        $this->fail('The platform should answer 404.');
    }
    catch (ListingNotFoundException $exception)
    {
        expect($exception->listingId)->toBe('0199b6f8-0000-7000-8000-000000000009')
            ->and($exception->getMessage())->toBe("L'inserzione non è più visibile al sistema.");
    }
});

it('A6: leaves any other refusal of a listing as it is', function (): void {
    withToken(['platform.test/api/v1/listings/*' => Http::response(['message' => 'In attesa.', 'reason' => 'suspended'], 403)]);

    Platform::listing('0199b6f8-0000-7000-8000-000000000001');
})->throws(PlatformRequestException::class);
