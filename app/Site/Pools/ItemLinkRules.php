<?php

namespace App\Site\Pools;

use Illuminate\Support\Facades\DB;

/**
 * What a hand-saved per-item platform link may be (owner, 2026-08-05):
 *
 * - The platform must belong to the item's POOL roster — the flexible
 *   "add a link" control offers the pool's platforms, not a free-text key,
 *   so future platforms are a roster line, never a schema change.
 * - ALTERNATES ONLY: a platform that already contributes a synced source
 *   link to this item is refused — the synced link is not manually
 *   editable, so it can never drift from the sync.
 * - The URL must live on the platform's own domain(s) — the same intent as
 *   the connect flows' normalisers, enforced simply by host suffix.
 */
class ItemLinkRules
{
    /** @var array<string, list<string>> pool → the platforms a link may name */
    public const ROSTER = [
        'watch' => ['youtube', 'vimeo', 'twitch'],
        'listen' => [
            'spotify', 'soundcloud', 'mixcloud', 'tidal',
            'apple-music', 'apple-podcast', 'youtube-music', 'bandcamp',
        ],
        'media' => ['instagram'],
        'events' => ['eventbrite', 'humanitix'],
        // Menus (overnight 2026-08-18, W5): a dish carries one link per
        // ordering platform — the store page on each. Synced links come from
        // the dish's offers (each offer knows the store url it was scraped
        // from); the owner may add a platform the scrape did not.
        'menus' => ['uber-eats', 'doordash', 'menulog', 'square-online', 'bopple', 'mr-yum'],
    ];

    /** @var array<string, list<string>> platform → accepted host suffixes */
    private const HOSTS = [
        'youtube' => ['youtube.com', 'youtu.be'],
        'vimeo' => ['vimeo.com'],
        'twitch' => ['twitch.tv'],
        'spotify' => ['open.spotify.com', 'spotify.link'],
        'soundcloud' => ['soundcloud.com', 'on.soundcloud.com'],
        'mixcloud' => ['mixcloud.com'],
        'tidal' => ['tidal.com', 'listen.tidal.com'],
        'apple-music' => ['music.apple.com'],
        'apple-podcast' => ['podcasts.apple.com'],
        'youtube-music' => ['music.youtube.com'],
        'bandcamp' => ['bandcamp.com'],
        'instagram' => ['instagram.com'],
        // Eventbrite runs ~15 country domains (.co.uk, .ca, .ie, .nz, .com.br…);
        // only the two the AU/US pilot needs are listed, so a link on another
        // one is refused. Add the TLD when a user turns up on it. Humanitix
        // serves events off a subdomain, which the suffix arm below covers.
        'eventbrite' => ['eventbrite.com', 'eventbrite.com.au'],
        'uber-eats' => ['ubereats.com'],
        'doordash' => ['doordash.com'],
        'menulog' => ['menulog.com.au'],
        'square-online' => ['square.site', 'squareup.com'],
        'bopple' => ['bopple.app', 'bopple.me', 'bopple.com'],
        'mr-yum' => ['mryum.com'],
        'humanitix' => ['humanitix.com'],
    ];

    /** @return list<string> */
    public static function rosterFor(string $pool): array
    {
        return self::ROSTER[$pool] ?? [];
    }

    public static function allowsPlatform(string $pool, string $platform): bool
    {
        return in_array($platform, self::rosterFor($pool), true);
    }

    /**
     * The platforms already contributing SYNCED links to this item — the set
     * the manual control must refuse.
     *
     * #LIFE-8: scoped to LIVE connections. Without that, a connection the owner
     * removed still counted as "already synced", so the manual control refused
     * to let them hand-add a replacement link for a platform that had stopped
     * publishing anything — the owner was locked out of the surface by the very
     * disconnection meant to hand it back to them.
     *
     * The inner join on platform_connections already means "connection-backed",
     * so constrainToLiveSource()'s manual arm is unreachable here; it is used
     * anyway rather than hand-copying the deleted_at/is_active pair, because a
     * fourth private copy of that predicate is how #LIFE-2 and #LIFE-4 happened.
     *
     * @return list<string>
     */
    public static function syncedPlatformsFor(string $itemId): array
    {
        $query = DB::connection('pgsql')->table('content.f_link')
            ->join('content.sources', 'content.sources.id', '=', 'content.f_link.source_id')
            ->join('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
            ->where('content.f_link.item_id', $itemId);

        LiveSourceScope::constrainToLiveSource($query);

        return $query
            ->distinct()
            ->pluck('site.platform_connections.platform')
            ->map(fn ($p) => (string) $p)
            ->all();
    }

    /** Host check: the URL must sit on the platform's own domain(s). */
    /** The roster platform a URL belongs to (by host suffix), or null. */
    public static function platformForUrl(string $url): ?string
    {
        foreach (array_keys(self::HOSTS) as $platform) {
            if (self::urlBelongsTo($platform, $url)) {
                return $platform;
            }
        }

        return null;
    }

    public static function urlBelongsTo(string $platform, string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        foreach (self::HOSTS[$platform] ?? [] as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
