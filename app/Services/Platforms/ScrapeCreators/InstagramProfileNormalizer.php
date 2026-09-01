<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 8: ScrapeCreators' /v1/instagram/profile returns Instagram's raw
// GraphQL user — the SAME shape the figue actor taught this codebase to read
// (snake_case, edge_* containers), which is why this normalizer is a thin
// aliasing pass, not a translation layer. It does exactly three jobs:
//
//  1. Spread the vendor user through UNTOUCHED, so every existing dual-shape
//     reader (full_name/biography/bio_links/business_*/profile_pic_url_hd)
//     keeps reading the keys it already knows.
//  2. Materialise `latestPosts` from edge_owner_to_timeline_media.edges[].node
//     — the ONE container rename the pipeline's readers do NOT absorb — with
//     per-node aliases for the two keys the media picker reads under other
//     names (shortCode ← shortcode, timestamp ← taken_at_timestamp). The
//     node itself is spread through too: display_url/is_video/video_url are
//     already the names isVideoPost()/videoUrlFromPost() accept.
//  3. Fill the count aliases the thin-profile check and diagnostics read
//     (postsCount, followersCount), from whichever variant the payload
//     carried (trial-verified: follower_count is sometimes only present as
//     edge_followed_by.count).
//
// Returns null unless the payload positively carries a usable user (a
// username at minimum) — a NotFound husk or shape drift must read as
// "vendor miss", never as an empty profile.
class InstagramProfileNormalizer
{
    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array<string, mixed>|null
     */
    public function normalize(array $body): ?array
    {
        $user = $body['data']['user'] ?? null;
        if (! is_array($user)) {
            return null;
        }

        $username = $user['username'] ?? null;
        if (! is_string($username) || $username === '') {
            return null;
        }

        $timeline = $user['edge_owner_to_timeline_media'] ?? null;
        $edges = is_array($timeline) ? ($timeline['edges'] ?? []) : [];

        $latestPosts = [];
        foreach (is_array($edges) ? $edges : [] as $edge) {
            $node = is_array($edge) ? ($edge['node'] ?? null) : null;
            if (! is_array($node)) {
                continue;
            }
            $latestPosts[] = [
                ...$node,
                'shortCode' => $node['shortcode'] ?? null,
                'timestamp' => $node['taken_at_timestamp'] ?? null,
            ];
        }

        $followers = $user['follower_count']
            ?? (is_array($user['edge_followed_by'] ?? null) ? ($user['edge_followed_by']['count'] ?? null) : null);
        $postsCount = (is_array($timeline) ? ($timeline['count'] ?? null) : null)
            ?? $user['media_count'] ?? null;

        return [
            ...$user,
            'latestPosts' => $latestPosts,
            'postsCount' => $postsCount,
            'followersCount' => $followers,
            // category_name is the general field; business_category_name only
            // exists on business accounts. The enricher reads the camelCase
            // alias first, so fill it from the best available.
            'businessCategoryName' => $user['business_category_name']
                ?? $user['category_name']
                ?? $user['category']
                ?? null,
            'externalUrl' => $user['external_url'] ?? null,
            // Provenance marker for logs/diagnostics only — nothing may branch
            // on it (the whole point is that downstream cannot tell the lanes
            // apart).
            'scrapedVia' => 'scrapecreators',
        ];
    }
}
