<?php

namespace App\Console\Commands;

use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\BuildSettleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Asks recently-created builds whether they have finished filling in.
 *
 * A timer rather than an event hook because three of the settle rule's terms
 * have no event to fire on: the media counts come from media rows, not the
 * progress ledger; stillConnecting() reads content.storefronts and
 * ingest.sources directly; and a started-but-unanswered stage stops blocking
 * after OWED_MINUTES -- a transition caused by time passing, for which there
 * is no event. Something has to look.
 *
 * The window is what keeps this cheap AND what makes the cutover safe: cost
 * scales with builds in flight rather than table size, and every build that
 * predates this feature is far older than the window, so no backfill was
 * needed.
 */
class SettleSweepCommand extends Command
{
    protected $signature = 'builds:settle-sweep {--window=30 : Only builds created within this many minutes}';

    protected $description = 'Stamp and act on pre-account builds that have reached a terminal setup outcome.';

    public function handle(BuildSettleService $settle): int
    {
        $window = max(1, (int) $this->option('window'));

        $builds = PreAccountBuild::query()
            ->where('created_at', '>=', now()->subMinutes($window))
            ->whereNull('settled_at')
            ->whereNull('setup_stalled_at')
            ->get();

        $counts = [];
        foreach ($builds as $build) {
            try {
                $outcome = $settle->evaluate($build);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            } catch (\Throwable $e) {
                // One bad build must not cost the rest of the batch its tick.
                report($e);
                $this->warn("build {$build->id}: {$e->getMessage()}");
            }
        }

        if ($counts !== []) {
            Log::info('builds.settle_sweep', ['window_minutes' => $window] + $counts);
        }
        $this->info('Swept '.$builds->count().' build(s): '.json_encode($counts));

        return self::SUCCESS;
    }
}
