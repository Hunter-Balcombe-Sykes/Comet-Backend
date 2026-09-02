<?php

namespace App\Services\Platforms;

use App\Services\Cache\ApifyBudget;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\Actors\InstagramActorAdapter;
use App\Services\Platforms\ScrapeCreators\InstagramHighlightsNormalizer;
use App\Services\Platforms\ScrapeCreators\InstagramProfileNormalizer;
use App\Services\Platforms\ScrapeCreators\InstagramReelsNormalizer;
use App\Services\Platforms\ScrapeCreators\InstagramTaggedPostsNormalizer;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
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
    // A THIN profile also returns null, and that is load-bearing: a caller of
    // this lossy wrapper gets "unusable", and the one caller that has data to
    // protect returns before seed() on null. seed()'s stale-reclaim deletes
    // every mirrored file it did not write this run, so not running it is what
    // preserves a live user's payload and their R2 objects. Callers that need to
    // USE a degraded profile must take fetchProfileResult() and read ->thin.
    //
    // $userId is threaded for log correlation. platform_connection_id is
    // intentionally not threaded: the connection row is written only AFTER a
    // successful scrape, so it doesn't exist at log time.
    public function fetchProfile(string $username, ?string $userId = null): ?array
    {
        $result = $this->fetchProfileResult($username, $userId);

        return $result->thin ? null : $result->profile;
    }

    // Same fetch, but reporting WHY it failed. fetchProfile()'s bare null could
    // not distinguish a handle that genuinely does not exist from an upstream
    // break, so callers labelled every failure "source not found" — telling real
    // prospects their own Instagram account doesn't exist while Meta was down.
    //
    // A thin result (post timeline missing) earns ONE retry: the fault is
    // transient — @crucibletattooco returned 4,164 posts at 10:01 on 2026-08-10,
    // zero at 10:22, and 4,164 again the next day — and a second paid run is
    // cheap against a site built empty.
    public function fetchProfileResult(string $username, ?string $userId = null): ProfileFetchResult
    {
        // One Apify run per connect, not two (W1 survey note, verified 2026-08-18
        // task #18): InstagramConnectJob scrapes the profile for the connection
        // card and, minutes later, the eager ingest run scraped it AGAIN for the
        // media pool — same username, same actor, same dataset. A successful
        // profile is kept for a short window so whichever lane comes second
        // reads it back instead of paying for it.
        // rememberLocked also collapses the two lanes when they race: the
        // second waits on the first's lock and reads its answer. A failed
        // fetch returns null (nothing cached) so it is retried, not stuck.
        $cached = app(CacheLockService::class)->rememberLockedNullable(
            CacheKeyGenerator::instagramProfile($username),
            (int) config('partna.limits.platforms.instagram.profile_reuse_seconds', 900),
            function () use ($username, $userId): ?array {
                $result = $this->fetchProfileResultUncached($username, $userId);
                if ($result->profile === null || $result->thin) {
                    // Carry a failure — or a THIN answer, which the next lane
                    // deserves its own retry at — out of the closure uncached.
                    $this->lastUncachedFailure = $result;

                    return null;
                }

                return ['profile' => $result->profile, 'thin' => false];
            },
            // A failed fetch is not worth remembering: 1s negative cache, so
            // the next caller simply tries again.
            nullTtl: 1,
            lockSeconds: 150,
            blockSeconds: 150,
        );
        if (is_array($cached) && is_array($cached['profile'] ?? null)) {
            $this->lastUncachedFailure = null;

            return ProfileFetchResult::ok($cached['profile'], thin: (bool) ($cached['thin'] ?? false));
        }

        $carried = $this->lastUncachedFailure;
        $this->lastUncachedFailure = null;

        return $carried ?? $this->fetchProfileResultUncached($username, $userId);
    }

    private ?ProfileFetchResult $lastUncachedFailure = null;

    private function fetchProfileResultUncached(string $username, ?string $userId = null): ProfileFetchResult
    {
        // Item 8 (2026-09-01): the ScrapeCreators lane fronts the actor. A
        // usable, non-thin vendor profile short-circuits the 15-60s run-sync
        // wait to ~2-4s; ANY other vendor outcome — no key, budget denied,
        // transport/HTTP failure, NotFound husk, shape drift, thin timeline —
        // falls through to the Apify path completely unchanged. Not-found
        // semantics deliberately stay Apify's: telling a prospect their
        // account doesn't exist remains a claim only the incumbent lane is
        // trusted to make, so a vendor miss never maps to ProfileNotFound.
        $vendor = $this->vendorProfileFetch($username, $userId);
        if ($vendor !== null && ! $this->isThinProfile($vendor)) {
            return ProfileFetchResult::ok($vendor);
        }

        $first = $this->attemptFetch($username, $userId);

        if ($first->profile === null || ! $this->isThinProfile($first->profile)) {
            return $first;
        }

        // This class does not otherwise claim Apify budget — the controllers do
        // (InstagramController::guardApifyBudget, RefreshController). Claim here
        // or the retry spends a paid run outside the daily cap. Denied means
        // denied: report thin rather than spending anyway.
        if (! app(ApifyBudget::class)->tryClaim('instagram')) {
            $this->logThinProfile($username, $userId, $first->profile, retried: false, recovered: false);

            return ProfileFetchResult::ok($first->profile, thin: true);
        }

        $retry = $this->attemptFetch($username, $userId);

        if ($retry->profile !== null && ! $this->isThinProfile($retry->profile)) {
            $this->logThinProfile($username, $userId, $first->profile, retried: true, recovered: true);

            return $retry;
        }

        // Still thin (or the retry broke outright): keep the FIRST profile — it is
        // degraded, not absent, and the build path needs something to render.
        $this->logThinProfile($username, $userId, $first->profile, retried: true, recovered: false);

        return ProfileFetchResult::ok($first->profile, thin: true);
    }

    /**
     * The vendor lane, contract-lossy by design: a normalized profile array
     * or null, never a failure classification. Budget is claimed before the
     * HTTP call and released when the call yields nothing usable — a miss
     * must not burn a slot the day's real scrapes need.
     */
    private function vendorProfileFetch(string $username, ?string $userId = null): ?array
    {
        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            return null;
        }
        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim('instagram')) {
            return null;
        }

        $body = $client->get('/v1/instagram/profile', ['handle' => $username], $userId);
        if ($body === null) {
            $budget->release('instagram');

            return null;
        }

        $profile = app(InstagramProfileNormalizer::class)->normalize($body);
        if ($profile === null) {
            // The call was made and billed upstream even when the shape is a
            // husk — the slot stays spent; only transport-level nulls release.
            Log::info('scrapecreators.instagram.unusable_shape', [
                'username_hash' => hash('sha256', mb_strtolower($username)),
                'user_id' => $userId,
            ]);

            return null;
        }

        return $profile;
    }

    /**
     * Item 11b: video history BEYOND the ≤12-post profile window, via the
     * vendor's /v1/instagram/user/reels. This supersedes Item 2's deferred
     * "conditional deeper scrape" — where that plan would have paid a second
     * Apify run, one vendor page fills the video pool for ~1/60th the wait.
     * Vendor-ONLY: no actor knows this surface, so every failure of any kind
     * is null and the caller simply has no depth this pass — the window lane
     * stands alone, exactly the Item 8 fallback contract.
     *
     * Budget rides the EXISTING 'instagram' source cap (unit ig_depth ruling)
     * rather than minting a new source: depth runs at most once per eager
     * ingest, and a shared ledger means an incident throttles the whole
     * Instagram vendor lane at one knob. Claim before the call, release on
     * transport-null, slot stays spent on a billed husk.
     *
     * @return list<array<string, mixed>>|null rows in the media stream's
     *                                         landed vocabulary (InstagramReelsNormalizer's contract)
     */
    public function fetchReelsDepth(string $username, ?string $userId = null): ?array
    {
        $rows = $this->vendorDepthRows(
            '/v1/instagram/user/reels',
            ['handle' => $username],
            InstagramReelsNormalizer::class,
            'instagram_reels',
            $username,
            $userId,
        );

        $limit = max(1, (int) config('partna.limits.scrapecreators.instagram_depth.reels_limit', 12));

        return $rows === null ? null : array_slice($rows, 0, $limit);
    }

    /**
     * Item 11b: story-highlight covers as gallery candidates, via the
     * vendor's /v1/instagram/user/highlights. Same lossy vendor discipline
     * and the same 'instagram' budget source as fetchReelsDepth.
     *
     * @return list<array<string, mixed>>|null
     */
    public function fetchHighlights(string $username, ?string $userId = null): ?array
    {
        $rows = $this->vendorDepthRows(
            '/v1/instagram/user/highlights',
            ['handle' => $username],
            InstagramHighlightsNormalizer::class,
            'instagram_highlights',
            $username,
            $userId,
        );

        $limit = max(1, (int) config('partna.limits.scrapecreators.instagram_depth.highlights_limit', 10));

        return $rows === null ? null : array_slice($rows, 0, $limit);
    }

    /**
     * Item 11b: tagged posts (social-proof imagery), via the vendor's
     * /v1/instagram/user/tagged-posts. The endpoint takes ONLY the numeric
     * Instagram user id — the caller reads it off the already-scraped
     * profile, so this can never spend a credit resolving a handle. Same
     * lossy discipline, same 'instagram' budget source.
     *
     * @return list<array<string, mixed>>|null
     */
    public function fetchTaggedPosts(string $igUserId, ?string $userId = null): ?array
    {
        $rows = $this->vendorDepthRows(
            '/v1/instagram/user/tagged-posts',
            ['user_id' => $igUserId],
            InstagramTaggedPostsNormalizer::class,
            'instagram_tagged',
            $igUserId,
            $userId,
        );

        $limit = max(1, (int) config('partna.limits.scrapecreators.instagram_depth.tagged_limit', 10));

        return $rows === null ? null : array_slice($rows, 0, $limit);
    }

    /**
     * The shared depth-lane skeleton: the Item 8 budget contract verbatim
     * (claim before the call, release on transport-null, keep the slot spent
     * on a billed husk), one endpoint, one normalizer. $identifier is hashed
     * for the same joinable-identity reason attemptFetch() documents.
     *
     * @param  array<string, string|int>  $query
     * @param  class-string  $normalizer  a ::rows(array): ?array normalizer
     * @return list<array<string, mixed>>|null
     */
    private function vendorDepthRows(string $path, array $query, string $normalizer, string $logName, string $identifier, ?string $userId): ?array
    {
        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            return null;
        }
        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim('instagram')) {
            return null;
        }

        $body = $client->get($path, $query, $userId);
        if ($body === null) {
            $budget->release('instagram');

            return null;
        }

        $rows = app($normalizer)->rows($body);
        if ($rows === null) {
            // Billed upstream even as a husk — the slot stays spent; only
            // transport-level nulls release (the vendorProfileFetch rule).
            Log::info("scrapecreators.{$logName}.unusable_shape", [
                'identifier_hash' => hash('sha256', mb_strtolower($identifier)),
                'user_id' => $userId,
            ]);

            return null;
        }

        return $rows;
    }

    // One run of the actor. Extracted so the thin retry above is exactly one
    // extra call and cannot become a loop.
    private function attemptFetch(string $username, ?string $userId = null): ProfileFetchResult
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

    /**
     * A 2xx profile whose post timeline never arrived.
     *
     * postsCount and latestPosts are the count and the contents of ONE upstream
     * container, so they fail together — this is a single signal, never two
     * independent checks. Observed 2026-08-10 on @crucibletattooco: name,
     * follower count, picture and category all present, those two absent. The
     * same account returned 4,164 posts 21 minutes earlier and again the next day.
     *
     * Deliberately conservative. postsCount === 0 WITH no posts is
     * self-consistent and indistinguishable from a genuinely empty account, so
     * it is NOT thin: a false positive tells a real prospect their build is
     * broken, while a false negative costs one thin site.
     *
     * businessCategoryName is deliberately absent from this predicate. "None" is
     * the normal value for an account with no subvertical — natgeo and
     * hungryjacksau both carry it with complete data.
     */
    public function isThinProfile(array $profile): bool
    {
        // An explicit error item is the adapter's classify() call to make.
        if (is_string(data_get($profile, 'error'))) {
            return false;
        }

        // Private accounts legitimately expose no posts.
        if (data_get($profile, 'private') === true) {
            return false;
        }

        $posts = data_get($profile, 'latestPosts');
        if (is_array($posts) && $posts !== []) {
            return false;
        }

        // Absent or non-numeric: the container never arrived (the observed fault).
        $count = data_get($profile, 'postsCount');
        if (! is_numeric($count)) {
            return true;
        }

        // Claims posts but shipped none.
        return (int) $count > 0;
    }

    // Hash the handle before logging, for the reason spelled out in
    // attemptFetch(): a raw handle beside our internal user_id builds a durable,
    // joinable identity record in long-retained logs. `recovered` is what makes
    // the retry's hit rate measurable rather than guessed.
    private function logThinProfile(string $username, ?string $userId, array $profile, bool $retried, bool $recovered): void
    {
        $posts = data_get($profile, 'latestPosts');

        Log::warning('instagram.thin_profile', [
            'username_hash' => hash('sha256', mb_strtolower($username)),
            'user_id' => $userId,
            'postsCount' => data_get($profile, 'postsCount'),
            'followersCount' => data_get($profile, 'followersCount'),
            'latestPosts' => is_array($posts) ? count($posts) : 0,
            'retried' => $retried,
            'recovered' => $recovered,
        ]);
    }

    /**
     * Whether a scrape could even be attempted: a token AND an adapter for the
     * currently configured actor. Both are checked inside attemptFetch(), but by
     * then a caller that claims budget first has already spent a slot — and the
     * adapter half is the easy one to miss, because a wrong `partna.instagram.actor`
     * looks like a working config until the run returns NotConfigured.
     *
     * Exists for InstagramActorDriver, which must decide whether to claim BEFORE
     * calling in; a config fault has to release the ledger claim, not settle it.
     */
    public function isConfigured(): bool
    {
        return (bool) config('services.apify.token')
            && $this->adapterFor((string) config('partna.instagram.actor')) !== null;
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

        // The SEED reel is the home background (2026-09-02, owner: it looks
        // soft). The profile lane hands one mid rendition; the reels endpoint
        // lists every rendition, so one budgeted call upgrades the chosen
        // reel to its best mp4 when the shortcodes match. Off via config;
        // every miss keeps the profile lane's url.
        $seedUpgraded = false;
        $username = data_get($profile, 'username');
        if ($video !== null && $userId !== null && is_string($username) && $username !== ''
            && (bool) config('partna.limits.scrapecreators.instagram_seed_reel_best', true)) {
            try {
                foreach ($this->fetchReelsDepth($username, $userId) ?? [] as $row) {
                    if (($row['shortcode'] ?? null) === ($video['shortCode'] ?? '__none__') && is_string($row['video_url'] ?? null) && $row['video_url'] !== '') {
                        $video['videoUrl'] = $row['video_url'];
                        $seedUpgraded = true;
                        break;
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($seedUpgraded) {
            Log::info('instagram.seed_reel.best_rendition', ['user_id' => $userId, 'short_code' => $video['shortCode'] ?? null]);
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
        // Best rendition when the vendor lists several (2026-09-02); the
        // single-url shapes otherwise.
        $vid = InstagramReelsNormalizer::bestVideoUrl(data_get($post, 'video_versions'))
            ?? data_get($post, 'videoUrl')
            ?? data_get($post, 'video_url');

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
