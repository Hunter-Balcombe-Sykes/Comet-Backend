<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\InstagramConnectionSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

// 9d (2026-09-01): the hero reel's mp4 was the largest single chunk of a
// build's ready path — 10-40s of Instagram CDN streaming that nothing in the
// first paint needs, because skeletons already fall back to the seed photo.
// seed() now writes the row video-less and hands the mp4 (plus its poster,
// still useless without the video) to this job; mirrorReelAndSwap merges both
// into the payload under the row's own lock, and the connection observer's
// wasChanged('payload') purge swaps the reel onto the live page.
class SeedReelMirrorJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * mirrorVideo() swallows CDN failures internally (retry + logged drop), so
     * a throw here means infrastructure (Redis, R2) — worth short retries,
     * mirroring MirrorMediaAssetJob's reasoning on the same lane.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** One mp4 stream (60s HTTP timeout inside) + a poster + an R2 put. */
    public int $timeout = 120;

    /** MUST exceed $timeout — see MirrorMediaAssetJob's uniqueFor note. */
    public int $uniqueFor = 300;

    /**
     * @param  array{videoUrl: ?string, thumbnailUrl: ?string, shortCode: ?string}  $video
     */
    public function __construct(
        public readonly string $connectionId,
        public readonly array $video,
        public readonly string $folder,
    ) {
        // Same lane as the pool mirrors: background bytes a page already
        // renders without, never queued ahead of a user-visible build step.
        $this->onQueue(config('partna.queues.media_mirror', 'media-mirror'));
    }

    public function uniqueId(): string
    {
        return $this->connectionId;
    }

    public function handle(InstagramConnectionSeeder $seeder): void
    {
        $connection = IntegrationConnection::query()->find($this->connectionId);
        if ($connection === null) {
            return; // disconnected/pruned since dispatch — nothing to swap into
        }

        $seeder->mirrorReelAndSwap($connection, $this->video, $this->folder);
    }

    public function failed(Throwable $e): void
    {
        report($e);
    }
}
