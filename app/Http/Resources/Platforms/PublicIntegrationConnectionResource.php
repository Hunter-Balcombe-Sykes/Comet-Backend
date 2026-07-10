<?php

namespace App\Http\Resources\Platforms;

use App\Exceptions\Platforms\MissingPublicAllowlistException;
use App\Http\Resources\ApiResource;
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
        'eventbrite' => ['url', 'organiser', 'next', 'upcoming', 'kind', 'id', 'name', 'venue', 'location', 'startDate', 'endDate', 'price', 'availability', 'image', 'link'],
        'humanitix' => ['url', 'organiser', 'next', 'upcoming', 'kind', 'id', 'name', 'venue', 'location', 'startDate', 'endDate', 'price', 'availability', 'image', 'link'],
        // events-custom: a non-Eventbrite/Humanitix link added via the Tickets &
        // Events card, stored as a standalone event row so it renders in the
        // sitepage Events section. Single card — no organiser/upcoming. Snapshot
        // once, never refreshed (absent from the registry's refreshable set).
        'events-custom' => ['kind', 'id', 'name', 'venue', 'location', 'startDate', 'endDate', 'price', 'availability', 'image', 'link'],
        // custom: one row per user-attached link.
        'custom' => ['kind', 'url', 'name', 'description', 'favicon', 'logo'],
        'facebook' => ['username', 'url'],
        'tiktok' => ['username', 'url'],
        'x' => ['username', 'url'],
        'linkedin' => ['username', 'url'],
        'threads' => ['username', 'url'],
        'reddit' => ['username', 'url'],
        'fresha' => ['url', 'selection'],
        'spotify' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        'soundcloud' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        // mixcloud + tidal share MusicEmbedConnectionResource — same five-key contract.
        'mixcloud' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        'tidal' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        // square: a single user-pasted booking URL. No scraping — only `url` is stored.
        'square' => ['url'],
        'bandcamp' => ['url', 'artist', 'name', 'thumbnail', 'link', 'latest', 'highlights'],
        'vimeo' => ['url', 'name', 'thumbnail', 'link', 'latest', 'items', 'highlights'],
        // youtube-music: channelId (the re-fetch input) stays private.
        'youtube-music' => ['url', 'name', 'thumbnail', 'link', 'latest', 'items', 'highlights'],
        'twitch' => ['url', 'login', 'name', 'image', 'description'],
        'pinterest' => ['url', 'username', 'name', 'image', 'followers', 'latest', 'items'],
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
        // fallback entry; online-ordering holds the ordering links. None render on
        // the public sitepage yet, so they expose NOTHING (empty list → {}). The
        // public controller also excludes them from the query (belt-and-suspenders).
        'booking' => [],
        'reservations' => [],
        'online-ordering' => [],
        // shop: brands live in the site.shop_brands child table now (FOUND-25) —
        // built from $this->shopBrands below, not from this allowlist map.
    ];

    /**
     * Public fields of a single shop brand object (FOUND-25: sourced from a
     * ShopBrand::toBrandArray(), not the connection's payload). `provider`
     * (shopify / woocommerce / generic) drives sitepage URL + discount
     * handling; products pass through verbatim (each carries `url`).
     * `linkMode` + `referralQuery` ride along so productHref() can build
     * checkout deep links and append the user's referral suffix.
     * `sourceUrl` stays private (re-scrape input); `selectionMode` stays
     * dashboard-only (selection policy, not render data).
     */
    private const SHOP_BRAND_ALLOWLIST = ['id', 'provider', 'url', 'name', 'currency', 'favicon', 'logo', 'discountCode', 'linkMode', 'referralQuery', 'products'];

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

            return $this->shopBrands
                ->mapWithKeys(function ($b) use ($linkMode, $productRanks) {
                    $brand = array_intersect_key(
                        $b->toBrandArray($productRanks), array_flip(self::SHOP_BRAND_ALLOWLIST));
                    if ($linkMode !== null) {
                        $brand['linkMode'] = $linkMode;
                    }

                    return [$b->brand_id => $brand];
                })
                ->all();
        }

        // Null / non-array payloads (e.g. a pending connection) pass through.
        if (! is_array($payload)) {
            return $payload;
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
