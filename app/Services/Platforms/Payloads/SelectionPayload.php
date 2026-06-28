<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the picker archetype — ONE DTO spanning the five picker
// platforms (fresha, square, opentable, resdiary, nowbookit). Two storage shapes
// live under this archetype:
//   • Fresha's TWO-LEVEL envelope {url, selection:{…}} — the inner blob is a
//     FreshaSelection (carried nested + verbatim; see that class for why).
//   • The FLAT reservation/square shape {url, rid|microsite|accountId+venueId,
//     name, embedUrl, source} — modelled as the top-level scalar fields here.
// Each platform stores a SUBSET; this is their union. The reservation resources
// allowlist their own key subset, so the canonical-null keys this DTO adds are
// dropped on serialization — Resource(fromArray(raw)->toArray()) === Resource(raw)
// (resource-output equivalence, the same contract guarantee FeedPayload uses).
// `source` is the reservation origin tag ('manual' / 'google-business'); the
// reservation resources omit it, but it is carried so the stored row round-trips.
final readonly class SelectionPayload
{
    public function __construct(
        public ?string $url,
        public ?FreshaSelection $selection,
        public ?string $rid,
        public ?string $microsite,
        public ?string $accountId,
        public ?string $venueId,
        public ?string $name,
        public ?string $embedUrl,
        public ?string $source,
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $inner = $payload['selection'] ?? null;

        return new self(
            url: self::stringOrNull($payload['url'] ?? null),
            selection: is_array($inner) ? FreshaSelection::fromArray($inner) : null,
            rid: self::stringOrNull($payload['rid'] ?? null),
            microsite: self::stringOrNull($payload['microsite'] ?? null),
            accountId: self::stringOrNull($payload['accountId'] ?? null),
            venueId: self::stringOrNull($payload['venueId'] ?? null),
            name: self::stringOrNull($payload['name'] ?? null),
            embedUrl: self::stringOrNull($payload['embedUrl'] ?? null),
            source: self::stringOrNull($payload['source'] ?? null),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'selection' => $this->selection?->toArray(),
            'rid' => $this->rid,
            'microsite' => $this->microsite,
            'accountId' => $this->accountId,
            'venueId' => $this->venueId,
            'name' => $this->name,
            'embedUrl' => $this->embedUrl,
            'source' => $this->source,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
