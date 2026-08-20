<?php

namespace App\Services\Platforms\Registry;

use App\Catalog\CatalogNotCompiled;
use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;
use App\Http\Resources\Platforms\LinkConnectionResource;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Strategies\Connect\BrandLinkConnect;
use App\Services\Shop\ShopConnections;

/**
 * Builds a PlatformDescriptor for every connectable, URL-detected catalog surface
 * that has no hand-written descriptor — so the registry-driven route loop emits
 * the platform endpoints for it without a route-file edit per brand.
 *
 * Keyed by LegacyPlatformMap::legacyFor($surfaceKey), NOT by brand_key. Three
 * reasons, all load-bearing:
 *
 *  1. It is the same function the GENERATED `site.platform_connections.platform`
 *     column computes, so a derived slug matches storage by construction and
 *     GenericPlatformController (which filters on that column) resolves.
 *  2. Fourteen hand-written slugs spell their brand differently from the catalog
 *     (`apple-music` vs `apple_music`, `google-business` vs `google_business`, …).
 *     Keying on brand_key would register a second descriptor beside each.
 *  3. SPECIAL_TO_LEGACY already disambiguates the only two multi-surface brands —
 *     square.book => 'square', square.order => 'square-ordering' — so there is no
 *     one-slug-two-surfaces case to special-case. Verified 2026-08-17: all 102
 *     URL-detected surfaces map to 102 distinct slugs.
 *
 * Runs at boot, on every request. Reads the compiled artefact ONLY — never
 * reflects Definitions/, never resolves a strategy factory. Derived descriptors
 * carry no strategies at all: they are link-only by owner ruling, and the write
 * is LinkRouter's.
 */
class DerivedDescriptorFactory
{
    /**
     * routing_class => the AccountCapabilities property a brand of that class needs.
     * Mirrors App\Routing\RoutingCapabilityGate — the same axis, applied at the
     * connect route instead of at routing time. Classes absent from this map
     * (social, content, events, link, shop) gate on nothing.
     *
     * @var array<string, string>
     */
    public const CAPABILITY_BY_ROUTING_CLASS = [
        'ordering' => 'can_use_online_ordering',
        'booking' => 'can_use_booking',
        'reservations' => 'can_use_reservations',
    ];

    /**
     * Slug => extra classify() answers this brand may answer to. EMPTY since
     * events-parity (2026-08-19): classify()'s events arms now return the
     * real brand keys (luma/partiful/ticketmaster), which was the only thing
     * the 'events-custom' aliases papered over. The mechanism stays for the
     * next brand whose classifier key can't equal its slug.
     *
     * @return array<string, list<string>>
     */
    public static function classifierAliases(): array
    {
        // Empty today: the two brands this existed for went with the
        // pseudo-platform link lane (ea6450eda). Kept as the extension point
        // its docblock describes — and as a METHOD rather than a const so the
        // declared array<string, list<string>> survives, instead of PHPStan
        // narrowing an empty const to array{} and calling every consumer dead.
        return [];
    }

    /**
     * Hand-written slugs that must NEVER be upgraded to the Brand shape, even
     * though they look routeless by the connectField() test.
     *
     * instagram is Bespoke with no connectField because its connect group is
     * hand-written in routes/api/platforms.php and backed by a real scraper —
     * the declared-flag test cannot see that, so it is named here.
     *
     * @var list<string>
     */
    public const NEVER_UPGRADE = ['instagram'];

    /**
     * Every connectable, URL-detected catalog surface, as slug => [surface key,
     * surface row, brand row]. The shared spine of build() and upgrades().
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    private function candidates(): array
    {
        try {
            $surfaces = CompiledCatalog::surfaces();
            $brands = CompiledCatalog::brands();
            $detected = $this->urlDetectedSurfaceKeys();
        } catch (CatalogNotCompiled) {
            // The registry must still build without an artefact — route:cache and
            // any bootstrap that runs before catalog:compile depend on it.
            return [];
        }

        $out = [];

        foreach ($surfaces as $key => $surface) {
            if (! in_array($surface['lifecycle'] ?? '', ['active', 'sunset'], true)) {
                continue;
            }
            if (($surface['is_connectable'] ?? false) !== true) {
                continue;
            }
            if (! isset($detected[$key])) {
                continue;
            }
            // Shop brands are NOT derivable. Two independent reasons:
            //
            // 1. PlatformRegistry::forConnection() falls back to the 'shop'
            //    family descriptor precisely BECAUSE get('shopify') is null —
            //    its docblock says so. Registering 'shopify' silently steals
            //    that lookup and hands scheduled product refresh a descriptor
            //    with no fetch strategy, so every store refresh starts erroring.
            // 2. A storefront is connected by the commerce probe and
            //    ShopController, never by pasting a link into a brand route.
            //    WebsiteLinkHarvester::classifyFromCatalog() returns null for
            //    this routing class on purpose, to keep the probe running — so a
            //    brand connect guard would reject every URL anyway.
            // Shop PROVIDERS (Shopify, WooCommerce, Squarespace…) connect
            // through the shop lane (ShopController), never as a brand card.
            // A shop-class surface that is NOT a provider we sync (Gumroad —
            // no product feed) still deserves the link card every other
            // brand gets; until 2026-08-18 it fell in the gap: 404 here, 422
            // "unsupported store" there (overnight W1 leftover, task #17).
            if (($surface['routing_class'] ?? null) === 'shop'
                && in_array($key, ShopConnections::surfaces(), true)) {
                continue;
            }

            $slug = LegacyPlatformMap::legacyFor($key);
            if ($slug === '' || isset($out[$slug])) {
                continue;
            }

            $out[$slug] = [$key, $surface, $brands[$surface['brand_key']] ?? []];
        }

        return $out;
    }

    /**
     * Brand-new descriptors, for catalog brands the registry has never carried.
     *
     * @return array<string, PlatformDescriptor> slug => descriptor
     */
    public function build(): array
    {
        $frozen = array_flip(PlatformRegistry::handWrittenFreeze());
        $derived = [];

        foreach ($this->candidates() as $slug => [$key, $surface, $brand]) {
            if (isset($frozen[$slug])) {
                continue;
            }

            $derived[$slug] = $this->descriptorFor($slug, $key, $surface, $brand);
        }

        return $derived;
    }

