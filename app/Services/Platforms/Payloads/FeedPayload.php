<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the scraped/API-feed archetype — ONE DTO spanning the 8 feed
// platforms (youtube, youtube-music, vimeo, bandcamp, twitch, apple-music,
// apple-podcast, strava). Each platform stores a SUBSET of these keys; this is their union.
// `channelId` (YouTube Music) and `apiPath` (Vimeo) are private re-fetch inputs the
// resources never emit — carried so the fetch strategies + Plan 6's refresher read
// them typed. `latest`/`items` hold nested scraper items, passed through
// verbatim. Single home for the tolerant `?? null` hydration scattered across the
// controllers, PlatformRefresher's per-platform methods, and the resources (spec §8).
//
// Contract guarantee: every feed resource allowlists its own key subset, so the
// canonical-null keys this DTO adds are dropped on serialization — Resource(fromArray
// (raw)->toArray()) === Resource(raw). GoogleBusiness is deliberately NOT covered here
// (its resource emits a variable key set via array_intersect_key; see plan §"Why
// GoogleBusiness…").
final readonly class FeedPayload
{
    public function __construct(
        public ?string $handle,
        // The account's own avatar (youtube channel art — plan 04 step A).
        public ?string $avatarUrl,
        public ?string $url,
        public ?string $channelId,
        public ?string $apiPath,
        public ?string $input,
        public ?string $login,
        public ?string $username,
        public ?string $artist,
        public ?string $name,
        public ?string $description,
        public ?string $link,
        public ?string $thumbnail,
        public ?string $image,
        public ?string $releaseDate,
        public ?string $location,
        public int|string|null $followers,
        public int|string|null $members,
        public ?array $latest,
        public ?array $items,
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            handle: self::stringOrNull($payload['handle'] ?? null),
            avatarUrl: self::stringOrNull($payload['avatarUrl'] ?? null),
            url: self::stringOrNull($payload['url'] ?? null),
            channelId: self::stringOrNull($payload['channelId'] ?? null),
            apiPath: self::stringOrNull($payload['apiPath'] ?? null),
            input: self::stringOrNull($payload['input'] ?? null),
            login: self::stringOrNull($payload['login'] ?? null),
            username: self::stringOrNull($payload['username'] ?? null),
            artist: self::stringOrNull($payload['artist'] ?? null),
            name: self::stringOrNull($payload['name'] ?? null),
            description: self::stringOrNull($payload['description'] ?? null),
            link: self::stringOrNull($payload['link'] ?? null),
            thumbnail: self::stringOrNull($payload['thumbnail'] ?? null),
            image: self::stringOrNull($payload['image'] ?? null),
            releaseDate: self::stringOrNull($payload['releaseDate'] ?? null),
            location: self::stringOrNull($payload['location'] ?? null),
            followers: self::intStringOrNull($payload['followers'] ?? null),
            members: self::intStringOrNull($payload['members'] ?? null),
            latest: self::arrayOrNull($payload['latest'] ?? null),
            items: self::arrayOrNull($payload['items'] ?? null),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'avatarUrl' => $this->avatarUrl,
            'url' => $this->url,
            'channelId' => $this->channelId,
            'apiPath' => $this->apiPath,
            'input' => $this->input,
            'login' => $this->login,
            'username' => $this->username,
            'artist' => $this->artist,
            'name' => $this->name,
            'description' => $this->description,
            'link' => $this->link,
            'thumbnail' => $this->thumbnail,
            'image' => $this->image,
            'releaseDate' => $this->releaseDate,
            'location' => $this->location,
            'followers' => $this->followers,
            'members' => $this->members,
            'latest' => $this->latest,
            'items' => $this->items,
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

    /** @return array<mixed>|null */
    private static function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
