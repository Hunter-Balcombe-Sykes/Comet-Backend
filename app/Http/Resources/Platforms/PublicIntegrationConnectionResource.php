<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
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
     * Per-platform allowlist of payload keys exposed publicly. Faithful to the
     * current public contract — i.e. exactly what each platform stores today,
     * minus internal keys (e.g. Instagram's `_folder`, added by CONS-21).
     */
    private const ALLOWLIST = [
        'instagram' => ['username', 'fullName', 'profilePicUrl', 'businessCategory', 'followersCount', 'postsCount', 'mode', 'images', 'imagesDropped'],
        'youtube' => ['handle', 'name', 'description', 'link', 'thumbnail', 'latest', 'highlights'],
        'apple-music' => ['input', 'name', 'thumbnail', 'releaseDate', 'link', 'latest', 'highlights'],
        'apple-podcast' => ['input', 'name', 'thumbnail', 'description', 'releaseDate', 'link', 'latest', 'highlights'],
        // Events platforms carry two row kinds: account rows ({url, organiser,
        // next, upcoming}) and standalone-event rows ({kind:'event', id, ...flat
        // event fields}). hiddenEventIds stays private (dashboard-only state).
        'eventbrite' => ['url', 'organiser', 'next', 'upcoming', 'kind', 'id', 'name', 'venue', 'location', 'startDate', 'endDate', 'price', 'availability', 'image', 'link'],
        'humanitix' => ['url', 'organiser', 'next', 'upcoming', 'kind', 'id', 'name', 'venue', 'location', 'startDate', 'endDate', 'price', 'availability', 'image', 'link'],
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
        'deezer' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        'bandcamp' => ['url', 'artist', 'name', 'thumbnail', 'link', 'latest', 'highlights'],
        'vimeo' => ['url', 'name', 'thumbnail', 'link', 'latest', 'items', 'highlights'],
        // youtube-music: channelId (the re-fetch input) stays private.
        'youtube-music' => ['url', 'name', 'thumbnail', 'link', 'latest', 'items', 'highlights'],
        'twitch' => ['url', 'login', 'name', 'image', 'description'],
        'pinterest' => ['url', 'username', 'name', 'image', 'followers', 'latest', 'items'],
        'skool' => ['url', 'name', 'image', 'description'],
        'strava' => ['url', 'name', 'location', 'image', 'description', 'members'],
        // google-business: placeId / phoneIntl / photos / priceLevel /
        // priceRange / detailsFetchedAt stay private.
        'google-business' => ['url', 'name', 'address', 'lat', 'lng', 'rating', 'reviewCount', 'businessStatus', 'category', 'phone', 'website', 'hours', 'links', 'reviews', 'reviewSummary', 'editorialSummary', 'amenities'],
        // shop: payload is a brand-keyed MAP, filtered per brand object (below).
    ];

    /**
     * Public fields of a single shop brand object inside the brand-keyed map.
     * `provider` (shopify / woocommerce / generic) drives sitepage URL +
     * discount handling; products pass through verbatim (each carries `url`).
     * `sourceUrl` stays private — it is a re-scrape input, not a public field.
     */
    private const SHOP_BRAND_ALLOWLIST = ['id', 'provider', 'url', 'name', 'currency', 'favicon', 'logo', 'discountCode', 'products'];

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

    /** Restrict a stored payload to its platform's public allowlist. */
    private function filterPayload(string $platform, mixed $payload): mixed
    {
        // Null / non-array payloads (e.g. a pending connection) pass through.
        if (! is_array($payload)) {
            return $payload;
        }

        if ($platform === 'shop') {
            // Brand-keyed map: allowlist each brand object's fields, keys
            // preserved. Brands stored before the provider field existed are
            // Shopify (the only provider back then).
            return array_map(
                fn ($brand) => is_array($brand)
                    ? array_intersect_key(['provider' => 'shopify', ...$brand], array_flip(self::SHOP_BRAND_ALLOWLIST))
                    : $brand,
                $payload,
            );
        }

        $allowed = self::ALLOWLIST[$platform] ?? null;
        if ($allowed === null) {
            // A new platform shipped without an allowlist entry — fail OPEN (never
            // break public rendering) but surface the gap so it gets filled.
            Log::warning('PublicIntegrationConnectionResource: no allowlist for platform', [
                'platform' => $platform,
            ]);

            return $payload;
        }

        return array_intersect_key($payload, array_flip($allowed));
    }
}
