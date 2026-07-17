<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Segments\SegmentResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * OV-A staff kill-switch takedown. Flips is_active=false on connections of a
 * disabled platform — globally (segmentId null) or for one segment's members —
 * so existing content stops rendering (public payload filters is_active=true).
 * Per-model save fires IntegrationConnectionObserver, busting each site's cache.
 * No data deleted: only the flag changes. Re-enable does NOT reactivate.
 */
class ReconcilePlatformTakedownJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public array $backoff = [30, 120, 300];

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public string $platform,
        public ?string $segmentId = null,
    ) {
        $this->onQueue(config('partna.queues.platform_refresh'));
    }

    public function handle(SegmentResolver $resolver): void
    {
        $query = IntegrationConnection::query()
            ->where('platform', $this->platform)
            ->active();

        if ($this->segmentId !== null) {
            $segment = UserSegment::query()->find($this->segmentId);
            if ($segment === null) {
                return; // segment removed between dispatch and run
            }
            $userIds = $resolver->userIds($segment);
            if ($userIds === []) {
                return;
            }
            $query->whereIn('user_id', $userIds);
        }

        // chunkById is safe while mutating is_active: pages by ascending id, and
        // flipped rows drop out of the active() filter without being revisited.
        $query->chunkById(200, function ($connections): void {
            foreach ($connections as $connection) {
                $connection->is_active = false;
                $connection->save(); // per-model save so the observer busts each site's cache
            }
        });
    }
}
