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
     * Slug => the label WebsiteLinkHarvester::classify() actually returns for this
     * brand's hosts. classify() matches partiful.com and ticketmaster.* with inline
     * regexes that yield the legacy pseudo-slug 'events-custom', never the brand's
     * own key, so a strict brand-match guard would 422 a perfectly valid URL. These
     * two are allowed to answer to that alias. Every other brand answers to itself.
     *
     * @var array<string, list<string>>
     */
    public const CLASSIFIER_ALIASES = [
        'partiful' => ['events-custom'],
        'ticketmaster' => ['events-custom'],
    ];

    /** @return array<string, PlatformDescriptor> slug => descriptor */
    public function build(): array
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

        $frozen = array_flip(PlatformRegistry::handWrittenFreeze());
        $derived = [];

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

            $slug = LegacyPlatformMap::legacyFor($key);
            if ($slug === '' || isset($frozen[$slug]) || isset($derived[$slug])) {
                continue;
            }

            $derived[$slug] = $this->descriptorFor($slug, $key, $surface, $brands[$surface['brand_key']] ?? []);
        }

        return $derived;
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
        $label = is_string($brand['display_name'] ?? null)
            ? $brand['display_name']
            : ($surface['display_name'] ?? $slug);

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
                fn () => new BrandLinkConnect($slug, $label),
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
