<?php

namespace App\Content\Values;

/** One source's value for one column, with what it takes to rank it. */
final readonly class Contribution
{
    public function __construct(
        public string $sourceId,
        public mixed $value,
        public int $sourcePriority = 100,
        /** Unix time the CONTENT changed — not when we last fetched it. */
        public ?int $changedAt = null,
    ) {}
}
