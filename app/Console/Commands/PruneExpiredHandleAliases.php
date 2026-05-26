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

        // Snapshot expired IDs before deletion to avoid TOCTOU: a rename between
        // the count query and the delete would produce a wrong affected-pro list.
        $expiredHandleIds = $pgsql->table('core.user_handle_aliases')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->pluck('id');

        $expiredSubdomainIds = $pgsql->table('site.site_subdomain_aliases')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->pluck('id');

        $handleCount   = $expiredHandleIds->count();
        $subdomainCount = $expiredSubdomainIds->count();

        $this->info("Expired handle aliases: {$handleCount}");
        $this->info("Expired subdomain aliases: {$subdomainCount}");

        if ($dry) {
            return self::SUCCESS;
        }

        // Capture affected pro IDs before deletion for KV re-sync.
        $affectedProIds = $expiredHandleIds->isNotEmpty()
            ? $pgsql->table('core.user_handle_aliases')
                ->whereIn('id', $expiredHandleIds)
                ->pluck('user_id')
                ->unique()
                ->values()
            : collect();

        $pgsql->transaction(function () use ($pgsql, $expiredHandleIds, $expiredSubdomainIds) {
            if ($expiredHandleIds->isNotEmpty()) {
                $pgsql->table('core.user_handle_aliases')
                    ->whereIn('id', $expiredHandleIds)
                    ->delete();
            }

            if ($expiredSubdomainIds->isNotEmpty()) {
                $pgsql->table('site.site_subdomain_aliases')
                    ->whereIn('id', $expiredSubdomainIds)
                    ->delete();
            }
        });

        foreach ($affectedProIds as $proId) {
            SyncSubdomainToKvJob::dispatch((string) $proId);
        }

        Log::info('handles.prune.completed', [
            'handle_aliases_deleted'    => $handleCount,
            'subdomain_aliases_deleted' => $subdomainCount,
            'pros_resynced'             => $affectedProIds->count(),
        ]);

        return self::SUCCESS;
    }
}
