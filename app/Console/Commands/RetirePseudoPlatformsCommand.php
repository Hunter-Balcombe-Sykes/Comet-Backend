<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Migration\PseudoPlatformRetirer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Convergence Phase 6 unit 5: move the live pseudo-platform connections to the
 * homes the disposition table gives them, then retire the rows.
 *
 * Idempotent and re-runnable — run it twice and the second run reports
 * `already done` for everything and changes nothing.
 *
 * The COVERAGE GATE is derived twice, independently, the way every backfill in
 * this programme has been (parent §8.4): once in PHP against the model layer,
 * once in SQL against the database. Two derivations that agree is evidence; one
 * derivation quoted back at itself is not. They disagree only if a row was
 * written between them, which is what the "run it on a quiet system" line in
 * the runbook is for.
 */
class RetirePseudoPlatformsCommand extends Command
{
    protected $signature = 'content:retire-pseudo-platforms {--dry-run : report what would change and write nothing}';

    protected $description = 'Convergence Phase 6: migrate every partna.* pseudo-platform connection to its real home and retire the row';

    public function handle(PseudoPlatformRetirer $retirer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $before = $this->liveCounts();
        if ($before === []) {
            $this->info('Nothing to do — no live pseudo-platform connections.');

            return self::SUCCESS;
        }

        $this->line('Live pseudo-platform connections before:');
        foreach ($before as $surface => $count) {
            $this->line(sprintf('  %-24s %d', $surface, $count));
        }
        $this->line(sprintf('  %-24s %d', 'TOTAL', array_sum($before)));

        $counts = $retirer->run($dryRun);

        $this->line('');
        $this->info(sprintf(
            '%srepointed %d, pooled %d, retired %d, shop brands repointed %d'
            .' | skipped: no url %d, no site %d | already done %d',
            $dryRun ? '[dry-run] would be: ' : '',
            $counts['repointed'], $counts['pooled'], $counts['retired'],
            $counts['shop_brands_repointed'],
            $counts['skipped_no_url'], $counts['skipped_no_site'], $counts['already_done'],
        ));

        // A skip is the one outcome that leaves content stranded — the row is
        // still live and its link is nowhere else. Loud, and non-zero exit, so a
        // deploy script cannot treat a partial run as a clean one.
        $stranded = $counts['skipped_no_url'] + $counts['skipped_no_site'];
        if ($stranded > 0) {
            $this->error("{$stranded} row(s) could not be migrated and were left live — see the counters above.");
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        $this->line('');
        $this->line('Coverage gate — derived twice, independently:');
        $php = $this->livePhp();
        $sql = $this->liveSql();
        $this->line(sprintf('  PHP (model layer)  live partna.* connections: %d', $php));
        $this->line(sprintf('  SQL (database)     live partna.* connections: %d', $sql));

        if ($php !== $sql) {
            $this->error('The two derivations DISAGREE — a row was written mid-run. Re-run and investigate.');

            return self::FAILURE;
        }

        // partna.manual_product is deliberately absent from RETIRED_SURFACES and
        // therefore from this count: it is hidden and dormant, not retired, and
        // it is where the individual-products bucket now anchors.
        if ($php > 0) {
            $this->error("{$php} live pseudo-platform connection(s) survive — the retirement is INCOMPLETE.");

            return self::FAILURE;
        }

        $this->info('0 live pseudo-platform connections remain.');

        return $stranded > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, int> surface => live count, non-zero only */
    private function liveCounts(): array
    {
        return IntegrationConnection::query()
            ->whereIn('surface_key', IntegrationConnection::RETIRED_SURFACES)
            ->selectRaw('surface_key, count(*) as c')
            ->groupBy('surface_key')
            ->pluck('c', 'surface_key')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** Derivation 1: through the model layer, with its soft-delete global scope. */
    private function livePhp(): int
    {
        return IntegrationConnection::query()
            ->whereIn('surface_key', IntegrationConnection::RETIRED_SURFACES)
            ->count();
    }

    /**
     * Derivation 2: raw SQL, with the soft-delete predicate written out by hand.
     *
     * Deliberately NOT the query builder off the model — the whole point is to
     * check the model layer's answer against one that does not share its scopes,
     * its casts or its assumptions. The surface list is passed as a bound array
     * rather than re-typed, because a second hand-written list would be a third
     * thing to keep in step, and drift there is exactly what this gate exists to
     * catch elsewhere.
     */
    private function liveSql(): int
    {
        return (int) DB::connection('pgsql')
            ->table('site.platform_connections')
            ->whereIn('surface_key', IntegrationConnection::RETIRED_SURFACES)
            ->whereNull('deleted_at')
            ->count();
    }
}
