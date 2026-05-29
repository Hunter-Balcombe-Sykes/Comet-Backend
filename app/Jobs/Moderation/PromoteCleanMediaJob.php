<?php

namespace App\Jobs\Moderation;

use App\Services\Cloudflare\CloudflareCsamScanClient;
use App\Services\Moderation\R2QuarantineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every 60s. Picks up site_media rows stuck in 'scanning',
 * polls Cloudflare for scan status, promotes clean ones.
 * Bounded batch (100 rows per run) so backlogs don't stall the job.
 *
 * Match notifications come via the webhook (CloudflareCsamWebhookController);
 * this job only handles the no-match → promote pathway.
 */
class PromoteCleanMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 60;

    public function handle(
        CloudflareCsamScanClient $client,
        R2QuarantineService $r2,
    ): void {
        $rows = DB::connection('pgsql')->select(<<<'SQL'
            SELECT id, site_id, bucket, path
            FROM site.site_media
            WHERE processing_state = 'scanning'
              AND scanned_at IS NULL
            ORDER BY created_at ASC
            LIMIT 100
        SQL);

        foreach ($rows as $row) {
            $status = $client->statusFor($row->path);

            if ($status === 'pending' || $status === 'error') {
                continue;
            }

            if ($status === 'clean') {
                $this->promote($row, $r2);
            }

            // 'match' is handled by the webhook path — don't double-process here.
        }
    }

    private function promote(object $row, R2QuarantineService $r2): void
    {
        $newKey = str_replace('quarantine/', '', $row->path);

        try {
            $r2->promoteToProduction($row->path, $newKey);
        } catch (\Throwable $e) {
            Log::warning('moderation.promote_clean.failed', [
                'site_media_id' => $row->id,
                'r2_key'        => $row->path,
                'error'         => $e->getMessage(),
            ]);
            return;
        }

        DB::connection('pgsql')->update(<<<'SQL'
            UPDATE site.site_media
            SET processing_state = 'ready',
                scanned_at       = ?,
                bucket           = 'public-assets',
                path             = ?,
                updated_at       = ?
            WHERE id = ?
        SQL, [now(), $newKey, now(), $row->id]);
    }
}
