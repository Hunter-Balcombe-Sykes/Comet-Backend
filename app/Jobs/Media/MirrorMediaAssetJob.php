<?php

namespace App\Jobs\Media;

use App\Services\Media\MediaMirror;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Slice 1b: fetch an owned-class media asset's bytes to R2 after projection.
 *
 * Deferred to a job rather than run inline because the projection run is on the
 * ingest hot path and a mirror is a network fetch plus an image re-encode. A
 * failure here degrades to "no bytes yet" — MediaMirror returns false and logs,
 * and the asset still resolves through its source_url until the next sync.
 */
class MirrorMediaAssetJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $userId,
        public readonly string $assetId,
        public readonly string $sourceUrl,
    ) {
        $this->onQueue(config('partna.queues.images', 'images'));
        // Fire only after the projection transaction commits — the asset row
        // must exist before the worker looks for it. Set on the INSTANCE, not
        // redeclared as a property: Queueable already declares $afterCommit
        // untyped, and re-declaring it as `public bool` is a fatal
        // incompatible-composition error at class-load time (which surfaces as
        // a runner crash with no output, not a red test).
        $this->afterCommit = true;
    }

    /** One in-flight mirror per asset — a retried projection run must not pile them up. */
    public function uniqueId(): string
    {
        return $this->assetId;
    }

    public function handle(MediaMirror $mirror): void
    {
        $mirror->mirror($this->userId, $this->assetId, $this->sourceUrl);
    }
}
