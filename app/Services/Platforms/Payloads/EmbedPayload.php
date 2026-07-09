<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the oEmbed music archetype (Spotify / SoundCloud, plus the
// dormant mixcloud/tidal). Stored shape is {url, name, thumbnail, embedUrl, link};
// `artistId` is an optional private re-fetch key some providers stored, which
// MusicEmbedConnectionResource omits — the DTO carries it so a stored row
// round-trips losslessly while the resource output stays the frozen 5 keys.
// Single home for the tolerant `?? null` hydration previously duplicated across the
// embed controllers, PlatformRefresher::musicEmbedPayload, and the resource (spec §8).
final readonly class EmbedPayload
{
    public function __construct(
        public ?string $url,
        public ?string $name,
        public ?string $thumbnail,
        public ?string $embedUrl,
        public ?string $link,
        public ?string $artistId,
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            url: self::stringOrNull($payload['url'] ?? null),
            name: self::stringOrNull($payload['name'] ?? null),
            thumbnail: self::stringOrNull($payload['thumbnail'] ?? null),
            embedUrl: self::stringOrNull($payload['embedUrl'] ?? null),
            link: self::stringOrNull($payload['link'] ?? null),
            artistId: self::stringOrNull($payload['artistId'] ?? null),
        );
    }

    /** @return array{url:?string,name:?string,thumbnail:?string,embedUrl:?string,link:?string,artistId:?string} */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'name' => $this->name,
            'thumbnail' => $this->thumbnail,
            'embedUrl' => $this->embedUrl,
            'link' => $this->link,
            'artistId' => $this->artistId,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
