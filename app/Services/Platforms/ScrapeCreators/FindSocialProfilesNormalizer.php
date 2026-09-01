<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 11g (2026-09-01): /v1/find-social-profiles distilled into the one
// thing the detection layer consumes — a {platform => profile URL} map, keyed
// in OUR registry vocabulary and holding only platforms the caller's
// allow-list knows. Everything else in the vendor body is deliberately
// dropped: same_handle_candidate_urls and google_candidate_urls are the
// vendor's UNVERIFIED piles (its own docs bar treating them as identity), and
// carrying them here would hand squatter accounts to the connect layer.
// Only `profiles` rows — the corroborated set — survive.
//
// Contract decisions, recorded:
//  - vendor says "twitter" even for x.com URLs; the registry key is 'x', so
//    the alias maps here and nowhere downstream sees the vendor's name.
//  - one URL per platform: the highest-confidence row wins, first-in-body on
//    ties (the vendor emits confidence-descending, so ties keep its order).
//  - the SOURCE platform is excluded entirely — the caller supplied that
//    identity, and a second same-platform account (a linked alt, a network's
//    other channel) is not a discovery the connect layer should act on.
//  - a husk, shape drift, or a map that filters to empty all read null: the
//    callers treat "vendor miss" and "nothing new found" identically
//    (additive enrichment — nothing to fall through TO), so one null keeps
//    the lane's lossy contract simple.
class FindSocialProfilesNormalizer
{
    /** Vendor platform names that differ from registry keys. */
    private const PLATFORM_ALIASES = ['twitter' => 'x'];

    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @param  list<string>  $knownPlatforms  registry keys the map may carry
     * @return non-empty-array<string, string>|null platform key => profile URL
     */
    public function normalize(array $body, array $knownPlatforms): ?array
    {
        $source = $body['source'] ?? null;
        $profiles = $body['profiles'] ?? null;
        if (! is_array($source) || ! is_array($profiles)) {
            return null;
        }

        $sourcePlatform = $this->platformKey($source['platform'] ?? null);
        if ($sourcePlatform === null) {
            return null;
        }

        $known = array_flip($knownPlatforms);
        $best = []; // platform => [confidence, url]
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $platform = $this->platformKey($profile['platform'] ?? null);
            if ($platform === null || $platform === $sourcePlatform || ! isset($known[$platform])) {
                continue;
            }

            // A row without a numeric confidence or an http(s) URL is shape
            // drift — skipped, never guessed at.
            $confidence = $profile['confidence'] ?? null;
            $url = trim((string) ($profile['url'] ?? ''));
            if ((! is_int($confidence) && ! is_float($confidence)) || preg_match('~^https?://~i', $url) !== 1) {
                continue;
            }

            if (! isset($best[$platform]) || $confidence > $best[$platform][0]) {
                $best[$platform] = [$confidence, $url];
            }
        }

        if ($best === []) {
            return null;
        }

        return array_map(static fn (array $row): string => $row[1], $best);
    }

    private function platformKey(mixed $vendorName): ?string
    {
        if (! is_string($vendorName) || $vendorName === '') {
            return null;
        }
        $vendorName = strtolower($vendorName);

        return self::PLATFORM_ALIASES[$vendorName] ?? $vendorName;
    }
}
