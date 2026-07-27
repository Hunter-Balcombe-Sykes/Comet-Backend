<?php

namespace App\Catalog;

use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\Lifecycle;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use InvalidArgumentException;

/**
 * One connectable/linkable product surface in the catalog — e.g.
 * 'tiktok.profile' or 'spotify.embed'. $key is namespaced '{brand}.{product}'
 * so two brands can each expose a same-named product surface without
 * collision. $capabilities maps strategy roles (connect/connectFetch/fetch/
 * normalize/highlights/gate/completeness) to the concrete capability name
 * implementing that role for this surface; a surface with no 'fetch' role is
 * link-only (isLinkOnly()).
 */
final readonly class Surface
{
    public function __construct(
        public string $key,
        public string $brandKey,
        public string $displayName,
        public RoutingClass $routingClass,
        public Shelf $shelf,
        public IdentifierKind $identifierKind,
        /** 0 = never auto-refresh. */
        public int $refreshIntervalSecs,
        /** @var array<string, string> role => capability name */
        public array $capabilities,
        public ?string $canonicalUrlTemplate,
        /** @var array<string, string> slot => section key */
        public array $defaultSections,
        public bool $isConnectable = true,
        public bool $isMultiAccount = false,
        public int $maxAccounts = 1,
        public bool $supportsIncremental = false,
        public Lifecycle $lifecycle = Lifecycle::Active,
        /** @var list<Detector> */
        public array $detectors = [],
        /** @var list<class-string> */
        public array $contracts = [],
        /** @var array{url_template: string, geometry: string, allow: array<int, string>, binds_host: bool}|null */
        public ?array $embed = null,
        /** @var list<string> */
        public array $reservedPaths = [],
        public ?string $note = null,
    ) {
        if (substr_count($key, '.') !== 1) {
            throw new InvalidArgumentException("Surface key '{$key}' must be exactly '{brand}.{product}'.");
        }
    }

    /** Absent 'fetch' capability means this surface is link-only — store a URL, no content pull. */
    public function isLinkOnly(): bool
    {
        return ! array_key_exists('fetch', $this->capabilities);
    }

    public function capability(string $role): ?string
    {
        return $this->capabilities[$role] ?? null;
    }

    /** @return array<string, mixed> Snake_case shape for the compiled catalog artefact. */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'brand_key' => $this->brandKey,
            'display_name' => $this->displayName,
            'routing_class' => $this->routingClass->value,
            'shelf' => $this->shelf->value,
            'identifier_kind' => $this->identifierKind->value,
            'refresh_interval_secs' => $this->refreshIntervalSecs,
            'capabilities' => $this->capabilities,
            'canonical_url_template' => $this->canonicalUrlTemplate,
            'default_sections' => $this->defaultSections,
            'is_connectable' => $this->isConnectable,
            'is_multi_account' => $this->isMultiAccount,
            'max_accounts' => $this->maxAccounts,
            'supports_incremental' => $this->supportsIncremental,
            'lifecycle' => $this->lifecycle->value,
            'detectors' => array_map(static fn (Detector $d): array => $d->toArray(), $this->detectors),
            'contracts' => $this->contracts,
            'embed' => $this->embed,
            'reserved_paths' => $this->reservedPaths,
            'note' => $this->note,
        ];
    }
}