    /**
     * Hand-written descriptors that are declared but ROUTELESS — the actual
     * defect this whole shape exists to fix.
     *
     * Roughly fifty registry slugs are shaped Bespoke, which the route loop reads
     * as "this platform keeps its own standalone group". For most of them no such
     * group was ever written: booksy, resy, vagaro, ticketek, patreon and their
     * kind are listed, validated and disconnectable only through a family-wide
     * endpoint. Skipping them because they are "already registered" would have
     * left the original problem exactly where it was.
     *
     * The discriminator is `connectField() === null` on a Bespoke descriptor — a
     * declared flag, safe to read at boot. It correctly excludes apple-music and
     * apple-podcast (they DO declare a connect field; their routes just live under
     * /apple/*), and NEVER_UPGRADE covers the one case a declared flag cannot see.
     *
     * The registry is PASSED IN, never resolved: this runs inside the container
     * closure that builds the PlatformRegistry singleton, so app(PlatformRegistry::class)
     * here would recurse until the process died.
     *
     * @return array<string, array{surface: string, label: string, multi: bool, capability: ?string}>
     */
    public function upgrades(PlatformRegistry $registry): array
    {
        $upgrades = [];

        foreach ($this->candidates() as $slug => [$key, $surface, $brand]) {
            if (in_array($slug, self::NEVER_UPGRADE, true)) {
                continue;
            }

            $descriptor = $registry->get($slug);
            if ($descriptor === null
                || $descriptor->routeShape() !== PlatformRouteShape::Bespoke
                || $descriptor->connectField() !== null) {
                continue;
            }

            $upgrades[$slug] = [
                'surface' => $key,
                'label' => $this->labelFor($slug, $surface, $brand),
                'multi' => ($surface['is_multi_account'] ?? false) === true,
                'capability' => self::CAPABILITY_BY_ROUTING_CLASS[$surface['routing_class'] ?? ''] ?? null,
            ];
        }

        return $upgrades;
    }

    /**
     * @param  array<string, mixed>  $surface
     * @param  array<string, mixed>  $brand
     */
    public function labelFor(string $slug, array $surface, array $brand): string
    {
        return is_string($brand['display_name'] ?? null)
            ? $brand['display_name']
            : (is_string($surface['display_name'] ?? null) ? $surface['display_name'] : $slug);
    }

    /**
     * Surface keys carrying at least one URL detector.
     *
     * detectors() is keyed by detector id, and each row's `surface_key` is
     * NULLABLE (null = a shared cross-surface signal keyed by signal_key instead).
     * `evidence` is the detector-kind discriminator; today every compiled detector
     * is 'url', but the field is real and filtering on it keeps this honest if a
     * DOM- or DNS-evidence detector is ever compiled.
     *
     * @return array<string, true>
     */
    private function urlDetectedSurfaceKeys(): array
    {
        $keys = [];

        foreach (CompiledCatalog::detectors() as $detector) {
            $surfaceKey = $detector['surface_key'] ?? null;
            if (is_string($surfaceKey) && $surfaceKey !== '' && ($detector['evidence'] ?? null) === 'url') {
                $keys[$surfaceKey] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $surface
     * @param  array<string, mixed>  $brand
     */
    private function descriptorFor(string $slug, string $surfaceKey, array $surface, array $brand): PlatformDescriptor
    {
        $label = $this->labelFor($slug, $surface, $brand);

        $descriptor = PlatformDescriptor::make($slug)
            ->label($label)
            ->derived()
            ->surfaceKey($surfaceKey)
            ->resource(LinkConnectionResource::class)
            ->payload(CardPayload::class)
            // A CLOSURE, never an instance: this runs at boot on every request,
            // and the loop's own comments explain at length why resolving a
            // strategy eagerly here is a trap. BrandLinkConnect is cheap, but the
            // rule is the rule.
            ->connect(
                fn () => new BrandLinkConnect($slug, $label, $surfaceKey),
                'Enter a valid '.$label.' link.'
            )
            // connectInput is NOT optional: ResolvesConnectRules::connectDescriptor()
            // aborts 404 when connectField() is null, so a derived descriptor without
            // it would 404 every connect before validation ever ran.
            ->connectInput('url', ['required', 'string', 'max:2048'])
            ->routes(
                PlatformRouteShape::Brand,
                null,
                ($surface['is_multi_account'] ?? false) === true,
            );

        $capability = self::CAPABILITY_BY_ROUTING_CLASS[$surface['routing_class'] ?? ''] ?? null;
        if ($capability !== null) {
            $descriptor->requiresCapability(
                static fn (User $user): bool => (bool) AccountCapabilities::for($user)->{$capability}
            );
        }

        return $descriptor;
    }
}
