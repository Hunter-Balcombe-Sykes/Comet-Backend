<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\Menu;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

// 9e (2026-09-01): the one in-band recovery shot for a transiently-failed
// menu scrape. MenuFetchJob::settled() dispatches this ~90s out when a
// platform landed 'unavailable'; the menu:retry-unavailable cron (15-minute
// cadence) remains the long-tail net for failures that outlive this shot.
//
// A relay job rather than a self-dispatch because MenuFetchJob is unique per
// user and its own lock is still held inside settled() — a direct delayed
// dispatch there would be silently dropped at the unique check. By the time
// this runs the lock is long released.
class RetryMenuFetchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @var list<int> moot at one attempt; declared for the job-hygiene policy. */
    public array $backoff = [30];

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $userId)
    {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(): void
    {
        // Re-check before re-billing: a manual refresh or the cron may have
        // recovered the menu while this relay sat in its delay window.
        $menu = Menu::query()->where('user_id', $this->userId)->with('platformLinks')->first();
        $stillUnavailable = $menu !== null
            && ($menu->fetch_status === 'unavailable'
                || $menu->platformLinks->contains(fn ($link) => $link->status === 'unavailable'));

        if (! $stillUnavailable) {
            return;
        }

        Log::info('menu_fetch.in_band_retry', ['user_id' => $this->userId]);
        MenuFetchJob::dispatch($this->userId, force: true, inBandRetry: true);
    }

    public function failed(Throwable $e): void
    {
        report($e);
    }
}
