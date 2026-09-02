<?php

namespace App\Jobs\PreAccount;

use App\Services\Platforms\InstagramScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

// 9g (2026-09-01): cache-warm the signup form's Instagram profile scrape
// while the visitor is still typing. fetchProfileResult() keys its 900s
// cache by USERNAME alone and rememberLocked collapses a warm-vs-build race
// (the build's prefetch waits on this run's lock and reads its answer), so a
// successful warm makes the build's own scrape a cache hit — the 2-4s vendor
// call (or the 10-40s Apify fallback) happens while the visitor types their
// email instead of after they submit. The result is deliberately discarded:
// this job exists for the cache side effect only, and the endpoint that
// dispatches it never learns whether the profile exists.
class PrewarmInstagramProfileJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Abandoned forms must cost at most one run — never retry a warm. */
    public int $tries = 1;

    /** @var list<int> moot at one attempt; declared for the job-hygiene policy. */
    public array $backoff = [30];

    /** Vendor call is 2-4s; the Apify fallback can run long. */
    public int $timeout = 120;

    /**
     * Matches the profile cache's own 900s window: while a warm's answer is
     * still fresh, an identical warm has nothing to add — coalesce it.
     */
    public int $uniqueFor = 900;

    public function __construct(public readonly string $username)
    {
        $this->onQueue(config('partna.queues.signup', 'signup'));
    }

    public function uniqueId(): string
    {
        return mb_strtolower($this->username);
    }

    public function handle(InstagramScraper $scraper): void
    {
        $result = $scraper->fetchProfileResult($this->username, null);

        Log::info('pre_account.prewarm', [
            'warmed' => $result->profile !== null,
        ]);
    }

    public function failed(Throwable $e): void
    {
        report($e);
    }
}
