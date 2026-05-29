<?php

namespace App\Console\Commands\Moderation;

use App\Models\Moderation\CsamQuarantine;
use App\Services\Moderation\ModerationAuditService;
use App\Services\Moderation\R2QuarantineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ModerationExpireCsamQuarantineCommand extends Command
{
    // Cap per-run batch to avoid OOM on a delayed backlog. In steady state
    // the expiry window is small; 500 rows gives a safe upper bound.
    private const BATCH_LIMIT = 500;

    protected $signature = 'moderation:expire-csam-quarantine';
    protected $description = 'Delete R2 binaries for csam_quarantine rows past their 90-day preservation window.';

    public function handle(R2QuarantineService $r2, ModerationAuditService $audit): int
    {
        $expired = CsamQuarantine::query()
            ->where('r2_binary_deleted', false)
            ->where('preservation_expires_at', '<', now())
            ->limit(self::BATCH_LIMIT)
            ->get();

        $deleted = 0;
        foreach ($expired as $row) {
            try {
                $r2->deleteQuarantineBinary($row->r2_quarantine_key);
                $row->update([
                    'r2_binary_deleted'    => true,
                    'r2_binary_deleted_at' => now(),
                ]);

                $audit->recordSystemAction(
                    'csam.quarantine_binary_deleted',
                    'CsamQuarantine',
                    $row->id,
                    ['r2_key' => $row->r2_quarantine_key],
                );
                $deleted++;
            } catch (\Throwable $e) {
                Log::error('moderation.csam_quarantine.delete_failed', [
                    'quarantine_id' => $row->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        $this->info("Expired {$deleted} quarantine binaries.");
        return self::SUCCESS;
    }
}
