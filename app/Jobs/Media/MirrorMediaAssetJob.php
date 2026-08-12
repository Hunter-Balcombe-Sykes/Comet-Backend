<?php

namespace App\Jobs\Media;

use App\Services\Media\MediaMirror;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

    /**
     * MediaMirror swallows its own failures and returns false, so a throw here
     * means infrastructure (Redis, R2, the disk) rather than a dead CDN link —
     * worth a few short retries, not many.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** Fetch + GD re-encode + object store put, over a third-party CDN. */
    public int $timeout = 120;

    /**
     * MUST exceed $timeout. ShouldBeUnique's lock is a cache lock with
     * TTL = $uniqueFor, force-released only on a CLEAN finish — set it below
     * $timeout and a mirror still running past the TTL loses its dedupe, so a
     * concurrent dispatch starts a second fetch of the same bytes. Without any
     * default it is worse: a PERMANENT lock (SETNX, no expiry) that a killed
     * worker strands forever, silently discarding every later dispatch for
     * that asset.
     */
    public int $uniqueFor = 300;

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

    /**
     * Exhausting retries is not a data problem — the asset keeps serving from
     * its source_url, and the next sync re-dispatches because storage_path is
     * still null. Logged rather than reported: a dead third-party CDN link is
     * not an operator event.
     */
    public function failed(?\Throwable $e): void
    {
        Log::warning('media_mirror.job_failed', [
            'asset_id' => $this->assetId,
            'error' => $e?->getMessage(),
        ]);
    }
}
