<?php

namespace App\Services\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\Client\ShopifyAdminClient;
use App\Services\Shopify\Client\ShopifyStorefrontClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

// Imports the brand-design shape from Shopify for a single integration.
//
// Two sources are combined:
//   1. Storefront API (GraphQL `shop.brand`) — colours, logos, slogan. The
//      `brand` field only exists on the Storefront API Shop type, not the Admin
//      API. Requires a storefront access token (provisioned by
//      CreateStorefrontAccessTokenJob).
//   2. Active theme's `config/settings_data.json` (Asset API) — radius,
//      border thickness, and section spacing. These are pixel values that we
//      bucket into our three design enums (square|rounded|pill, etc.) so every
//      Sidest theme can map them to its own concrete values.
//
// Values that Shopify has no answer for are returned as null; callers are
// expected to preserve any existing user edits for those fields (leave-if-absent).
class BrandDesignImporter
{
    public function __construct(
        private readonly ShopifyAdminClient $client,
        private readonly ShopifyStorefrontClient $storefront,
    ) {}

    // Enum buckets. These thresholds intentionally match the design brief —
    // change them here and the whole app follows.
    //
    // Radius:    0-4 = square,    5-16 = rounded,    17+ = pill
    // Thickness: 0-1 = hairline,  2-3  = standard,   4+  = bold
    // Spacing:   0-32 = tight,    33-64 = default,   65+ = spacious
    private const RADIUS_ROUNDED_MIN = 5;

    private const RADIUS_PILL_MIN = 17;

    private const THICKNESS_STANDARD_MIN = 2;

    private const THICKNESS_BOLD_MIN = 4;

    private const SPACING_DEFAULT_MIN = 33;

    private const SPACING_SPACIOUS_MIN = 65;

    // Per-theme hints for where each pixel value lives in settings_data.json.
    // Lookups walk this list in order and take the first numeric value found.
    // Unknown themes fall through to `generic`.
    private const THEME_HINTS = [
        'horizon' => [
            'radius' => ['buttons_radius', 'inputs_radius', 'variant_pills_radius', 'radius'],
            'thickness' => ['buttons_border_thickness', 'inputs_border_thickness', 'border_thickness'],
            'spacing' => ['spacing_sections', 'sections_spacing', 'section_spacing'],
        ],
        'dawn' => [
            'radius' => ['buttons_radius', 'inputs_radius', 'variant_pills_radius'],
            'thickness' => ['buttons_border_thickness', 'inputs_border_thickness'],
            'spacing' => ['spacing_sections', 'page_width'],
        ],
        'prestige' => [
            'radius' => ['button_border_radius', 'buttons_radius', 'input_border_radius'],
            'thickness' => ['button_border_width', 'input_border_width'],
            'spacing' => ['section_vertical_spacing', 'section_spacing'],
        ],
        'impact' => [
            'radius' => ['buttons_radius', 'radius'],
            'thickness' => ['buttons_border_thickness', 'border_thickness'],
            'spacing' => ['section_spacing', 'sections_spacing'],
        ],
        'impulse' => [
            'radius' => ['buttons_radius', 'radius'],
            'thickness' => ['buttons_border_thickness'],
            'spacing' => ['section_spacing'],
        ],
        // Catch-all for themes we haven't explicitly mapped.
        'generic' => [
            'radius' => ['buttons_radius', 'button_border_radius', 'radius', 'border_radius'],
            'thickness' => ['buttons_border_thickness', 'button_border_width', 'border_thickness', 'border_width'],
            'spacing' => ['spacing_sections', 'section_spacing', 'sections_spacing'],
        ],
    ];

    private const STOREFRONT_BRAND_QUERY = <<<'GRAPHQL'
    {
      shop {
        id
        brand {
          slogan
          shortDescription
          logo { image { url } }
          squareLogo { image { url } }
          colors {
            primary   { background foreground }
            secondary { background foreground }
          }
        }
      }
    }
    GRAPHQL;

    private const THEMES_QUERY = <<<'GRAPHQL'
    {
      themes(first: 20, roles: [MAIN]) {
        nodes {
          id
          name
          role
        }
      }
    }
    GRAPHQL;

