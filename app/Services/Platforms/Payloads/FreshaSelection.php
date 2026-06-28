<?php

namespace App\Services\Platforms\Payloads;

// Fresha's inner selection blob — the {url, storeName, mode, employee, services,
// hiddenServiceIds} object stored under the outer {url, selection} envelope.
//
// Unlike the normalizing DTOs (Link/Embed/Feed, and SelectionPayload's flat
// reservation fields), this one PRESERVES the stored blob VERBATIM (toArray()
// returns it unchanged) and exposes typed READ accessors over it. The reason is
// contract-critical: PublicIntegrationConnectionResource allowlists
// fresha => ['url','selection'] and passes the `selection` value THROUGH VERBATIM
// to the public CDN payload (it is NOT re-filtered per key). A normalizing DTO
// would inject canonical-null keys (e.g. mode:null) into the stored blob on
// write-back and leak them to the public sitepage. Verbatim storage + typed
// accessors gives the typed read boundary the spec wants WITHOUT changing a single
// stored byte. `employee` and `services[]` are scraped objects passed through
// verbatim (their inner keys come from Fresha's __NEXT_DATA__ / booking GraphQL).
final readonly class FreshaSelection
{
    /** @param array<string,mixed> $raw the stored inner selection blob, preserved verbatim */
    public function __construct(public array $raw) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self($raw);
    }

    public function url(): ?string
    {
        return is_string($this->raw['url'] ?? null) ? $this->raw['url'] : null;
    }

    public function storeName(): ?string
    {
        return is_string($this->raw['storeName'] ?? null) ? $this->raw['storeName'] : null;
    }

    /** 'employee' | 'storewide' (the resource defaults a missing value to 'employee'). */
    public function mode(): ?string
    {
        return is_string($this->raw['mode'] ?? null) ? $this->raw['mode'] : null;
    }

    /** @return array<string,mixed>|null the scraped team-member object, verbatim */
    public function employee(): ?array
    {
        return is_array($this->raw['employee'] ?? null) ? $this->raw['employee'] : null;
    }

    /** @return array<int,mixed> the scraped service menu, verbatim */
    public function services(): array
    {
        return is_array($this->raw['services'] ?? null) ? $this->raw['services'] : [];
    }

    /** @return array<int,mixed> the curated hidden-service id list */
    public function hiddenServiceIds(): array
    {
        return is_array($this->raw['hiddenServiceIds'] ?? null) ? $this->raw['hiddenServiceIds'] : [];
    }

    /** @return array<string,mixed> the inner blob, byte-for-byte as stored */
    public function toArray(): array
    {
        return $this->raw;
    }
}
