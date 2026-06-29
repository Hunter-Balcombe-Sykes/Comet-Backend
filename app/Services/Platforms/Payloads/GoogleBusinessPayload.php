<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the google-business connection payload. VERBATIM-preserving
// (raw map + typed accessors, toArray() returns it unchanged), like FreshaSelection
// / ShopPayload — because GoogleBusinessConnectionResource emits a VARIABLE key set
// via array_intersect_key over the stored keys (a never-enriched link-parse
// selection keeps its 5-key shape; an Apify-enriched one carries rating/hours/…).
// A normalizing DTO would inject canonical-null enrichment keys and the resource
// would then leak them. Accessors cover the fields the read paths actually read:
// name() + placeId() (the enrich job's reconnect guard + name adoption), apifyStatus(),
// and syncFindings() (the /synced + /synced/apply "Change to" flow). The enrich job's
// seeding WRITE-BACK and GoogleBusinessAutoSync are untouched.
final readonly class GoogleBusinessPayload
{
    /** @param array<string,mixed> $raw the stored selection map, preserved verbatim */
    public function __construct(public array $raw) {}

    public static function fromArray(mixed $payload): self
    {
        return new self(is_array($payload) ? $payload : []);
    }

    public function name(): ?string
    {
        return is_string($this->raw['name'] ?? null) ? $this->raw['name'] : null;
    }

    public function placeId(): ?string
    {
        return is_string($this->raw['placeId'] ?? null) ? $this->raw['placeId'] : null;
    }

    public function apifyStatus(): ?string
    {
        return is_string($this->raw['apifyStatus'] ?? null) ? $this->raw['apifyStatus'] : null;
    }

    /** @return list<mixed> the recorded auto-sync findings, verbatim, or [] */
    public function syncFindings(): array
    {
        return is_array($this->raw['syncFindings'] ?? null) ? array_values($this->raw['syncFindings']) : [];
    }

    /** @return array<string,mixed> the stored map, byte-for-byte */
    public function toArray(): array
    {
        return $this->raw;
    }
}