    /**
     * Pull the full brand-design shape from Shopify. Returns null for fields
     * Shopify cannot answer for — callers should preserve existing user values
     * in those slots.
     *
     * @return array{
     *     colors: array{accent: ?string},
     *     theme_mode: ?string,
     *     corner_radius: ?string,
     *     border_thickness: ?string,
     *     section_spacing: ?string,
     *     logo: array{full_url: ?string, square_url: ?string},
     *     slogan: ?string,
     *     short_description: ?string,
     *     shop_gid: ?string
     * }
     */
    public function import(ProfessionalIntegration $integration): array
    {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        if ($shopDomain === '' || $accessToken === '' || ! preg_match('/^[a-z0-9\-]+\.myshopify\.com$/', $shopDomain)) {
            throw new \RuntimeException('Invalid Shopify credentials on integration.');
        }

        $brand = $this->fetchBrand($shopDomain, $apiVersion, $integration->storefront_token);
        $themeSettings = $this->fetchActiveThemeSettings($shopDomain, $accessToken, $apiVersion);

        $themeName = strtolower((string) ($themeSettings['_theme_name'] ?? ''));
        $settings = is_array($themeSettings['current'] ?? null) ? $themeSettings['current'] : [];

        // Brand background/text/border are no longer brand-picked — the
        // dashboard exposes a single light|dark theme_mode instead. Infer the
        // mode from the merchant's primary background colour: dark hue → dark
        // mode. Accent stays a free-form hex.
        $primaryBackground = Arr::get($brand, 'colors.primary.0.background');

        return [
            'colors' => [
                // The "secondary" pair backs accents (buttons, links). The
                // background half is what merchants see as their accent swatch.
                'accent' => Arr::get($brand, 'colors.secondary.0.background'),
            ],
            'theme_mode' => $this->inferThemeMode(is_string($primaryBackground) ? $primaryBackground : null),
            'corner_radius' => $this->bucketRadius($this->resolvePx($settings, $themeName, 'radius')),
            'border_thickness' => $this->bucketThickness($this->resolvePx($settings, $themeName, 'thickness')),
            'section_spacing' => $this->bucketSpacing($this->resolvePx($settings, $themeName, 'spacing')),
            'logo' => [
                'full_url' => Arr::get($brand, 'logo.image.url'),
                'square_url' => Arr::get($brand, 'squareLogo.image.url'),
            ],
            'slogan' => Arr::get($brand, 'slogan'),
            'short_description' => Arr::get($brand, 'shortDescription'),
            'shop_gid' => Arr::get($brand, 'shop_gid'),
        ];
    }

    /**
     * Pull the brand-design shape from Shopify Storefront API.
     *
     * The `brand` field on `Shop` exists only in the Storefront API, not the
     * Admin API. If no storefront token has been provisioned yet (e.g. the
     * CreateStorefrontAccessTokenJob hasn't run), this returns an empty
     * shape and the caller still imports theme tokens — that case is
     * expected and pre-conditional, not an error.
     *
     * Throttle and GraphQL errors propagate as exceptions so the install
     * job's backoff() chain retries instead of silently installing a
     * no-logo brand (the historic bug — DB-D#SCALE-1).
     *
     * @return array{slogan: ?string, logo: array, squareLogo: array, colors: array, shop_gid: ?string}
     *
     * @throws \App\Exceptions\Shopify\ShopifyThrottledException
     * @throws \App\Exceptions\Shopify\ShopifyGraphQLException
     * @throws \App\Exceptions\Shopify\ShopifyTransportException
     */
    private function fetchBrand(string $shopDomain, string $apiVersion, ?string $storefrontToken): array
    {
        if ($storefrontToken === null || $storefrontToken === '') {
            Log::info('Skipping brand query — no storefront token available yet.');

            return $this->emptyBrand();
        }

        $shop = ShopDomain::fromUntrusted($shopDomain);
        $response = $this->storefront->graphql($shop, $storefrontToken, $apiVersion, self::STOREFRONT_BRAND_QUERY);

        return [
            'slogan' => $response->json('data.shop.brand.slogan'),
            'shortDescription' => $response->json('data.shop.brand.shortDescription'),
            'logo' => $response->json('data.shop.brand.logo') ?? [],
            'squareLogo' => $response->json('data.shop.brand.squareLogo') ?? [],
            'colors' => $response->json('data.shop.brand.colors') ?? [],
            'shop_gid' => $response->json('data.shop.id'),
        ];
    }

    private function emptyBrand(): array
    {
        return [
            'slogan' => null,
            'shortDescription' => null,
            'logo' => [],
            'squareLogo' => [],
            'colors' => [],
            'shop_gid' => null,
        ];
    }

