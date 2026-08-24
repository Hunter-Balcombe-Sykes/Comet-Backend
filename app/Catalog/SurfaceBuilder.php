<?php

namespace App\Catalog;

use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\Lifecycle;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;

/**
 * Mutable fluent assembly for a Surface — mirrors PlatformDescriptor's builder
 * shape (displayName defaults to the key, chain methods return self, build()
 * freezes the result). SurfaceBuilder::for() is the sole entry point;
 * brandKey derives from the key's '{brand}.{product}' prefix when brand() is
 * never called.
 */
final class SurfaceBuilder
{
    private ?string $brandKey = null;

    private string $displayName;

    private ?RoutingClass $routingClass = null;

    private ?Shelf $shelf = null;

    private ?IdentifierKind $identifierKind = null;

    private int $refreshIntervalSecs = 0;

    /** @var array<string, string> */
    private array $capabilities = [];

    private ?string $canonicalUrlTemplate = null;

    /** @var array<string, string> */
    private array $defaultSections = [];

    private bool $isConnectable = true;

    private bool $isMultiAccount = false;

    private int $maxAccounts = 1;

    private bool $supportsIncremental = false;

    private Lifecycle $lifecycle = Lifecycle::Active;

    /** @var list<Detector> */
    private array $detectors = [];

    /** @var list<class-string> */
    private array $contracts = [];

    /** @var array{url_template: string, geometry: string, allow: array<int, string>, binds_host: bool}|null */
    private ?array $embed = null;

    /** @var list<string> */
    private array $reservedPaths = [];

    private ?string $legacyPlatform = null;

    private ?string $note = null;

    private function __construct(private readonly string $key)
    {
        $this->displayName = $key;
    }

    public static function for(string $key): self
    {
        return new self($key);
    }

    public function brand(string $brandKey): self
    {
        $this->brandKey = $brandKey;

        return $this;
    }

    public function displayName(string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function routing(RoutingClass $routingClass): self
    {
        $this->routingClass = $routingClass;

        return $this;
    }

    public function shelf(Shelf $shelf): self
    {
        $this->shelf = $shelf;

        return $this;
    }

    public function identifier(IdentifierKind $identifierKind): self
    {
        $this->identifierKind = $identifierKind;

        return $this;
    }

    public function refreshEvery(int $secs): self
    {
        $this->refreshIntervalSecs = $secs;

        return $this;
    }

    public function canonicalUrl(string $template): self
    {
        $this->canonicalUrlTemplate = $template;

        return $this;
    }

    public function connect(string $capabilityName): self
    {
        $this->capabilities['connect'] = $capabilityName;

        return $this;
    }

    public function connectFetch(string $capabilityName): self
    {
        $this->capabilities['connectFetch'] = $capabilityName;

        return $this;
    }

    public function fetch(string $capabilityName): self
    {
        $this->capabilities['fetch'] = $capabilityName;

        return $this;
    }

    public function normalize(string $capabilityName): self
    {
        $this->capabilities['normalize'] = $capabilityName;

        return $this;
    }

    public function gate(string $capabilityName): self
    {
        $this->capabilities['gate'] = $capabilityName;

        return $this;
    }

    public function completeness(string $capabilityName): self
    {
        $this->capabilities['completeness'] = $capabilityName;

        return $this;
    }

    public function section(string $slot, string $sectionKey): self
    {
        $this->defaultSections[$slot] = $sectionKey;

        return $this;
    }

    /**
     * Owner decree 2026-08-20 (scan-refinement run, FI-1): SOCIAL surfaces are
     * single-account — none of them calls this any more, so the builder default
     * (1) holds and SourceReconciler::capReached() turns a scan-detected second
     * account into a Hold with conflicting_connection_id → a Swap suggestion in
     * the inbox, matching the legacy Instagram lane's own 1-account behaviour.
     * The engines diverged here before (Engine 1 auto-created up to 5 Instagram
     * connections from a Linktree while Engine 2 raised a conflict) — that
     * divergence is why a second Instagram rendered as "Instagram +1". Content
     * and store surfaces keep their explicit multiAccount(10).
     */
    public function multiAccount(int $max): self
    {
        $this->isMultiAccount = true;
        $this->maxAccounts = $max;

        return $this;
    }

    public function notConnectable(): self
    {
        $this->isConnectable = false;

        return $this;
    }

    public function incremental(): self
    {
        $this->supportsIncremental = true;

        return $this;
    }

    public function lifecycle(Lifecycle $lifecycle): self
    {
        $this->lifecycle = $lifecycle;

        return $this;
    }

    /** Builds each detector against THIS surface's key. */
    public function detect(DetectorBuilder ...$d): self
    {
        foreach ($d as $builder) {
            $this->detectors[] = $builder->build($this->key);
        }

        return $this;
    }

    /** Builds each detector with a null surfaceKey — the builder must already carry its own signalKey. */
    public function signalDetect(DetectorBuilder ...$d): self
    {
        foreach ($d as $builder) {
            $this->detectors[] = $builder->build(null);
        }

        return $this;
    }

    public function contract(string ...$fqcn): self
    {
        $this->contracts = $fqcn;

        return $this;
    }

    public function embed(string $urlTemplate, string $geometry, array $allow = [], bool $bindsHost = false): self
    {
        $this->embed = [
            'url_template' => $urlTemplate,
            'geometry' => $geometry,
            'allow' => $allow,
            'binds_host' => $bindsHost,
        ];

        return $this;
    }

    public function reservedPaths(string ...$paths): self
    {
        $this->reservedPaths = $paths;

        return $this;
    }

    /**
     * The legacy `platform` slug, for the handful of surfaces where it is not
     * the brand prefix. Changing one is a migration (the SQL alias column is
     * GENERATED ... STORED) — see CatalogLegacyMapTest.
     */
    public function legacyPlatform(string $slug): self
    {
        $this->legacyPlatform = $slug;

        return $this;
    }

    public function note(string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function build(): Surface
    {
        return new Surface(
            key: $this->key,
            brandKey: $this->brandKey ?? explode('.', $this->key, 2)[0],
            displayName: $this->displayName,
            routingClass: $this->routingClass,
            shelf: $this->shelf,
            identifierKind: $this->identifierKind,
            refreshIntervalSecs: $this->refreshIntervalSecs,
            capabilities: $this->capabilities,
            canonicalUrlTemplate: $this->canonicalUrlTemplate,
            defaultSections: $this->defaultSections,
            isConnectable: $this->isConnectable,
            isMultiAccount: $this->isMultiAccount,
            maxAccounts: $this->maxAccounts,
            supportsIncremental: $this->supportsIncremental,
            lifecycle: $this->lifecycle,
            detectors: $this->detectors,
            contracts: $this->contracts,
            embed: $this->embed,
            reservedPaths: $this->reservedPaths,
            legacyPlatform: $this->legacyPlatform,
            note: $this->note,
        );
    }
}
