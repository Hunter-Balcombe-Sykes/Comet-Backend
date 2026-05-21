<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\Professional;
use Illuminate\Console\Command;

// One-off backfill for the §28.6 individual KV branch.
// Iterates every professional whose account_type is NOT brand AND who has no active
// brand_partner_links row (i.e. the cohort whose KV entry should be {type:'individual'})
// and dispatches SyncSubdomainToKvJob for each. Idempotent — the job's ShouldBeUnique
// lock plus the deterministic KV value mean re-runs are safe.
//
// Why a separate command (vs reusing `partna:backfill-subdomain-kv --all`):
//   The generic command resyncs every handle, which is heavier than needed and rewrites
//   brand/affiliate KV entries that are already correct. This command is the targeted
//   sweep run once post-deploy to populate the individual entries the Worker now expects.
//
// Local-env caveat: this command iterates the DB and dispatches jobs. The local `.env`
// in this repo points at an unrelated Supabase project; run via Laravel Cloud / a host
// with the correct DB_* config (or invoke via Horizon's scheduled-task wiring).
class BackfillIndividualKvEntries extends Command
{
    protected $signature = 'partna:backfill-individual-kv-entries
                            {--chunk=500 : Chunk size when streaming professionals.}
                            {--dry-run : Count the target cohort and exit without dispatching.}
                            {--sync : Run jobs synchronously instead of queueing (debug only).}';

    protected $description = 'Dispatches SyncSubdomainToKvJob for every individual (non-brand, no active partner link) so the Cloudflare KV routing table gets the {type:"individual"} entry.';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync');

        // All users are now individual type — query is unconditional.
        $query = Professional::query()
            ->whereNotNull('handle')
            ->where('handle', '!=', '')
            ->where('account_type', 'individual');

        $total = (clone $query)->count();
        $this->info("Target cohort: {$total} individual professional(s).");

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
