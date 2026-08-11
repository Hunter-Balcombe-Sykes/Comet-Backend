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
     * @return list<string>
     */
    public static function syncedPlatformsFor(string $itemId): array
    {
        return DB::connection('pgsql')->table('content.f_link')
            ->join('content.sources', 'content.sources.id', '=', 'content.f_link.source_id')
            ->join('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
            ->where('content.f_link.item_id', $itemId)
            ->distinct()
            ->pluck('site.platform_connections.platform')
            ->map(fn ($p) => (string) $p)
            ->all();
    }

    /** Host check: the URL must sit on the platform's own domain(s). */
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
