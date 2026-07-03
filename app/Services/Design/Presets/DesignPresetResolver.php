<?php

namespace App\Services\Design\Presets;

use App\Models\Core\Site\DesignKitContribution;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves integration "factors" into design-kit contributions and merges them
 * into the read-time "preset layer".
 *
 * WRITE side (resolveForUser): rebuild a user's contributions from their active
 * integration connections. One-shot factors are FROZEN (kept as-is once
 * resolved); auto factors + newly-connected one-shots (re)detect. Contributions
 * of disconnected integrations are swept. Idempotent.
 *
 * READ side (presetLayer / mergedFlatKit): priority-merge a site's rows into a
 * flat [column => value] map. DEFENSIVE — any failure returns an empty layer so
 * a preset bug can never break the public sitepage render.
 */
class DesignPresetResolver
{
    public function __construct(private readonly DesignFactorRegistry $registry) {}

    /**
     * The priority-merged preset layer for a site: flat [design_kit column =>
     * value]. Highest priority wins each column; equal priority breaks by source
     * ascending (stable + independent of connection order).
     *
     * @return array<string, string>
     */
    public function presetLayer(string $siteId): array
    {
        try {
            $rows = DesignKitContribution::query()
                ->where('site_id', $siteId)
                ->get(['target_var', 'value', 'source', 'priority']);

            /** @var array<string, array{priority:int, source:string, value:string}> $winners */
            $winners = [];
            foreach ($rows as $row) {
                $var = (string) $row->target_var;
                $current = $winners[$var] ?? null;
                $wins = $current === null
                    || (int) $row->priority > $current['priority']
                    || ((int) $row->priority === $current['priority'] && (string) $row->source < $current['source']);
                if ($wins) {
                    $winners[$var] = [
                        'priority' => (int) $row->priority,
                        'source' => (string) $row->source,
                        'value' => (string) $row->value,
                    ];
                }
            }

            return array_map(static fn (array $w): string => $w['value'], $winners);
        } catch (\Throwable $e) {
            report($e); // never break a render over a preset bug

            return [];
        }
    }

    /**
     * The manual design_kits row (non-null cols) overlaid on the preset layer —
     * manual wins per column. Flat snake_case, for the email brand resolver.
     *
     * @return array<string, mixed>
     */
    public function mergedFlatKit(string $siteId): array
    {
        $row = DB::connection('pgsql')
            ->table('site.design_kits')
            ->where('site_id', $siteId)
            ->first();

        $manual = $row ? array_filter((array) $row, static fn ($v) => $v !== null) : [];
        unset($manual['site_id']);

        return array_merge($this->presetLayer($siteId), $manual);
    }

