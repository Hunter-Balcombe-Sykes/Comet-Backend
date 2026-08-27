<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// T2 (2026-08-27): the delayed leg of ConnectFetchJob's system-retry chain.
// A failed SYSTEM-initiated first fetch keeps its row and schedules this job
// instead of the F26 delete; at fire time it re-dispatches ConnectFetchJob.
//
// Its own class, not a delayed ConnectFetchJob self-dispatch, because
// ConnectFetchJob is ShouldBeUnique and its lock is still held inside
// handle() — a delayed self-dispatch there is silently dropped, and putting
// the attempt number in uniqueId() would break the auto/dashboard mutual
// exclusion ConnectFetchSystemInitiatedTest pins. Deliberately NOT unique:
// the ConnectFetchJob it dispatches carries the uniqueness, so a duplicate
// of this thin trigger costs one dropped dispatch, never a double write.
class ScheduleConnectRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** @var list<int> moot at one attempt; declared for the job-hygiene policy. */
    public array $backoff = [30];

    public int $timeout = 15;

    public function __construct(
        public readonly string $connectionId,
        public readonly string $platform,
    ) {
        $this->onQueue(config('partna.queues.platform_connect'));
    }

    public function handle(): void
    {
        // find() respects the soft-delete scope: a row the user disconnected
        // (or a pruned build tore down) while this retry waited is a no-op.
        $connection = IntegrationConnection::find($this->connectionId);
        if (! $connection || $connection->last_refresh_status === 'ok') {
            return;
        }

        Log::info('platform.connect_job.system_retry_firing', [
            'connection_id' => $this->connectionId,
            'platform' => $this->platform,
            'attempt' => (int) $connection->consecutive_failures + 1,
        ]);

        ConnectFetchJob::dispatch($this->connectionId, $this->platform, systemInitiated: true);
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        Log::warning('platform.connect_job.system_retry_trigger_failed', [
            'connection_id' => $this->connectionId,
            'platform' => $this->platform,
            'error' => $e->getMessage(),
        ]);
    }
}
