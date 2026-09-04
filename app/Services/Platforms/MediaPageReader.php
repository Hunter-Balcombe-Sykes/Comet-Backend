<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;

// The watch/listen twin of EventPageReader (events-parity follow-up,
// 2026-08-20): one URL grammar deciding ITEM vs ACCOUNT per media platform,
// and one reader turning an item URL into the fields a pool item renders —
// oEmbed first (no keys: YouTube, Vimeo, Spotify, SoundCloud, Mixcloud all
// serve it; SpotifyTracksConnector already reads its endpoint), OG tags as
// the fallback for hosts without one (Twitch, Apple Music, Bandcamp, Tidal).
//
// CANONICAL FORM IS THE CONTRACT. Identity folding is canonical-URL equality
// lowercased and nothing else (KeyClass::canonicalise), so each platform's
// canonical here must be byte-identical to what its CONNECTOR writes into
// f_link.url — youtube `https://www.youtube.com/watch?v={id}`
// (YoutubeFeed.php), vimeo `https://vimeo.com/{id}` (VimeoConnector.php),
// youtube-music `https://music.youtube.com/watch?v={id}`. That equality is
// what makes pasting youtu.be/X beside a synced watch?v=X fold into ONE item
// instead of minting a duplicate.
class MediaPageReader extends PlatformScraper
{
    /** platform → oEmbed endpoint (the url param is appended). */
    private const OEMBED = [
        'youtube' => 'https://www.youtube.com/oembed?format=json&url=',
        'youtube-music' => 'https://www.youtube.com/oembed?format=json&url=',
        'vimeo' => 'https://vimeo.com/api/oembed.json?url=',
        'spotify' => 'https://open.spotify.com/oembed?url=',
        'soundcloud' => 'https://soundcloud.com/oembed?format=json&url=',
        'mixcloud' => 'https://app.mixcloud.com/oembed/?format=json&url=',
        // Public, unauthenticated, and returns the spec's title/thumbnail_url/
        // author_url — so the generic parser below needs nothing special for
        // it. Verified live 2026-09-03.
        'tiktok' => 'https://www.tiktok.com/oembed?url=',
        // Spec-standard title/thumbnail_url/author_url, zero special-casing
        // needed — author_url is the free parent-account suggestion.
        // Verified live 2026-09-04.
        'audiomack' => 'https://audiomack.com/oembed?format=json&url=',
        // Keyless, spec-standard. Returns author_name but NOT author_url —
        // no free authorUrl derivation here, unlike audiomack/dailymotion/
        // rumble. Verified live 2026-09-04.
        'deezer' => 'https://api.deezer.com/oembed?format=json&url=',
        // Spec-standard fields including author_url — no special-casing, no
        // derivedAuthorUrl arm needed. NO OpenGraph exists on this platform
        // at all (confirmed by full HTML grep), so this is the only
        // enrichment path. Verified live 2026-09-04.
        'dailymotion' => 'https://www.dailymotion.com/services/oembed?url=',
        // Discovery tag confirmed present, hands back author_url for free.
        // Verified live 2026-09-04.
        'rumble' => 'https://rumble.com/api/Media/oembed.json?url=',
    ];

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * Read one media-item page: grammar first (pure), then oEmbed/OG for the
     * words and the cover. Null when the URL is not a recognised item shape
     * or the page gave us no title — a rich item without a real title is
     * worse than the card fallback.
     *
     * `authorUrl` (T9b, 2026-08-20) is the item's PARENT account when the
     * platform hands it to us for free — oEmbed's author_url (YouTube,
     * Vimeo, SoundCloud, Mixcloud), Bandcamp's artist subdomain, a Twitch
     * clip's channel path — so the caller can file a connect SUGGESTION
     * beside the item. Null when the platform doesn't say.
     *
     * @return array{platform: string, kind: string, canonical: string, title: string, thumbnail: ?string, authorUrl: ?string}|null
     */
    public function read(string $url): ?array
    {
        $item = $this->classifyItem($url);
        if ($item === null) {
            return null;
        }

        $meta = $this->oembed($item['platform'], $item['canonical'])
            ?? $this->openGraph($item['canonical']);
        if ($meta === null || $meta['title'] === null || trim($meta['title']) === '') {
            return null;
        }

        if ($this->isSiteChrome($item['platform'], $meta['title'])) {
            return null;
        }

        return [
            'platform' => $item['platform'],
            'kind' => $item['kind'],
            'canonical' => $item['canonical'],
            'title' => trim($meta['title']),
            'thumbnail' => $meta['thumbnail'],
            'authorUrl' => $meta['authorUrl'] ?? $this->derivedAuthorUrl($item),
        ];
    }

    /**
     * The name each platform calls itself in a page title. Keyed by the
     * classifyItem() platform so the leading-segment rule below can ask about
     * THIS page's own site and no other.
     *
     * @var array<string, list<string>>
     */
    private const SITE_NAMES = [
        'apple-music' => ['apple music'],
        'apple-podcast' => ['apple podcasts'],
        'audiomack' => ['audiomack'],
        'bandcamp' => ['bandcamp'],
        'beatport' => ['beatport'],
        'dailymotion' => ['dailymotion'],
        'deezer' => ['deezer'],
        'feature_fm' => ['feature.fm', 'ffm.to'],
        'hypeddit' => ['hypeddit'],
        'laylo' => ['laylo'],
        'linkfire' => ['linkfire', 'lnk.to'],
        'mixcloud' => ['mixcloud'],
        'rumble' => ['rumble'],
        'soundcloud' => ['soundcloud'],
        'spotify' => ['spotify'],
        'tidal' => ['tidal'],
        'tiktok' => ['tiktok'],
        'twitch' => ['twitch'],
        'vimeo' => ['vimeo'],
        'youtube' => ['youtube'],
        'youtube-music' => ['youtube music', 'youtube'],
    ];

    /**
     * Is this title the SITE talking about itself rather than an item?
     *
     * A dead or JS-walled page unfurls with the site's own name (a nonexistent
     * Twitch VOD answers "Twitch"). That is a failed read, not a title — the
     * card fallback is honest, an item called "Twitch" is not.
     *
     * Two rules, because the sites do it two ways:
     *
     *  1. EXACT match against any platform's name, kept global — the original
     *      rule, widened only by the nine brands added 2026-08-28..09-04, which
     *      it had never learned. A title that is precisely a platform's name is
     *      chrome whichever page it came from.
     *  2. LEADING SEGMENT equal to THIS platform's own name. Audiomack's dead
     *      pages answer "Audiomack - Music platform empowering artists & fans |
     *      Audiomack" — decorated, so rule 1 slid straight past it (live-verified
     *      2026-09-04: a nonexistent song minted a pool item with that as its
     *      headline, onto the public sitepage).
     *
     * Rule 2 is scoped to the page's OWN platform on purpose. Globally it would
     * reject a real YouTube video titled "Spotify - Wrapped 2025", which is an
     * ordinary title. And it reads the LEADING segment only, never a trailing
     * one: Beatport's genuine titles end "… | Music & Downloads on Beatport"
     * (live-verified), so a suffix rule would delete every real Beatport track.
     */
    private function isSiteChrome(string $platform, string $title): bool
    {
        $t = strtolower(trim($title));

        $all = array_unique(array_merge(...array_values(self::SITE_NAMES)));
        if (in_array($t, $all, true)) {
            return true;
        }

        $lead = trim(explode('|', $t)[0]);
        $lead = trim(explode(' - ', $lead)[0]);

        return in_array($lead, self::SITE_NAMES[$platform] ?? [], true);
    }

    /**
     * The parent-account URL platforms encode in the item URL itself —
     * no request needed. Bandcamp's artist IS the subdomain; a Twitch
     * clip's path carries the channel login.
     *
     * @param  array{platform: string, kind: string, canonical: string}  $item
     */
    private function derivedAuthorUrl(array $item): ?string
    {
        if ($item['platform'] === 'bandcamp') {
            $host = strtolower((string) parse_url($item['canonical'], PHP_URL_HOST));

            return $host === '' ? null : 'https://'.$host.'/';
        }
        if ($item['platform'] === 'twitch'
            && preg_match('~^https://www\.twitch\.tv/([A-Za-z0-9_]+)/clip/~', $item['canonical'], $m)) {
            return 'https://www.twitch.tv/'.$m[1];
        }

        // FI-4 (2026-08-20): Spotify's oEmbed carries NO author_url — which is
        // why the sammy.pdf baseline seeded the track but never suggested the
        // artist. The public EMBED page does carry it (10KB, no auth): the
        // first-billed artist/ href for a track, the show/ href for an
        // episode. One extra capped fetch, only for spotify items. NOT albums
        // (critic pass 2, verified live on two real releases): the album
        // embed renders no artist link at all, so that arm would only ever
        // spend a fetch to return null.
        if ($item['platform'] === 'spotify'
            && preg_match('~^https://open\.spotify\.com/(track|episode)/([A-Za-z0-9]{10,30})$~', $item['canonical'], $m)) {
            $res = $this->fetcher->tryFetch("https://open.spotify.com/embed/{$m[1]}/{$m[2]}", ['User-Agent' => self::USER_AGENT]);
            if ($res !== null && $res['status'] === 200) {
                if (preg_match('~artist/([A-Za-z0-9]{10,30})~', (string) $res['body'], $a)) {
                    return 'https://open.spotify.com/artist/'.$a[1];
                }
                if (preg_match('~show/([A-Za-z0-9]{10,30})~', (string) $res['body'], $a)) {
                    return 'https://open.spotify.com/show/'.$a[1];
                }
            }

            return null;
        }

        // FI-4: Apple Music album/song pages are SSR'd and link their artist
        // page in the markup — no oEmbed exists for them at all.
        if ($item['platform'] === 'apple-music') {
            $res = $this->fetcher->tryFetch($item['canonical'], ['User-Agent' => self::USER_AGENT]);
            if ($res !== null && $res['status'] === 200
                && preg_match('~https://music\.apple\.com/[a-z]{2}/artist/[a-z0-9%.-]+/(\d+)~i', (string) $res['body'], $a)) {
                return $a[0];
            }

            return null;
        }

        return null;
    }

    /**
     * The pure grammar: which item (if any) this URL names, and its
     * canonical form. Account/profile shapes and unknown hosts answer null.
     *
     * @return array{platform: string, kind: string, canonical: string}|null
     */
    public function classifyItem(string $url): ?array
    {
        [$host, $path, $query] = $this->parts($url);
        if ($host === null) {
            return null;
        }

        // ── YouTube (video) + YouTube Music (track) ──────────────────────
        if (in_array($host, ['youtube.com', 'm.youtube.com'], true)) {
            if (preg_match('~^/(?:watch)$~', $path) && preg_match('~^[A-Za-z0-9_-]{6,20}$~', $query['v'] ?? '')) {
                return ['platform' => 'youtube', 'kind' => 'video', 'canonical' => 'https://www.youtube.com/watch?v='.$query['v']];
            }
            if (preg_match('~^/(?:shorts|live|embed)/([A-Za-z0-9_-]{6,20})$~', $path, $m)) {
                return ['platform' => 'youtube', 'kind' => 'video', 'canonical' => 'https://www.youtube.com/watch?v='.$m[1]];
            }

            return null;
        }
        if ($host === 'youtu.be' && preg_match('~^/([A-Za-z0-9_-]{6,20})$~', $path, $m)) {
            return ['platform' => 'youtube', 'kind' => 'video', 'canonical' => 'https://www.youtube.com/watch?v='.$m[1]];
        }
        if ($host === 'music.youtube.com' && $path === '/watch' && preg_match('~^[A-Za-z0-9_-]{6,20}$~', $query['v'] ?? '')) {
            return ['platform' => 'youtube-music', 'kind' => 'track', 'canonical' => 'https://music.youtube.com/watch?v='.$query['v']];
        }

        // ── Vimeo: /{digits} is a video; anything wordy is a profile ─────
        if ($host === 'vimeo.com' && preg_match('~^/(\d{6,12})(?:/[a-f0-9]+)?$~', $path, $m)) {
            return ['platform' => 'vimeo', 'kind' => 'video', 'canonical' => 'https://vimeo.com/'.$m[1]];
        }

        // ── Twitch VODs + clips (no synced connector — canonical is the
        //    cleaned URL itself, there is nothing to fold with) ────────────
        if ($host === 'twitch.tv') {
            if (preg_match('~^/videos/(\d+)$~', $path, $m)) {
                return ['platform' => 'twitch', 'kind' => 'video', 'canonical' => 'https://www.twitch.tv/videos/'.$m[1]];
            }
            if (preg_match('~^/[A-Za-z0-9_]+/clip/([A-Za-z0-9_-]+)$~', $path)) {
                return ['platform' => 'twitch', 'kind' => 'video', 'canonical' => 'https://www.twitch.tv'.$path];
            }

            return null;
        }
        if ($host === 'clips.twitch.tv' && preg_match('~^/([A-Za-z0-9_-]+)$~', $path)) {
            return ['platform' => 'twitch', 'kind' => 'video', 'canonical' => 'https://clips.twitch.tv'.$path];
        }

        // ── TikTok: /@handle/video/<digits> is one video ─────────────────
        // The handle is part of the canonical URL, not decoration — TikTok
        // 404s /video/<id> on its own. Lowercased like the SoundCloud and
        // Bandcamp arms because the canonical is what the identity spine
        // folds on, and TikTok treats the handle case-insensitively.
        //
        // The vm.tiktok.com / tiktok.com/t/<code> share forms are DELIBERATELY
        // absent: they carry no video id, only a redirect token, and resolving
        // one costs a network call. classifyItem() is pure grammar and never
        // fetches — see the method docblock.
        if (in_array($host, ['tiktok.com', 'm.tiktok.com'], true)
            && preg_match('~^/@([\w.]{1,24})/video/(\d{6,25})$~', $path, $m)) {
            return ['platform' => 'tiktok', 'kind' => 'video', 'canonical' => 'https://www.tiktok.com/@'.strtolower($m[1]).'/video/'.$m[2]];
        }

        // ── Spotify: /track|/album|/episode (optional /intl-xx prefix) ───
        if ($host === 'open.spotify.com' && preg_match('~^(?:/intl-[a-z]{2,5})?/(track|album|episode)/([A-Za-z0-9]{10,30})$~', $path, $m)) {
            $kind = ['track' => 'track', 'album' => 'release', 'episode' => 'episode'][$m[1]];

            return ['platform' => 'spotify', 'kind' => $kind, 'canonical' => "https://open.spotify.com/{$m[1]}/{$m[2]}"];
        }

        // ── SoundCloud: /{user}/{slug} is a track; one segment is a profile.
        //    Reserved second segments are profile chrome, and /sets/ is a
        //    playlist — neither is a single track. ─────────────────────────
        if ($host === 'soundcloud.com' && preg_match('~^/([a-z0-9_-]+)/([a-z0-9_-]+)$~i', $path, $m)) {
            $reserved = ['tracks', 'albums', 'sets', 'reposts', 'likes', 'followers', 'following', 'comments', 'popular-tracks'];
            if (! in_array(strtolower($m[2]), $reserved, true)) {
                return ['platform' => 'soundcloud', 'kind' => 'track', 'canonical' => 'https://soundcloud.com/'.strtolower($m[1]).'/'.strtolower($m[2])];
            }

            return null;
        }

        // ── Mixcloud: /{user}/{show}/ is a show; /{user}/ is a profile ───
        if ($host === 'mixcloud.com' && preg_match('~^/([A-Za-z0-9_-]+)/([A-Za-z0-9_%-]+)/?$~', $path, $m)) {
            $reserved = ['uploads', 'reposts', 'favorites', 'listens', 'stream', 'about', 'playlists', 'hosts'];
            if (! in_array(strtolower($m[2]), $reserved, true)) {
                return ['platform' => 'mixcloud', 'kind' => 'track', 'canonical' => "https://www.mixcloud.com/{$m[1]}/{$m[2]}/"];
            }

            return null;
        }

        // ── Apple Music: ?i= names a song on an album page; /song/ directly;
        //    an album URL without ?i= is a release ─────────────────────────
        if ($host === 'music.apple.com') {
            // Locale segment OPTIONAL (L-2, 2026-08-20): locale-less
            // music.apple.com/album|song URLs are real share shapes, and
            // requiring /xx/ here while the artist detector accepts both left
            // them claimed by NEITHER arm — mis-filed as custom links.
            if (preg_match('~^(?:/[a-z]{2})?/album/[^/]+/(\d+)$~', $path, $m)) {
                if (preg_match('~^\d+$~', $query['i'] ?? '')) {
                    return ['platform' => 'apple-music', 'kind' => 'track', 'canonical' => "https://music.apple.com{$path}?i={$query['i']}"];
                }

                return ['platform' => 'apple-music', 'kind' => 'release', 'canonical' => 'https://music.apple.com'.$path];
            }
            if (preg_match('~^(?:/[a-z]{2})?/song/[^/]+/\d+$~', $path)) {
                return ['platform' => 'apple-music', 'kind' => 'track', 'canonical' => 'https://music.apple.com'.$path];
            }

            return null;
        }

        // ── Apple Podcasts: ?i= names one episode; the show page without it
        //    is the podcast itself (the connectable thing) ─────────────────
        if ($host === 'podcasts.apple.com' && preg_match('~^/[a-z]{2}/podcast/[^/]+/id\d+$~', $path) && preg_match('~^\d+$~', $query['i'] ?? '')) {
            return ['platform' => 'apple-podcast', 'kind' => 'episode', 'canonical' => "https://podcasts.apple.com{$path}?i={$query['i']}"];
        }

        // ── Bandcamp: /track/ and /album/ on an artist subdomain ─────────
        if (str_ends_with($host, '.bandcamp.com')) {
            if (preg_match('~^/track/[a-z0-9-]+$~i', $path)) {
                return ['platform' => 'bandcamp', 'kind' => 'track', 'canonical' => 'https://'.$host.strtolower($path)];
            }
            if (preg_match('~^/album/[a-z0-9-]+$~i', $path)) {
                return ['platform' => 'bandcamp', 'kind' => 'release', 'canonical' => 'https://'.$host.strtolower($path)];
            }

            return null;
        }

        // ── Tidal ────────────────────────────────────────────────────────
        if (in_array($host, ['tidal.com', 'listen.tidal.com'], true)) {
            if (preg_match('~^(?:/browse)?/track/(\d+)~', $path, $m)) {
                return ['platform' => 'tidal', 'kind' => 'track', 'canonical' => 'https://tidal.com/track/'.$m[1]];
            }
            if (preg_match('~^(?:/browse)?/album/(\d+)~', $path, $m)) {
                return ['platform' => 'tidal', 'kind' => 'release', 'canonical' => 'https://tidal.com/album/'.$m[1]];
            }

            return null;
        }

        // ── Audiomack: /{username}/song/{slug} is a track, /{username}/album/
        //    {slug} a release. /{username}/playlist/{slug} is deliberately
        //    NOT matched here (excluded, like Spotify's playlist) — it just
        //    falls through to null since neither arm below matches it.
        //    Canonical keeps the ORIGINAL request path segments verbatim
        //    (not oEmbed's echoed url/author_url, which can differ for a
        //    renamed/aliased uploader account — live-observed 2026-09-04). ──
        if ($host === 'audiomack.com') {
            if (preg_match('~^/([\w.-]+)/song/([\w-]+)$~', $path, $m)) {
                return ['platform' => 'audiomack', 'kind' => 'track', 'canonical' => 'https://audiomack.com/'.$m[1].'/song/'.$m[2]];
            }
            if (preg_match('~^/([\w.-]+)/album/([\w-]+)$~', $path, $m)) {
                return ['platform' => 'audiomack', 'kind' => 'release', 'canonical' => 'https://audiomack.com/'.$m[1].'/album/'.$m[2]];
            }

            return null;
        }

        // ── Beatport: /track/{slug}/{id} is a track, /release/{slug}/{id} a
        //    release. No oEmbed exists (missing discovery tag + a live 404
        //    on a same-origin oembed probe, 2026-09-04) — deliberately absent
        //    from OEMBED, so read() falls through to the OpenGraph fallback
        //    automatically. ───────────────────────────────────────────────
        if ($host === 'beatport.com') {
            if (preg_match('~^/track/[a-z0-9-]+/\d+$~', $path)) {
                return ['platform' => 'beatport', 'kind' => 'track', 'canonical' => 'https://beatport.com'.$path];
            }
            if (preg_match('~^/release/[a-z0-9-]+/\d+$~', $path)) {
                return ['platform' => 'beatport', 'kind' => 'release', 'canonical' => 'https://beatport.com'.$path];
            }

            return null;
        }

        // ── Deezer: /track/{id} is a track, /album/{id} a release; /playlist/
        //    {id} is a collection (not one item), mapped to accountPlatform
        //    Label() below the same way Spotify sends playlist to its own
        //    label. Optional 2-letter locale prefix (/en/, /us/), stripped
        //    for the canonical. Host is always www.deezer.com, which parts()
        //    already reduces to deezer.com like every other www.-prefixed
        //    host here. ────────────────────────────────────────────────────
        if ($host === 'deezer.com') {
            if (preg_match('~^(?:/[a-z]{2})?/track/(\d+)$~', $path, $m)) {
                return ['platform' => 'deezer', 'kind' => 'track', 'canonical' => 'https://www.deezer.com/track/'.$m[1]];
            }
            if (preg_match('~^(?:/[a-z]{2})?/album/(\d+)$~', $path, $m)) {
                return ['platform' => 'deezer', 'kind' => 'release', 'canonical' => 'https://www.deezer.com/album/'.$m[1]];
            }

            return null;
        }

        // ── Dailymotion: /video/{id} on dailymotion.com, or a bare {id} on
        //    the dai.ly short host (youtu.be-style) — both fold onto the
        //    same canonical. NO OpenGraph exists on this platform at all
        //    (confirmed by a full HTML grep, 2026-09-04) — oEmbed is the
        //    only enrichment path, not a fallback of last resort. ─────────
        if ($host === 'dailymotion.com' && preg_match('~^/video/([A-Za-z0-9]{6,8})$~', $path, $m)) {
            return ['platform' => 'dailymotion', 'kind' => 'video', 'canonical' => 'https://www.dailymotion.com/video/'.$m[1]];
        }
        if ($host === 'dai.ly' && preg_match('~^/([A-Za-z0-9]{6,8})$~', $path, $m)) {
            return ['platform' => 'dailymotion', 'kind' => 'video', 'canonical' => 'https://www.dailymotion.com/video/'.$m[1]];
        }

        // ── Rumble: /v{id}-{slug}.html is a video. The slug is kept AS
        //    GIVEN — a bare id-only URL 404s (live-verified 2026-09-04), so
        //    this is not a shape to normalise down. No www variant seen. ──
        if ($host === 'rumble.com' && preg_match('~^/v[A-Za-z0-9]+-[A-Za-z0-9%.-]+\.html$~', $path)) {
            return ['platform' => 'rumble', 'kind' => 'video', 'canonical' => 'https://rumble.com'.$path];
        }

        // ── Feature.fm: a HOST-based grammar, not a path one — the real item
        //    host is ffm.to (or a branded *.ffm.to subdomain), ffm.bio is the
        //    account host; feature.fm itself is marketing-only and hosts no
        //    item pages. No reliable kind signal in the URL, so 'track' is
        //    the default (same reasoning as Linkfire below). A real oEmbed
        //    discovery tag exists but its endpoint 503'd on every live call
        //    (2026-09-04) — deliberately NOT in OEMBED; the OpenGraph
        //    fallback fires automatically since the platform is absent from
        //    it. ────────────────────────────────────────────────────────────
        if (($host === 'ffm.to' || str_ends_with($host, '.ffm.to')) && preg_match('~^/([A-Za-z0-9_-]+)$~', $path, $m)) {
            return ['platform' => 'feature_fm', 'kind' => 'track', 'canonical' => 'https://ffm.to/'.strtolower($m[1])];
        }

        // ── Hypeddit: no oEmbed. OpenGraph fallback (confirmed present: a
        //    "Free Download …: Artist - Track" og:title, og:image,
        //    og:site_name). There is NO public account/profile page shape at
        //    all — stripping the second segment off a real 2-segment item
        //    URL 404s (live-verified 2026-09-04) — so this platform
        //    deliberately has NO accountPlatformLabel() arm. ────────────────
        if ($host === 'hypeddit.com') {
            if (preg_match('~^/track/([A-Za-z0-9]{6})$~', $path, $m)) {
                return ['platform' => 'hypeddit', 'kind' => 'track', 'canonical' => 'https://hypeddit.com/track/'.$m[1]];
            }
            if (preg_match('~^/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)$~', $path, $m)) {
                return ['platform' => 'hypeddit', 'kind' => 'track', 'canonical' => 'https://hypeddit.com/'.$m[1].'/'.$m[2]];
            }
            if (preg_match('~^/([A-Za-z0-9_-]+)$~', $path, $m)) {
                $reserved = ['music', 'newreleases', 'pricing', 'login', 'auth', 'news', 'privacy'];
                if (! in_array(strtolower($m[1]), $reserved, true)) {
                    return ['platform' => 'hypeddit', 'kind' => 'track', 'canonical' => 'https://hypeddit.com/'.$m[1]];
                }
            }

            return null;
        }

        // ── Laylo: grammar-only — no oEmbed, and OpenGraph is served only to
        //    allowlisted crawler user agents (a plain browser UA gets a bare
        //    client-side SPA shell with zero OG tags, confirmed by a
        //    UA-switching test 2026-09-04), so this platform's read() call
        //    falls through to the card default same as any platform whose
        //    enrichment genuinely has nothing — expected, not a bug. 1
        //    segment is an account (below); 2 segments is a drop/item; 3
        //    segments with the literal segment "m" is a multidrop/tour
        //    CONTAINER (excluded, not one item); 3 segments otherwise is a
        //    single item nested under a multidrop. ──────────────────────────
        if ($host === 'laylo.com') {
            if (preg_match('~^/([\w-]+)/([\w-]+)/([\w-]+)$~', $path, $m)) {
                if ($m[2] !== 'm') {
                    return ['platform' => 'laylo', 'kind' => 'track', 'canonical' => 'https://laylo.com/'.$m[1].'/'.$m[2].'/'.$m[3]];
                }

                return null;
            }
            if (preg_match('~^/([\w-]+)/([\w-]+)$~', $path, $m)) {
                return ['platform' => 'laylo', 'kind' => 'track', 'canonical' => 'https://laylo.com/'.$m[1].'/'.$m[2]];
            }

            return null;
        }

        // ── Linkfire: the path shape is IDENTICAL for item and account (a
        //    single opaque segment on both) — the HOST is the only
        //    distinguishing signal. lnk.to / a branded *.lnk.to subdomain /
        //    lnkfi.re is an item; bio.to is an account (below). No oEmbed
        //    (confirmed absent via a full HTML grep on 5 real pages, no
        //    discovery tag). No reliable kind signal, so 'track' is the
        //    default (same reasoning as Feature.fm above). lnk.to/pricing is
        //    Linkfire's own marketing page, excluded from the item shape. ──
        if (($host === 'lnk.to' || str_ends_with($host, '.lnk.to') || $host === 'lnkfi.re')
            && preg_match('~^/([A-Za-z0-9_-]+)$~', $path, $m)) {
            if (strtolower($m[1]) !== 'pricing') {
                return ['platform' => 'linkfire', 'kind' => 'track', 'canonical' => 'https://'.$host.'/'.strtolower($m[1])];
            }
        }

        return null;
    }

    /**
     * The platform label when the URL is a PROFILE/CHANNEL page of a media
     * platform — the caller should send the owner to the connect flow rather
     * than filing an account as an "item". Pure, no fetch. The arms
     * themselves now live in accountPlatform(); this just unwraps its label.
     */
    public function accountPlatformLabel(string $url): ?string
    {
        return $this->accountPlatform($url)['label'] ?? null;
    }

    /**
     * The platform (slug + label) when the URL is a PROFILE/CHANNEL page of a
     * media platform — the caller should send the owner to the connect flow
     * rather than filing an account as an "item". Pure, no fetch.
     *
     * @return array{platform: string, label: string}|null
     */
    public function accountPlatform(string $url): ?array
    {
        // An ITEM claim always wins: some platforms name an item with a query
        // param on an account-shaped path (Apple Podcasts' show/id…?i=episode),
        // and the arms below only read the path.
        if ($this->classifyItem($url) !== null) {
            return null;
        }

        [$host, $path] = $this->parts($url);
        if ($host === null) {
            return null;
        }

        if (in_array($host, ['youtube.com', 'm.youtube.com'], true)
            && preg_match('~^/(@[\w.-]+|channel/[\w-]+|c/[\w.-]+|user/[\w.-]+)/?$~', $path)) {
            return ['platform' => 'youtube', 'label' => 'YouTube'];
        }
        if ($host === 'vimeo.com' && preg_match('~^/[a-z][\w-]*/?$~i', $path)
            && ! preg_match('~^/(channels|ondemand|categories|features|upgrade|log_in|join|watch|site_map|about|blog|help|stock|create|solutions|enterprise)\b~i', $path)) {
            return ['platform' => 'vimeo', 'label' => 'Vimeo'];
        }
        if ($host === 'twitch.tv' && preg_match('~^/[A-Za-z0-9_]{3,25}/?$~', $path)
            && ! preg_match('~^/(videos|directory|downloads|jobs|turbo|settings|subscriptions|wallet|drops|search|p)\b~i', $path)) {
            return ['platform' => 'twitch', 'label' => 'Twitch'];
        }
        // A podcast SHOW is its own brand, and must be named before the
        // generic Spotify arm below or it inherits the wrong one. The catalog
        // has carried `spotify_podcasts` (Brand 'Spotify Podcasts', surface
        // `spotify_podcasts.show`, RoutingClass::Content, Shelf::Podcast,
        // connectable, path-qualified to /show/<id>) as a brand DISTINCT from
        // `spotify.player` since 2026-09-01. The label picked here is the one
        // PoolItemCreateController puts in front of the user — "Connect
        // {$account} as a platform to bring its content in automatically" —
        // so returning 'Spotify' for a show sent them to connect the music
        // player, which does not bring a show's episodes in. Named by the W9
        // completeness critic (2026-09-04) as plan §1b's one unresolved item.
        if ($host === 'open.spotify.com' && preg_match('~^(?:/intl-[a-z]{2,5})?/show/~', $path)) {
            return ['platform' => 'spotify_podcasts', 'label' => 'Spotify Podcasts'];
        }
        // `playlist` joined artist/user on 2026-09-03: a playlist is not
        // one track, so the Listen pool must refuse it — and the advice that
        // hangs off this label ("connect Spotify to bring its content in, or
        // paste one track's link") is exactly right for a playlist. Without a
        // label it fell through to the generic "not a track" error, which
        // tells the person nothing about what to do instead.
        if ($host === 'open.spotify.com' && preg_match('~^(?:/intl-[a-z]{2,5})?/(artist|user|playlist)/~', $path)) {
            return ['platform' => 'spotify', 'label' => 'Spotify'];
        }
        if (in_array($host, ['tiktok.com', 'm.tiktok.com'], true) && preg_match('~^/@[\w.]{1,24}/?$~', $path)) {
            return ['platform' => 'tiktok', 'label' => 'TikTok'];
        }
        if ($host === 'soundcloud.com' && preg_match('~^/[a-z0-9_-]+/?$~i', $path)
            && ! preg_match('~^/(discover|search|upload|stream|library|charts|feed|you|messages|notifications|settings|pro|premium|mobile|imprint|terms-of-use|jobs|blog|pages)\b~i', $path)) {
            return ['platform' => 'soundcloud', 'label' => 'SoundCloud'];
        }
        if ($host === 'mixcloud.com' && preg_match('~^/[A-Za-z0-9_-]+/?$~', $path)
            && ! preg_match('~^/(discover|upload|live|pro|premium|select|about|jobs|competitions|categories|search)\b~i', $path)) {
            return ['platform' => 'mixcloud', 'label' => 'Mixcloud'];
        }
        // Locale optional (L-2) — the catalog detector already accepts both.
        if ($host === 'music.apple.com' && preg_match('~^(?:/[a-z]{2})?/artist/~', $path)) {
            return ['platform' => 'apple-music', 'label' => 'Apple Music'];
        }
        if ($host === 'podcasts.apple.com' && preg_match('~^/[a-z]{2}/podcast/[^/]+/id\d+$~', $path)) {
            // The show page WITHOUT ?i= — classifyItem() claims it first when
            // an episode is named, so reaching here means the show itself.
            return ['platform' => 'apple-podcast', 'label' => 'Apple Podcasts'];
        }
        if (str_ends_with($host, '.bandcamp.com') && ($path === '/' || $path === '' || preg_match('~^/(music|merch|community)/?$~', $path))) {
            return ['platform' => 'bandcamp', 'label' => 'Bandcamp'];
        }
        if (in_array($host, ['tidal.com', 'listen.tidal.com'], true) && preg_match('~^(?:/browse)?/artist/~', $path)) {
            return ['platform' => 'tidal', 'label' => 'Tidal'];
        }
        if ($host === 'music.youtube.com'
            && preg_match('~^/(@[\w.-]+|channel/[\w-]+|c/[\w.-]+|user/[\w.-]+)/?$~', $path)) {
            return ['platform' => 'youtube-music', 'label' => 'YouTube Music'];
        }
        if ($host === 'audiomack.com' && preg_match('~^/([\w.-]+)/?$~', $path, $m)) {
            $reserved = ['trending-now', 'top', 'albums', 'songs', 'songalbum', 'album', 'download', 'search', 'upload'];
            if (! in_array(strtolower($m[1]), $reserved, true)) {
                return ['platform' => 'audiomack', 'label' => 'Audiomack'];
            }
        }
        if ($host === 'beatport.com'
            && (preg_match('~^/artist/[a-z0-9-]+/\d+~', $path) || preg_match('~^/label/[a-z0-9-]+/\d+~', $path))) {
            return ['platform' => 'beatport', 'label' => 'Beatport'];
        }
        // /playlist/{id} joins /artist/{id} here (not classifyItem) the same
        // way Spotify's playlist does — a collection, not one track.
        if ($host === 'deezer.com'
            && (preg_match('~^(?:/[a-z]{2})?/artist/\d+~', $path) || preg_match('~^(?:/[a-z]{2})?/playlist/\d+~', $path))) {
            return ['platform' => 'deezer', 'label' => 'Deezer'];
        }
        if ($host === 'dailymotion.com' && preg_match('~^/([A-Za-z0-9_-]+)/?$~', $path, $m)) {
            $reserved = [
                'video', 'playlist', 'live', 'news', 'search', 'channel', 'pricing',
                'partner', 'gaming', 'login', 'signup', 'about', 'press', 'legal', 'help',
            ];
            if (! in_array(strtolower($m[1]), $reserved, true)) {
                return ['platform' => 'dailymotion', 'label' => 'Dailymotion'];
            }
        }
        if ($host === 'rumble.com' && preg_match('~^/(?:user|c)/[A-Za-z0-9_-]+/?$~', $path)) {
            return ['platform' => 'rumble', 'label' => 'Rumble'];
        }
        if (($host === 'ffm.bio' || str_ends_with($host, '.ffm.bio')) && preg_match('~^/[A-Za-z0-9_-]+/?$~', $path)) {
            return ['platform' => 'feature_fm', 'label' => 'Feature.fm'];
        }
        // Hypeddit has NO accountPlatformLabel arm, deliberately: there is no
        // public account/profile page shape at all — stripping the second
        // segment off a real 2-segment item URL 404s (live-verified
        // 2026-09-04). Every hypeddit path either classifies as an item
        // above or is a marketing/reserved route neither arm should label.
        if ($host === 'laylo.com' && preg_match('~^/([\w-]+)/?$~', $path, $m)) {
            $reserved = ['music', 'join', 'pricing', 'blog', 'dashboard', 'auth'];
            if (! in_array(strtolower($m[1]), $reserved, true)) {
                return ['platform' => 'laylo', 'label' => 'Laylo'];
            }
        }
        if (($host === 'bio.to' || str_ends_with($host, '.bio.to')) && preg_match('~^/[A-Za-z0-9_-]+/?$~', $path)) {
            return ['platform' => 'linkfire', 'label' => 'Linkfire'];
        }

        return null;
    }

    /** @return array{0: ?string, 1: string, 2: array<string, string>} host (www-stripped, lowercased), path, query map */
    private function parts(string $url): array
    {
        $url = PlatformInput::urlish($url);
        if (! preg_match('~^https?://~i', $url)) {
            return [null, '', []];
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return [null, '', []];
        }
        $host = preg_replace('~^www\.~', '', $host) ?? $host;
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/') ?: '/';

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $query = array_filter($query, 'is_string');

        // $path is already `?: '/'` above, so it can never be '' here.
        return [$host, $path, $query];
    }

    /** @return array{title: ?string, thumbnail: ?string, authorUrl: ?string}|null null when the endpoint gave nothing usable */
    private function oembed(string $platform, string $canonical): ?array
    {
        $endpoint = self::OEMBED[$platform] ?? null;
        if ($endpoint === null) {
            return null;
        }

        $res = $this->fetcher->tryFetch($endpoint.urlencode($canonical), ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json']);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        $json = json_decode((string) $res['body'], true);
        if (! is_array($json)) {
            return null;
        }

        $title = is_string($json['title'] ?? null) ? $json['title'] : null;
        // Mixcloud serves the cover as `image`, not the spec's thumbnail_url.
        $thumb = is_string($json['thumbnail_url'] ?? null) ? $json['thumbnail_url']
            : (is_string($json['image'] ?? null) ? $json['image'] : null);
        // The item's parent account, when oEmbed carries it (T9b).
        $author = is_string($json['author_url'] ?? null) && $json['author_url'] !== ''
            ? $json['author_url'] : null;

        return $title === null && $thumb === null ? null : ['title' => $title, 'thumbnail' => $thumb, 'authorUrl' => $author];
    }

    /** @return array{title: ?string, thumbnail: ?string}|null */
    private function openGraph(string $canonical): ?array
    {
        $res = $this->fetcher->tryFetch($canonical, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        $title = $this->metaContent($res['body'], 'og:title');
        $thumb = $this->metaContent($res['body'], 'og:image');

        return $title === null && $thumb === null ? null : ['title' => $title, 'thumbnail' => $thumb];
    }
}
