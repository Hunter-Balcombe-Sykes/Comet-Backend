<?php

namespace App\Console\Commands;

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\Menu;
use App\Services\Cache\ApifyBudget;
use Illuminate\Console\Command;

// Self-heal transient menu scrapes. The Uber Eats / DoorDash Apify actors are
// bot-blocked on a fraction of runs, so a connected platform can land
// 'unavailable' even though the store is fine — leaving the menu single-platform
// until something forces a re-scrape. This re-dispatches a FORCED MenuFetchJob
// for menus with a connected-but-'unavailable' platform, bounded to a recent
// window so a genuinely dead store isn't retried forever. Once the platform
// scrapes 'ok' the row drops out of the selection; after the window it ages out
// (a manual "Refresh menu" still works thereafter). Scheduled every 15 min.
//
// SCALE-4: jobs are budget-paced (stops when ApifyBudget::remaining('menu') hits
// 0) and staggered across the scraping queue to avoid a burst hitting Apify all
// at once. Default limit reduced 200→50 to match realistic per-run headroom.
class RetryUnavailableMenusCommand extends Command
{
    protected $signature = 'menu:retry-unavailable {--limit=50 : Max menus to retry this run} {--hours=6 : Only retry menus fetched within this many hours} {--stagger-seconds=6 : Delay spacing between dispatches}';

    protected $description = 'Re-scrape menus whose Uber Eats / DoorDash scrape came back unavailable (transient bot-block recovery).';

    public function handle(ApifyBudget $budget): int
    {
        $limit = (int) $this->option('limit');
        $hours = (int) $this->option('hours');
        $stagger = (int) $this->option('stagger-seconds');
        $since = now()->subHours($hours);

        $menus = Menu::query()
            // The RETRYABLE statuses, enumerated — not a "everything that isn't
            // ok" negation. Since 2026-09-03 a failed scrape records WHY
            // (MenuFetchJob::writePlatformSyncStatus), and the difference is the
            // whole point of this cron:
            //
            //   blocked      the actor was refused. Transient — exactly what
            //                this command exists to self-heal.
            //   empty_menu   the store is there and mapped to nothing. Often a
            //                shape change or a mid-update store; worth retrying.
            //   unavailable  the legacy blanket value. Still written by the
            //                transport=http driver lane and by any path that
            //                records no reason, so it stays in the set.
            //
            //   not_found    DELIBERATELY ABSENT. The actor ran cleanly and the
            //                store is not there. Retrying is re-billing a paid
            //                run to be told the same thing forever, which is the
            //                guzman-y-gomez failure mode in a new costume.
            ->whereHas('platformLinks', fn ($q) => $q->whereIn('status', ['unavailable', 'blocked', 'empty_menu']))
            // Bound the retry window so a permanently-dead store isn't re-billed
            // forever. This used to read last_fetched_at, which was the one
            // column that CANNOT expire: MenuFetchJob advances it on every
            // FAILED attempt too, so each retry pushed the row back inside its
            // own window and the "eventually crosses it and stops" comment
            // described something that never happened (guzman-y-gomez,
            // 2026-08-31: ~3 billed Apify runs per quarter hour, indefinitely).
            // last_successful_fetch_at has exactly one writer — the
            // fetch_status='ok' branch — so "how long since this menu last
            // worked" is the question actually being asked. A menu that has
            // never succeeded falls back to its own age, so a brand-new
            // connection whose first scrape was genuinely bot-blocked still gets
            // its window of retries.
            ->where(fn ($q) => $q
                ->where('last_successful_fetch_at', '>=', $since)
                ->orWhere(fn ($q) => $q
                    ->whereNull('last_successful_fetch_at')
                    ->where('created_at', '>=', $since)))
            ->orderByRaw('last_fetched_at ASC NULLS FIRST')
            ->limit($limit)
            ->get();

        $dispatched = 0;
        foreach ($menus as $i => $menu) {
            // SCALE-4: stop as soon as the shared menu budget is spent — don't
            // enqueue jobs that would only no-op at the scraper's budget gate.
            if ($budget->remaining('menu') <= 0) {
                break;
            }

            // Stagger so a full run doesn't hit the scraping queue / Apify all at
            // once — spread across the window instead of a single burst.
            MenuFetchJob::dispatch((string) $menu->user_id, true)
                ->delay(now()->addSeconds($i * $stagger));
            $dispatched++;
        }

        $this->info("Menu retries dispatched: {$dispatched} of {$menus->count()} candidate(s) (budget-paced).");

        return self::SUCCESS;
    }
}
