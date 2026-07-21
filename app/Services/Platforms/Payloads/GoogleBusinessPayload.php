<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the google-business connection payload. VERBATIM-preserving
// (raw map + typed accessors, toArray() returns it unchanged), like FreshaSelection
// / ShopPayload — because GoogleBusinessConnectionResource emits a VARIABLE key set
// via array_intersect_key over the stored keys (a never-enriched link-parse
// selection keeps its 5-key shape; an Apify-enriched one carries rating/hours/…).
// A normalizing DTO would inject canonical-null enrichment keys and the resource
// would then leak them. Accessors cover the fields the read paths actually read:
// name() + placeId() (name adoption + verbatim identifier),
// and syncFindings() (the /synced + /synced/apply "Change to" flow). The enrich job's
// seeding WRITE-BACK and GoogleBusinessAutoSync are untouched.
// apifyStatus is now a real column (site.platform_connections.apify_status) —
// the payload no longer carries it; re-injection happens in the controller.
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

    /** The business's own website URL from Place Details, or null. */
    public function website(): ?string
    {
        return is_string($this->raw['website'] ?? null) ? $this->raw['website'] : null;
    }

    /** The Places primaryTypeDisplayName (e.g. "Barber shop"), or null. */
    public function category(): ?string
    {
        return is_string($this->raw['category'] ?? null) ? $this->raw['category'] : null;
    }

    /** @return list<mixed> the recorded auto-sync findings, verbatim, or [] */
    public function syncFindings(): array
    {
        return is_array($this->raw['syncFindings'] ?? null) ? array_values($this->raw['syncFindings']) : [];
    }

    /**
     * The stored Google-Business photos, normalised to { ref, photoPicUrl }.
     *
     * `ref` is the stable Google resource name we reference a photo by; the
     * resolved image URL is stored under `url` by GoogleBusinessService (its
     * weekly-refreshed media resolution), so we surface it as `photoPicUrl`
     * for the content read-path — tolerating a literal `photoPicUrl` key too in
     * case an upstream payload already used that name. Photos without a `ref`
     * are dropped (a ref is required to reference the photo at all).
     *
     * @return list<array{ref: string, photoPicUrl: string|null}>
     */
    public function photos(): array
    {
        $photos = is_array($this->raw['photos'] ?? null) ? $this->raw['photos'] : [];

        $out = [];
        foreach ($photos as $photo) {
            if (! is_array($photo)) {
                continue;
            }
            $ref = $photo['ref'] ?? null;
            if (! is_string($ref) || $ref === '') {
                continue;
            }
            $url = $photo['photoPicUrl'] ?? $photo['url'] ?? null;
            $out[] = [
                'ref' => $ref,
                'photoPicUrl' => is_string($url) && $url !== '' ? $url : null,
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> the stored map, byte-for-byte */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * PRIV-1: strip third-party reviewer PII from a mapped/stored payload —
     * drops `reviews` entirely (author name/uri/photo/text) and `authors`
     * from each `photos[]` entry (contributor display names) — while keeping
     * every other field (name/address/phone/hours/rating/reviewCount/
     * category/website/photos[ref,widthPx,heightPx,url]/…) untouched.
     *
     * Used ONLY on the provisional (pre-claim) write paths —
     * GoogleBusinessSourceGenerator's initial build and GoogleBusinessFetch's
     * refresh for an unclaimed owner. The authenticated connect() /
     * GoogleBusinessEnrichJob / refresh-for-claimed-user paths never call
     * this and keep the full payload.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function stripThirdPartyPii(array $payload): array
    {
        unset($payload['reviews']);

        if (isset($payload['photos']) && is_array($payload['photos'])) {
            $payload['photos'] = array_map(function ($photo) {
                if (is_array($photo)) {
                    unset($photo['authors']);
                }

                return $photo;
            }, $payload['photos']);
        }

        return $payload;
    }
}
