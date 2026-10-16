<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\MemberData;
use ITuoiProfessionistiDigitali\Connector\Enums\EventStatus;
use ITuoiProfessionistiDigitali\Connector\Enums\SystemStatus;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformNotConfiguredException;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Facades\Platform;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;

const TOKEN_URL = 'platform.test/oauth/token';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Sleep::fake();
});

/**
 * @return array<string, mixed>
 */
function systemPayload(array $overrides = []): array
{
    return [
        'id' => '0199b6ef-2a41-7d1c-8b3e-1f0a9c4d2e77',
        'name' => 'Gestionale A',
        'status' => 'active',
        'webhook_url' => 'https://a.test/platform/webhook',
        'contract_versions' => [1],
        'verified_at' => '2026-10-07T13:30:02Z',
        'last_verification' => ['attempted_at' => '2026-10-07T13:30:01Z', 'succeeded' => true, 'error' => null],
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function acceptedPayload(string $status = 'queued'): array
{
    return ['event_id' => envelope()['event_id'], 'type' => Contract::PING, 'received_at' => '2026-10-07T13:30:00Z', 'status' => $status];
}

function token(): array
{
    return [TOKEN_URL => Http::response(['access_token' => 'token', 'expires_in' => 3600])];
}

it('A1: asks a client_credentials token with the platform scope and sends it as bearer', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/system' => Http::response(['data' => systemPayload()])]);

    Platform::system();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform.test/oauth/token'
        && $request->data() === ['grant_type' => 'client_credentials', 'client_id' => 'client-id', 'client_secret' => 'client-secret', 'scope' => 'platform']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform.test/api/v1/system'
        && $request->hasHeader('Authorization', 'Bearer token')
        && $request->hasHeader('Accept', 'application/json'));
});

it('A1: keeps the token in cache until it expires', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/typologies' => Http::response(['data' => []])]);

    Platform::typologies();
    Platform::typologies();

    Http::assertSentCount(3);
});

it('A1: keeps a token at least a minute, whatever the platform says', function (mixed $expiresIn): void {
    Http::fake([
        TOKEN_URL => Http::response(['access_token' => 'token', 'expires_in' => $expiresIn]),
        'platform.test/api/v1/typologies' => Http::response(['data' => []]),
    ]);

    Platform::typologies();

    expect(Cache::get('platform.access-token.'.sha1('client-id')))->toBe('token');
})->with([10, null]);

it('A4: asks a new token once when the platform refuses the cached one', function (): void {
    Http::fakeSequence(TOKEN_URL)
        ->push(['access_token' => 'old', 'expires_in' => 3600])
        ->push(['access_token' => 'new', 'expires_in' => 3600]);
    Http::fakeSequence('platform.test/api/v1/system')
        ->push(['message' => 'Unauthenticated.'], 401)
        ->push(['data' => systemPayload()]);

    expect(Platform::system()->name)->toBe('Gestionale A');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer new'));
});

it('A4: says the credentials are wrong when the token is refused', function (array $response, int $status): void {
    Http::fake([TOKEN_URL => Http::response($response, $status)]);

    try
    {
        Platform::system();
        $this->fail('The client should not have a token.');
    }
    catch (PlatformRequestException $exception)
    {
        expect($exception->getMessage())->toBe('Credenziali del portale non valide.')
            ->and($exception->status)->toBe($status);
    }
})->with([
    'refused' => [['error' => 'invalid_client'], 401],
    'no token in the answer' => [['token_type' => 'Bearer'], 200],
]);

it('does not call the platform without url and credentials', function (): void {
    $this->app->instance(ConnectorConfig::class, new ConnectorConfig);
    $this->app->forgetInstance(PlatformClient::class);
    Platform::clearResolvedInstances();

    Platform::system();
})->throws(PlatformNotConfiguredException::class);

it('S6: reads the system', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/system' => Http::response(['data' => systemPayload()])]);

    expect(Platform::system())
        ->status->toBe(SystemStatus::Active)
        ->contract_versions->toBe([1]);
});

it('S4: presents the system with its webhook and the contract versions', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/system' => Http::response(['data' => systemPayload(['status' => 'pending'])])]);

    expect(Platform::present('https://a.test/platform/webhook')->status)->toBe(SystemStatus::Pending);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->data() === ['webhook_url' => 'https://a.test/platform/webhook', 'contract_versions' => [1]]);
});

it('C1: reads the typologies', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/typologies' => Http::response(['data' => [
        ['code' => 'commercialisti', 'name' => 'Commercialisti', 'description' => null],
    ]])]);

    expect(Platform::typologies()[0]->code)->toBe('commercialisti');
});

