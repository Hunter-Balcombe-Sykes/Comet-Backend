<?php

namespace App\Content\Identity;

/** One per-source record, with the identity evidence it carries. */
final readonly class SourceItem
{
    /** @param list<IdentityKey> $keys */
    public function __construct(
        public string $coord,
        public string $sourceId,
        public string $kind,
        public array $keys = [],
        public ?string $firstSeenAt = null,
    ) {}
}
