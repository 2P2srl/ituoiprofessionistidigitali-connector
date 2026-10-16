<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Facades;

use Illuminate\Support\Facades\Facade;
use ITuoiProfessionistiDigitali\Connector\Data\AcceptedEventData;
use ITuoiProfessionistiDigitali\Connector\Data\AccessLinkData;
use ITuoiProfessionistiDigitali\Connector\Data\ApplicationPage;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\EventTypeData;
use ITuoiProfessionistiDigitali\Connector\Data\ListingData;
use ITuoiProfessionistiDigitali\Connector\Data\ListingPage;
use ITuoiProfessionistiDigitali\Connector\Data\MemberData;
use ITuoiProfessionistiDigitali\Connector\Data\MemberPage;
use ITuoiProfessionistiDigitali\Connector\Data\ProfessionalRecordData;
use ITuoiProfessionistiDigitali\Connector\Data\RecordedTransactionData;
use ITuoiProfessionistiDigitali\Connector\Data\RegisteredMemberData;
use ITuoiProfessionistiDigitali\Connector\Data\SystemData;
use ITuoiProfessionistiDigitali\Connector\Data\TypologyData;
use ITuoiProfessionistiDigitali\Connector\Enums\ApplicationStatus;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;

/**
 * @method static SystemData system()
 * @method static SystemData present(string $webhookUrl, list<int> $contractVersions = [1])
 * @method static list<TypologyData> typologies()
 * @method static list<EventTypeData> eventTypes()
 * @method static list<RegisteredMemberData> syncMembers(list<MemberData> $members)
 * @method static MemberPage searchMembers(?string $typology = null, ?string $search = null, ?int $perPage = null, ?string $cursor = null)
 * @method static AccessLinkData memberAccessLink(string $memberId)
 * @method static AcceptedEventData send(EnvelopeData $envelope)
 * @method static void declareProfessional(string $taxCode, ProfessionalRecordData $record)
 * @method static ApplicationPage applications(string $reference, ?ApplicationStatus $status = null, ?int $perPage = null, ?string $cursor = null)
 * @method static RecordedTransactionData selectApplication(string $reference, string $applicationId)
 * @method static ListingPage listings(?string $typology = null, ?int $perPage = null, ?string $cursor = null)
 * @method static ListingData listing(string $listingId)
 *
 * @see PlatformClient
 */
final class Platform extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PlatformClient::class;
    }
}