    /**
     * Fetch and parse the active theme's settings_data.json. Returns both the
     * theme's display name (for per-theme mapping) and the resolved settings.
     *
     * @return array{_theme_name: ?string, current: array<string, mixed>}
     */
    private function fetchActiveThemeSettings(string $shopDomain, string $accessToken, string $apiVersion): array
    {
        // Step 1 — find the MAIN (active) theme ID and name via GraphQL.
        $shop = ShopDomain::fromUntrusted($shopDomain);

        try {
            $themesResponse = $this->client->graphql(
                $shop,
                $accessToken,
                $apiVersion,
                self::THEMES_QUERY,
            );
        } catch (\Throwable) {
            return ['_theme_name' => null, 'current' => []];
        }

        $nodes = $themesResponse->json('data.themes.nodes', []) ?? [];
        $main = collect($nodes)->first(fn ($t) => strtoupper((string) Arr::get($t, 'role', '')) === 'MAIN');

        if (! is_array($main)) {
            return ['_theme_name' => null, 'current' => []];
        }

        $themeName = (string) Arr::get($main, 'name', '');
        $themeGid = (string) Arr::get($main, 'id', '');

        // GIDs look like gid://shopify/OnlineStoreTheme/12345. The Asset REST
        // endpoint wants the numeric ID.
        if (! preg_match('#/(\d+)$#', $themeGid, $matches)) {
            return ['_theme_name' => $themeName, 'current' => []];
        }

        $themeId = $matches[1];

        // Step 2 — fetch settings_data.json via the Asset REST endpoint.
        // The response body contains an `asset.value` string of JSON.
        // Step 2 — fetch settings_data.json via the Asset REST endpoint.
        try {
            $assetResponse = $this->client->rest(
                method: 'GET',
                shop: $shop,
                accessToken: $accessToken,
                path: "/admin/api/{$apiVersion}/themes/{$themeId}/assets.json",
                body: ['asset[key]' => 'config/settings_data.json'],
            );
        } catch (\Throwable) {
            return ['_theme_name' => $themeName, 'current' => []];
        }

        $raw = (string) $assetResponse->json('asset.value', '');
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return ['_theme_name' => $themeName, 'current' => []];
        }

        // settings_data.json stores the active preset under `current`, which
        // is either an object (live settings) or a string (preset name → look
        // it up in `presets`).
        $current = $decoded['current'] ?? [];
        if (is_string($current)) {
            $current = Arr::get($decoded, "presets.{$current}", []);
        }

        return [
            '_theme_name' => $themeName,
            'current' => is_array($current) ? $current : [],
        ];
    }

    /**
     * Walk this theme's hint list for a design dimension and return the first
     * numeric value found. Falls back to the `generic` hint list for themes we
     * haven't explicitly mapped.
     */
    private function resolvePx(array $settings, string $themeName, string $dimension): ?int
    {
        $hintsKey = $this->matchThemeHints($themeName);
        $keys = self::THEME_HINTS[$hintsKey][$dimension] ?? [];

        foreach ($keys as $key) {
            $value = $settings[$key] ?? null;
            if (is_numeric($value)) {
                return (int) round((float) $value);
            }
        }

        return null;
    }

    /**
     * Map a Shopify theme display name to one of our hint buckets. Shopify
     * themes ship as e.g. "Dawn", "Horizon 1.0.0" — substring match keeps us
     * forgiving of versioning.
     */
    private function matchThemeHints(string $themeName): string
    {
        $name = strtolower($themeName);

        foreach (['horizon', 'dawn', 'prestige', 'impact', 'impulse'] as $key) {
            if (str_contains($name, $key)) {
                return $key;
            }
        }

        return 'generic';
    }

    private function bucketRadius(?int $px): ?string
    {
        if ($px === null) {
            return null;
        }

        return match (true) {
            $px >= self::RADIUS_PILL_MIN => 'pill',
            $px >= self::RADIUS_ROUNDED_MIN => 'default',
            default => 'square',
        };
    }

    private function bucketThickness(?int $px): ?string
    {
        if ($px === null) {
            return null;
        }

        return match (true) {
            $px >= self::THICKNESS_BOLD_MIN => 'bold',
            $px >= self::THICKNESS_STANDARD_MIN => 'default',
            default => 'hairline',
        };
    }

    private function bucketSpacing(?int $px): ?string
    {
        if ($px === null) {
            return null;
        }

        return match (true) {
            $px >= self::SPACING_SPACIOUS_MIN => 'spacious',
            $px >= self::SPACING_DEFAULT_MIN => 'default',
            default => 'tight',
        };
    }

    /**
     * Pick light/dark from the merchant's primary background colour using WCAG
     * relative luminance. Returns null when no value is present so callers can
     * preserve any existing theme_mode the brand picked manually.
     *
     * Threshold 0.5 splits the WCAG luminance range into halves — anything
     * darker than mid-grey reads as "dark mode", anything lighter as "light".
     */
    private function inferThemeMode(?string $hex): ?string
    {
        if ($hex === null) {
            return null;
        }

        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $h)) {
            return null;
        }

        // sRGB transfer curve → linear-light, then WCAG luminance.
        $channel = function (int $byte): float {
            $v = $byte / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        $r = $channel((int) hexdec(substr($h, 0, 2)));
        $g = $channel((int) hexdec(substr($h, 2, 2)));
        $b = $channel((int) hexdec(substr($h, 4, 2)));
        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return $luminance < 0.5 ? 'dark' : 'light';
    }
}
