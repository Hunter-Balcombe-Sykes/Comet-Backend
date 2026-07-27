<?php

namespace App\Console\Commands;

use App\Catalog\CompiledCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Projects the compiled artefact into the `catalog` schema. Upsert +
 * tombstone ONLY: rows never deleted, rows absent from the current artefact
 * get tombstoned_at, rows that return get resurrected. Runtime tables
 * (detector_suspensions, unmatched_domains) are never touched. Idempotent —
 * the digest short-circuit makes the hourly schedule effectively free.
 */
class CatalogSyncCommand extends Command
{
    protected $signature = 'catalog:sync {--force : sync even when the digest already landed}';

    protected $description = 'Upsert the compiled catalog artefact into the catalog schema';

    public function handle(): int
    {
        if (! CompiledCatalog::isCompiled()) {
            $this->error('No compiled artefact — run `php artisan catalog:compile` first.');

            return self::FAILURE;
        }

        $digest = CompiledCatalog::digest();
        $current = DB::table('catalog.sync_state')->value('digest');

        if ($current === $digest && ! $this->option('force')) {
            $this->info("Catalog already synced ({$digest}).");

            return self::SUCCESS;
        }

        $now = now();

        DB::transaction(function () use ($digest, $now): void {
            // Brands: two passes — successor_key references a sibling brand,
            // so the FK can only be satisfied once every brand row exists.
            foreach (CompiledCatalog::brands() as $brand) {
                DB::table('catalog.brands')->upsert([
                    [
                        'key' => $brand['key'],
                        'display_name' => $brand['display_name'],
                        'homepage' => $brand['homepage'],
                        'lifecycle' => $brand['lifecycle'],
                        'successor_key' => null,
                        'last_synced_digest' => $digest,
                        'tombstoned_at' => null,
                        'updated_at' => $now,
                    ],
                ], ['key'], ['display_name', 'homepage', 'lifecycle', 'last_synced_digest', 'tombstoned_at', 'updated_at']);
            }
            foreach (CompiledCatalog::brands() as $brand) {
                if ($brand['successor_key'] !== null) {
                    DB::table('catalog.brands')->where('key', $brand['key'])->update(['successor_key' => $brand['successor_key']]);
                }
            }

            foreach (CompiledCatalog::surfaces() as $surface) {
                $capabilities = $surface['capabilities'] ?? [];
                DB::table('catalog.surfaces')->upsert([
                    [
                        'key' => $surface['key'],
                        'brand_key' => $surface['brand_key'],
                        'display_name' => $surface['display_name'],
                        'routing_class' => $surface['routing_class'],
                        'shelf' => $surface['shelf'],
                        'identifier_kind' => $surface['identifier_kind'],
                        'refresh_interval_secs' => $surface['refresh_interval_secs'],
                        'connect_capability' => $capabilities['connect'] ?? null,
                        'connect_fetch_capability' => $capabilities['connectFetch'] ?? null,
                        'fetch_capability' => $capabilities['fetch'] ?? null,
                        'normalize_capability' => $capabilities['normalize'] ?? null,
                        'highlights_capability' => $capabilities['highlights'] ?? null,
                        'gate_capability' => $capabilities['gate'] ?? null,
                        'completeness_capability' => $capabilities['completeness'] ?? null,
                        'canonical_url_template' => $surface['canonical_url_template'],
                        'default_sections' => json_encode($surface['default_sections'] ?: (object) []),
                        'is_connectable' => $surface['is_connectable'],
                        'is_multi_account' => $surface['is_multi_account'],
                        'max_accounts' => $surface['max_accounts'],
                        'supports_incremental' => $surface['supports_incremental'],
                        'lifecycle' => $surface['lifecycle'],
                        'embed' => $surface['embed'] !== null ? json_encode($surface['embed']) : null,
                        'reserved_paths' => json_encode($surface['reserved_paths']),
                        'note' => $surface['note'],
                        'last_synced_digest' => $digest,
                        'tombstoned_at' => null,
                        'updated_at' => $now,
                    ],
                ], ['key'], [
                    'brand_key', 'display_name', 'routing_class', 'shelf', 'identifier_kind',
                    'refresh_interval_secs', 'connect_capability', 'connect_fetch_capability',
                    'fetch_capability', 'normalize_capability', 'highlights_capability',
                    'gate_capability', 'completeness_capability', 'canonical_url_template',
                    'default_sections', 'is_connectable', 'is_multi_account', 'max_accounts',
                    'supports_incremental', 'lifecycle', 'embed', 'reserved_paths', 'note',
                    'last_synced_digest', 'tombstoned_at', 'updated_at',
                ]);
            }

            foreach (CompiledCatalog::detectors() as $detector) {
                DB::table('catalog.detectors')->upsert([
                    [
                        'id' => $detector['id'],
                        'surface_key' => $detector['surface_key'],
                        'signal_key' => $detector['signal_key'],
                        'evidence' => $detector['evidence'],
                        'registrable_key' => $detector['registrable_key'],
                        'subdomain_pattern' => $detector['subdomain_pattern'],
                        'path_pattern' => $detector['path_pattern'],
                        'query_requires' => json_encode($detector['query_requires']),
                        'fingerprint' => $detector['fingerprint'],
                        'identifier_capture' => $detector['identifier_capture'],
                        'identifier_source' => $detector['identifier_source'],
                        'probe_capability' => $detector['probe_capability'],
                        'strength' => $detector['strength'],
                        'reject_patterns' => json_encode($detector['reject_patterns']),
                        'markets' => json_encode($detector['markets']),
                        'note' => $detector['note'],
                        'last_synced_digest' => $digest,
                        'tombstoned_at' => null,
                        'updated_at' => $now,
                    ],
                ], ['id'], [
                    'surface_key', 'signal_key', 'evidence', 'registrable_key', 'subdomain_pattern',
                    'path_pattern', 'query_requires', 'fingerprint', 'identifier_capture',
                    'identifier_source', 'probe_capability', 'strength', 'reject_patterns',
                    'markets', 'note', 'last_synced_digest', 'tombstoned_at', 'updated_at',
                ]);
            }

            foreach (CompiledCatalog::hostAliases() as $alias => $registrable) {
                DB::table('catalog.host_aliases')->upsert([
                    [
                        'alias_host' => $alias,
                        'registrable_key' => $registrable,
                        'last_synced_digest' => $digest,
                        'tombstoned_at' => null,
                        'updated_at' => $now,
                    ],
                ], ['alias_host'], ['registrable_key', 'last_synced_digest', 'tombstoned_at', 'updated_at']);
            }

            foreach (CompiledCatalog::suffixOverrides() as $suffix) {
                DB::table('catalog.suffix_overrides')->upsert([
                    [
                        'suffix' => $suffix,
                        'last_synced_digest' => $digest,
                        'tombstoned_at' => null,
                        'updated_at' => $now,
                    ],
                ], ['suffix'], ['last_synced_digest', 'tombstoned_at', 'updated_at']);
            }

            // Tombstone what this artefact no longer contains. Detectors first
            // (they FK surfaces), then surfaces, then brands.
            foreach (['catalog.detectors', 'catalog.surfaces', 'catalog.brands', 'catalog.host_aliases', 'catalog.suffix_overrides'] as $table) {
                DB::table($table)
                    ->where('last_synced_digest', '!=', $digest)
                    ->whereNull('tombstoned_at')
                    ->update(['tombstoned_at' => $now, 'updated_at' => $now]);
            }

            DB::table('catalog.sync_state')->upsert(
                [['id' => 1, 'digest' => $digest, 'synced_at' => $now]],
                ['id'],
                ['digest', 'synced_at'],
            );
        });

        $this->info("Catalog synced ({$digest}).");

        return self::SUCCESS;
    }
}
