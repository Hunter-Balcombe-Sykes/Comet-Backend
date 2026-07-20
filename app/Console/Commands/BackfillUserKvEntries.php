<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\User\User;
use Illuminate\Console\Command;

// One-off backfill for {type:"individual"} KV entries in the Cloudflare routing
// table. "individual" here is the worker_kv_type routing discriminator every
// account renders under (AccountCapabilities), unrelated to core.users.account_type
// — which has not permitted 'individual' since migration 20260612120000 (see the
// inline comment on the query below). The query dispatches SyncSubdomainToKvJob for
// every user with a handle. Idempotent: the job's ShouldBeUnique lock plus the
// deterministic KV value mean re-runs are safe.
//
// Local-env caveat: this command iterates the DB and dispatches jobs. Run via
// Laravel Cloud / a host with the correct DB_* config, or invoke via Horizon's
// scheduled-task wiring.
class BackfillUserKvEntries extends Command
{
    protected $signature = 'partna:backfill-user-kv-entries
                            {--chunk=500 : Chunk size when streaming users.}
                            {--dry-run : Count the target cohort and exit without dispatching.}
                            {--sync : Run jobs synchronously instead of queueing (debug only).}';

    protected $description = 'Dispatches SyncSubdomainToKvJob for every user with a handle so the Cloudflare KV routing table gets the {type:"individual"} entry.';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync');

        // No account_type filter: every account renders an individual sitepage,
        // and 'individual' has not been a legal value since 20260612120000 — this
        // filter matched zero rows and made the command a silent no-op at cutover.
        // Cohort gates (moderation-hidden, unclaimed TTL, expiry) live in
        // SyncSubdomainToKvJob, the single authority. Do not duplicate them here.
        $query = User::query()
            ->whereNotNull('handle')
            ->where('handle', '!=', '');

        $total = (clone $query)->count();
        $this->info("Target cohort: {$total} user(s).");

        if ($dryRun || $total === 0) {
            return self::SUCCESS;
        }

        $dispatched = 0;
        $query->orderBy('id')->chunkById($chunk, function ($pros) use (&$dispatched, $sync) {
            foreach ($pros as $pro) {
                if ($sync) {
                    SyncSubdomainToKvJob::dispatchSync((string) $pro->id);
                } else {
                    SyncSubdomainToKvJob::dispatch((string) $pro->id);
                }
                $dispatched++;
            }
            $this->line("  dispatched: {$dispatched} in chunk");
        });

        $this->info("Backfill complete. Dispatched {$dispatched} job(s).");

        return self::SUCCESS;
    }
}
