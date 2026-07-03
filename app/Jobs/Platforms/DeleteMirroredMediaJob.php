<?php

namespace App\Jobs\Platforms;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes a single mirrored-media folder from the R2 `media` disk.
 *
 * Instagram is the only platform that mirrors upstream images into our own
 * object storage (under `platforms/instagram/{timestamp}/`). When a connection
 * is disconnected or its selection is re-saved to a new folder, the old folder
 * is now orphaned — this job reclaims it (CONS-21). Dispatched from
 * IntegrationConnectionObserver with the folder recorded in `payload._folder`.
 *
 * Queued on `scraping` (the platform queue, consumed by supervisor-scraping)
 * since cleanup is not latency-sensitive — isolated from the `default` queue so
 * a burst of disconnects can't crowd out user-facing jobs. ShouldBeUnique keyed
 * on the folder so a burst of writes can't queue duplicate deletes of the prefix.
 */
class DeleteMirroredMediaJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** Exponential backoff (seconds): 60 → 300 → 900, tolerating ~21min of R2 degradation. */
    public array $backoff = [60, 300, 900];

    public int $timeout = 120;

    public int $maxExceptions = 2;

    /** Coalesce duplicate deletes of the same prefix queued within the window. */
    public int $uniqueFor = 300;

    /**
     * @param  string  $folder  R2 prefix to delete, e.g. "platforms/instagram/1717000000".
     */
    public function __construct(public readonly string $folder)
    {
        // SCALE-7: actually land on the scraping queue the docblock promises —
        // without this the job ran on `default`, contending with user-facing jobs.
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->folder;
    }

    public function handle(): void
    {
        // Defence-in-depth: only ever delete inside the platforms namespace. A
        // corrupted/empty _folder must never widen into a broad-prefix wipe — and it
        // must NOT silently no-op either (OBS-1). fail() → failed() → report() pages
        // on-call; fail() doesn't stop handle(), so return right after.
        if (! str_starts_with($this->folder, 'platforms/')) {
            Log::warning('DeleteMirroredMediaJob: refused non-platforms prefix', [
                'folder' => $this->folder,
            ]);
            $this->fail(new \RuntimeException("DeleteMirroredMediaJob refused non-platforms prefix: {$this->folder}"));

            return;
        }

        Storage::disk('media')->deleteDirectory($this->folder);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('DeleteMirroredMediaJob: cleanup exhausted retries', [
            'folder' => $this->folder,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ]);
    }
}
