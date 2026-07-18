<?php

namespace App\Console\Commands;

use App\Models\Core\HandleChangeLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// PRIV-2: enforces the existing config('partna.handle.audit_retention_years')
// retention (default 7y) on the append-only handle-rename audit log. app_backend
// has no DELETE on the audit schema, so the real deletion runs inside the
// SECURITY DEFINER audit.prune_handle_change_log() Postgres function (see
// 20260718010000_handle_change_log_retention_prune.sql), which briefly disables
// the append-only trigger for its own atomic delete.
class PruneHandleAuditLogs extends Command
{
    // Fat-finger floor: a misconfigured retention below 1 year would silently
    // shred fraud-investigation evidence far earlier than intended.
    private const MIN_RETENTION_YEARS = 1;

    protected $signature = 'handles:prune-audit-logs {--dry-run : Report the eligible count without deleting}';

    protected $description = 'Hard-delete audit.handle_change_log rows past the configured retention window.';

    public function handle(): int
    {
        $years = (int) config('partna.handle.audit_retention_years');

        if ($years < self::MIN_RETENTION_YEARS) {
            $this->error(sprintf(
                'Retention window must be at least %d year(s) (got %d). Check SIDEST_HANDLE_AUDIT_RETENTION_YEARS.',
                self::MIN_RETENTION_YEARS,
                $years
            ));

            return self::FAILURE;
        }

        $cutoff = now()->subYears($years);
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $eligible = HandleChangeLog::where('changed_at', '<', $cutoff)->count();
            $this->info("[DRY RUN] Would delete {$eligible} handle_change_log rows older than {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        // Deletion is confined to this SECURITY DEFINER RPC — app_backend cannot
        // DELETE from the audit schema directly (append-only REVOKE + trigger).
        $deleted = (int) DB::connection('pgsql')
            ->selectOne('SELECT audit.prune_handle_change_log(?) AS deleted', [$cutoff])
            ->deleted;

        $this->info("Deleted {$deleted} handle_change_log rows older than {$cutoff->toDateTimeString()}.");

        // Count only — never log row contents (handles, IPs) from an audit table.
        Log::info('handles.prune_audit_logs', [
            'deleted' => $deleted,
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        return self::SUCCESS;
    }
}
