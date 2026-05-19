<?php

namespace App\Console\Commands;

use App\Models\Commerce\CommissionExportAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CommissionExportsPruneExpiredCommand extends Command
{
    protected $signature = 'commission-exports:prune-expired';
    protected $description = 'Delete commission export files past their expires_at; clear file_path on the audit row.';

    public function handle(): int
    {
        $disk = Storage::disk(config('partna.media_disk'));
        $count = 0;

        CommissionExportAudit::dueForRetention()->chunk(100, function ($rows) use (&$count, $disk) {
            foreach ($rows as $audit) {
                if ($audit->file_path && $disk->exists($audit->file_path)) {
                    $disk->delete($audit->file_path);
                }
                $audit->forceFill(['file_path' => null])->save();
                Log::info('commission_export.pruned_expired', ['audit_id' => $audit->id]);
                $count++;
            }
        });

        $this->info("Pruned {$count} expired export file(s).");
        return self::SUCCESS;
    }
}
