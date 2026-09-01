<?php

namespace App\Services\Platforms;

use App\Exceptions\Platforms\VendorAccountFaultException;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\YoutubeLivesNormalizer;
use App\Services\Platforms\ScrapeCreators\YoutubeShortsNormalizer;
use App\Support\ThrottledReport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Scrapes a YouTube channel's recent uploads with no API key. Two
// unauthenticated fetches: the channel page (to resolve the channel's own ID)
// then the RSS feed (the 15 most-recent uploads — YouTube's hard cap). Extracted
// from the youtube connect strategy so it stays thin and the scrape is reusable.
// Spec: ~/Developer/platform link capabilites/youtube-implementation.md
class YoutubeScraper extends PlatformScraper
{
    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly YoutubeThumbnailResolver $thumbnails,
    ) {}

    // Paths that never address a channel: video links (a video is not a
    // channel) and YouTube's own utility pages. Also catches a /channel/ URL
    // whose id is NOT a well-formed UC id — without the lookahead that would
    // fall through to the legacy-vanity branch below and yield the literal
    // handle "channel".
    private const NON_CHANNEL_PATH = '~youtu\.be/|youtube\.com/(?:watch|shorts/|live/|embed/|playlist|results|feed/|channel/(?!UC[A-Za-z0-9_-]{22}))~i';

    // YouTube's own pages, which the bare-vanity branch below reads as channels
    // because it matches ANY first path segment. That cost nothing until F6
    // (2026-08-31) pointed the WRITE side at this parser: socialUsername()
    // used to answer '' for youtube whatever the URL, so /about, /t/terms,
    // /creators, /gaming and /premium all landed on the same no-identity road.
    // Since the delegation they parse as 'about', 't', 'creators', … and get
    // STORED as the channel identity — the commit made this class worse, not
    // better. It is the same class the instagram arm's own $reserved list
    // exists to close (BuildsAutoSyncFindings::socialUsername — "developer/
    // about/legal/directory leaked as fake usernames"), and a superset is safe
    // here for exactly the reason a subset is the bug.
    //
    // Only the BARE branch consults this. The prefixed forms are deliberately
    // exempt: @about is a handle a real person can own, and /c/gaming is a
    // legacy vanity someone may hold — a reserved word is only evidence of a
    // YouTube page when YouTube's own routing would claim that path.
    private const RESERVED_SEGMENTS = [
        // Legal + corporate hubs. '/t/…' is where terms/privacy/creators-
        // policy live, so the segment 't' is never a vanity.
        't', 'about', 'howyoutubeworks', 'jobs', 'press', 'ads', 'creators',
        'creatorresearch', 'intl', 'dev', 'howto', 'new', 'oops', 'error',
        // Product surfaces. Each is a destination page, not a channel.
        'gaming', 'premium', 'music', 'movies', 'moviesandshows', 'learning',
        'sports', 'news', 'trends', 'podcasts', 'shopping', 'kids', 'select_site',
        // Signed-in surfaces and utility routes.
        'account', 'reporthistory', 'upload', 'studio', 'analytics', 'redeem',
        'purchases', 'paid_memberships', 'subscription_center', 'my_videos',
        'timeline', 'audiolibrary', 'features', 'verify_age', 'signin',
        'logout', 'redirect', 'source', 'hashtag', 'supported_browsers',
        // The prefixes NON_CHANNEL_PATH only recognises WITH a trailing slash
        // or argument — bare "youtube.com/shorts" reaches the vanity branch.
        'watch', 'shorts', 'live', 'embed', 'playlist', 'results', 'feed',
        'channel', 'c', 'user',
    ];

    // The characters a channel identity is actually made of. \p{L}/\p{N}/\p{M}
    // rather than [A-Za-z0-9] because YouTube handles are NOT ASCII-only:
    // the old class stopped dead at the first non-ASCII byte and handed back
    // the prefix it had eaten, so "youtube.com/@José" resolved to 'Jos' — a
    // WRONG identity, which points at somebody else's channel or at nothing
    // and does it silently. That is strictly worse than no identity at all.
    private const HANDLE_CHARS = '[\p{L}\p{M}\p{N}._-]+';

    // Refuse a match that stopped mid-identity. After the greedy class above
    // only '%' can still be an identity character (a percent-encoded handle,
    // "@Jos%C3%A9"), and consuming the 'Jos' in front of it would re-introduce
    // the truncation this pair exists to stop. Punctuation that genuinely ENDS
    // a link — '/', '?', '#', a comma or bracket from scraped bio text — is
    // not listed, so a trailing character still yields the whole handle.
    private const NOT_MID_IDENTITY = '(?![\p{L}\p{M}\p{N}%])';

    /**
     * Reduce any channel reference — bare handle, @handle, or full URL (scheme
     * optional) — to the identity persisted as payload.handle: either a bare
     * handle or, for /channel/ URLs, the UC… id itself. Both round-trip through
     * fetchRecentVideos(), which is what YoutubeFetch replays on every refresh.
     * Returns '' for anything that doesn't address a channel, so the caller
     * fails with the descriptor's "Enter your YouTube channel." rather than
     * sending garbage to the @handle resolver and reporting it as missing.
     */
    public function normalizeHandle(string $input): string
    {
        $s = PlatformInput::urlish($input);

        if (preg_match(self::NON_CHANNEL_PATH, $s)) {
            return '';
        }

        // /channel/UC… — YouTube's own "share channel" URL. The id IS the
        // identity here; there is no handle to recover from it without a fetch,
        // and channelIdFrom() short-circuits a raw id straight to the feed.
        if (preg_match('~youtube\.com/channel/(UC[A-Za-z0-9_-]{22})~i', $s, $m)) {
            return $m[1];
        }

        if (preg_match('~youtube\.com/(?:@|c/|user/)('.self::HANDLE_CHARS.')'.self::NOT_MID_IDENTITY.'~iu', $s, $m) === 1) {
            return $m[1];
        }

        // Legacy bare vanity ("youtube.com/MrBeast") — pre-@ custom URLs still
        // circulate and still resolve. Last, so every prefixed form above wins.
        if (preg_match('~youtube\.com/('.self::HANDLE_CHARS.')'.self::NOT_MID_IDENTITY.'~iu', $s, $m) === 1) {
            // This branch, and only this one, is where YouTube's own pages
            // masquerade as vanities — see RESERVED_SEGMENTS.
            return in_array(mb_strtolower($m[1]), self::RESERVED_SEGMENTS, true) ? '' : $m[1];
        }

        // Last resort: a bare handle typed with no host at all ("mrbeast",
        // "@MrBeast"). A token still carrying a path separator means nothing
        // above recognised the URL, and returning it invents an identity out of
        // a link. Both callers already discard a slash-bearing answer
        // (socialUsername, channelIdFrom); saying '' here makes every path give
        // that same answer, including YoutubeConnect::resolve(), which
        // otherwise spent a live channel-page fetch to learn it.
        $token = PlatformInput::token($s);

        return str_contains($token, '/') ? '' : $token;
    }

    /**
     * Resolve any channel reference to its UC… channel id: a raw id, a
     * /channel/UC… URL on youtube.com OR music.youtube.com (scheme optional),
     * or a handle/@handle/handle-URL (resolved via the channel page).
     */
    public function channelIdFrom(string $input): ?string
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~(?:^|/channel/|/browse/)(UC[A-Za-z0-9_-]{22})~', $s, $m)) {
            return $m[1];
        }

        $handle = $this->normalizeHandle($s);
        if ($handle === '' || str_contains($handle, '/') || str_contains($handle, '.com')) {
            return null;
        }

        return $this->resolveChannelId($handle, ['User-Agent' => self::USER_AGENT]);
    }

    /**
     * The channel's most-recent videos, newest first, up to $limit (the RSS
     * feed itself caps at 15). Returns null when the channel or feed can't be
     * resolved; an empty array is possible for a channel with no uploads.
     *
     * @return list<array{videoId:string, name:string, description:string, link:string, date:?string, thumbnail:string}>|null
     */
    public function fetchRecentVideos(string $handle, int $limit = 15): ?array
    {
        // Via channelIdFrom(), NOT the private handle-only resolver: the stored
        // identity may be a bare handle OR a UC… id (see normalizeHandle), and
        // only channelIdFrom() accepts both — a raw id short-circuits to the
        // feed with no channel-page fetch. Reaching past it is what made a
        // pasted /channel/UC… URL 404 while the same channel's @handle worked.
        $channelId = $this->channelIdFrom($handle);
        if ($channelId === null) {
            return null;
        }

        return $this->fetchUploadsFeed($channelId, $limit)['videos'] ?? null;
    }

    /**
     * The uploads feed for a known channel id: the feed-level channel title +
     * the most-recent videos (same shape as fetchRecentVideos). Null when the
     * feed can't be fetched AND the vendor fallback (vendorUploadsFeed) can't
     * rescue it; vendor-rescued entries additionally carry lengthSeconds.
     *
     * @return array{title: ?string, videos: list<array{videoId:string, name:string, description:string, link:string, date:?string, thumbnail:string}>}|null
     */
    public function fetchUploadsFeed(string $channelId, int $limit = 15, ?ConditionalContext $cond = null): ?array
    {
        // B3 (#W2-OBS-4): deliberately NOT escalated to report(). This runs per
        // channel, per refresh tick — the failure is already carried by the
        // REFRESH lane (YoutubeFetch/YoutubeMusicFetch convert a null feed to
        // FetchUnavailableException -> PlatformRefresher::recordFailure ->
        // consecutive_failures -> PlatformHealthNotifier::connectionRefreshFailing
        // at the breaker trip). Adding report() here would fire on every tick for
        // every dead channel, exactly the noise PlatformRefresher.php:84-88 rules
        // out. Do not re-file this without re-reading that ruling.
        $headers = array_merge(['User-Agent' => self::USER_AGENT], $cond?->headers() ?? []);

        // The channel feed (?channel_id=UC…) is the only uploads feed YouTube
        // still serves. This used to request the uploads-PLAYLIST feed
        // (?playlist_id=UU…, the channel id with "UC" swapped to "UU") because
        // it updates within minutes where the channel feed can lag hours on a
        // fresh upload; as of 2026-07 that feed 404s/500s for every channel, so
        // the freshness win no longer exists to trade for. Every connect
        // attempt failed with a resolved-but-unfetchable channel until this
        // swapped back. Do not "restore" the UU feed without re-checking it live.
        $feedUrl = 'https://www.youtube.com/feeds/videos.xml?channel_id='.$channelId;
        $rss = $this->fetcher->tryFetch($feedUrl, $headers);

        // M-13 (B7 live): the feed endpoint intermittently serves 404/500 for
        // channels that verifiably exist — measured live 2026-08-21: three
        // consecutive identical requests answered 500, 404, 200. On the
        // auto-route path a terminal miss permanently drops the channel
        // (ConnectFetchJob catches the exception, so job tries never engage,
        // and F26 removes the never-fetched row with nobody watching a modal
        // to retry). Up to four spaced re-requests before giving up — during
        // the measured incident roughly every second request failed, so five
        // attempts put recovery near-certain while three still lost the
        // channel twice in one afternoon. Bounded costs: total added sleep is
        // ~5s only when attempts keep FAILING (the interactive connect path —
        // youtube isn't in PARTNA_CONNECT_DEFERRED, so this can run
        // in-request — pays nothing on a first-try 200), and a permanently
        // dead channel stops being refreshed at the consecutive-failures
        // circuit breaker. Transport-level null deliberately does NOT retry:
        // SafeUrlFetcher's null covers SSRF/DNS/timeout, where an immediate
        // second attempt is noise. 304 is a healthy answer, not an error.
        foreach ([500_000, 1_000_000, 1_500_000, 2_000_000] as $delay) {
            if (! is_array($rss) || in_array($rss['status'], [200, 304], true)) {
                break;
            }
            usleep($delay);
            $rss = $this->fetcher->tryFetch($feedUrl, $headers) ?? $rss;
        }

        if ($rss === null) {
            // LIFE-26: transport-level failure (SSRF/timeout/DNS) reaching the feed.
            Log::warning('youtube.uploads_feed_failed', ['channelId' => $channelId, 'reason' => 'fetch_null']);

            return $this->vendorUploadsFeed($channelId, $limit);
        }
        // 304 Not Modified → let the caller (a fetch strategy) short-circuit. On a
        // 200, capture the fresh ETag/Last-Modified for next time. Normal on every
        // healthy poll — deliberately NOT logged (LIFE-26: would be pure noise).
        if ($cond !== null && $cond->handle($rss)) {
            return null;
        }
        if ($rss['status'] !== 200) {
            // LIFE-26: reachable but an explicit non-200 (e.g. 403/404/5xx).
            Log::warning('youtube.uploads_feed_failed', ['channelId' => $channelId, 'reason' => 'non_200:'.$rss['status']]);

            return $this->vendorUploadsFeed($channelId, $limit);
        }

        // Channel display name from the feed head (before any <entry>): the
        // feed-level <author><name> carries it verbatim; the feed's own <title>
        // is the fallback, stripped of the "Uploads from " prefix the retired
        // playlist feed used to carry.
        $head = explode('<entry>', $rss['body'], 2)[0];
        preg_match('~<author>\s*<name>([^<]*)</name>~', $head, $an);
        preg_match('~<title>([^<]*)</title>~', $head, $ft);
        $rawTitle = trim($an[1] ?? '') !== ''
            ? trim($an[1])
            : preg_replace('~^Uploads from\s+~i', '', trim($ft[1] ?? ''));
        $feedTitle = $rawTitle !== ''
            ? html_entity_decode($rawTitle, ENT_QUOTES | ENT_HTML5)
            : null;

        preg_match_all('~<entry>(.*?)</entry>~s', $rss['body'], $entries);

        $out = [];
        foreach ($entries[1] as $entry) {
            if (! preg_match('~<yt:videoId>([^<]+)</yt:videoId>~', $entry, $vm)) {
                continue;
            }
            $videoId = $vm[1];
            preg_match('~<title>([^<]+)</title>~', $entry, $tm);
            preg_match('~<media:description>(.*?)</media:description>~s', $entry, $dm);
            // Atom <published> is the upload timestamp (ISO8601) — carried through
            // so the sitepage can sort videos/releases chronologically.
            preg_match('~<published>([^<]+)</published>~', $entry, $pm);

            $out[] = [
                'videoId' => $videoId,
                'name' => html_entity_decode($tm[1] ?? '', ENT_QUOTES | ENT_HTML5),
                'description' => trim(html_entity_decode($dm[1] ?? '', ENT_QUOTES | ENT_HTML5)),
                'link' => "https://www.youtube.com/watch?v={$videoId}",
                'date' => trim($pm[1] ?? '') ?: null,
                // Filled in below from a single batched maxres-vs-hq probe.
                'thumbnail' => '',
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        // One batched probe for every video: maxresdefault.jpg (1280×720 16:9)
        // where available, hqdefault.jpg otherwise. Replaces a per-entry hq guess.
        $thumbnails = $this->thumbnails->bestForMany(array_column($out, 'videoId'));
        foreach ($out as &$entry) {
            // bestForMany() omits ids it rejects as malformed (and always has,
            // for empty ones) — a missing key is "no thumbnail", not a crash.
            $entry['thumbnail'] = $thumbnails[$entry['videoId']] ?? '';
        }
        unset($entry);

        return ['title' => $feedTitle, 'videos' => $out];
    }

    /**
     * Item 8 G3 (2026-09-01): YouTube is TIERED, not vendor-primary — the free
     * RSS feed stays the primary lane (a healthy poll makes ZERO vendor calls;
     * the two call sites above are failure exits only), and ScrapeCreators'
     * /v1/youtube/channel-videos (the HYPHEN route) answers only after the RSS
     * retry ladder exhausts — the live AWS-egress 404/500 class that used to
     * drop real channels. The vendor items are mapped into the exact shape the
     * RSS parse returns so callers cannot tell the lanes apart; lengthSeconds
     * rides along as pure enrichment (RSS has no duration — readers that don't
     * know the key ignore it). publishDate is the real upload date;
     * publishedTime is synthesized at scrape time (trial-verified: identical
     * across every item) and must never be read.
     *
     * Any vendor failure returns null — exactly what the caller was about to
     * receive — so this lane can only rescue, never degrade. Budget is claimed
     * before the call and released on transport-null; a billed husk (2xx body
     * with no usable videos) keeps the slot spent.
     *
     * @return array{title: ?string, videos: list<array{videoId:string, name:string, description:string, link:string, date:?string, thumbnail:string, lengthSeconds:?int}>}|null
     */
    private function vendorUploadsFeed(string $channelId, int $limit): ?array
    {
        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            return null;
        }
        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim('youtube')) {
            return null;
        }

        $body = $client->get('/v1/youtube/channel-videos', ['channelId' => $channelId, 'sort' => 'latest']);
        if ($body === null) {
            $budget->release('youtube');

            return null;
        }

        $videos = $body['videos'] ?? null;
        $out = [];
        $title = null;
        foreach (is_array($videos) ? $videos : [] as $video) {
            if (! is_array($video) || ! is_string($video['id'] ?? null) || $video['id'] === '') {
                continue;
            }
            $id = $video['id'];

            if ($title === null) {
                $channel = $video['channel'] ?? null;
                $channelTitle = is_array($channel) ? ($channel['title'] ?? null) : null;
                $title = is_string($channelTitle) && trim($channelTitle) !== '' ? $channelTitle : null;
            }

            $date = $video['publishDate'] ?? null;
            $out[] = [
                'videoId' => $id,
                'name' => is_string($video['title'] ?? null) ? $video['title'] : '',
                'description' => is_string($video['description'] ?? null) ? trim($video['description']) : '',
                'link' => "https://www.youtube.com/watch?v={$id}",
                'date' => is_string($date) && trim($date) !== '' ? $date : null,
                'thumbnail' => is_string($video['thumbnail'] ?? null) ? $video['thumbnail'] : '',
                'lengthSeconds' => is_int($video['lengthSeconds'] ?? null) ? $video['lengthSeconds'] : null,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        if ($out === []) {
            // Success-shaped husk (NotFound bills a credit as success:true) or
            // shape drift — either way the call was billed; slot stays spent.
            Log::info('scrapecreators.youtube.unusable_shape', ['channelId' => $channelId]);

            return null;
        }

        Log::info('youtube.uploads_feed_vendor_rescue', ['channelId' => $channelId, 'videos' => count($out)]);

        return ['title' => $title, 'videos' => $out];
    }

    /**
     * Item 11c (2026-09-01): the channel's Shorts shelf via the vendor's
     * /v1/youtube/channel/shorts (a SLASH route — unlike channel-videos this
     * one is NOT hyphenated; verified against the live OpenAPI spec). Vendor-
     * ONLY: YouTube serves no free feed for the shelf, so unlike
     * fetchUploadsFeed there is no lane to rescue — every failure of any kind
     * is null and the caller simply has no shorts this pass. Rows are the
     * exact vendorUploadsFeed shape (WATCH-pool vocabulary, Item 11c), so
     * readers cannot tell a short from a video.
     *
     * Its own budget source ('youtube_shorts', G2: every new source gets its
     * own cap from day one) so a chatty shorts lane can never starve the
     * RSS-rescue slots under 'youtube'. Claim before the call, release on
     * transport-null, slot stays spent on a billed husk.
     *
     * @return list<array{videoId: string, name: string, description: string, link: string, date: ?string, thumbnail: string, lengthSeconds: ?int}>|null
     */
    public function fetchShorts(string $handleOrId, int $limit = 15): ?array
    {
        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            return null;
        }
        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim('youtube_shorts')) {
            return null;
        }

        $body = $client->get(
            '/v1/youtube/channel/shorts',
            $this->vendorChannelParam($handleOrId) + ['sort' => 'newest'],
        );
        if ($body === null) {
            $budget->release('youtube_shorts');

            return null;
        }

        $rows = app(YoutubeShortsNormalizer::class)->rows($body);
        if ($rows === null) {
            // Success-shaped husk (NotFound bills a credit as success:true) or
            // shape drift — either way the call was billed; slot stays spent.
            Log::info('scrapecreators.youtube_shorts.unusable_shape', ['channel' => $handleOrId]);

            return null;
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * Item 11c (2026-09-01): the channel's Live tab via the vendor's
     * /v1/youtube/channel/lives — a LIVE-STATUS input, never pool content.
     * The result's isLive bool is the normalized read Item 11d's
     * CheckStreamingLiveStatusJob consolidation consumes; nothing here polls.
     * Same lossy vendor discipline as fetchShorts, on its own budget source
     * ('youtube_lives' — a status poller's cadence must never spend the
     * shorts or RSS-rescue slots). Null means "vendor miss / status unknown",
     * never "offline": isLive false is only ever asserted off a populated
     * Live tab with nothing live (the normalizer's contract).
     *
     * @return array{isLive: bool, lives: list<array{videoId: string, name: string, link: string, thumbnail: ?string, isLive: bool, watching: ?int, lengthSeconds: ?int}>}|null
     */
    public function fetchLives(string $handleOrId): ?array
    {
        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            return null;
        }
        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim('youtube_lives')) {
            return null;
        }

        $body = $client->get('/v1/youtube/channel/lives', $this->vendorChannelParam($handleOrId));
        if ($body === null) {
            $budget->release('youtube_lives');

            return null;
        }

        $result = app(YoutubeLivesNormalizer::class)->normalize($body);
        if ($result === null) {
            Log::info('scrapecreators.youtube_lives.unusable_shape', ['channel' => $handleOrId]);

            return null;
        }

        return $result;
    }

    /**
     * The shorts/lives endpoints accept a UC… id or a bare handle, on
     * DIFFERENT query params — routing the stored identity (which is either,
     * see normalizeHandle) to the right one skips the channel-page resolution
     * fetch a channelIdFrom() round-trip would spend.
     *
     * @return array{channelId: string}|array{handle: string}
     */
    private function vendorChannelParam(string $handleOrId): array
    {
        return preg_match('/^UC[A-Za-z0-9_-]{22}$/', $handleOrId) === 1
            ? ['channelId' => $handleOrId]
            : ['handle' => ltrim($handleOrId, '@')];
    }

    /**
     * The channel's own identity off its page: the canonical UC… id and, where
     * the page carries one, its avatar (the largest `avatar.thumbnails` URL —
     * YouTube lists them ascending). One fetch; the connect strategy reads
     * both so the stored connection can show the channel's face rather than
     * its latest video's frame (owner, 2026-08-23). Null when the page can't
     * be fetched or holds no id — logged like resolveChannelId().
     *
     * @return array{id: string, avatar: ?string}|null
     */
    public function fetchChannelProfile(string $handle): ?array
    {
        $page = $this->fetchChannelPage($handle, ['User-Agent' => self::USER_AGENT]);
        if ($page === null) {
            return null;
        }
        $id = $this->channelIdFromPage($page, $handle);
        if ($id === null) {
            return null;
        }

        $avatar = null;
        if (preg_match('~"avatar":\{"thumbnails":\[(.*?)\]~s', $page, $block)
            && preg_match_all('~"url":"(https://[^"]+)"~', $block[1], $urls)) {
            $avatar = str_replace('\/', '/', end($urls[1]));
        }

        return ['id' => $id, 'avatar' => $avatar];
    }

    // Channel page → the channel's OWN canonical ID. A channel page lists several
    // "channelId" values (featured/related channels, video owners) and the first
    // is NOT reliably the channel itself — so prefer "externalId" / the canonical
    // /channel/<id> URL (both the page owner's ID). Falls back to the first
    // channelId only if neither is present. (Fixes @casey → wrong side-channel.)
    private function resolveChannelId(string $handle, array $headers): ?string
    {
        // T2 addendum / D9 (2026-08-27): the official Data API resolves a
        // handle with no bot-walls — the scrape leg lost three channels to
        // intermittent challenges on the 2026-08-27 test builds. Config-gated:
        // activates the moment YOUTUBE_DATA_API_KEY lands; the page scrape
        // stays as the keyless path and the fallback.
        $api = $this->apiChannelId($handle);
        if ($api !== null) {
            return $api;
        }

        $page = $this->fetchChannelPage($handle, $headers);

        return $page === null ? null : $this->channelIdFromPage($page, $handle);
    }

    /** Data API handle→channel-id, or null (no key, quota, miss, error). */
    private function apiChannelId(string $handle): ?string
    {
        $key = (string) config('services.youtube.data_api_key');
        if ($key === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->get('https://www.googleapis.com/youtube/v3/channels', [
                    'part' => 'id',
                    'forHandle' => $handle,
                    'key' => $key,
                ]);
        } catch (\Throwable $e) {
            Log::warning('youtube.api_resolve_threw', ['handle' => $handle, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            $status = $response->status();
            Log::warning('youtube.api_resolve_not_ok', ['handle' => $handle, 'status' => $status]);

            // B3 (#W2-OBS-4): 401/403 means the shared YOUTUBE_DATA_API_KEY is
            // rejected — the scrape leg silently absorbs it forever otherwise.
            // 404/429/5xx stay Log::warning-only; the scrape fallback covers them.
            if (in_array($status, [401, 403], true)) {
                ThrottledReport::once(
                    "youtube:data_api_fault:{$status}",
                    new VendorAccountFaultException('youtube_data_api', 'key_rejected', $status),
                );
            }

            return null;
        }

        $id = $response->json('items.0.id');

        return is_string($id) && preg_match('/^UC[A-Za-z0-9_-]{22}$/', $id) === 1 ? $id : null;
    }

    /** The channel page's body on a 200; null (logged) otherwise. */
    private function fetchChannelPage(string $handle, array $headers): ?string
    {
        $page = $this->fetcher->tryFetch('https://www.youtube.com/@'.rawurlencode($handle), $headers);
        if ($page === null || $page['status'] !== 200) {
            // LIFE-25: distinguish a transport failure (SSRF/timeout/DNS — $page
            // null) from an explicit non-200 so the two show up differently in logs.
            Log::warning('youtube.channel_resolve_failed', [
                'handle' => $handle,
                'reason' => $page === null ? 'fetch_failed' : 'non_200:'.$page['status'],
            ]);

            return null;
        }

        return $page['body'];
    }

    private function channelIdFromPage(string $body, string $handle): ?string
    {
        if (! preg_match('/"externalId":"(UC[A-Za-z0-9_-]{22})"/', $body, $m)
            && ! preg_match('~/channel/(UC[A-Za-z0-9_-]{22})~', $body, $m)
            && ! preg_match('/"channelId":"(UC[A-Za-z0-9_-]{22})"/', $body, $m)) {
            // LIFE-25: page fetched fine (200) but none of the three id patterns
            // matched — a page-layout change, not a transport failure.
            Log::warning('youtube.channel_resolve_failed', [
                'handle' => $handle,
                'reason' => 'no_channel_id_match',
            ]);

            return null;
        }

        return $m[1];
    }
}
