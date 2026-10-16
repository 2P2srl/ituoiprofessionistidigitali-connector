<?php

declare(strict_types=1);

use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\EventTypeData;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use ITuoiProfessionistiDigitali\Connector\Validation\PayloadValidator;

/**
 * @return list<EventTypeData>
 */
function typedCatalogue(array $extra = []): array
{
    return array_map(EventTypeData::from(...), [...catalogue(), ...$extra]);
}

/**
 * The errors PayloadValidator gives for an envelope, or null when it passes.
 *
 * @return array<string, list<string>>|null
 */
function payloadErrors(array $envelope, array $extra = []): ?array
{
    try
    {
        PayloadValidator::validate(EnvelopeData::from($envelope), typedCatalogue($extra));

        return null;
    }
    catch (PlatformRequestException $exception)
    {
        expect($exception->status)->toBe(422)
            ->and($exception->isContractViolation())->toBeTrue();

        return $exception->errors;
    }
}

it('E7: accepts a payload that matches the schema of its version', function (): void {
    expect(payloadErrors(envelope()))->toBeNull();
});

it('E5: refuses a type that is not in the catalogue', function (): void {
    expect(payloadErrors(envelope(['type' => 'coworking.request'])))->toHaveKey('type');
});

it('E5 and L6: refuses a type that only the platform sends', function (): void {
    $selection = ['name' => Contract::COUNTERPARTY_SELECTED, 'description' => null, 'typologies' => null, 'sent_by' => 'platform', 'versions' => [
        ['version' => 1, 'supported' => true, 'schema' => ['type' => 'object']],
    ]];

    expect(payloadErrors(envelope(['type' => Contract::COUNTERPARTY_SELECTED, 'payload' => []]), [$selection]))
        ->toBe(['type' => ['Il tipo di evento transaction.counterparty_selected lo manda solo il portale.']]);
});

it('E5: refuses a version that does not exist or is no longer supported', function (): void {
    $retired = ['name' => 'coworking.request', 'description' => null, 'typologies' => null, 'versions' => [
        ['version' => 1, 'supported' => false, 'schema' => ['type' => 'object']],
    ]];

    expect(payloadErrors(envelope(['schema_version' => 2])))->toHaveKey('schema_version')
        ->and(payloadErrors(envelope(['type' => 'coworking.request']), [$retired]))->toHaveKey('schema_version');
});

it('E6: refuses a typology the event type does not allow', function (): void {
    $forLawyers = ['name' => 'legal.request', 'description' => null, 'typologies' => ['avvocati'], 'versions' => [
        ['version' => 1, 'supported' => true, 'schema' => ['type' => 'object']],
    ]];

    expect(payloadErrors(envelope(['type' => 'legal.request', 'payload' => []]), [$forLawyers]))->toHaveKey('typology');
});

it('E7: names the payload fields that break the schema', function (array $payload, string $key): void {
    expect(payloadErrors(envelope(['payload' => $payload])))->toHaveKey($key);
})->with([
    'challenge too short' => [['challenge' => 'short'], 'payload.challenge'],
    'no challenge' => [[], 'payload'],
    'one more field' => [['challenge' => 'a1b2c3d4e5f6a7b8', 'extra' => true], 'payload'],
]);

it('E7: names nested fields with dots', function (): void {
    $nested = ['name' => 'coworking.request', 'description' => null, 'typologies' => null, 'versions' => [
        ['version' => 1, 'supported' => true, 'schema' => [
            'type' => 'object',
            'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['hours' => ['type' => 'integer']]]]],
        ]],
    ]];

    expect(payloadErrors(envelope(['type' => 'coworking.request', 'payload' => ['items' => [['hours' => 'two']]]]), [$nested]))
        ->toHaveKey('payload.items.0.hours');
});

it('E12: takes the files of the envelope only if they are the ones the payload cites', function (array $files, ?array $errors): void {
    $type = ['name' => Contract::ACTIVITIES_ASSIGNED, 'description' => null, 'typologies' => null, 'versions' => [['version' => 1, 'supported' => true, 'schema' => json_decode((string) file_get_contents(Contract::schemaPath(Contract::ACTIVITIES_ASSIGNED, 1)), true)]]];

    expect(payloadErrors(envelope(['type' => Contract::ACTIVITIES_ASSIGNED, 'payload' => activitiesAssigned(), 'files' => $files]), [$type]))->toBe($errors);
})->with([
    'the same files' => [['0199c1a0-0000-7000-8000-000000000001'], null],
    'a file not cited' => [['0199c1a0-0000-7000-8000-000000000001', '0199c1a0-0000-7000-8000-000000000002'], ['files.1' => ['Il file non è citato nel payload.']]],
    'a file cited but not in the envelope' => [[], ['files' => ['Il payload cita il file 0199c1a0-0000-7000-8000-000000000001, che non è nella busta.']]],
]);