it('C3: reads the event types with versions and schemas', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/event-types' => Http::response(['data' => catalogue()])]);

    $eventTypes = Platform::eventTypes();

    expect($eventTypes)->toHaveCount(2)
        ->and($eventTypes[0]->version(1)?->schema['$id'])->toBe('https://ituoiprofessionistidigitali.it/contract/event-types/platform.ping/1.json');
});

it('M7: publishes the complete list of members and returns their ids', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/members' => Http::response(['data' => [
        ['id' => '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43', ...member()],
    ]])]);

    $registered = Platform::syncMembers([MemberData::validateAndCreate(member())]);

    expect($registered[0]->id)->toBe('0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43')
        ->and($registered[0]->email)->toBe('segreteria@studiorossi.example');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->data() === ['members' => [member()]]);
});

it('M1: publishes an empty list to make every member inactive', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/members' => Http::response(['data' => []])]);

    expect(Platform::syncMembers([]))->toBe([]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->data() === ['members' => []]);
});

it('M3: does not publish a member built without validation', function (): void {
    Http::fake();

    Platform::syncMembers([MemberData::from(member(['vat_number' => '01234567890']))]);
})->throws(ValidationException::class);

it('M15, T6: does not publish the same email twice in a request, whatever its case', function (string $email): void {
    Http::fake();
    $first = MemberData::from(member());
    $first->email = $email;

    try
    {
        Platform::syncMembers([$first, MemberData::from(member(['external_ref' => 'struttura-2']))]);
        $this->fail('The emails should be refused.');
    }
    catch (ValidationException $exception)
    {
        expect($exception->errors())->toHaveKey('members.1.email');
        Http::assertNothingSent();
    }
})->with([
    'same case' => ['segreteria@studiorossi.example'],
    'different case' => ['Segreteria@StudioRossi.Example'],
]);

it('T6: sends the email of a member in lower case in the request body', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/members' => Http::response(['data' => [
        ['id' => '0199b6f0-4e2a-7b31-9f6c-2d8a1e5b7c43', ...member()],
    ]])]);

    Platform::syncMembers([MemberData::validateAndCreate(member(['email' => 'Segreteria@StudioRossi.Example']))]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->data() === ['members' => [member()]]);
});

it('M6: does not publish more than a thousand members, with a local refusal that is not a retry', function (): void {
    Http::fake();
    $member = MemberData::from(member());

    try
    {
        Platform::syncMembers(array_fill(0, Contract::MAX_MEMBERS + 1, $member));
        $this->fail('The list should be too long.');
    }
    catch (ValidationException $exception)
    {
        expect($exception->errors())->toHaveKey('members');
        Http::assertNothingSent();
    }
});

it('M13: searches the members of the other systems with filters and cursor', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/members*' => Http::response([
        'data' => [['id' => 'x', 'subject_type' => 'organization', 'name' => 'Studio Bianchi', 'vat_number' => '09876543217', 'municipality' => 'Lecce', 'province' => 'LE', 'typologies' => ['commercialisti']]],
        'links' => ['next' => 'https://platform.test/api/v1/members?cursor=abc', 'prev' => null],
        'meta' => ['per_page' => 25, 'next_cursor' => 'abc', 'prev_cursor' => null],
    ])]);

    $page = Platform::searchMembers(typology: 'commercialisti', search: 'bianchi', cursor: 'start');

    expect($page->members[0]->name)->toBe('Studio Bianchi')
        ->and(property_exists($page->members[0], 'email'))->toBeFalse()
        ->and($page->nextCursor)->toBe('abc')
        ->and($page->previousCursor)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform.test/api/v1/members?typology=commercialisti&search=bianchi&cursor=start');
});

it('E8: sends an event and reads the answer of the platform', function (int $status, string $eventStatus): void {
    Http::fake([
        ...token(),
        'platform.test/api/v1/event-types' => Http::response(['data' => catalogue()]),
        'platform.test/api/v1/events' => Http::response(['data' => acceptedPayload($eventStatus)], $status),
    ]);

    expect(Platform::send(EnvelopeData::from(envelope()))->status)->toBe(EventStatus::from($eventStatus));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === envelope());
})->with([
    'new event' => [202, 'queued'],
    'E2: already received' => [200, 'delivered'],
]);

it('E1: does not send an envelope built without validation', function (): void {
    Http::fake();

    Platform::send(EnvelopeData::from(envelope(['sender' => null])));
})->throws(ValidationException::class);

it('E7: checks the payload on the cached catalogue before sending', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/event-types' => Http::response(['data' => catalogue()])]);

    foreach (range(1, 2) as $attempt)
    {
        expect(fn () => Platform::send(EnvelopeData::from(envelope(['payload' => ['challenge' => 'short']]))))
            ->toThrow(PlatformRequestException::class);
    }

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/events'));
});

