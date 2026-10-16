<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ITuoiProfessionistiDigitali\Connector\Enums\ApplicationStatus;
use ITuoiProfessionistiDigitali\Connector\Exceptions\ApplicationNotFoundException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\ApplicationNotWithdrawableException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\ApplicationRefusedException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\ListingNotFoundException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Facades\Platform;

const LISTING_ID = '0199b6f8-0000-7000-8000-000000000001';

const FIRM_ID = '0199b6f1-5c3a-7e10-8d2b-4a6f9e1c3b55';

const FIRM_APPLICATION_ID = '0199b6fa-0000-7000-8000-000000000001';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * An application of a member of the system, as GET /applications returns it.
 *
 * @return array<string, mixed>
 */
function memberApplication(array $overrides = []): array
{
    return [
        'id' => FIRM_APPLICATION_ID,
        'transaction' => LISTING_ID,
        'member' => FIRM_ID,
        'status' => 'pending',
        'applied_at' => '2026-10-10T08:00:00Z',
        'terms_accepted_at' => '2026-10-10T08:00:00Z',
        'accepted_revision' => 1,
        'closed_at' => null,
        ...$overrides,
    ];
}

it('L10: applies a member of the system to a listing, with the explicit consent to its conditions', function (int $status): void {
    withToken(['platform.test/api/v1/listings/*/applications' => Http::response(['data' => memberApplication()], $status)]);

    $application = Platform::apply(LISTING_ID, FIRM_ID);

    expect($application)
        ->id->toBe(FIRM_APPLICATION_ID)
        ->transaction->toBe(LISTING_ID)
        ->member->toBe(FIRM_ID)
        ->status->toBe(ApplicationStatus::Pending)
        ->accepted_revision->toBe(1)
        ->closed_at->toBeNull()
        ->and($application->terms_accepted_at->toIso8601ZuluString())->toBe('2026-10-10T08:00:00Z');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://platform.test/api/v1/listings/'.LISTING_ID.'/applications'
        && $request->data() === ['member' => FIRM_ID, 'terms_accepted' => true]);
})->with([201, 200]);

it('L10: tells a listing no longer visible, and a member that cannot apply, with their own exceptions', function (): void {
    withToken(['platform.test/api/v1/listings/*/applications' => Http::sequence()
        ->push(['message' => 'Inserzione non trovata.'], 404)
        ->push(['message' => 'Dati non validi.', 'errors' => ['member' => ['Lo studio non ha la tipologia dell\'incarico.']]], 422),
    ]);

    expect(fn () => Platform::apply(LISTING_ID, FIRM_ID))->toThrow(ListingNotFoundException::class);

    try
    {
        Platform::apply(LISTING_ID, FIRM_ID);
        $this->fail('The platform should answer 422.');
    }
    catch (ApplicationRefusedException $exception)
    {
        expect($exception->listingId)->toBe(LISTING_ID)
            ->and($exception->memberId)->toBe(FIRM_ID)
            ->and($exception->messages)->toBe(['Lo studio non ha la tipologia dell\'incarico.'])
            ->and($exception->getPrevious())->toBeInstanceOf(PlatformRequestException::class);
    }
});

it('L10: leaves any other refusal of an application as it is', function (): void {
    withToken(['platform.test/api/v1/listings/*/applications' => Http::response(['message' => 'Dati non validi.', 'errors' => ['terms_accepted' => ['Il consenso è obbligatorio.']]], 422)]);

    Platform::apply(LISTING_ID, FIRM_ID);
})->throws(PlatformRequestException::class);

it('L3: withdraws a pending application of a member of the system, also again', function (): void {
    withToken(['platform.test/api/v1/listings/*/applications/*/withdrawal' => Http::response(['data' => memberApplication(['status' => 'withdrawn', 'closed_at' => '2026-10-11T08:00:00Z'])])]);

    $application = Platform::withdrawApplication(LISTING_ID, FIRM_APPLICATION_ID);

    expect($application->status)->toBe(ApplicationStatus::Withdrawn)
        ->and($application->closed_at?->toIso8601ZuluString())->toBe('2026-10-11T08:00:00Z');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://platform.test/api/v1/listings/'.LISTING_ID.'/applications/'.FIRM_APPLICATION_ID.'/withdrawal'
        && $request->body() === '');
});

it('L3: tells an application of another system, and one no longer pending, with their own exceptions', function (): void {
    withToken(['platform.test/api/v1/listings/*/applications/*/withdrawal' => Http::sequence()
        ->push(['message' => 'Candidatura non trovata.'], 404)
        ->push(['message' => 'Dati non validi.', 'errors' => ['status' => ['La candidatura è già stata scelta.']]], 422)
        ->push(['message' => 'Dati non validi.', 'errors' => ['application' => ['Altro.']]], 422),
    ]);

    try
    {
        Platform::withdrawApplication(LISTING_ID, FIRM_APPLICATION_ID);
        $this->fail('The platform should answer 404.');
    }
    catch (ApplicationNotFoundException $exception)
    {
        expect($exception->listingId)->toBe(LISTING_ID)
            ->and($exception->applicationId)->toBe(FIRM_APPLICATION_ID);
    }

    try
    {
        Platform::withdrawApplication(LISTING_ID, FIRM_APPLICATION_ID);
        $this->fail('The platform should answer 422.');
    }
    catch (ApplicationNotWithdrawableException $exception)
    {
        expect($exception->applicationId)->toBe(FIRM_APPLICATION_ID)
            ->and($exception->messages)->toBe(['La candidatura è già stata scelta.']);
    }

    expect(fn () => Platform::withdrawApplication(LISTING_ID, FIRM_APPLICATION_ID))->toThrow(PlatformRequestException::class);
});

it('L10: reads the applications of the members of the system, with filters and cursor', function (): void {
    withToken(['platform.test/api/v1/applications*' => Http::response([
        'data' => [memberApplication(), memberApplication(['id' => '0199b6fa-0000-7000-8000-000000000002', 'status' => 'declined', 'closed_at' => '2026-10-12T09:00:00Z'])],
        'links' => [],
        'meta' => ['per_page' => 2, 'next_cursor' => 'abc', 'prev_cursor' => null],
    ])]);

    $page = Platform::memberApplications(FIRM_ID, ApplicationStatus::Pending, perPage: 2, cursor: 'xyz');

    expect($page->applications)->toHaveCount(2)
        ->and($page->applications[1]->status)->toBe(ApplicationStatus::Declined)
        ->and($page->hasMore())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform.test/api/v1/applications?member='.FIRM_ID.'&status=pending&per_page=2&cursor=xyz');
});

it('L10: reads the last page of the applications, without filters', function (): void {
    withToken(['platform.test/api/v1/applications' => Http::response(['data' => [], 'meta' => ['next_cursor' => null]])]);

    $page = Platform::memberApplications();

    expect($page->applications)->toBe([])
        ->and($page->hasMore())->toBeFalse();
});
