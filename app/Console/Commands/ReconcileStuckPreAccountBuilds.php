<?php

namespace App\Console\Commands;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * LIFE-4: reconciles pre_account_builds stuck in pending/building.
 *
 * GeneratePreAccountSiteJob (tries=1, timeout=300s) can be killed mid-run
 * (worker OOM/SIGKILL) without ever calling failed() — its ShouldBeUnique lock
 * (uniqueFor=600s) also blocks a same-build retry from firing for up to 10
 * minutes after. A build still in pending/building past the SLA can no longer
 * be legitimately in flight, so this command marks it honestly failed.
 * Re-dispatch is NOT this command's job — that happens the next time the build
 * is reserve()'d (PreAccountBuildService::reserve(), which independently treats
 * a still-stuck build the same way) or a retry request comes in. Mirrors
 * media:cleanup-stuck-processing's design.
 */
class ReconcileStuckPreAccountBuilds extends Command
{
    protected $signature = 'builds:reconcile-stuck {--dry-run : Report counts without updating}';

    protected $description = 'Fail pre_account_builds stuck in pending/building past the SLA.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sla = (int) config('partna.pre_account.stuck_build_sla_minutes', 30);
        $cutoff = now()->subMinutes($sla);

        $query = PreAccountBuild::live()
            ->whereIn('build_state', [PreAccountBuild::STATE_PENDING, PreAccountBuild::STATE_BUILDING])
            ->where('updated_at', '<', $cutoff);

        if ($dryRun) {
            $count = (clone $query)->count();
            $this->info("[dry-run] {$count} stuck pre-account build(s) older than {$sla}m.");

            return self::SUCCESS;
        }

        $total = 0;

        // chunkById (not chunk): rows leave the pending/building filter as we fail
        // them, so an offset-based chunk() would skip every other page.
        $query->chunkById(100, function ($rows) use (&$total) {
            foreach ($rows as $build) {
                $wasState = $build->build_state;
                $ageMinutes = $build->updated_at->diffInMinutes(now());

                $build->update([
                    'build_state' => PreAccountBuild::STATE_FAILED,
                    'failure_code' => PreAccountBuild::FAILURE_STUCK_TIMEOUT,
                ]);

                Log::warning('pre_account.cleanup.swept_stuck', [
                    'build_id' => $build->id,
                    'was_state' => $wasState,
                    'age_minutes' => $ageMinutes,
                ]);
                $total++;
            }
        });

        $this->info("Swept {$total} stuck pre-account build(s) → failed.");

        return self::SUCCESS;
    }
}
