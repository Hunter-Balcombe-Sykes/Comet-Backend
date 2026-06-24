<?php

namespace App\Console\Commands;

use App\Models\Core\Gdpr\DataExportAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PRIV-2: Enforce the GDPR export file retention window.
 *
 * Rows in audit.data_export_audit are KEPT (they are the legal record that an
 * export happened). Only the R2 ZIP artifact and its DB columns are removed.
 *
 * Ordering: delete the R2 file FIRST, then null the columns. If we crash
 * between the two, the row points at a now-gone file instead of an orphaned
 * R2 object — the safer failure mode.
 */
class PruneCompletedExportsCommand extends Command
{
    protected $signature = 'gdpr:prune-completed-exports
                            {--days= : Override retention window (default from config gdpr.export_retention_days)}
                            {--dry-run : Report eligible rows without deleting or nulling anything}';

    protected $description = 'Null the R2 file path/size/hash on completed GDPR export rows older than the retention window. '
        .'The audit row itself is KEPT. Runs in a lazy cursor to keep memory bounded.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('partna.gdpr.export_retention_days', 30));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%s GDPR export artifacts older than %d days (cutoff: %s).',
            $dryRun ? '[DRY RUN] Would prune' : 'Pruning',
            $days,
            $cutoff->toDateTimeString()
        ));

        $disk = Storage::disk((string) config('partna.media_disk'));

        // Only rows that still hold an artifact — those already pruned (file_path IS NULL)
        // are skipped so repeated runs are a cheap no-op.
        $query = DataExportAudit::query()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('file_path');

        if ($dryRun) {
            $count = $query->count();
            $this->info("Would prune {$count} export artifact(s).");

            return self::SUCCESS;
        }

        $pruned = 0;

        $query->cursor()->each(function (DataExportAudit $audit) use ($disk, &$pruned): void {
            $path = $audit->file_path;

            // Step 1: delete the R2 artifact. If this succeeds but the DB update
            // below fails, the row points at a missing file — acceptable.
            // If R2 errors, we skip the row so the next run retries.
            try {
                if ($path !== null && $disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (\Throwable $e) {
                Log::warning('gdpr.prune_exports: R2 delete failed — skipping row', [
                    'id' => $audit->id,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            // Step 2: null the artifact columns. The audit row itself is preserved —
            // the GDPR record that an export happened must survive. update() uses
            // the UPDATE grant re-granted by 20260624000000_grant_update_data_export_audit.sql.
            $audit->update([
                'file_path' => null,
                'file_size_bytes' => null,
                'file_sha256' => null,
            ]);

            $pruned++;
        });

        $this->info("Pruned {$pruned} export artifact(s). Audit rows retained.");

        Log::info('gdpr.prune_exports.complete', [
            'days' => $days,
            'cutoff' => $cutoff->toIso8601String(),
            'pruned' => $pruned,
        ]);

        return self::SUCCESS;
    }
}
