<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

/**
 * A page of GET /listings: pass nextCursor to read on.
 */
final readonly class ListingPage
{
    /**
     * @param  list<ListingData>  $listings
     */
    public function __construct(
        public array $listings,
        public ?string $nextCursor,
    ) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
