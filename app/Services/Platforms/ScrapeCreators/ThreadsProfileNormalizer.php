<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10a (2026-09-01): /v1/threads/profile → the Threads identity card.
// Threads rides Instagram's infrastructure, so the vendor answer is
// IG-flavoured (pk ids, hd_profile_pic_versions, signed
// scontent-*.cdninstagram.com URLs). The card is SYNTHESIZED, never spread —
// credits_* and the text_app_* noise can never leak into a persisted
// connection payload.
//
// Trial-verified husk (recorded fixture, 2026-09-01): an unknown/private
// handle answers success:true with error:"not_found" and NO username — so
// gating on a present username reads every husk as a vendor miss, never as
// an empty profile (Item 8 contract: gate on SHAPE, not HTTP status).
//
// The avatar URL is IG-signed and expiring like every asset in this lane:
// serving it means mirroring the bytes under an owned `threads:` ref
// (MediaMirror::OWNED_REF_PREFIXES), never hot-linking.
class ThreadsProfileNormalizer
{
    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array<string, mixed>|null
     */
    public function normalize(array $body): ?array
    {
        $username = $body['username'] ?? null;
        if (! is_string($username) || trim($username) === '') {
            return null;
        }
        $username = trim($username);

        $followers = is_numeric($body['follower_count'] ?? null) ? (int) $body['follower_count'] : null;

        return array_filter([
            'id' => $this->string($body, 'pk') ?? $this->string($body, 'id'),
            'username' => $username,
            'full_name' => $this->string($body, 'full_name'),
            'biography' => $this->string($body, 'biography'),
            'follower_count' => $followers,
            // camelCase twin, matching the dual-shape tolerance every other
            // platform reader in this codebase grew.
            'followersCount' => $followers,
            'is_verified' => ($body['is_verified'] ?? null) === true,
            // Vendor key is text_post_app_is_private — Threads' "private"
            // lives on the text app, not the IG account.
            'is_private' => ($body['text_post_app_is_private'] ?? null) === true,
            'profile_pic_url' => $this->avatarUrl($body),
            'bio_links' => $this->bioLinks($body),
            'url' => 'https://www.threads.com/@'.$username,
            // Provenance marker for logs/diagnostics only — nothing may
            // branch on it (same rule as the Instagram normalizer).
            'scrapedVia' => 'scrapecreators',
        ], static fn ($v) => $v !== null);
    }

    /**
     * Largest hd_profile_pic_versions entry, falling back to the 150px
     * profile_pic_url — the card wants the best frame the mirror lane can
     * keep, not the thumbnail.
     *
     * @param  array<string, mixed>  $body
     */
    private function avatarUrl(array $body): ?string
    {
        $best = null;
        $bestWidth = -1;
        foreach (is_array($body['hd_profile_pic_versions'] ?? null) ? $body['hd_profile_pic_versions'] : [] as $version) {
            if (! is_array($version)) {
                continue;
            }
            $url = $version['url'] ?? null;
            $width = is_numeric($version['width'] ?? null) ? (int) $version['width'] : 0;
            if (is_string($url) && $url !== '' && $width > $bestWidth) {
                $best = $url;
                $bestWidth = $width;
            }
        }

        return $best ?? $this->string($body, 'profile_pic_url');
    }

    /**
     * Bio links reduced to what the link lane consumes (url + title) —
     * per-entry lossy, so one malformed entry drops without taking the
     * profile with it.
     *
     * @param  array<string, mixed>  $body
     * @return list<array{url: string, title?: string}>|null
     */
    private function bioLinks(array $body): ?array
    {
        $links = [];
        foreach (is_array($body['bio_links'] ?? null) ? $body['bio_links'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $url = $entry['url'] ?? null;
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $title = $entry['title'] ?? null;
            $links[] = array_filter([
                'url' => trim($url),
                'title' => is_string($title) && trim($title) !== '' ? trim($title) : null,
            ], static fn ($v) => $v !== null);
        }

        return $links === [] ? null : $links;
    }

    /** @param array<string, mixed> $body */
    private function string(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
