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

    public const string APPLICATION_RECEIVED = 'transaction.application_received';

    public const string APPLICATION_WITHDRAWN = 'transaction.application_withdrawn';

    public const string COUNTERPARTY_SELECTED = 'transaction.counterparty_selected';

    public const string TRANSACTION_WITHDRAWN = 'transaction.withdrawn';

    /**
     * A published assignment given to a firm with the selection, sent to the system of the firm.
     */
    public const string ASSIGNMENT_RECEIVED = 'transaction.assignment_received';

    /**
     * The details for the contracts of the coworking, that each firm sends to the other after the selection.
     */
    public const string CONTRACT_DETAILS = 'coworking.contract_details';

    /**
     * The work the principal hands to the selected firm after the signature of the documents of the coworking.
     */
    public const string ACTIVITIES_ASSIGNED = 'coworking.activities_assigned';

    /**
     * The selected firm telling the principal that an activity was completed or reopened.
     */
    public const string ACTIVITY_STATUS = 'coworking.activity_status';

    /**
     * The event types only the platform sends, with no sender (rule L6). A webhook takes an envelope without sender
     * only of these types, or the verification ping.
     */
    public const array PLATFORM_EVENTS = [self::APPLICATION_RECEIVED, self::APPLICATION_WITHDRAWN, self::COUNTERPARTY_SELECTED, self::TRANSACTION_WITHDRAWN, self::ASSIGNMENT_RECEIVED];

    public const int MAX_MEMBERS = 1000;

    public const int MAX_PER_PAGE = 100;

    public const int MAX_TRANSACTIONS = 500;

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