    /**
     * Rebuild a user's contributions from their active integration connections.
     * Idempotent — safe on every connection change (coalesced by the unique job).
     * Returns true if any contribution changed, so the caller rolls caches only
     * when something actually moved.
     */
    public function resolveForUser(User $user): bool
    {
        $site = $user->site()->first();
        if ($site === null) {
            return false;
        }
        $siteId = (string) $site->id;

        // Rebuild is read-then-write across several factors + a stale sweep;
        // wrap it in a transaction so a mid-rebuild failure can't leave the
        // site's contributions half-written. Explicit 'pgsql' connection (not
        // bare DB::transaction()) because BaseModel forces pgsql regardless of
        // the default connection config.
        return DB::connection('pgsql')->transaction(function () use ($user, $site, $siteId) {
            $connections = IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->active()
                ->get();

            /** @var Collection<string, Collection<int, DesignKitContribution>> $existing */
            $existing = DesignKitContribution::query()
                ->where('site_id', $siteId)
                ->get()
                ->groupBy('source');

            $desiredSources = [];
            $changed = false;

            foreach ($connections as $connection) {
                foreach ($this->registry->factorsFor((string) $connection->platform) as $factor) {
                    $source = $factor->key();
                    $desiredSources[] = $source;

                    // One-shot + already resolved => frozen: keep the existing rows,
                    // don't re-detect (the integration's data may have moved).
                    if ($factor->mode() === FactorMode::OneShot && $existing->has($source)) {
                        continue;
                    }

                    // A misbehaving factor degrades to an empty detection (its rows
                    // sweep) rather than failing the whole rebuild — symmetric with
                    // the site-factor loop below.
                    $values = [];
                    try {
                        $values = PresetTargetableColumns::filter($factor->detect($connection));
                    } catch (\Throwable $e) {
                        report($e);
                    }

                    $changed = $this->syncSource(
                        $siteId,
                        $source,
                        (string) $connection->platform,
                        $factor->priority(),
                        $factor->mode()->value,
                        $values,
                        $existing->get($source),
                    ) || $changed;
                }
            }

            // Site-level factors (previous-website analysis, cross-connection
            // aggregates) re-detect on EVERY resolve — their "frozenness" lives in
            // the stored analyses, not in contribution rows. A factor that throws
            // degrades to an empty detection (its rows sweep), never a failed job.
            foreach ($this->registry->siteFactors() as $siteFactor) {
                $source = $siteFactor->key();
                $desiredSources[] = $source;

                $values = [];
                try {
                    $values = PresetTargetableColumns::filter($siteFactor->detect($user, $site));
                } catch (\Throwable $e) {
                    report($e);
                }

                $changed = $this->syncSource(
                    $siteId,
                    $source,
                    $siteFactor->integrationLabel(),
                    $siteFactor->priority(),
                    FactorMode::Auto->value,
                    $values,
                    $existing->get($source),
                ) || $changed;
            }

            // Sweep contributions whose source no longer belongs to any active factor
            // (disconnect, or a factor that stopped applying). With zero desired
            // sources this clears every row for the site.
            $stale = DesignKitContribution::query()
                ->where('site_id', $siteId)
                ->when($desiredSources !== [], fn ($q) => $q->whereNotIn('source', $desiredSources))
                ->get();
            if ($stale->isNotEmpty()) {
                DesignKitContribution::query()->whereKey($stale->modelKeys())->delete();
                $changed = true;
            }

            return $changed;
        });
    }

    /**
     * Reconcile one factor's rows to its detected values: upsert desired columns,
     * delete columns it no longer sets.
     *
     * @param  array<string, string>  $values
     * @param  Collection<int, DesignKitContribution>|null  $existingRows
     */
    private function syncSource(
        string $siteId,
        string $source,
        string $integration,
        int $priority,
        string $mode,
        array $values,
        ?Collection $existingRows,
    ): bool {
        $existingByVar = ($existingRows ?? collect())->keyBy('target_var');

        // Change-detection pass — same comparison as before, but no writes yet.
        // Breaking on the first diff is enough: a single dirty column means the
        // whole batch upsert must run (it's one query either way).
        $valuesChanged = false;
        foreach ($values as $var => $value) {
            $row = $existingByVar->get($var);
            if ($row === null
                || (string) $row->value !== (string) $value
                || (int) $row->priority !== $priority
                || (string) $row->mode !== $mode
                || (string) $row->integration !== $integration) {
                $valuesChanged = true;
                break;
            }
        }

        if ($valuesChanged) {
            $rows = [];
            foreach ($values as $var => $value) {
                $rows[] = [
                    'id' => (string) Str::uuid(),   // REQUIRED: upsert bypasses HasUuids; SQLite test PK has no default
                    'site_id' => $siteId,
                    'source' => $source,
                    'integration' => $integration,
                    'priority' => $priority,
                    'mode' => $mode,
                    'target_var' => $var,
                    'value' => (string) $value,
                ];
            }
            DesignKitContribution::query()->upsert(
                $rows,
                ['site_id', 'source', 'target_var'],
                ['integration', 'priority', 'mode', 'value'],
            );
        }

        // Columns this factor no longer sets.
        $obsolete = $existingByVar->keys()->diff(array_keys($values));
        $obsoleteDeleted = false;
        if ($obsolete->isNotEmpty()) {
            DesignKitContribution::query()
                ->where('site_id', $siteId)
                ->where('source', $source)
                ->whereIn('target_var', $obsolete->all())
                ->delete();
            $obsoleteDeleted = true;
        }

        return $valuesChanged || $obsoleteDeleted;
    }
}
