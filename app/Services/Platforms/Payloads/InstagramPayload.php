<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the bespoke Instagram connection payload. Instagram is the
// async-scrape special: InstagramConnectJob mirrors the latest post + reel to R2
// and writes this shape; InstagramConnectionResource emits a FIXED key subset.
// This DTO is NORMALIZING (fixed props), which is safe because the resource
// allowlists its keys (resource-output equivalence). It additionally carries the
// two INTERNAL fields the resource omits — `_folder` (the R2 prefix the disconnect
// observer reclaims; spec §8 names it explicitly) and `source` (the google-business
// origin tag InstagramConnectJob preserves across a re-scrape) — so the DTO is the
// honest, complete schema while responses stay byte-identical. The job's scrape
// WRITE stays literal (live-scrape writes are not migrated); this DTO is the READ
// boundary (controller status/selection, the job's source read, the observer's
// _folder reads).
//
// BE2: also carries the bio-link auto-sync fields — `website` (the profile's
// `externalUrl`), `bioLinks` (every URL InstagramScraper::bioLinks() found),
// `syncFindings` (InstagramAutoSync's seed/conflict findings, mirroring
// GoogleBusinessPayload::syncFindings()), and `unmatched` (bio links that
// classified to nothing InstagramAutoSync seeds, or to nothing at all — the
// "add as custom link" leftovers). Same INTERNAL-field contract as _folder/source:
// present in the DTO, never emitted by InstagramConnectionResource. An old
// connection row that predates this feature has none of these keys and reads
// them as null/[] — never breaks.
final readonly class InstagramPayload
{
    public function __construct(
        public ?string $username,
        public ?string $fullName,
        public ?string $profilePicUrl,
        public ?string $businessCategory,
        public int|string|null $followersCount,
        public int|string|null $postsCount,
        public ?string $mode,
        public array $images,
        public ?string $videoUrl,
        public ?string $videoPoster,
        public int $imagesDropped,
        public ?string $source,
        public ?string $folder,
        public ?string $website,
        public array $bioLinks,
        public array $syncFindings,
        public array $unmatched,
    ) {}

    public static function fromArray(mixed $payload): self
    {
        $p = is_array($payload) ? $payload : [];

        return new self(
            username: self::stringOrNull($p['username'] ?? null),
            fullName: self::stringOrNull($p['fullName'] ?? null),
            profilePicUrl: self::stringOrNull($p['profilePicUrl'] ?? null),
            businessCategory: self::stringOrNull($p['businessCategory'] ?? null),
            followersCount: self::intStringOrNull($p['followersCount'] ?? null),
            postsCount: self::intStringOrNull($p['postsCount'] ?? null),
            mode: self::stringOrNull($p['mode'] ?? null),
            images: is_array($p['images'] ?? null) ? $p['images'] : [],
            videoUrl: self::stringOrNull($p['videoUrl'] ?? null),
            videoPoster: self::stringOrNull($p['videoPoster'] ?? null),
            imagesDropped: is_int($p['imagesDropped'] ?? null) ? $p['imagesDropped'] : 0,
            source: self::stringOrNull($p['source'] ?? null),
            folder: self::stringOrNull($p['_folder'] ?? null),
            website: self::stringOrNull($p['website'] ?? null),
            bioLinks: is_array($p['bioLinks'] ?? null) ? array_values($p['bioLinks']) : [],
            syncFindings: is_array($p['syncFindings'] ?? null) ? array_values($p['syncFindings']) : [],
            unmatched: is_array($p['unmatched'] ?? null) ? array_values($p['unmatched']) : [],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'fullName' => $this->fullName,
            'profilePicUrl' => $this->profilePicUrl,
            'businessCategory' => $this->businessCategory,
            'followersCount' => $this->followersCount,
            'postsCount' => $this->postsCount,
            'mode' => $this->mode,
            'images' => $this->images,
            'videoUrl' => $this->videoUrl,
            'videoPoster' => $this->videoPoster,
            'imagesDropped' => $this->imagesDropped,
            'source' => $this->source,
            '_folder' => $this->folder,
            'website' => $this->website,
            'bioLinks' => $this->bioLinks,
            'syncFindings' => $this->syncFindings,
            'unmatched' => $this->unmatched,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function intStringOrNull(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }
}
