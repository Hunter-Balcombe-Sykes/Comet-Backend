<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10b (2026-09-01): /v1/bluesky/profile → the identity card the
// socials/custom_links lane consumes. Bluesky has NO Apify lane behind it —
// this vendor answer is the platform's first data source, so the shape here
// IS the contract, synthesized key by key (never spread from the body) so
// credits_* and vendor drift can never reach persistence.
//
// Trial-verified quirks absorbed here (recorded payloads 2026-09-01):
//  - the NotFound husk is success:true + error:"not_found" — and, unlike
//    Spotify's, bills ZERO credits. Shape gating stays the rule regardless:
//    a usable answer positively carries did + handle, a husk carries neither.
//  - avatar/banner are unsigned cdn.bsky.app URLs — stabler than IG's signed
//    ones, but still mirror candidates under the existing doctrine; nothing
//    here may be hot-linked into a served document.
class BlueskyProfileNormalizer
{
    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array<string, mixed>|null null unless the payload positively
     *                                   carries an account (did + handle) — a husk or shape drift must read
     *                                   as "vendor miss", never as an empty profile.
     */
    public function normalize(array $body): ?array
    {
        $did = $body['did'] ?? null;
        $handle = $body['handle'] ?? null;
        if (! is_string($did) || ! str_starts_with($did, 'did:') || ! is_string($handle) || trim($handle) === '') {
            return null;
        }
        $handle = trim($handle);

        return [
            'did' => $did,
            'handle' => $handle,
            'url' => 'https://bsky.app/profile/'.$handle,
            'displayName' => $this->str($body['displayName'] ?? null),
            'description' => $this->str($body['description'] ?? null),
            'avatar' => $this->url($body['avatar'] ?? null),
            'banner' => $this->url($body['banner'] ?? null),
            'followersCount' => $this->count($body['followersCount'] ?? null),
            'followsCount' => $this->count($body['followsCount'] ?? null),
            'postsCount' => $this->count($body['postsCount'] ?? null),
            'createdAt' => $this->str($body['createdAt'] ?? null),
        ];
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function url(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^https?://~i', $value) === 1 ? $value : null;
    }

    private function count(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }
}
