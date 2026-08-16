<?php

namespace App\Services\Platforms\Registry;

use App\Models\Core\Site\IntegrationConnection;

// The single source of truth for which platforms exist and what each is. Bound as
// a singleton in PlatformRegistryServiceProvider. Consumers (validation now; the
// refresher, detector, and generic controller in later plans) read this instead
// of hard-coded platform lists.
class PlatformRegistry
{
    /** @var array<string, PlatformDescriptor> */
    private array $descriptors = [];

    public function register(PlatformDescriptor $descriptor): self
    {
        $this->descriptors[$descriptor->key()] = $descriptor;

        return $this;
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
