<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Facades;

use Illuminate\Support\Facades\Facade;
use ITuoiProfessionistiDigitali\Connector\Data\AcceptedEventData;
use ITuoiProfessionistiDigitali\Connector\Data\EnvelopeData;
use ITuoiProfessionistiDigitali\Connector\Data\EventTypeData;
use ITuoiProfessionistiDigitali\Connector\Data\FileData;
use ITuoiProfessionistiDigitali\Connector\Data\FileReferenceData;
use ITuoiProfessionistiDigitali\Connector\Data\ListedMemberData;
use ITuoiProfessionistiDigitali\Connector\Data\MemberData;
use ITuoiProfessionistiDigitali\Connector\Data\MemberPage;
use ITuoiProfessionistiDigitali\Connector\Data\ProfessionalData;
use ITuoiProfessionistiDigitali\Connector\Data\ProfessionalPage;
use ITuoiProfessionistiDigitali\Connector\Data\ProfessionalRecordData;
use ITuoiProfessionistiDigitali\Connector\Data\RecordedTransactionData;
use ITuoiProfessionistiDigitali\Connector\Data\RegisteredMemberData;
use ITuoiProfessionistiDigitali\Connector\Data\SystemData;
use ITuoiProfessionistiDigitali\Connector\Data\TypologyData;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;

/**
 * @method static SystemData system()
 * @method static SystemData present(string $webhookUrl, list<int> $contractVersions = [1])
 * @method static list<TypologyData> typologies()
 * @method static list<EventTypeData> eventTypes()
 * @method static list<RegisteredMemberData> syncMembers(list<MemberData> $members)
 * @method static MemberPage searchMembers(?string $typology = null, ?string $search = null, ?int $perPage = null, ?string $cursor = null)
 * @method static ListedMemberData member(string $memberId)
 * @method static ProfessionalPage searchProfessionals(?string $typology = null, ?string $province = null, ?string $search = null, ?int $perPage = null, ?string $cursor = null)
 * @method static ProfessionalData professional(string $professionalId)
 * @method static AcceptedEventData send(EnvelopeData $envelope)
 * @method static RecordedTransactionData transaction(string $id)
 * @method static void declareProfessional(string $taxCode, ProfessionalRecordData $record)
 * @method static FileData uploadFile(string $uploadId, string $sender, string $recipient, string $disk, string $path, string $name, string $mediaType)
 * @method static FileData fileStatus(string $fileId)
 * @method static void downloadFile(FileReferenceData $file, string $disk, string $path)
 * @method static FileData confirmFile(string $fileId, string $sha256)
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
