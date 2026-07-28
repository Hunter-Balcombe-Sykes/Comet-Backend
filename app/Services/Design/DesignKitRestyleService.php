<?php

namespace App\Services\Design;

use App\Models\Core\Site\DesignKitRestyle;
use App\Models\Core\Site\Site;
use App\Services\Cache\SiteCacheInvalidator;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Restyle from brand" (plan §13): preview diff → the user applies → an undo
 * snapshot lands in site.design_kit_restyles. NEVER silent — nothing here
 * runs from an observer or a job; the only callers are explicit user actions.
 *
 * Why apply is "clear + fill" and not a plain write: §13 keeps the existing
 * null=reset-to-auto semantics. A chosen column's manual value is cleared and
 * the brand-derived value takes the same seat every persisted auto-write uses
 * — so a later "reset to auto" behaves identically on a restyled column and a
 * hand-set one. Pre-existing applier-written accents carry no provenance and
 * are treated as manual (§13): they show in the diff as "current", and only
 * an explicit apply replaces them — this flow IS the one-time upgrade path
 * the banner offers.
 */
class DesignKitRestyleService
{
    public function __construct(
        private readonly DesignKitAutopilot $autopilot,
        private readonly SiteCacheInvalidator $invalidator,
    ) {}

    /**
     * The diff the RestylePreviewDialog renders. Empty proposals carry an
     * honest reason (neutral wordmark / no palette yet) instead of an
     * invented accent.
     *
     * @return array{
     *     reason: ?string,
     *     changes: list<array{column: string, current: mixed, proposed: string, currentIsManual: bool}>,
     * }
     */
    public function preview(Site $site): array
    {
        $derived = $this->autopilot->fromBrandPalette((string) $site->id);
        $kit = $this->kitRow((string) $site->id);

        $changes = [];
        foreach ($derived['proposals'] as $column => $proposed) {
            $current = $kit[$column] ?? null;
            if ($current === $proposed) {
                continue; // Already wearing it — nothing to offer.
            }

            $changes[] = [
                'column' => $column,
                'current' => $current,
                'proposed' => $proposed,
                // No provenance column exists; non-null IS manual (§13 — the
                // treat-applier-writes-as-manual rule).
                'currentIsManual' => $current !== null,
            ];
        }

        return ['reason' => $derived['reason'], 'changes' => $changes];
    }

    /**
     * Apply the chosen columns, snapshotting the whole kit row first so undo
     * is a restore rather than arithmetic.
     *
     * @param  list<string>  $columns
     */
    public function apply(Site $site, array $columns): DesignKitRestyle
    {
        $derived = $this->autopilot->fromBrandPalette((string) $site->id);
        $applicable = array_intersect_key($derived['proposals'], array_flip($columns));

        if ($applicable === []) {
            throw ValidationException::withMessages([
                'columns' => 'None of those areas has a brand-derived value to apply.',
            ]);
        }

        $restyle = DB::connection('pgsql')->transaction(function () use ($site, $applicable): DesignKitRestyle {
            $siteId = (string) $site->id;

            DB::connection('pgsql')->table('site.design_kits')
                ->where('site_id', $siteId)->lockForUpdate()->first();

            // Snapshot AFTER the lock: the undo must restore exactly what the
            // apply displaced, not what a racing write left behind.
            $snapshot = $this->kitRow($siteId);

            $restyle = new DesignKitRestyle([
                'snapshot' => $snapshot,
                'applied_at' => now(),
            ]);
            $restyle->site_id = $siteId;
            $restyle->save();

            DB::connection('pgsql')->table('site.design_kits')->updateOrInsert(
                ['site_id' => $siteId],
                [...$applicable, 'updated_at' => now()],
            );

            return $restyle;
        });

        $this->touched($site);

        return $restyle;
    }

    /** Restore the snapshot. Refuses a second undo of the same row. */
    public function undo(Site $site, DesignKitRestyle $restyle): void
    {
        if ($restyle->undone_at !== null) {
            throw ValidationException::withMessages([
                'restyle' => 'That restyle has already been undone.',
            ]);
        }

        DB::connection('pgsql')->transaction(function () use ($site, $restyle) {
            $siteId = (string) $site->id;
            $snapshot = (array) $restyle->snapshot;

            DB::connection('pgsql')->table('site.design_kits')
                ->where('site_id', $siteId)->lockForUpdate()->first();

            // Full-row restore, nulls included — a column the restyle filled
            // from empty goes back to empty (reset-to-auto), not to a value.
            DB::connection('pgsql')->table('site.design_kits')->updateOrInsert(
                ['site_id' => $siteId],
                [...$snapshot, 'updated_at' => now()],
            );

            $restyle->undone_at = now();
            $restyle->save();
        });

        $this->touched($site);
    }

    /**
     * The kit's var columns as stored — identity and bookkeeping stripped, so
     * a snapshot restore can never touch site_id or timestamps.
     *
     * @return array<string, mixed>
     */
    private function kitRow(string $siteId): array
    {
        $row = DB::connection('pgsql')->table('site.design_kits')
            ->where('site_id', $siteId)
            ->first();

        if ($row === null) {
            return [];
        }

        $vars = (array) $row;
        unset($vars['site_id'], $vars['created_at'], $vars['updated_at']);

        return $vars;
    }

    private function touched(Site $site): void
    {
        BuildState::bump((string) $site->id);
        $this->invalidator->touchSite(fn () => $site, 'design-restyle', ['site_id' => (string) $site->id]);
    }
}
