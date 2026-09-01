<?php

namespace App\Console\Commands;

use App\Ingest\FacebookEventsSourceProvisioner;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

// Item 11a's "EXISTING connection becomes an events source" half: the
// observer hook only fires on a save, so the facebook connections already
// live when this ships need a one-shot (idempotent, re-runnable) walk.
// Provisions through the SAME FacebookEventsSourceProvisioner the observer
// runs, so backfilled rows and connect-time rows can never drift — the
// IngestBackfillSourcesCommand convention, scoped to the one satellite that
// command's brand-derived mapping cannot reach.
//
// Provisioning is free; RUNNING is paid. Backfilled rows land auto_sync=false
// (CostClass::Actor) and, deliberately, with NO trigger: --eager is the
// explicit spend decision, stamping needs_eager_run so SourceScheduler feeds
// each row once under the 'facebook_events' ScrapeCreatorsBudget cap — the
// same one-shot obligation a connect-time eager run records (#LIFE-5).
class IngestBackfillFacebookEventsCommand extends Command
{
    protected $signature = 'ingest:backfill-facebook-events
        {--user= : Only this user id}
        {--dry-run : Report what would happen without writing}
        {--eager : Stamp needs_eager_run on created rows so the scheduler runs each once (paid)}';

    protected $description = 'Provision facebook_events ingest.sources rows for every existing Facebook page connection.';

    public function handle(FacebookEventsSourceProvisioner $provisioner): int
    {
        $query = IntegrationConnection::query()
            ->where('surface_key', 'like', 'facebook.%')
            ->whereNull('resource_kind')
            ->orderBy('created_at');

        if ($this->option('user') !== null) {
            $query->where('user_id', (string) $this->option('user'));
        }

        $dryRun = (bool) $this->option('dry-run');
        $eager = (bool) $this->option('eager');
        $tally = [];
        $skips = [];
        $failures = 0;

        foreach ($query->cursor() as $connection) {
            if ($dryRun) {
                $tally['would_process'] = ($tally['would_process'] ?? 0) + 1;
                $this->line("would process: facebook_events connection {$connection->id}");

                continue;
            }

            try {
                $result = $provisioner->sync($connection);
            } catch (Throwable $e) {
                report($e);
                $failures++;
                $this->error("failed: facebook_events connection {$connection->id} — {$e->getMessage()}");

                continue;
            }

            $tally[$result['status']] = ($tally[$result['status']] ?? 0) + 1;

            if ($result['status'] === 'skipped') {
                $skips[] = [$connection->id, $result['reason'] ?? '?'];
            }

            if ($eager && $result['status'] === 'created') {
                // The obligation, not a dispatch: scoreDue() selects
                // needs_eager_run rows despite auto_sync=false, and release()
                // clears the flag once a run actually lands — so the spend
                // spreads over scheduler ticks under the daily cap instead of
                // bursting from this loop.
                DB::table('ingest.sources')
                    ->where('connection_id', $connection->id)
                    ->where('source_key', 'facebook_events')
                    ->update(['needs_eager_run' => true, 'updated_at' => now()]);
                $tally['eager_stamped'] = ($tally['eager_stamped'] ?? 0) + 1;
            }
        }

        ksort($tally);
        $this->table(['outcome', 'count'], array_map(null, array_keys($tally), array_values($tally)));

        if ($skips !== []) {
            $this->warn('Connections that did not provision:');
            $this->table(['connection', 'reason'], $skips);
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
