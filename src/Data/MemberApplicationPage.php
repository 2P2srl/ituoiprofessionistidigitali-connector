<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

/**
 * A page of GET /applications: pass nextCursor to read on.
 */
final readonly class MemberApplicationPage
{
    /**
     * @param  list<MemberApplicationData>  $applications
     */
    public function __construct(
        public array $applications,
        public ?string $nextCursor,
    ) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
