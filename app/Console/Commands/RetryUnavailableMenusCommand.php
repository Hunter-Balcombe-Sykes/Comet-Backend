<?php

namespace App\Console\Commands;

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\Menu;
use Illuminate\Console\Command;

// Self-heal transient menu scrapes. The Uber Eats / DoorDash Apify actors are
// bot-blocked on a fraction of runs, so a connected platform can land
// 'unavailable' even though the store is fine — leaving the menu single-platform
// until something forces a re-scrape. This re-dispatches a FORCED MenuFetchJob
// for menus with a connected-but-'unavailable' platform, bounded to a recent
// window so a genuinely dead store isn't retried forever. Once the platform
// scrapes 'ok' the row drops out of the selection; after the window it ages out
// (a manual "Refresh menu" still works thereafter). Scheduled every 15 min.
class RetryUnavailableMenusCommand extends Command
{
    protected $signature = 'menu:retry-unavailable {--limit=200 : Max menus to retry this run} {--hours=6 : Only retry menus fetched within this many hours}';

    protected $description = 'Re-scrape menus whose Uber Eats / DoorDash scrape came back unavailable (transient bot-block recovery).';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $hours = (int) $this->option('hours');
        $since = now()->subHours($hours);

        $menus = Menu::query()
            ->whereHas('platformLinks', fn ($q) => $q->where('status', 'unavailable'))
            // Bound the retry window so a permanently-dead store isn't re-billed
            // forever — last_fetched_at advances on every attempt, so a menu that
            // keeps failing eventually crosses the window and stops.
            ->where('last_fetched_at', '>=', $since)
            ->orderByRaw('last_fetched_at ASC NULLS FIRST')
            ->limit($limit)
            ->get();

        foreach ($menus as $menu) {
            MenuFetchJob::dispatch((string) $menu->user_id, true);
        }

        $this->info("Menu retries dispatched: {$menus->count()} (connected platform unavailable, fetched within {$hours}h).");

        return self::SUCCESS;
    }
}
