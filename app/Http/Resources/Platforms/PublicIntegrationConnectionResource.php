<?php

namespace App\Http\Resources\Platforms;

use App\Exceptions\Platforms\MissingPublicAllowlistException;
use App\Http\Resources\ApiResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\DisplaySettingsFilter;
use App\Services\Platforms\ThirdPartyPii;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public, CDN-cached shape of one `site.platform_connections` row, served by
 * PublicIntegrationController to unauthenticated sitepage visitors.
 *
 * The stored `payload` JSONB is NOT passed through verbatim (CONS-11). Each
 * platform's public fields are allowlisted below — this `toArray()` is the
 * canonical public contract. Anything not listed (internal storage paths like
 * Instagram's `_folder`, future scraper metadata) never reaches the wire until
 * a developer deliberately adds it here.
 *
 * @mixin IntegrationConnection
 */
class PublicIntegrationConnectionResource extends ApiResource
{
    /**
     * Per-platform allowlist of payload keys exposed publicly. Faithful to the
     * current public contract — i.e. exactly what each platform stores today,
     * minus internal keys (e.g. Instagram's `_folder`, added by CONS-21).
     */
    // EVENT_PLATFORMS + STANDALONE_EVENT_KEYS were deleted with the standalone
    // branch of filterPayload() (slice 7 Phase 4, parent §7 step 3). Standalone
    // events reach the wire through `profile.pools.events` now, bounded by
    // PoolResolver::ITEM_KEYS rather than by a per-platform key list here.

    private const ALLOWLIST = [
        'instagram' => ['username', 'fullName', 'profilePicUrl', 'businessCategory', 'followersCount', 'postsCount', 'mode', 'images', 'videoUrl', 'videoPoster', 'imagesDropped'],
        'youtube' => ['handle', 'name', 'description', 'link', 'thumbnail', 'latest'],
        'apple-music' => ['input', 'name', 'thumbnail', 'releaseDate', 'link', 'latest'],
        'apple-podcast' => ['input', 'name', 'thumbnail', 'description', 'releaseDate', 'link', 'latest'],
        // RETIRED 2026-08-12 (slice 2, Task 9). The events platforms are now
        // DASHBOARD-ONLY: events reach the public wire through
        // `profile.pools.events` instead, which serves the same events from
        // content.items with occurrence/place/price facets the legacy shape
        // could not carry, plus slug/aliases from content.item_slugs.
        //
        // `=> []` rather than deletion, deliberately. The platforms are still
        // REGISTERED (via their binding classes in Registry/Bindings —
        // EventbriteBinding/HumanitixBinding since the PD retirement) because the
        // dashboard connect/refresh lane still uses them, and
        // PublicAllowlistCoverageTest requires every registered platform to
        // carry an entry — deleting the keys would report a
        // MissingPublicAllowlistException to Nightwatch on every public
        // request. An empty allowlist is that test's sanctioned form for a
        // dashboard-only platform, same as booking/reservations.
        //
        // Owners who hid events keep them hidden: the hides were carried into
        // section_items excludes by content:migrate-hidden-events.
        //
        // 2026-08-16 (slice 7 Phase 4): this now covers BOTH row kinds. The
        // standalone exception in filterPayload() is gone — those events are
        // content.items in the events pool now, so nothing on this wire
        // publishes an event any more.
        'eventbrite' => [],
        'humanitix' => [],
        // custom: one row per user-attached link.
        'facebook' => ['username', 'url'],
        'tiktok' => ['username', 'url'],
        'x' => ['username', 'url'],
        'linkedin' => ['username', 'url'],
        'threads' => ['username', 'url'],
        'reddit' => ['username', 'url'],
        // 2026-07-23 link-only additions (TEST-3 fix). Discord's `username`
        // carries the invite code, not a handle — see DiscordNormalizer.
        'snapchat' => ['username', 'url'],
        'discord' => ['username', 'url'],
        'telegram' => ['username', 'url'],
        'kick' => ['username', 'url'],
        'medium' => ['username', 'url'],
        // 2026-07-25 link classification consolidation, Phase 2. Same
        // {username, url} contract as every other PD::linkOnly social above —
        // LinkRouter seeds them with exactly that shape via resolveWrite(). These
        // were registered in the registry WITHOUT an allowlist entry, which this
        // map fails closed on: each would have rendered as {} on every public
        // sitepage AND reported MissingPublicAllowlistException to Nightwatch on
        // every request. PublicAllowlistCoverageTest is the guard that caught it.
        'whatsapp' => ['username', 'url'],
        'substack' => ['username', 'url'],
        'patreon' => ['username', 'url'],
        'ko-fi' => ['username', 'url'],
        'buymeacoffee' => ['username', 'url'],
        'github' => ['username', 'url'],
        'gitlab' => ['username', 'url'],
        'codepen' => ['username', 'url'],
        'dribbble' => ['username', 'url'],
        'behance' => ['username', 'url'],
        'gumroad' => ['username', 'url'],
        // `selection` LEFT the public allowlist 2026-08-19: it carried the whole
        // scraped service list (and, in team mode, the chosen staff member) —
        // the sitepage reads services from `pools.services`, never from here.
        'fresha' => ['url'],
        'spotify' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        'soundcloud' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        // mixcloud + tidal share MusicEmbedConnectionResource — same five-key contract.
        'mixcloud' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        'tidal' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        // square: a single user-pasted booking URL. No scraping — only `url` is stored.
        'square' => ['url'],
        // bandcamp: which releases appear is the Listen pool's selection now —
        // the stored `releases` grid stays off the wire.
        'bandcamp' => ['url', 'artist', 'name', 'thumbnail', 'link', 'latest'],
        'vimeo' => ['url', 'name', 'thumbnail', 'link', 'latest', 'items'],
        // youtube-music: channelId (the re-fetch input) stays private.
        'youtube-music' => ['url', 'name', 'thumbnail', 'link', 'latest', 'items'],
        // 2026-08-16: demoted to link-only, finishing Phase 1.2. They published
        // scraped card decoration — name, image, description, follower/member
        // counts — which nothing refreshes any more now the fetch strategies
        // and scrapers are gone. Publishing a stale name forever is worse than
        // publishing the link, so they take the same {username, url} contract
        // as every other link-only platform above. Existing payloads are
        // normalised to that shape by `platforms:backfill-link-payloads`.
        'twitch' => ['username', 'url'],
        'skool' => ['username', 'url'],
        'strava' => ['username', 'url'],
        // google-business: placeId / phoneIntl / priceLevel / priceRange /
        // detailsFetchedAt stay private. photos now public for home bg.
        //
        // RETIRED 2026-08-13 (slice 6): `reviews`, `reviewSummary`, `rating`
        // and `reviewCount` left this list. Individual reviews reach the
        // sitepage through `profile.pools.reviews`, served from
        // content.f_review — which redaction, PruneOrphanedReviewPiiCommand and
        // DSAR all govern, unlike this payload copy — and the aggregates
        // through content.source_stats. Unlike the events and shop
        // retirements, the platform KEEPS a non-empty entry: everything else
        // on this lane (hours, address, links, photos, amenities) still
        // publishes from here.
        //
        // The FETCH is unchanged. GoogleBusinessService still populates all
        // four keys and GoogleBusinessConnectionResource (dashboard) still
        // reads them, so nothing about billing or the owner's review toggle
        // moves. This retires a read, not a fetch.
        'google-business' => ['url', 'name', 'address', 'lat', 'lng', 'businessStatus', 'category', 'phone', 'website', 'hours', 'links', 'editorialSummary', 'amenities', 'photos'],
        // opentable: the reservation-widget embed is public by design (it's a
        // keyless booking widget). rid/url are public widget params, not secrets.
        'opentable' => ['url', 'rid', 'name', 'embedUrl'],
        // resdiary / nowbookit: keyless reservation-widget embeds, public by
        // design (same contract as opentable — widget params, not secrets).
        'resdiary' => ['url', 'microsite', 'name', 'embedUrl'],
        'nowbookit' => ['url', 'accountId', 'venueId', 'name', 'embedUrl'],
        // Dashboard-only category platforms — booking/reservations hold a custom
        // fallback entry. Neither renders on the public sitepage, so they expose
        // NOTHING (empty list → {}); the public controller also excludes them
        // from the query (belt-and-suspenders).
        // Widened 2026-07-25 — link classification consolidation adds provider
        // to shared-key rows so non-Fresha/Square/Vagaro etc. bookings render
        // as "Book with {provider}" on the sitepage. Still fail-closed: only
        // explicitly listed keys emit.
        // online-ordering (2026-07-23 actions rebuild): entries now feed the
        // public ordering:<resource_id> actions (ActionCandidates (platform:<key> actions)
        // reads url + name from this exact payload). id/provider/source/data
        // stay private — internal bookkeeping the sitepage doesn't need.
        // ── Named provider cards (registered 265f9aa4, entries added 2026-07-26) ──
        // 27 branded link cards across booking / reservations / events /
        // online-ordering, every one on CardPayload. They shipped registered but
        // UNLISTED, so filterPayload()'s fail-closed branch fired: each rendered
        // as {} on every public sitepage and reported a
        // MissingPublicAllowlistException to Nightwatch on every public request.
        //
        // Key set is the `online-ordering` contract above — the one existing
        // CardPayload entry that renders a branded card publicly. `url`/`name` are
        // what the owner pasted, `favicon`/`logo` are the derived branding these
        // "logo-only" cards exist to show, and `provider` drives the
        // "Book with {provider}" label. CardPayload's remaining keys stay private:
        // `source` and `id` are internal bookkeeping and `data` is the ordering
        // sub-map, none of which the sitepage reads.
        //
        // These are DELIBERATELY wider than the generic 'booking'/'reservations'
        // fallbacks (url + provider): those are custom rows with no scraped
        // branding, whereas each of these is a known provider whose logo renders.
        'booksy' => ['url', 'name', 'favicon', 'logo', 'provider'],
        // T27a (2026-08-28) — the 12 new link-only platforms, same card shape.
        'bookwell' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'cliniko' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'fareharbor' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'google_appointments' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'halaxy' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'hotdoc' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'jane_app' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'microsoft_bookings' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'mr_yum' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'rezdy' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'styleseat' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'wix_bookings' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'vagaro' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'timely' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'kitomba' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'phorest' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'shortcuts' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'bella-booking' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'boulevard' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'glossgenius' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'mangomint' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'zenoti' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'mindbody' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'ovatu' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'resy' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'quandoo' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'sevenrooms' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'tock' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'tablecheck' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'ticketek' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'oztix' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'trybooking' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'resident-advisor' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'ticketmaster' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'bopple' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'square-ordering' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'hungrypanda' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'easi' => ['url', 'name', 'favicon', 'logo', 'provider'],
        // RETIRED 2026-08-13 (slice 5b). Shop is DASHBOARD-ONLY on this wire
        // now: products reach the public payload through `profile.pools.shop`,
        // which serves them from content.items with variants, store cards and a
        // backend-composed outbound URL the legacy brand shape could not carry.
        //
        // `=> []` rather than deletion, deliberately — same reason the event
        // platforms keep an entry. The platform is still REGISTERED (the
        // dashboard connect/refresh lane uses it) and
        // PublicAllowlistCoverageTest requires every registered platform to
        // carry one; deleting it would report a MissingPublicAllowlistException
        // to Nightwatch on every public request.
        'shop' => [],

        // ── Catalog-only brands (convergence Phase 6) ────────────────────────
        // Every key below names a CATALOG brand, not a PlatformRegistry one.
        // The two vocabularies are not the same set and cannot be made the
        // same set: CatalogLegacyMapTest freezes LegacyPlatformMap to the
        // 20260727110001 backfill CASE pair-for-pair, and RegistryCoverageTest
        // chains the registry to that same frozen 78, so a brand added after P1
        // is CATALOG-ONLY by construction. Connections still land on them —
        // isKnownSurface() falls through to CompiledCatalog, which is the write
        // authority — and `platform` is then the brand prefix.
        //
        // That is why these were missing and why nothing caught it:
        // PublicAllowlistCoverageTest iterated PlatformRegistry::keys(), so the
        // catalog-only set was structurally outside its reach. On dev this was
        // not theoretical — showcase-eats published doordash/menulog/uber_eats/
        // shopify as EMPTY payloads and reported MissingPublicAllowlistException
        // on every public request (Nightwatch #436). The coverage test now
        // iterates catalog brands too, so this cannot reopen.
        //
        // Key set is the CardPayload contract the 27 named provider cards above
        // already use. Storefront brands take `[]` for the same reason 'shop'
        // does — products reach the wire through `profile.pools.shop`, and a
        // second, thinner store shape here would only be able to disagree with it.
        'uber_eats' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'doordash' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'menulog' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'deliveroo' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'deezer' => ['url', 'name', 'favicon', 'logo', 'provider'],
        // Wave 2 link-only batch (2026-08-28): plain brand-link payloads.
        'audiomack' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'bandsintown' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'bark' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'beatport' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'bluesky' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'cal_com' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'cameo' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'cash_app' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'classpass' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'dailymotion' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'depop' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'dice' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'etsy' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'eventfinda' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'fiverr' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'flickr' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'houzz' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'megatix' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'moshtix' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'paypal' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'pinterest' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'productreview' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'redbubble' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'rumble' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'skiddle' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'songkick' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'stripe' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'tripadvisor' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'trustpilot' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'tumblr' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'upwork' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'venmo' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'vsco' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'yelp' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'ordermate' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'order_online' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'skipthedishes' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'just_eat' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'grubhub' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'slice' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'chownow' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'toast' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'wolt' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'zomato' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'thefork' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'chope' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'tablein' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'eat_app' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'calendly' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'acuity' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'setmore' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'genbook' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'treatwell' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'noterro' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'schedulicity' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'simplybook_me' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'youcanbookme' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'see_tickets' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'etix' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'ticketweb' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'tixr' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'admitone' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'eventim' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'linkfire' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'feature_fm' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'orchard' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'laylo' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'venue_ink' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'obee' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'libro_fm' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'abacus' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'heyzine' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'circle' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'kajabi' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'luma' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'partiful' => ['url', 'name', 'favicon', 'logo', 'provider'],
        // Storefront brands — see the `[]` rationale above.
        'shopify' => [],
        'woocommerce' => [],
        'squarespace' => [],
        'bigcartel' => [],
        'stan' => [],
        // generic.store — the storefront whose platform has no name (Phase 6's
        // per-store split needed a home for it). Same `[]` as every other
        // storefront brand: products reach the wire through `profile.pools.shop`.
        'generic' => [],
        // direct.book — the booking page no brand claims, nearly always the
        // business's own site. Takes the CARD key set, not `[]`: the sitepage
        // renders it as a Book button and needs the url to do that. This is why
        // it has its own brand rather than sharing `generic`, which must stay
        // empty for storefronts.
        'direct' => ['url', 'name', 'favicon', 'logo', 'provider'],
        // partna.manual_product is the dormant manual product add-path (§16);
        // it has no public shape of its own for the same reason as the above.
        'partna' => [],
    ];

    // SHOP_BRAND_ALLOWLIST + SHOP_PRODUCT_ALLOWLIST were deleted with the shop
    // branch of filterPayload() (slice 5b Task 8). #API-1's enforcement point did not go
    // away with them — it MOVED: PoolResolver::ITEM_KEYS / STORE_KEYS /
    // VARIANT_KEYS are what now bound the product shape on the public wire,
    // pinned by tests/Feature/Content/PoolWireShapeTest.php. That filter is
    // strictly stronger than the one deleted here, because it reaches inside
    // variant objects, which this class's top-level array_intersect_key never did.

    /**
     * @return array{resourceId: ?string, payload: mixed, lastRefreshedAt: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'resourceId' => $this->resource_id,
            'payload' => $this->filterPayload($this->platform, $this->payload),
            'lastRefreshedAt' => $this->last_refreshed_at?->toIso8601String(),
        ];
    }

    /**
     * Drop payload keys the owner's display toggles hide. WS-B2 moved the
     * suppression map into the shared DisplaySettingsFilter so the dashboard GB
     * card + the scheduled refresh gate on the exact same rules (menu stays a
     * no-op here — it isn't in this allowlist; the menus pool gates it).
     */
    private function applyDisplaySettings(string $platform, array $payload): array
    {
        return DisplaySettingsFilter::suppress($platform, $payload, $this->display_settings);
    }

    /** Restrict a stored payload to its platform's public allowlist. */
    private function filterPayload(string $platform, mixed $payload): mixed
    {
        // Fail CLOSED (SEC-3): a non-array payload must never reach this public,
        // CDN-cached wire unfiltered. `payload` is NOT NULL in prod Postgres
        // (jsonb, default '{}') — null here is only the nullable SQLite test mirror.
        if (! is_array($payload)) {
            return [];
        }

        $allowed = self::ALLOWLIST[$platform] ?? null;

        // The standalone-event exception that used to sit here is GONE (slice 7
        // Phase 4). Slice 2 kept it because a standalone row had no connector
        // and so no pool representation — emptying it would have made
        // add-an-event-by-URL publicly inert rather than migrating it. Slice
        // 0b's manual write lane closed that gap: the standalone-event backfill (retired 2026-08-19)
        // carried the existing rows onto content.* and
        // the standalone-event lane lands every new one there, so BOTH
        // row kinds now fall through to the platform's empty allowlist and
        // events reach the wire only through `profile.pools.events`.
        //
        // Do not reinstate a per-row exception here: two event shapes on one
        // wire can only disagree.

        if ($allowed === null) {
            // A new platform shipped without an allowlist entry — fail CLOSED to
            // never leak unvetted stored keys (e.g. _folder, source, sourceUrl) on
            // the public, CDN-cached wire. The Log::warning surfaces the gap so the
            // entry gets filled; until then the platform renders an empty payload
            // publicly, which is safe. (Fail-open was the prior behaviour; removed
            // by SEC-2 because every registered platform already has an entry, so
            // this branch is only reachable by an unregistered platform — which
            // the SEC-1 model saving guard also rejects at write time.)
            // OBS-1: report() so Nightwatch pages — Log::warning alone is invisible to it.
            report(new MissingPublicAllowlistException($platform));
            Log::warning('PublicIntegrationConnectionResource: no allowlist for platform', [
                'platform' => $platform,
            ]);

            return [];
        }

        // Nested identity survives array_intersect_key (it inspects top-level
        // keys only), so allowlisted parents like `photos` need a second pass.
        return $this->applyDisplaySettings(
            $platform,
            ThirdPartyPii::stripNested(array_intersect_key($payload, array_flip($allowed))),
        );
    }
}
