<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

/**
 * A page of GET /professionals: pass nextCursor to the next search to read on.
 */
final readonly class ProfessionalPage
{
    /**
     * @param  list<ListedProfessionalData>  $professionals
     */
    public function __construct(
        public array $professionals,
        public ?string $nextCursor,
        public ?string $previousCursor,
    ) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