it('E7: leaves the payload to the platform when the local validation is off', function (): void {
    $this->app->instance(ConnectorConfig::class, ConnectorConfig::fromArray([...config('platform'), 'validate_payloads' => false]));
    $this->app->forgetInstance(PlatformClient::class);
    Platform::clearResolvedInstances();
    Http::fake([...token(), 'platform.test/api/v1/events' => Http::response([
        'message' => 'Il payload non rispetta lo schema.',
        'errors' => ['payload.challenge' => ['Troppo corta.'], 'payload' => 'not a list', 'other' => [1, 'Testo.']],
    ], 422)]);

    try
    {
        Platform::send(EnvelopeData::from(envelope(['payload' => ['challenge' => 'short']])));
        $this->fail('The platform should refuse the event.');
    }
    catch (PlatformRequestException $exception)
    {
        expect($exception->isContractViolation())->toBeTrue()
            ->and($exception->getMessage())->toBe('Il payload non rispetta lo schema.')
            ->and($exception->errors)->toBe(['payload.challenge' => ['Troppo corta.'], 'payload' => ['not a list'], 'other' => ['Testo.']]);
    }
});

it('E9: reads the existing event from a conflict', function (): void {
    Http::fake([
        ...token(),
        'platform.test/api/v1/event-types' => Http::response(['data' => catalogue()]),
        'platform.test/api/v1/events' => Http::response(['message' => 'Contenuto diverso.', 'data' => acceptedPayload()], 409),
    ]);

    try
    {
        Platform::send(EnvelopeData::from(envelope()));
        $this->fail('The platform should answer 409.');
    }
    catch (PlatformRequestException $exception)
    {
        expect($exception->isConflict())->toBeTrue()
            ->and($exception->existingEvent?->event_id)->toBe(envelope()['event_id']);
    }
});

it('A5: reads the reason of a 403', function (string $reason): void {
    Http::fake([...token(), 'platform.test/api/v1/members' => Http::response(['message' => 'Sistema non attivo.', 'reason' => $reason], 403)]);

    try
    {
        Platform::syncMembers([]);
        $this->fail('The platform should answer 403.');
    }
    catch (PlatformRequestException $exception)
    {
        expect($exception->reason)->toBe(SystemStatus::from($reason))
            ->and($exception->errors)->toBe([]);
    }
})->with(['pending', 'suspended', 'revoked']);

it('T5: gives a readable message when the platform answers without one', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/typologies' => Http::response('Bad gateway', 502)]);

    expect(fn () => Platform::typologies())->toThrow(PlatformRequestException::class, 'Richiesta al portale non riuscita.');
});

it('retries server errors and connection failures, never contract violations', function (): void {
    Http::fake([
        ...token(),
        'platform.test/api/v1/typologies' => Http::sequence()->push([], 503)->push([], 503)->push(['data' => []]),
        'platform.test/api/v1/system' => Http::response(['message' => 'Non valido.', 'errors' => ['webhook_url' => ['Non valido.']]], 422),
    ]);

    expect(Platform::typologies())->toBe([]);
    expect(fn () => Platform::present('ftp://a.test'))->toThrow(PlatformRequestException::class);

    Http::assertSentCount(5);
});

it('T7: repeats a request after the seconds of Retry-After', function (): void {
    Http::fake([
        ...token(),
        'platform.test/api/v1/typologies' => Http::sequence()->push(['message' => 'Troppe richieste.'], 429, ['Retry-After' => '3'])->push(['data' => []]),
    ]);

    expect(Platform::typologies())->toBe([]);

    Sleep::assertSlept(fn (Carbon\CarbonInterval $duration): bool => (int) $duration->totalSeconds === 3);
    Http::assertSentCount(3);
});

it('T7: leaves a wait longer than the request can afford to the caller, with the seconds of Retry-After', function (?string $retryAfter, ?int $seconds): void {
    Http::fake([...token(), 'platform.test/api/v1/typologies' => Http::response(['message' => 'Quota piena.'], 429, $retryAfter === null ? [] : ['Retry-After' => $retryAfter])]);

    try
    {
        Platform::typologies();
        $this->fail('The platform should answer 429.');
    }
    catch (PlatformRequestException $exception)
    {
        expect($exception->isThrottled())->toBeTrue()
            ->and($exception->retryAfter)->toBe($seconds)
            ->and($exception->isContractViolation())->toBeFalse();
    }

    Http::assertSentCount(2);
})->with([
    'over the longest wait' => ['120', 120],
    'without Retry-After' => [null, PlatformClient::DEFAULT_RETRY_AFTER_SECONDS],
    'not a number of seconds' => ['Wed, 21 Oct 2026 07:28:00 GMT', PlatformClient::DEFAULT_RETRY_AFTER_SECONDS],
]);

it('reads an answer without data as empty', function (): void {
    Http::fake([...token(), 'platform.test/api/v1/typologies' => Http::response(['data' => 'nothing'])]);

    expect(Platform::typologies())->toBe([]);
});
