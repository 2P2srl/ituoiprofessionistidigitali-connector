<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Data;

/**
 * The contacts of the selected person that are not part of the counterparty: the following PUTs send the
 * counterparty without them (rules R3 and L7). A firm has none: the two firms exchange their details with the event
 * coworking.contract_details.
 */
final class ContactData extends Data
{
    /**
     * @param  string  $mobile  The mobile number in E.164, such as +393331234567.
     */
    public function __construct(
        public string $mobile,
    ) {}
}
