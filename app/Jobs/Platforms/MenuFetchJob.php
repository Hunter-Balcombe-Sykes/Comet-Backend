<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\Menu;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Fetches (or refreshes) the user's menu into the single site.menus row from
// their connected online-ordering platform — Uber Eats preferred, DoorDash
// fallback, resolved by MenuSource. Dispatched on every online-ordering change
// (add / remove / forget + the Google Business ordering seed) and by the manual
// "Refresh menu" button.
//
// Cost control: the paid Apify scrape runs ONLY when the resolved store URL is
// new (or $force is set by a manual refresh) or the last fetch wasn't 'ok' —
// re-deriving links on an unrelated ordering change is free and leaves the menu
// untouched. When the user has no Uber Eats / DoorDash link at all, the menu row
// is soft-deleted (cleared).
class MenuFetchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // The menu actors run a real store scrape, retried on empty; allow headroom
    // for MAX_ATTEMPTS × ATTEMPT_TIMEOUT in MenuApifyScraper.
    public int $timeout = 300;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $maxExceptions = 2;

    // One menu fetch per user at a time; the window exceeds $timeout so a
    // duplicate dispatch can't slip in and bill a second Apify run mid-flight.
    public int $uniqueFor = 360;

    public function __construct(
        public readonly string $userId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(MenuSource $source, MenuApifyScraper $scraper): void
    {
        $resolved = $source->resolve($this->userId);

        // No Uber Eats / DoorDash link → clear any existing menu.
        if ($resolved === null) {
            Menu::query()->where('user_id', $this->userId)->delete();

            return;
        }

        $existing = Menu::query()->where('user_id', $this->userId)->first();

        // Skip the (paid) scrape when the store URL is unchanged, the last fetch
        // succeeded, and this isn't a forced refresh — links recompute at read
        // time, so there's nothing to do. A prior 'unavailable' re-scrapes.
        if (! $this->force
            && $existing
            && $existing->store_url === $resolved['storeUrl']
            && $existing->fetch_status === 'ok') {
            return;
        }

        // Flip to pending (preserving any existing categories) so the dashboard
        // shows a syncing state while the scrape runs.
        $menu = Menu::updateOrCreate(
            ['user_id' => $this->userId],
            [
                'source' => $resolved['platform'],
                'store_url' => $resolved['storeUrl'],
                'fetch_status' => 'pending',
            ],
        );

        $result = $scraper->fetch($resolved['storeUrl'], $resolved['platform'], $this->userId);

        if ($result === null) {
            // Soft failure: keep the last known menu, mark unavailable so the
            // dashboard stops polling. A manual refresh (or the next ordering
            // change) retries.
            $menu->forceFill(['fetch_status' => 'unavailable', 'last_fetched_at' => now()])->save();

            return;
        }

        $menu->forceFill([
            'source' => $resolved['platform'],
            'store_url' => $resolved['storeUrl'],
            'rating' => $result['rating'],
            'review_count' => $result['reviewCount'],
            'currency' => $result['currency'],
            'categories' => $result['categories'],
            'fetch_status' => 'ok',
            'last_fetched_at' => now(),
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('menu.fetch_job.failed', ['user_id' => $this->userId, 'error' => $e->getMessage()]);

        $menu = Menu::query()->where('user_id', $this->userId)->first();
        if ($menu) {
            $menu->forceFill(['fetch_status' => 'unavailable'])->save();
        }
    }
}
