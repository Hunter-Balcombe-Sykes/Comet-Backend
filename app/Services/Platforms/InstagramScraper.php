<?php

namespace App\Services\Platforms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Instagram profile scrape via Apify's instagram-profile-scraper (no direct IG
// access). Pure fetch + parse — mirroring the chosen images to R2 stays in the
// controller (a storage concern). Extracted from InstagramController.
//
// run-sync-get-dataset-items returns 201 on success, so we accept any 2xx.
class InstagramScraper extends PlatformScraper
{
    // Posts to ask Apify for — enough that auto reliably yields 8 covers and the
    // manual picker has a healthy pool.
    private const RESULTS_LIMIT = 24;

    // Run the profile scraper, returning the first dataset item (the profile,
    // with latestPosts) or null on any failure / missing token.
    //
    // $userId is threaded for log correlation. platform_connection_id is
    // intentionally not threaded: the connection row is written only AFTER a
    // successful scrape, so it doesn't exist at log time.
    public function fetchProfile(string $username, ?string $userId = null): ?array
    {
        $token = config('services.apify.token');
        if (! $token) {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(110)
                ->post(
                    'https://api.apify.com/v2/acts/'.config('partna.instagram.actor').'/run-sync-get-dataset-items',
                    ['usernames' => [$username], 'resultsLimit' => self::RESULTS_LIMIT],
                );
        } catch (Throwable $e) {
            report($e);
            Log::warning('instagram.apify.threw', ['username' => $username, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return null;
        }

        // 201 Created on success — ->ok() would only accept exactly 200.
        if (! $response->successful()) {
            // Server errors (5xx) indicate genuine Apify infra failures worth alerting on;
            // 4xx (e.g. 404 for an unknown username) are expected and log-only.
            if ($response->status() >= 500) {
                report(new \RuntimeException('Apify scrape failed with status '.$response->status()));
            }
            Log::warning('instagram.apify.not_ok', [
                'username' => $username,
                'user_id' => $userId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $items = $response->json();
        if (! is_array($items) || empty($items) || ! is_array($items[0])) {
            Log::warning('instagram.apify.bad_items', [
                'username' => $username,
                'user_id' => $userId,
                'type' => gettype($items),
                'count' => is_array($items) ? count($items) : 0,
            ]);

            return null;
        }

        return $items[0];
    }

    public function profilePicUrl(array $profile): ?string
    {
        $url = data_get($profile, 'profilePicUrlHD') ?? data_get($profile, 'profilePicUrl');

        return is_string($url) ? $url : null;
    }

    // Cap on returned bio links — plenty for a "synced" popup, bounds the work
    // InstagramAutoSync does per connect.
    private const MAX_BIO_LINKS = 10;

    /**
     * BE2: the raw Apify profile item, defensively read for bio links — actor
     * field names vary by version, and TODAY's actor doesn't return any of
     * these at all (confirmed via recon), so every source is optional. Collects,
     * in order: `externalUrl` (Instagram's single "website" field), each
     * `externalUrls[].url` (or a bare string entry — some actor versions return
     * a plain string list), then URLs regexed out of `biography` free text.
     * Deduped (exact string match) and capped at MAX_BIO_LINKS. Empty array
     * when the profile carries none of these fields (older connections, or an
     * account with an empty bio) — never throws on malformed types.
     *
     * @param  array<string,mixed>  $profile
     * @return list<string>
     */
    public function bioLinks(array $profile): array
    {
        $links = [];

        $bioLinksField = data_get($profile, 'bio_links');
        if (is_array($bioLinksField)) {
            foreach ($bioLinksField as $entry) {
                $url = is_array($entry) ? data_get($entry, 'url') : (is_string($entry) ? $entry : null);
                if (is_string($url) && $this->isHttpUrl($url)) {
                    $links[] = trim($url);
                }
            }
        }

        $external = data_get($profile, 'externalUrl');
        if (is_string($external) && $this->isHttpUrl($external)) {
            $links[] = trim($external);
        }

        $externalUrls = data_get($profile, 'externalUrls');
        if (is_array($externalUrls)) {
            foreach ($externalUrls as $entry) {
                $url = is_array($entry) ? data_get($entry, 'url') : $entry;
                if (is_string($url) && $this->isHttpUrl($url)) {
                    $links[] = trim($url);
                }
            }
        }

        $bio = data_get($profile, 'biography');
        if (is_string($bio) && $bio !== '') {
            if (preg_match_all('~https?://[^\s<>"\']+~i', $bio, $matches)) {
                foreach ($matches[0] as $match) {
                    // Trim common trailing sentence punctuation a bio's prose
                    // leaves stuck to the URL ("...my site: https://x.com.").
                    $url = rtrim($match, '.,!?)]}');
                    if ($this->isHttpUrl($url)) {
                        $links[] = $url;
                    }
                }
            }
        }

        return array_slice(array_values(array_unique($links)), 0, self::MAX_BIO_LINKS);
    }

    private function isHttpUrl(string $url): bool
    {
        return preg_match('~^https?://~i', trim($url)) === 1;
    }

    /**
     * AUTO mode: the most-recent PHOTO and the most-recent VIDEO/REEL for the
     * account, picked independently. Apify returns pinned posts first (so a pinned
     * still photo can sit ahead of a newer reel), so we sort by post timestamp —
     * not array order — before picking, which is why latestPosts[0] alone gave the
     * wrong "latest". Either side is null when the account has no post of that kind.
     *
     * @return array{photo: array{thumbnailUrl:string, shortCode:?string}|null, video: array{thumbnailUrl:?string, videoUrl:string, shortCode:?string}|null}
     */
    public function latestMedia(array $profile, ?string $userId = null): array
    {
        $posts = data_get($profile, 'latestPosts');
        if (! is_array($posts)) {
            return ['photo' => null, 'video' => null];
        }

        // Sort newest-first by timestamp so pinned-but-older posts don't win. Posts
        // missing a timestamp fall back to their original feed order.
        $sorted = [];
        foreach (array_values($posts) as $i => $post) {
            if (is_array($post)) {
                $sorted[] = ['post' => $post, 'i' => $i, 't' => $this->postTimestamp($post)];
            }
        }
        usort($sorted, function ($a, $b) {
            if ($a['t'] === $b['t']) {
                return $a['i'] <=> $b['i'];
            }
            if ($a['t'] === null) {
                return 1;
            }
            if ($b['t'] === null) {
                return -1;
            }

            return $b['t'] <=> $a['t'];
        });

        $photo = null;
        $video = null;
        foreach ($sorted as $entry) {
            $post = $entry['post'];
            // displayUrl is the cover for every type — a video's poster frame too.
            $cover = data_get($post, 'displayUrl') ?? data_get($post, 'images.0');
            $cover = is_string($cover) && $cover !== '' ? $cover : null;

            if (data_get($post, 'type') === 'Video') {
                $vid = data_get($post, 'videoUrl');
                $vid = is_string($vid) && $vid !== '' ? $vid : null;
                if ($video === null && $vid !== null) {
                    $video = ['thumbnailUrl' => $cover, 'videoUrl' => $vid, 'shortCode' => data_get($post, 'shortCode')];
                }
            } elseif ($photo === null && $cover !== null) {
                $photo = ['thumbnailUrl' => $cover, 'shortCode' => data_get($post, 'shortCode')];
            }

            if ($photo !== null && $video !== null) {
                break;
            }
        }

        // Diagnostic: confirms from the dev logs whether Apify is returning reels at
        // all for this account (vs. just mis-ordering them) when a reel doesn't show.
        Log::info('instagram.latest_media', [
            'user_id' => $userId,
            'posts' => count($posts),
            'videos' => count(array_filter($posts, fn ($p) => is_array($p) && data_get($p, 'type') === 'Video')),
            'picked_photo' => $photo !== null,
            'picked_video' => $video !== null,
        ]);

        return ['photo' => $photo, 'video' => $video];
    }

    // Post timestamp as a unix int for sorting, or null if absent/unparseable.
    private function postTimestamp(array $post): ?int
    {
        $ts = data_get($post, 'timestamp');
        if (is_int($ts)) {
            return $ts;
        }
        if (is_string($ts) && $ts !== '') {
            $t = strtotime($ts);

            return $t === false ? null : $t;
        }

        return null;
    }
}
