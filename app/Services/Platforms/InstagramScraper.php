<?php

namespace App\Services\Platforms;

use App\Services\Platforms\Actors\InstagramActorAdapter;
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
    /** Fallback default for config('partna.limits.apify.run_sync_timeout_seconds') (CFG-9). */
    private const RUN_SYNC_TIMEOUT_SECONDS = 110;

    // Run the profile scraper, returning the first dataset item (the profile,
    // with latestPosts) or null on any failure / missing token.
    //
    // $userId is threaded for log correlation. platform_connection_id is
    // intentionally not threaded: the connection row is written only AFTER a
    // successful scrape, so it doesn't exist at log time.
    public function fetchProfile(string $username, ?string $userId = null): ?array
    {
        return $this->fetchProfileResult($username, $userId)->profile;
    }

    // Same fetch, but reporting WHY it failed. fetchProfile()'s bare null could
    // not distinguish a handle that genuinely does not exist from an upstream
    // break, so callers labelled every failure "source not found" — telling real
    // prospects their own Instagram account doesn't exist while Meta was down.
    public function fetchProfileResult(string $username, ?string $userId = null): ProfileFetchResult
    {
        $token = config('services.apify.token');
        if (! $token) {
            return ProfileFetchResult::failed(ProfileFetchFailure::NotConfigured);
        }

        $actor = (string) config('partna.instagram.actor');
        $adapter = $this->adapterFor($actor);
        if (! $adapter instanceof InstagramActorAdapter) {
            // Fail closed rather than guess an input shape: each actor has its
            // own schema and sending the wrong body is a hard 400, which would
            // surface as a failed scrape on EVERY build. No username in this
            // context — it's a config fault, not a user one.
            Log::warning('instagram.actor.unadapted', ['actor' => $actor]);

            return ProfileFetchResult::failed(ProfileFetchFailure::NotConfigured);
        }

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('partna.limits.apify.run_sync_timeout_seconds', self::RUN_SYNC_TIMEOUT_SECONDS))
                ->post(
                    'https://api.apify.com/v2/acts/'.$actor.'/run-sync-get-dataset-items',
                    $adapter->input($username),
                );
        } catch (Throwable $e) {
            report($e);
            // Hash the handle before logging — public on Instagram, but pairing it
            // with our internal user_id in long-retained logs builds a durable,
            // joinable identity record that shouldn't outlive the request. Lowercase
            // first: Instagram usernames are case-insensitive, so two connect attempts
            // for the same account ("DocPizza" vs "docpizza") must hash identically or
            // they won't correlate in the logs.
            Log::warning('instagram.apify.threw', ['username_hash' => hash('sha256', mb_strtolower($username)), 'user_id' => $userId, 'error' => $e->getMessage()]);

            return ProfileFetchResult::failed(ProfileFetchFailure::Transport);
        }

        // 201 Created on success — ->ok() would only accept exactly 200.
        if (! $response->successful()) {
            // Server errors (5xx) indicate genuine Apify infra failures worth alerting on;
            // 4xx (e.g. 404 for an unknown username) are expected and log-only.
            if ($response->status() >= 500) {
                report(new \RuntimeException('Apify scrape failed with status '.$response->status()));
            }
            Log::warning('instagram.apify.not_ok', [
                'username_hash' => hash('sha256', mb_strtolower($username)),
                'user_id' => $userId,
                'status' => $response->status(),
            ]);

            return ProfileFetchResult::failed(ProfileFetchFailure::UpstreamError);
        }

        $items = $response->json();
        if (! is_array($items) || empty($items) || ! is_array($items[0])) {
            Log::warning('instagram.apify.bad_items', [
                'username_hash' => hash('sha256', mb_strtolower($username)),
                'user_id' => $userId,
                'type' => gettype($items),
                'count' => is_array($items) ? count($items) : 0,
            ]);

            return ProfileFetchResult::failed(ProfileFetchFailure::MalformedPayload);
        }

        // A 2xx run can still carry a per-item scrape failure: the dataset item
        // is profile-shaped (username/url/scrapedAt) but its fields are null and
        // it carries an "error" string instead. Treat that as a failed fetch —
        // not a valid (empty) profile — so callers retry instead of silently
        // persisting a blank account. Only the adapter knows whether that error
        // means "no such handle" or "couldn't read it".
        if ($failure = $adapter->classify($items[0])) {
            Log::warning('instagram.apify.error_item', [
                'username_hash' => hash('sha256', mb_strtolower($username)),
                'user_id' => $userId,
                'error' => $items[0]['error'],
                'failure' => $failure->value,
            ]);

            return ProfileFetchResult::failed($failure);
        }

        return ProfileFetchResult::ok($items[0]);
    }

    // Actor id → its adapter. Indexed off the full map rather than via config()
    // dot-notation because actor ids are owner~name strings.
    private function adapterFor(string $actor): ?InstagramActorAdapter
    {
        $class = ((array) config('partna.instagram.actor_adapters', []))[$actor] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $adapter = app($class);

        return $adapter instanceof InstagramActorAdapter ? $adapter : null;
    }

    public function profilePicUrl(array $profile): ?string
    {
        // Field-name drift across actor versions: the figue actor returns raw
        // Instagram GraphQL snake_case (profile_pic_url_hd — confirmed live
        // 2026-07-23 against the real actor), older shapes used camelCase.
        // Read both, HD first.
        $url = data_get($profile, 'profilePicUrlHD')
            ?? data_get($profile, 'profile_pic_url_hd')
            ?? data_get($profile, 'profilePicUrl')
            ?? data_get($profile, 'profile_pic_url');

        return is_string($url) ? $url : null;
    }

    // Cap on returned bio links — plenty for a "synced" popup, bounds the work
    // InstagramAutoSync does per connect.
    private const MAX_BIO_LINKS = 10;

    /**
     * BE2: the raw Apify profile item, defensively read for bio links — actor
     * field names vary by version, so every source is optional. (An earlier note
     * here claimed the live actor returned none of these; that was true of the
     * figue actor and is WRONG for the apify actor made default 2026-08-10 —
     * verified 2026-08-11 against live run history, it returns externalUrl,
     * externalUrls AND biography, so this method is load-bearing, not vacuous.)
     *
     * Collects, in order: `externalUrl` (Instagram's single "website" field), each
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
     * diagnostics (R1) records what was actually seen so a future "reel didn't
     * mirror" report is diagnosable from stored data (InstagramConnectionSeeder
     * persists it as _mediaDiagnostics) instead of a guess.
     *
     * @return array{photo: array{thumbnailUrl:string, shortCode:?string}|null, video: array{thumbnailUrl:?string, videoUrl:string, shortCode:?string}|null, diagnostics: array{posts:int, videos:int, pickedPhoto:bool, pickedVideo:bool, videoCandidates:list<array{shortCode:?string, hasMp4:bool, type:?string}>}}
     */
    public function latestMedia(array $profile, ?string $userId = null): array
    {
        $posts = data_get($profile, 'latestPosts');
        if (! is_array($posts)) {
            return ['photo' => null, 'video' => null, 'diagnostics' => [
                'posts' => 0, 'videos' => 0, 'pickedPhoto' => false, 'pickedVideo' => false, 'videoCandidates' => [],
            ]];
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
        $videoCandidates = [];
        foreach ($sorted as $entry) {
            $post = $entry['post'];
            // The cover for every type — a video's poster frame too. Actor
            // versions differ on naming: camelCase displayUrl (legacy) vs raw
            // GraphQL display_url (figue actor, confirmed live 2026-07-23);
            // images.0 is the shared fallback both emit.
            $cover = data_get($post, 'displayUrl')
                ?? data_get($post, 'display_url')
                ?? data_get($post, 'images.0');
            $cover = is_string($cover) && $cover !== '' ? $cover : null;

            if ($this->isVideoPost($post)) {
                $vid = $this->videoUrlFromPost($post);
                if (count($videoCandidates) < 5) {
                    $videoCandidates[] = [
                        'shortCode' => data_get($post, 'shortCode'),
                        'hasMp4' => $vid !== null,
                        'type' => is_string(data_get($post, 'type')) ? data_get($post, 'type') : null,
                    ];
                }
                if ($video === null && $vid !== null) {
                    $video = ['thumbnailUrl' => $cover, 'videoUrl' => $vid, 'shortCode' => data_get($post, 'shortCode')];
                }
            } elseif ($photo === null && $cover !== null) {
                $photo = ['thumbnailUrl' => $cover, 'shortCode' => data_get($post, 'shortCode')];
            }
        }

        $diagnostics = [
            'posts' => count($posts),
            'videos' => $this->totalVideoCount($sorted),
            'pickedPhoto' => $photo !== null,
            'pickedVideo' => $video !== null,
            'videoCandidates' => $videoCandidates,
        ];
        // Diagnostic: confirms from the dev logs whether Apify is returning reels at
        // all for this account (vs. just mis-ordering them) when a reel doesn't show.
        Log::info('instagram.latest_media', ['user_id' => $userId, ...$diagnostics]);

        return ['photo' => $photo, 'video' => $video, 'diagnostics' => $diagnostics];
    }

    /**
     * Broadened 2026-07-24 (R1): today's figue-actor grid node reliably
     * carries type==='Video' + videoUrl/video_url (live-tested against a real
     * account), but reel-specific variance (a clips/igtv product_type, a
     * GraphQL __typename, or an mp4 nested under video_versions[0].url
     * instead of a top-level field) has been seen on other Meta scrapers and
     * would otherwise silently vanish here.
     */
    private function isVideoPost(array $post): bool
    {
        if (data_get($post, 'type') === 'Video') {
            return true;
        }
        if (data_get($post, 'is_video') === true) {
            return true;
        }
        $productType = data_get($post, 'product_type') ?? data_get($post, 'productType');
        if (in_array($productType, ['clips', 'igtv'], true)) {
            return true;
        }
        $typename = data_get($post, '__typename');

        return in_array($typename, ['GraphVideo', 'XDTGraphVideo'], true);
    }

    private function videoUrlFromPost(array $post): ?string
    {
        $vid = data_get($post, 'videoUrl')
            ?? data_get($post, 'video_url')
            ?? data_get($post, 'video_versions.0.url');

        return is_string($vid) && $vid !== '' ? $vid : null;
    }

    /** @param  list<array{post: array, i: int, t: ?int}>  $sorted */
    private function totalVideoCount(array $sorted): int
    {
        $count = 0;
        foreach ($sorted as $entry) {
            if ($this->isVideoPost($entry['post'])) {
                $count++;
            }
        }

        return $count;
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
