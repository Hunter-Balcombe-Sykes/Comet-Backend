<?php

namespace App\Services\Platforms\Registry;

use App\Catalog\LegacyPlatformMap;
use App\Models\Core\Site\IntegrationConnection;
use RuntimeException;

// The single source of truth for which platforms exist and what each is. Bound as
// a singleton in PlatformRegistryServiceProvider. Consumers (validation now; the
// refresher, detector, and generic controller in later plans) read this instead
// of hard-coded platform lists.
class PlatformRegistry
{
    /** @var array<string, PlatformDescriptor> */
    private array $descriptors = [];

    /**
     * Registration is fail-loud on a duplicate key. It used to be last-write-wins
     * with no guard, which was safe only while every descriptor was hand-written
     * in one file. Descriptors are now ALSO derived from the compiled catalog
     * (DerivedDescriptorFactory), so a slug collision would silently replace a
     * real descriptor — strategies, resources and all — with a link-only stub.
     * The derived registration site skips on has() first; this throw is the
     * backstop for the case where it doesn't.
     */
    public function register(PlatformDescriptor $descriptor): self
    {
        $key = $descriptor->key();

        if (isset($this->descriptors[$key])) {
            throw new RuntimeException(
                "Duplicate platform registration for '{$key}'. A descriptor with this key already exists; "
                .'registering again would silently discard it.'
            );
        }

        $this->descriptors[$key] = $descriptor;

        return $this;
    }

    /**
     * The hand-written slugs, frozen to the 20260727110001 backfill CASE and
     * pinned by CatalogLegacyMapTest.
     *
     * Derived descriptors are registered ON TOP of these and are deliberately NOT
     * in this list — the freeze applies to the hand-written half only. The
     * registry and LegacyPlatformMap were once asserted byte-identical on the
     * premise that the connect layer and the write-guard must not drift; that
     * premise is already half-false, because LegacyPlatformMap::isKnownSurface()
     * falls back to the compiled catalog and so already accepts surfaces the
     * registry has never heard of. See the uniformity design doc §6.8.
     *
     * @return list<string>
     */
    public static function handWrittenFreeze(): array
    {
        return array_keys(LegacyPlatformMap::toSurfaceMap());
    }

    public function get(string $key): ?PlatformDescriptor
    {
        return $this->descriptors[$key] ?? null;
    }

    /**
     * A catalog-only brand inherits its FAMILY's descriptor.
     *
     * The registry is frozen at the 78 slugs `CatalogLegacyMapTest` pins to the
     * 20260727110001 backfill CASE, and `RegistryCoverageTest` chains it to the
     * same set — so a brand added after P1 is catalog-only by construction and
     * can never have a descriptor of its own. Convergence Phase 6 made that
     * matter: splitting the shop marker put every store on `shopify.store`,
     * `woocommerce.store`, … whose generated `platform` column is the brand
     * prefix, and `get('shopify')` is null. Left there, scheduled product
     * refresh would simply stop — silently, because PlatformRefresher's
     * unknown-platform arm records a failure rather than throwing.
     *
     * The fallback is keyed on routing_class, the axis that travels with
     * surface_key on every row, so it covers brands nobody has added yet. Only
     * families whose behaviour genuinely IS per-family are listed: a shop
     * refresh re-syncs whatever stores the connection anchors, and does not
     * care which platform they run on.
     */
    public function forConnection(IntegrationConnection $connection): ?PlatformDescriptor
    {
        return $this->get((string) $connection->platform)
            ?? $this->get(self::FAMILY_DESCRIPTOR[(string) $connection->routing_class] ?? '');
    }

    /**
     * routing_class → the registered descriptor its catalog-only brands inherit.
     * Deliberately tiny: every other family's brands either have their own
     * registry entry (the frozen 78) or are not refreshable at all.
     *
     * @var array<string, string>
     */
    public const FAMILY_DESCRIPTOR = ['shop' => 'shop'];

    public function has(string $key): bool
    {
        return isset($this->descriptors[$key]);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->descriptors);
    }

    /** @return array<string, PlatformDescriptor> */
    public function all(): array
    {
        return $this->descriptors;
    }

    /** @return array<string, PlatformDescriptor> */
    public function refreshable(): array
    {
        return array_filter($this->descriptors, fn (PlatformDescriptor $d) => $d->isRefreshable());
    }

    public function isRefreshable(string $key): bool
    {
        return isset($this->descriptors[$key]) && $this->descriptors[$key]->isRefreshable();
    }
}
