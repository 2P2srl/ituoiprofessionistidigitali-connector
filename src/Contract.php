<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector;

/**
 * The fixed values of the contract, as written in `docs/contratto.md` of the platform.
 */
final class Contract
{
    public const int VERSION = 1;

    public const string API_PREFIX = '/api/v1';

    public const string SCOPE = 'platform';

    public const string DATE_FORMAT = 'Y-m-d\TH:i:sP';

    /**
     * RFC 3339 as sent by the platform and by systems: with or without fractions of a second.
     */
    public const array DATE_INPUT_FORMATS = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'];

    /**
     * RFC 3339 with the offset: the date_format rule cannot check it, since it compares the date formatted back.
     */
    public const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    public const int SIGNATURE_TOLERANCE_SECONDS = 300;

    /**
     * How far in the future the date of a declared record may be (rule R19).
     */
    public const int DECLARATION_TOLERANCE_SECONDS = 300;

    public const string SIGNATURE_VERSION = 'v1';

    public const string HEADER_TIMESTAMP = 'X-Platform-Timestamp';

    public const string HEADER_SIGNATURE = 'X-Platform-Signature';

    public const string HEADER_EVENT_ID = 'X-Platform-Event-Id';

    public const string HEADER_DELIVERY_ID = 'X-Platform-Delivery-Id';

    public const string PING = 'platform.ping';

    public const string PONG = 'platform.pong';

    /**
     * The principal offering to a firm of another system the assignment it registered invited (rule R24).
     */
    public const string ASSIGNMENT_OFFERED = 'coworking.assignment_offered';

    /**
     * The firm accepting the assignment offered, once: the principal then registers it accepted (rule R24).
     */
    public const string ASSIGNMENT_ACCEPTED = 'coworking.assignment_accepted';

    /**
     * The firm declining the assignment offered, once: the principal then registers it declined (rule R24).
     */
    public const string ASSIGNMENT_DECLINED = 'coworking.assignment_declined';

    /**
     * The principal revoking the assignment, after registering it revoked.
     */
    public const string ASSIGNMENT_REVOKED = 'coworking.assignment_revoked';

    /**
     * The details for the contracts of the coworking, that each firm sends to the other after the acceptance.
     */
    public const string CONTRACT_DETAILS = 'coworking.contract_details';

    /**
     * The work the principal hands to the assigned firm after the signature of the documents of the coworking.
     */
    public const string ACTIVITIES_ASSIGNED = 'coworking.activities_assigned';

    /**
     * The assigned firm telling the principal that an activity was completed or reopened.
     */
    public const string ACTIVITY_STATUS = 'coworking.activity_status';

    public const int MAX_MEMBERS = 1000;

    public const int MAX_PER_PAGE = 100;

    public const int MAX_TRANSACTIONS = 500;

    /**
     * The files an event may cite (rule E12).
     */
    public const int MAX_FILES_PER_EVENT = 20;

    /**
     * The format of the schemas for the id of a file the envelope lists (rule E12).
     */
    public const string FILE_FORMAT = 'platform-file';

    /**
     * The JSON Schema of an event type version, as fixed by this release of the package.
     */
    public static function schemaPath(string $type, int $version): string
    {
        return dirname(__DIR__).'/resources/schemas/event-types/'.$type.'/'.$version.'.json';
    }

    /**
     * The JSON Schema of a transaction payload, as fixed by this release of the package.
     */
    public static function transactionSchemaPath(string $type, int $version): string
    {
        return dirname(__DIR__).'/resources/schemas/transaction-types/'.$type.'/'.$version.'.json';
    }
}
