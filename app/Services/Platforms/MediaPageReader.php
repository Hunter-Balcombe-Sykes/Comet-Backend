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

        // A dead or JS-walled page unfurls with the SITE's own name as its
        // og:title (a nonexistent Twitch VOD answers "Twitch") — that is a
        // failed read, not a title. The card fallback is honest; an item
        // called "Twitch" is not.
        if (in_array(strtolower(trim($meta['title'])), [
            'twitch', 'tidal', 'youtube', 'vimeo', 'spotify', 'soundcloud',
            'mixcloud', 'bandcamp', 'apple music', 'apple podcasts',
        ], true)) {
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
            if (preg_match('~^/[a-z]{2}/album/[^/]+/(\d+)$~', $path, $m)) {
                if (preg_match('~^\d+$~', $query['i'] ?? '')) {
                    return ['platform' => 'apple-music', 'kind' => 'track', 'canonical' => "https://music.apple.com{$path}?i={$query['i']}"];
                }

                return ['platform' => 'apple-music', 'kind' => 'release', 'canonical' => 'https://music.apple.com'.$path];
            }
            if (preg_match('~^/[a-z]{2}/song/[^/]+/\d+$~', $path)) {
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

        return null;
    }

    /**
     * The platform label when the URL is a PROFILE/CHANNEL page of a media
     * platform — the caller should send the owner to the connect flow rather
     * than filing an account as an "item". Pure, no fetch.
     */
    public function accountPlatformLabel(string $url): ?string
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
            return 'YouTube';
        }
        if ($host === 'vimeo.com' && preg_match('~^/[a-z][\w-]*/?$~i', $path)
            && ! preg_match('~^/(channels|ondemand|categories|features|upgrade|log_in|join|watch|site_map|about|blog|help|stock|create|solutions|enterprise)\b~i', $path)) {
            return 'Vimeo';
        }
        if ($host === 'twitch.tv' && preg_match('~^/[A-Za-z0-9_]{3,25}/?$~', $path)
            && ! preg_match('~^/(videos|directory|downloads|jobs|turbo|settings|subscriptions|wallet|drops|search|p)\b~i', $path)) {
            return 'Twitch';
        }
        if ($host === 'open.spotify.com' && preg_match('~^(?:/intl-[a-z]{2,5})?/(artist|show|user)/~', $path)) {
            return 'Spotify';
        }
        if ($host === 'soundcloud.com' && preg_match('~^/[a-z0-9_-]+/?$~i', $path)
            && ! preg_match('~^/(discover|search|upload|stream|library|charts|feed|you|messages|notifications|settings|pro|premium|mobile|imprint|terms-of-use|jobs|blog|pages)\b~i', $path)) {
            return 'SoundCloud';
        }
        if ($host === 'mixcloud.com' && preg_match('~^/[A-Za-z0-9_-]+/?$~', $path)
            && ! preg_match('~^/(discover|upload|live|pro|premium|select|about|jobs|competitions|categories|search)\b~i', $path)) {
            return 'Mixcloud';
        }
        if ($host === 'music.apple.com' && preg_match('~^/[a-z]{2}/artist/~', $path)) {
            return 'Apple Music';
        }
        if ($host === 'podcasts.apple.com' && preg_match('~^/[a-z]{2}/podcast/[^/]+/id\d+$~', $path)) {
            // The show page WITHOUT ?i= — classifyItem() claims it first when
            // an episode is named, so reaching here means the show itself.
            return 'Apple Podcasts';
        }
        if (str_ends_with($host, '.bandcamp.com') && ($path === '/' || $path === '' || preg_match('~^/(music|merch|community)/?$~', $path))) {
            return 'Bandcamp';
        }
        if (in_array($host, ['tidal.com', 'listen.tidal.com'], true) && preg_match('~^(?:/browse)?/artist/~', $path)) {
            return 'Tidal';
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
