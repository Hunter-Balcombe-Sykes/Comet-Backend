<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneExpiredHandleAliases extends Command
{
    protected $signature = 'handles:prune-expired-aliases {--dry-run : Report counts without deleting}';

    protected $description = 'Hard-delete handle/subdomain aliases past their expires_at and re-sync Cloudflare KV.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $pgsql = DB::connection('pgsql');

        // Fix the expiry boundary once so the counts, the affected-pro lookup, and
        // the deletes all see the same cutoff — a rename mid-run can't shift the
        // set (the TOCTOU the old id-snapshot guarded against). SCALE-9: delete
        // in-place by this predicate instead of plucking every expired id into
        // memory and issuing DELETE ... WHERE id IN (huge list).
        $cutoff = now();

        $expiredHandles = fn () => $pgsql->table('core.user_handle_aliases')
            ->whereNotNull('expires_at')->where('expires_at', '<', $cutoff);
        $expiredSubdomains = fn () => $pgsql->table('site.site_subdomain_aliases')
            ->whereNotNull('expires_at')->where('expires_at', '<', $cutoff);

        $handleCount = $expiredHandles()->count();
        $subdomainCount = $expiredSubdomains()->count();

        $this->info("Expired handle aliases: {$handleCount}");
        $this->info("Expired subdomain aliases: {$subdomainCount}");

        if ($dry) {
            return self::SUCCESS;
        }

        // Capture affected pro IDs before deletion for KV re-sync. Only the
        // distinct user_ids (one per affected pro) are materialised — not every
        // expired alias id.
        $affectedProIds = $handleCount > 0
            ? $expiredHandles()->distinct()->pluck('user_id')->filter()->values()
            : collect();

        $pgsql->transaction(function () use ($expiredHandles, $expiredSubdomains) {
            $expiredHandles()->delete();
            $expiredSubdomains()->delete();
        });

        foreach ($affectedProIds as $proId) {
            SyncSubdomainToKvJob::dispatch((string) $proId);
        }

        Log::info('handles.prune.completed', [
            'handle_aliases_deleted' => $handleCount,
            'subdomain_aliases_deleted' => $subdomainCount,
            'pros_resynced' => $affectedProIds->count(),
        ]);

        return self::SUCCESS;
    }
}
