<?php

namespace App\Jobs\Site;

use App\Site\Documents\DocumentBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Builds one site's versioned document (plan §9). ShouldBeUnique per site so
 * a burst of content bumps coalesces into one build — the CAS in the builder
 * already makes concurrent builds SAFE; uniqueness merely makes them cheap.
 *
 * `superseded` means content moved while we built: loop and rebuild from the
 * new revision, bounded so a pathological write storm degrades to "the
 * 5-minute sweeper catches up" rather than an unbounded queue loop.
 */
class BuildSiteDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private const MAX_REBUILDS = 3;

    public int $timeout = 60;

    public int $tries = 1;

    /**
     * Declared for the queue-hygiene policy even though $tries = 1 means it
     * is never consulted: a failed build needs no queue retry — the site
     * stays stale and the 5-minute sweeper re-queues it.
     */
    public int $backoff = 0;

    /** Must exceed $timeout: a lock that dies before its job can readmit a duplicate mid-run. */
    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $siteId,
        public readonly string $channel = 'live',
    ) {}

    public function uniqueId(): string
    {
        return $this->siteId.':'.$this->channel;
    }

    public function handle(DocumentBuilder $builder): void
    {
        for ($attempt = 1; $attempt <= self::MAX_REBUILDS; $attempt++) {
            $result = $builder->build($this->siteId, $this->channel);

            if ($result['status'] !== 'superseded') {
                return;
            }
        }

        Log::info('site.document_build.still_superseded', [
            'site_id' => $this->siteId,
            'channel' => $this->channel,
            'attempts' => self::MAX_REBUILDS,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        Log::error('site.document_build.failed', [
            'site_id' => $this->siteId,
            'channel' => $this->channel,
            'error' => $e->getMessage(),
        ]);
    }
}
