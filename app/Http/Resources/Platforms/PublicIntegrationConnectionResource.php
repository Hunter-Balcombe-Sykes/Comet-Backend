<?php

namespace App\Http\Resources\Platforms;

use App\Exceptions\Platforms\MissingPublicAllowlistException;
use App\Http\Resources\ApiResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\DisplaySettingsFilter;
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
     * The owner's GLOBAL shop link mode (site.sites.shop_link_mode). When set,
     * every shop brand's public `linkMode` is stamped from this single value
     * (2026-07-08 — the per-brand control became one global choice). The
     * controller resolves it once per profile and threads it in via
     * withShopLinkMode(); null falls back to the design-kit default so the
     * sitepage contract (pages read brand.linkMode) is unchanged.
     */
    private ?string $shopLinkModeOverride = null;

    /**
     * Popularity ranks for this connection's shop products (product_id → rank,
     * content_popularity_scores content_type='shop_product'). Threaded in by
     * PublicIntegrationController so each product gains a nullable
     * `popularityRank` on the PUBLIC wire (inert until ONE consumes it). Null =
     * not a shop connection / no ranks resolved.
     *
     * @var array<string, int>|null
     */
    private ?array $productRanks = null;

    /**
     * Fluent setter used by PublicIntegrationController to inject the owner's
     * global shop link mode into a shop connection resource before resolve().
     * Returns $this so it composes with the existing single-resource path.
     */
    public function withShopLinkMode(?string $mode): self
    {
        $this->shopLinkModeOverride = $mode;

        return $this;
    }

    /**
     * Fluent setter — inject the shop-product popularity ranks so filterPayload()
     * can annotate each product. Passing an array (even empty) opts this shop
     * resource into `popularityRank` annotation; null leaves products unannotated.
     *
     * @param  array<string, int>|null  $ranks  product_id → rank
     */
    public function withProductRanks(?array $ranks): self
    {
        $this->productRanks = $ranks;

        return $this;
    }

    /**
     * Per-platform allowlist of payload keys exposed publicly. Faithful to the
     * current public contract — i.e. exactly what each platform stores today,
     * minus internal keys (e.g. Instagram's `_folder`, added by CONS-21).
     */
    private const ALLOWLIST = [
        'instagram' => ['username', 'fullName', 'profilePicUrl', 'businessCategory', 'followersCount', 'postsCount', 'mode', 'images', 'videoUrl', 'videoPoster', 'imagesDropped'],
        'youtube' => ['handle', 'name', 'description', 'link', 'thumbnail', 'latest', 'highlights'],
        'apple-music' => ['input', 'name', 'thumbnail', 'releaseDate', 'link', 'latest', 'highlights'],
        'apple-podcast' => ['input', 'name', 'thumbnail', 'description', 'releaseDate', 'link', 'latest', 'highlights'],
        // Events platforms carry two row kinds: account rows ({url, organiser,
        // next, upcoming}) and standalone-event rows ({kind:'event', id, ...flat
        // event fields}). hiddenEventIds stays private (dashboard-only state).
        // The enriched-event keys (description / startsAt / endsAt / priceMin /
        // currency / soldOut, 2026-07-17) are listed for the STANDALONE rows —
        // account rows' next/upcoming event objects pass through whole (the
        // allowlist filters top-level keys only) and carry them implicitly.
        // slug/aliases (item-url-slugs, 2026-07-24) are injected onto every
        // event object by PublicIntegrationController before this resource
        // resolves — listed here so they survive the STANDALONE top-level
        // filter; account rows' upcoming/next carry them for free (pass-through).
        'eventbrite' => ['url', 'organiser', 'next', 'upcoming', 'kind', 'id', 'name', 'venue', 'location', 'startDate', 'endDate', 'description', 'startsAt', 'endsAt', 'price', 'priceMin', 'currency', 'availability', 'soldOut', 'image', 'link', 'slug', 'aliases'],
        'humanitix' => ['url', 'organiser', 'next', 'upcoming', 'kind', 'id', 'name', 'venue', 'location', 'startDate', 'endDate', 'description', 'startsAt', 'endsAt', 'price', 'priceMin', 'currency', 'availability', 'soldOut', 'image', 'link', 'slug', 'aliases'],
        // events-custom: a non-Eventbrite/Humanitix link added via the Tickets &
        // Events card, stored as a standalone event row so it renders in the
        // sitepage Events section. Single card — no organiser/upcoming. Snapshot
        // once, never refreshed (absent from the registry's refreshable set).
        'events-custom' => ['kind', 'id', 'name', 'venue', 'location', 'startDate', 'endDate', 'description', 'startsAt', 'endsAt', 'price', 'priceMin', 'currency', 'availability', 'soldOut', 'image', 'link', 'slug', 'aliases'],
        // custom: one row per user-attached link.
        'custom' => ['kind', 'url', 'name', 'description', 'favicon', 'logo'],
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
        'fresha' => ['url', 'selection'],
        'spotify' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        'soundcloud' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        // mixcloud + tidal share MusicEmbedConnectionResource — same five-key contract.
        'mixcloud' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        'tidal' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        // square: a single user-pasted booking URL. No scraping — only `url` is stored.
        'square' => ['url'],
        // bandcamp: `releases` (the full grid) is allowlisted but only SURVIVES
        // when the owner's show_all_releases toggle is on — DisplaySettingsFilter
        // suppresses it by default (the toggle is default-OFF, see TOGGLE_DEFAULTS).
        'bandcamp' => ['url', 'artist', 'name', 'thumbnail', 'link', 'latest', 'highlights', 'releases'],
        'vimeo' => ['url', 'name', 'thumbnail', 'link', 'latest', 'items', 'highlights'],
        // youtube-music: channelId (the re-fetch input) stays private.
        'youtube-music' => ['url', 'name', 'thumbnail', 'link', 'latest', 'items', 'highlights'],
        'twitch' => ['url', 'login', 'name', 'image', 'description'],
        'skool' => ['url', 'name', 'image', 'description'],
        'strava' => ['url', 'name', 'location', 'image', 'description', 'members'],
        // google-business: placeId / phoneIntl / priceLevel / priceRange /
        // detailsFetchedAt stay private. photos now public for home bg.
        'google-business' => ['url', 'name', 'address', 'lat', 'lng', 'rating', 'reviewCount', 'businessStatus', 'category', 'phone', 'website', 'hours', 'links', 'reviews', 'reviewSummary', 'editorialSummary', 'amenities', 'photos'],
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
        'booking' => ['url', 'provider'],
        'reservations' => ['url', 'provider'],
        // online-ordering (2026-07-23 actions rebuild): entries now feed the
        // public ordering:<resource_id> actions (SiteActionsService::pool()
        // reads url + name from this exact payload). id/provider/source/data
        // stay private — internal bookkeeping the sitepage doesn't need.
        'online-ordering' => ['url', 'name', 'favicon', 'logo', 'provider'],
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
        // shop: brands live in the site.shop_brands child table now (FOUND-25) —
        // built from $this->shopBrands below, not from this allowlist map.
    ];

    /**
     * Public fields of a single shop brand object (FOUND-25: sourced from a
     * ShopBrand::toBrandArray(), not the connection's payload). `provider`
     * (shopify / woocommerce / generic) drives sitepage URL + discount
     * handling. `products` is the one nested collection this list lets
     * through — each product is then filtered per-key by
     * SHOP_PRODUCT_ALLOWLIST below (#API-1); a product's `variants[]`
     * sub-objects still pass through whole, since like every allowlist here
     * this one filters TOP-LEVEL keys only (same residual as eventbrite's
     * `next`/`upcoming` event objects).
     * `linkMode` + `referralQuery` ride along so productHref() can build
     * checkout deep links and append the user's referral suffix.
     * `sourceUrl` stays private (re-scrape input); `selectionMode` stays
     * dashboard-only (selection policy, not render data).
     */
    private const SHOP_BRAND_ALLOWLIST = ['id', 'provider', 'url', 'name', 'currency', 'favicon', 'logo', 'discountCode', 'linkMode', 'referralQuery', 'products'];

    /**
     * #API-1: public fields of a single shop PRODUCT. `ShopProduct.data` is raw
     * scraper output, so before this existed whatever a fetcher chose to store
     * reached unauthenticated visitors — the only structure in this class with
     * no enforcement point. This is the enforcement point: unlike the brand and
     * platform lists it is NOT a subtraction from a fixed column set, it is what
     * keeps a future fetcher shape change off a CDN-cached public wire.
     *
     * Derived as the UNION of every emitter of that shape: ShopifyScraper
     * (widest — `createdAt` and `variants` originate there), WooCommerceScraper
     * (incl. productsFromClient, which mutates values not keys),
     * SquarespaceScraper, BigCartelScraper, and GenericShopScraper's two paths
     * (JSON-LD + the OpenGraph fallback that ShopProductSeeder / addProduct
     * store for an individually-added product). No user-supplied product object
     * exists — SetShopProductsRequest takes ids, AddShopProductRequest a url.
     *
     * `popularityRank` is NOT stored: ShopBrand::toBrandArray() appends it on
     * the public path, and this filter runs after, so it must be listed.
     *
     * `createdAt` is retained deliberately. It is ShopCatalog::syncLatest()'s
     * latest-mode sort input AND ordinary public storefront data; no doc in this
     * repo describes the public product contract, so we cannot prove the
     * sitepage doesn't read it. Installing the enforcement point and narrowing
     * the contract are two separate changes — only the first is in scope.
     *
     * Residual: `variants[]` sub-objects ({id, title, price, available, image})
     * pass through whole — top-level filtering only, matching the rest of this
     * class. Widening the variant shape puts new keys on the public wire with no
     * further gate.
     */
    private const SHOP_PRODUCT_ALLOWLIST = [
        'productId', 'title', 'handle', 'vendor', 'description',
        'image', 'images', 'price', 'currency', 'variantId',
        'available', 'url', 'createdAt', 'variants', 'popularityRank',
    ];

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
     * no-op here — it isn't in this allowlist; PublicMenuController gates it).
     */
    private function applyDisplaySettings(string $platform, array $payload): array
    {
        return DisplaySettingsFilter::suppress($platform, $payload, $this->display_settings);
    }

    /** Restrict a stored payload to its platform's public allowlist. */
    private function filterPayload(string $platform, mixed $payload): mixed
    {
        if ($platform === 'shop') {
            // FOUND-25: brands are the relational site.shop_brands rows
            // (eager-loaded by the controller as `shopBrands.products`), not the
            // connection's payload — build the brand-keyed map from there,
            // allowlisted per brand exactly as before.
            //
            // 2026-07-08: the per-brand link mode became ONE global choice
            // (site.sites.shop_link_mode). Stamp EVERY brand's public linkMode
            // from that global override so the sitepage contract is unchanged
            // (pages still read brand.linkMode) — its value now comes from the
            // single site setting, not the per-brand column. Null override (the
            // controller couldn't resolve a site) leaves toBrandArray()'s own
            // per-brand value, itself defaulted to 'product', so the wire is
            // never missing the key.
            $linkMode = $this->shopLinkModeOverride;
            // Pass the ranks map (possibly empty) so toBrandArray annotates each
            // product with a nullable popularityRank on the public wire. null →
            // no annotation (keeps parity if the controller didn't thread ranks).
            $productRanks = $this->productRanks;

            // W9: a brand mid deferred-connect has no display profile yet — a
            // nameless, logo-less, empty-products card must never ship on the
            // CDN-cached public wire (it can't cause the Shop page to appear
            // by itself, since presentPageIds() additionally requires a chosen
            // product, but it WOULD ride along once another brand qualifies
            // the page). 'failed' is deliberately NOT filtered — a failed
            // brand's content is identical to what today's synchronous path
            // already produces when a homepage fetch fails, so it stays public.
            // Hoisted: flip once, not once per product.
            $productKeys = array_flip(self::SHOP_PRODUCT_ALLOWLIST);

            return $this->shopBrands
                ->reject(fn ($b) => $b->connect_status === 'pending')
                ->mapWithKeys(function ($b) use ($linkMode, $productRanks, $productKeys) {
                    $brand = array_intersect_key(
                        $b->toBrandArray($productRanks), array_flip(self::SHOP_BRAND_ALLOWLIST));
                    // #API-1: `products` is the ONE nested collection the brand
                    // allowlist lets through whole. Filter each product to its own
                    // allowlist so a future fetcher shape change can't put an
                    // unvetted key on this public, CDN-cached wire without a
                    // developer adding it above. array_map (not collect()->map())
                    // preserves keys, so the JSON stays exactly what it is today.
                    if (is_array($brand['products'] ?? null)) {
                        $brand['products'] = array_map(
                            fn ($p) => is_array($p) ? array_intersect_key($p, $productKeys) : [],
                            $brand['products'],
                        );
                    }
                    if ($linkMode !== null) {
                        $brand['linkMode'] = $linkMode;
                    }

                    return [$b->brand_id => $brand];
                })
                ->all();
        }

        // Fail CLOSED (SEC-3): a non-array payload must never reach this public,
        // CDN-cached wire unfiltered. `payload` is NOT NULL in prod Postgres
        // (jsonb, default '{}') — null here is only the nullable SQLite test mirror.
        if (! is_array($payload)) {
            return [];
        }

        $allowed = self::ALLOWLIST[$platform] ?? null;
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

        return $this->applyDisplaySettings(
            $platform,
            array_intersect_key($payload, array_flip($allowed)),
        );
    }
}
