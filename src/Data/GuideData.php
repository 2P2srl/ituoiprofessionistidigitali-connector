<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Data;

/**
 * A guide of an activity, in Markdown, with the ids of its files, uploaded to the platform and listed in the files of
 * the envelope (rule E12).
 */
final class GuideData extends Data
{
    /**
     * @param  list<string>  $attachments
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $attachments,
    ) {}
}
