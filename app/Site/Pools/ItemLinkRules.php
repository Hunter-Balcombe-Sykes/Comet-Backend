<?php

namespace App\Site\Pools;

use App\Catalog\Definitions\Eventbrite;
use App\Catalog\Definitions\Ticketek;
use App\Catalog\Definitions\Ticketmaster;
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
        // TikTok joined 2026-09-03: its /@handle/video/<id> shape is a real
        // `video` kind in MediaPageReader::classifyItem(), so the pool can
        // hold one — and a roster that omitted it would refuse the hand-added
        // alternate link for an item the paste lane happily creates.
        // dailymotion/rumble joined 2026-09-04 overnight run (§1C/W4)
        // alongside their MediaPageReader::classifyItem() grammar.
        'watch' => ['youtube', 'vimeo', 'twitch', 'tiktok', 'dailymotion', 'rumble'],
        'listen' => [
            'spotify', 'soundcloud', 'mixcloud', 'tidal',
            'apple-music', 'apple-podcast', 'youtube-music', 'bandcamp',
            // beatport/hypeddit/audiomack/deezer/feature_fm/laylo/linkfire
            // joined 2026-09-04 overnight run (§1C/W4) alongside their
            // MediaPageReader::classifyItem() grammar — beatport/hypeddit
            // were caught by ItemUrlCorpusTest's cross-file roster check;
            // the rest added the same pass to close the same gap for every
            // platform that pass gave classifyItem() coverage to.
            'beatport', 'hypeddit', 'audiomack', 'deezer', 'feature_fm',
            'laylo', 'linkfire',
        ],
        'media' => ['instagram'],
        // No entry for 'shop', 'services' or 'custom_links' — not an
        // oversight. 'custom_links' is a link kind end-to-end, already
        // documented at PastedLinkClassifier.php:90 ("a card is its
        // product") — there is no separate platform for a link card to
        // alternate onto. For 'shop'/'services': a shop item's canonical
        // source is the connected store and a service item is
        // owner-authored, so an "alternate platform link" per item isn't a
        // coherent concept the way it is for a video's alternate host.
        // Assessed during the 2026-09-04 overnight run (§1E) and closed
        // WONTFIX, rather than left as an apparent gap for the next reader
        // to re-investigate.
        // Events-parity (2026-08-19): every events brand the platform knows,
        // not just the two with bespoke scrapers — an event item may carry a
        // hand-added ticket link on any of them. Extended 2026-09-04
        // overnight run (§1A/W2): 14 more catalog events brands — admitone
        // through tixr below — were missing from this roster even though
        // EventPageReader's JSON-LD read is host-agnostic and already ready
        // for all of them.
        'events' => [
            'eventbrite', 'humanitix', 'luma', 'partiful', 'ticketmaster',
            'ticketek', 'oztix', 'trybooking', 'resident-advisor',
            'admitone', 'bandsintown', 'dice', 'etix', 'eventfinda',
            'eventim', 'megatix', 'moshtix', 'see_tickets', 'skiddle',
            'songkick', 'tickethype', 'ticketweb', 'tixr',
        ],
        // Menus (overnight 2026-08-18, W5): a dish carries one link per
        // ordering platform — the store page on each. Synced links come from
        // the dish's offers (each offer knows the store url it was scraped
        // from); the owner may add a platform the scrape did not.
        'menus' => ['uber-eats', 'doordash', 'menulog', 'square-online', 'bopple', 'mr-yum'],
    ];

    /**
     * platform → accepted host suffixes. Multi-TLD brands are NOT listed here
     * — hostsFor() builds theirs from the catalog definitions' TLDS consts,
     * the single source of truth (events-parity 2026-08-19; the hand-copied
     * eventbrite pair here had drifted to 2 of the brand's 25 TLDs).
     *
     * @var array<string, list<string>>
     */
    private const HOSTS = [
        'youtube' => ['youtube.com', 'youtu.be'],
        'vimeo' => ['vimeo.com'],
        'twitch' => ['twitch.tv'],
        'tiktok' => ['tiktok.com'],
        'spotify' => ['open.spotify.com', 'spotify.link'],
        'soundcloud' => ['soundcloud.com', 'on.soundcloud.com'],
        'mixcloud' => ['mixcloud.com'],
        'tidal' => ['tidal.com', 'listen.tidal.com'],
        'apple-music' => ['music.apple.com'],
        'apple-podcast' => ['podcasts.apple.com'],
        'youtube-music' => ['music.youtube.com'],
        'bandcamp' => ['bandcamp.com'],
        'instagram' => ['instagram.com'],
        'uber-eats' => ['ubereats.com'],
        'doordash' => ['doordash.com'],
        'menulog' => ['menulog.com.au'],
        'square-online' => ['square.site', 'squareup.com'],
        'bopple' => ['bopple.app', 'bopple.me', 'bopple.com'],
        'mr-yum' => ['mryum.com'],
        // Humanitix serves events off a subdomain, which the suffix arm in
        // urlBelongsTo() covers.
        'humanitix' => ['humanitix.com'],
        // luma.com is the rebrand target lu.ma 301s onto (2026-08-19).
        'luma' => ['lu.ma', 'luma.com'],
        'partiful' => ['partiful.com'],
        'oztix' => ['oztix.com.au'],
        'trybooking' => ['trybooking.com'],
        'resident-advisor' => ['ra.co', 'residentadvisor.net'],
        // 14 events brands added 2026-09-04 overnight run (§1A/W2) to close
        // the roster gap — hosts taken from each brand's own catalog
        // Detector::url() calls (app/Catalog/Definitions/<Name>.php), none
        // of which carry a TLDS const, so none get a hostsFor() match arm;
        // eventfinda/eventim/ticketweb are the multi-host brands among them.
        'admitone' => ['admitone.com', 'admitonelive.com'],
        'bandsintown' => ['bandsintown.com'],
        'dice' => ['dice.fm'],
        'etix' => ['etix.com'],
        'eventfinda' => ['eventfinda.com.au', 'eventfinda.co.nz', 'eventfinda.com'],
        'eventim' => [
            'eventim.de', 'eventim.com', 'eventim.co.uk',
            'eventim.fr', 'eventim.nl', 'eventim.pl',
        ],
        'megatix' => ['megatix.com.au'],
        'moshtix' => ['moshtix.com.au'],
        'see_tickets' => ['seetickets.com'],
        'skiddle' => ['skiddle.com'],
        'songkick' => ['songkick.com'],
        'tickethype' => ['tickethype.com.mt'],
        'ticketweb' => ['ticketweb.com', 'ticketweb.co.uk', 'ticketweb.ca'],
        'tixr' => ['tixr.com'],
        // 7 more item-URL-grammar brands added the same run (§1C/W4), hosts
        // taken straight from MediaPageReader::classifyItem()'s new arms —
        // feature_fm/linkfire are host-keyed (item host differs from their
        // account host, both listed here since a hand-added link only ever
        // needs the item form).
        'audiomack' => ['audiomack.com'],
        'beatport' => ['beatport.com'],
        'deezer' => ['deezer.com'],
        'dailymotion' => ['dailymotion.com', 'dai.ly'],
        'rumble' => ['rumble.com'],
        'feature_fm' => ['ffm.to'],
        'hypeddit' => ['hypeddit.com'],
        'laylo' => ['laylo.com'],
        'linkfire' => ['lnk.to', 'lnkfi.re'],
    ];

    /**
     * The accepted host suffixes for a platform — static pairs from HOSTS,
     * multi-TLD brands expanded from their catalog definition's TLDS const.
     *
     * @return list<string>
     */
    private static function hostsFor(string $platform): array
    {
        return match ($platform) {
            'eventbrite' => array_map(static fn (string $t): string => "eventbrite.{$t}", Eventbrite::TLDS),
            'ticketmaster' => array_map(static fn (string $t): string => "ticketmaster.{$t}", Ticketmaster::TLDS),
            'ticketek' => array_map(static fn (string $t): string => "ticketek.{$t}", Ticketek::TLDS),
            default => self::HOSTS[$platform] ?? [],
        };
    }

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
     * #FU-2: $userId is REQUIRED, not optional. This query has no outer items
     * row to correlate to (LiveSourceScope::apply()'s answer to the same
     * problem), and an optional pin is one a caller forgets — so the tenancy
     * value is a parameter PHP will not let a call site omit. It travels the
     * same two FK hops as the pool reads: f_link.source_id -> content.sources,
     * then the NULLABLE sources.connection_id -> site.platform_connections.
     * Both joins here are INNER by construction, so a plain `where` is correct
     * — unlike PoolResolver's LEFT joins, where a `where` would collapse the
     * join and drop the manual lane. A manual source (connection_id NULL) never
     * reaches this query at all, which is the behaviour it already had.
     *
     * @param  string  $userId  the item's owner; the caller has already proved
     *                          ownership of $itemId to obtain it
     * @return list<string>
     */
    public static function syncedPlatformsFor(string $userId, string $itemId): array
    {
        $query = DB::connection('pgsql')->table('content.f_link')
            ->join('content.sources', 'content.sources.id', '=', 'content.f_link.source_id')
            ->join('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
            ->where('content.f_link.item_id', $itemId)
            ->where('content.sources.user_id', $userId)
            ->where('site.platform_connections.user_id', $userId);

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
        $platforms = [...array_keys(self::HOSTS), 'eventbrite', 'ticketmaster', 'ticketek'];
        foreach ($platforms as $platform) {
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

        foreach (self::hostsFor($platform) as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
