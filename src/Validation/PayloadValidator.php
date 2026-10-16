<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Validation;

use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\EventTypeData;
use ITuoiProfessionistiDigitali\Connector\Enums\EventSender;
use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformRequestException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use stdClass;

/**
 * Checks an outgoing envelope against the catalogue before it leaves, as POST /events would (rules E5, E6, E7 and
 * E12).
 *
 * Whether sender and recipient have the typology is known to the platform only.
 *
 * @internal
 */
final class PayloadValidator
{
    /**
     * @param  list<EventTypeData>  $catalogue
     *
     * @throws PlatformRequestException
     */
    public static function validate(EnvelopeData $envelope, array $catalogue): void
    {
        $eventType = array_find($catalogue, fn (EventTypeData $candidate): bool => $candidate->name === $envelope->type);

        if ($eventType === null)
        {
            self::fail(['type' => ["Il tipo di evento {$envelope->type} non è nel catalogo."]]);
        }

        if ($eventType->sent_by === EventSender::Platform)
        {
            self::fail(['type' => ["Il tipo di evento {$envelope->type} lo manda solo il portale."]]);
        }

        $version = $eventType->version($envelope->schema_version);

        if ($version === null || !$version->supported)
        {
            self::fail(['schema_version' => ["La versione {$envelope->schema_version} di {$envelope->type} non è supportata."]]);
        }

        if ($envelope->typology !== null && !$eventType->allowsTypology($envelope->typology))
        {
            self::fail(['typology' => ["La tipologia {$envelope->typology} non è ammessa per {$envelope->type}."]]);
        }

        $cited = self::validateSchema($version->schema, $envelope->payload);
        self::checkFiles($envelope->fileIds(), $cited);
    }

    /**
     * A JSON Schema fixed in the package, decoded.
     *
     * @return array<string, mixed>
     */
    public static function schemaAt(string $path): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Checks a payload against a JSON Schema, with the keys the platform uses under its field
     * (`payload.challenge`, `activities.0.description.process`), and answers the ids of the files it cites, in the
     * fields of the format platform-file (rule E12).
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $payload
     * @return list<string>
     *
     * @throws PlatformRequestException
     */
    public static function validateSchema(array $schema, array $payload, string $field = 'payload'): array
    {
        $cited = [];
        $validator = new Validator();
        $validator->parser()->getFormatResolver()?->registerCallable('string', Contract::FILE_FORMAT, static function (string $id) use (&$cited): bool {
            $cited[] = $id;

            return true;
        });

        $error = $validator->validate(
            $payload === [] ? new stdClass : Helper::toJSON($payload),
            json_encode($schema, JSON_THROW_ON_ERROR),
        )->error();

        if ($error === null)
        {
            /** @var list<string> $cited */
            return $cited;
        }

        $errors = [];

        foreach (new ErrorFormatter()->format($error) as $pointer => $messages)
        {
            $errors[self::key($field, (string) $pointer)] = array_values(array_filter((array) $messages, is_string(...)));
        }

        self::fail($errors);
    }

    /**
     * The files of the envelope are the ones the payload cites, no more and no fewer (rule E12).
     *
     * @param  list<string>  $listed
     * @param  list<string>  $cited
     *
     * @throws PlatformRequestException
     */
    private static function checkFiles(array $listed, array $cited): void
    {
        $errors = [];

        foreach ($listed as $index => $id)
        {
            if (!in_array($id, $cited, true))
            {
                $errors["files.{$index}"] = ['Il file non è citato nel payload.'];
            }
        }

        foreach (array_values(array_unique(array_diff($cited, $listed))) as $id)
        {
            $errors['files'][] = "Il payload cita il file {$id}, che non è nella busta.";
        }

        if ($errors !== [])
        {
            self::fail($errors);
        }
    }

    /**
     * A JSON pointer of the payload (`/items/0/name`) as the key the platform uses (`payload.items.0.name`).
     */
    private static function key(string $field, string $pointer): string
    {
        return mb_rtrim($field.'.'.str_replace('/', '.', mb_ltrim($pointer, '/')), '.');
    }

    /**
     * @param  array<string, list<string>>  $errors
     *
     * @throws PlatformRequestException
     */
    private static function fail(array $errors): never
    {
        throw new PlatformRequestException(
            message: 'L\'evento non rispetta il catalogo del portale.',
            status: 422,
            errors: $errors,
        );
    }
}
