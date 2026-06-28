<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the link-only archetype's stored payload. The link socials
// (LinkedIn, X, Threads, Reddit, TikTok, Facebook) all store {username, url};
// this DTO is the single home for the tolerant hydration that used to be
// scattered as `$payload['username'] ?? null` across each controller and again
// in LinkConnectionResource (spec §8). It round-trips {username, url} losslessly,
// so the frozen LinkConnectionResource output is byte-identical whether it
// serializes a raw array or fromArray()->toArray().
final readonly class LinkPayload
{
    public function __construct(
        public ?string $username,
        public ?string $url,
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            username: self::stringOrNull($payload['username'] ?? null),
            url: self::stringOrNull($payload['url'] ?? null),
        );
    }

    /** @return array{username: string|null, url: string|null} */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'url' => $this->url,
        ];
    }

    // Empty string stays a string (Facebook page links store username:''); only
    // non-strings (missing key → null, arrays, ints) collapse to null.
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
