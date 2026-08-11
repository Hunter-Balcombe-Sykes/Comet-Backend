<?php

namespace App\Ingest\Runtime\Effects;

/**
 * Everything a driver may know about the effect it is performing. `userId` is
 * here because both drivers spend per-user budget: PlacesBudget has a per-user
 * daily cap, and Instagram's scraper threads it for log correlation.
 */
final readonly class BilledEffectContext
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public string $kind,
        public string $name,
        public array $input,
        public ?string $runId,
        public ?string $sourceId,
        public ?string $userId,
    ) {}
}
