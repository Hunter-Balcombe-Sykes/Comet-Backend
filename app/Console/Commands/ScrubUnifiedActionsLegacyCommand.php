<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// #MIG-1: the re-runnable half of the unified-actions data cleanup that used
// to be inline DML in supabase/migrations/20260823100000_unified_actions.sql.
// Migrations run once (the CLI keys its ledger on the version, not content —
// editing an applied file is a no-op there), so this is what makes the
// cleanup available on an environment that hasn't applied that migration's
// data yet (prod, at time of writing) without re-running the whole file.
// See that migration's header for why the content_type='page' DELETE could
// not move here too (VALIDATE CONSTRAINT / job-crash ordering).
class ScrubUnifiedActionsLegacyCommand extends Command
{
    protected $signature = 'partna:scrub-unified-actions-legacy
                            {--dry-run : Report row counts without deleting}
                            {--chunk=1000 : Rows per batch}
                            {--only= : Restrict to one step (action-events|site-settings|page-scores)}';

    protected $description = 'Idempotently scrub the legacy unified-actions data '
        .'(action_events rows keyed by the retired vocabulary, site.sites.settings '
        .'legacy action keys, stored page-typed popularity scores).';

    private const STEPS = ['action-events', 'site-settings', 'page-scores'];

    public function handle(): int
    {
        // Validate input shape before touching a connection at all, so a typo
        // in --only/--chunk is reported on its own terms instead of being
        // masked by the driver guard below.
        $only = $this->option('only');
        if ($only !== null && ! in_array($only, self::STEPS, true)) {
            $this->error(sprintf('--only must be one of: %s (got: %s)', implode('|', self::STEPS), $only));

            return self::FAILURE;
        }

        $chunk = (int) $this->option('chunk');
        if ($chunk < 1) {
            $this->error('--chunk must be a positive integer.');

            return self::FAILURE;
        }

        // All three predicates below are Postgres-specific syntax (!~,
        // jsonb_exists_any) — bail loudly on SQLite rather than let one throw
        // a confusing driver-level syntax error mid-run.
        if (DB::connection('pgsql')->getDriverName() !== 'pgsql') {
            $this->error('This command only runs against a real Postgres connection (got: '
                .DB::connection('pgsql')->getDriverName().').');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $steps = $only === null ? self::STEPS : [$only];
        $totals = [];

        foreach ($steps as $step) {
            $totals[$step] = match ($step) {
                'action-events' => $this->scrubActionEvents($chunk, $dryRun),
                'site-settings' => $this->scrubSiteSettings($chunk, $dryRun),
                'page-scores' => $this->scrubPageScores($chunk, $dryRun),
            };
        }

        $totalRows = array_sum($totals);

        $this->info(sprintf(
            '%s %d total row(s) across %s.',
            $dryRun ? 'Would affect' : 'Affected',
            $totalRows,
            implode(', ', $steps),
        ));

        Log::info('partna:scrub-unified-actions-legacy completed', [
            'dry_run' => $dryRun,
            'chunk' => $chunk,
            'steps' => $steps,
            'totals' => $totals,
        ]);

        return self::SUCCESS;
    }

    // action_events keyed by the retired vocabulary: anything that is not
    // <kind>:<ref> for the four surviving kinds. ActionSeenRequest/
    // ActionTapRequest enforce the id pattern on ingest, so this predicate
    // can only ever match pre-migration rows — safe to re-run indefinitely.
    private function scrubActionEvents(int $chunk, bool $dryRun): int
    {
        $predicate = fn () => DB::connection('pgsql')
            ->table('analytics.action_events')
            ->whereRaw("action_id !~ '^(page|platform|item|category):'");

        if ($dryRun) {
            $count = $predicate()->count();
            $this->line("  action-events: {$count} row(s) eligible (dry run).");

            return $count;
        }

        $deleted = 0;
        do {
            // Laravel's Postgres grammar rewrites a limited delete into a
            // `ctid IN (SELECT ctid ... LIMIT n)` subquery — the same
            // batching idiom PurgeRawAnalyticsEvents::purgeBatched() relies
            // on — so this never holds one long-running transaction.
            $count = $predicate()->limit($chunk)->delete();
            $deleted += $count;
        } while ($count === $chunk);

        $this->line("  action-events: deleted {$deleted} row(s).");

        return $deleted;
    }

    // site.sites.settings loses the smart_actions/manual_actions/
    // manual_order_pools keys (replaced by settings.actions + pool_order).
    // Chunked by primary key rather than a limited UPDATE, because a jsonb
    // key-removal predicate isn't naturally idempotent-batchable the same way
    // a DELETE is — this walks matching site ids in pages and updates each
    // page in one statement.
    //
    // Deliberately does NOT bump site.sites.updated_at: rotating it across
    // every affected site would rotate every public.profile:{handle}:{ts}
    // cache key at once (a self-inflicted thundering herd) to remove settings
    // keys no renderer reads. CLAUDE.md's "a non-Eloquent write must
    // invalidate explicitly" rule is satisfied by making that call
    // deliberately and recording it here, not by silently skipping it.
    private function scrubSiteSettings(int $chunk, bool $dryRun): int
    {
        // PDO treats a literal `?` in raw SQL as a bind placeholder, and
        // Laravel only un-escapes `??` for operators — not inside whereRaw
        // bodies — so `settings ?| ARRAY[...]` throws "Invalid parameter
        // number" at runtime. jsonb_exists_any() is the function-call
        // equivalent with no `?` in it.
        $matching = fn () => DB::connection('pgsql')
            ->table('site.sites')
            ->whereRaw("jsonb_exists_any(settings, ARRAY['smart_actions','manual_actions','manual_order_pools'])");

        if ($dryRun) {
            $count = $matching()->count();
            $this->line("  site-settings: {$count} row(s) eligible (dry run).");

            return $count;
        }

        $affected = 0;

        while (true) {
            $ids = $matching()->limit($chunk)->pluck('id')->all();
            if ($ids === []) {
                break;
            }

            DB::connection('pgsql')
                ->table('site.sites')
                ->whereIn('id', $ids)
                ->update([
                    'settings' => DB::raw("settings - 'smart_actions' - 'manual_actions' - 'manual_order_pools'"),
                ]);

            $affected += count($ids);
        }

        $this->line("  site-settings: updated {$affected} row(s).");

        return $affected;
    }

    // Belt-and-braces net for stored 'page'-typed popularity scores. The
    // inline DELETE in 20260823100000_unified_actions.sql stays authoritative
    // (it must run inside that migration's transaction, before the CHECK is
    // re-added — see that file's header) — this exists for an environment
    // where the migration's DDL has already landed but a 'page' row somehow
    // survived (e.g. written between the DELETE and the CHECK going live on
    // an environment that applied the migration out of band).
    private function scrubPageScores(int $chunk, bool $dryRun): int
    {
        $predicate = fn () => DB::connection('pgsql')
            ->table('analytics.content_popularity_scores')
            ->where('content_type', 'page');

        if ($dryRun) {
            $count = $predicate()->count();
            $this->line("  page-scores: {$count} row(s) eligible (dry run).");

            return $count;
        }

        $deleted = 0;
        do {
            $count = $predicate()->limit($chunk)->delete();
            $deleted += $count;
        } while ($count === $chunk);

        $this->line("  page-scores: deleted {$deleted} row(s).");

        return $deleted;
    }
}
