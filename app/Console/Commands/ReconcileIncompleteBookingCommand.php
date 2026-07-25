<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Console\Command;

/**
 * Reports booking connections that are active but carry no publishable content
 * — an auto-harvested fresha row is {url, selection: null}.
 *
 * Not scheduled. These rows are invisible to the refresh cron by design
 * (FreshaFetch 304s a selection-less row), so an account built before the
 * completeness gate shipped keeps a cached sitepage advertising an empty
 * Services page until this runs with --invalidate.
 *
 * Dry run by default: --apply is required to touch anything.
 */
class ReconcileIncompleteBookingCommand extends Command
{
    protected $signature = 'booking:reconcile-incomplete
                            {--apply : Perform writes instead of reporting only}
                            {--invalidate : Also purge the sitepage cache for each affected user}';

    protected $description = 'Report (and optionally re-cache) booking connections awaiting owner setup';

    public function __construct(
        private readonly IntegrationConnectionCacheRefresher $refresher = new IntegrationConnectionCacheRefresher,
    ) {
        parent::__construct();
    }

    public function handle(PlatformRegistry $registry): int
    {
        $descriptor = $registry->get('fresha');
        if ($descriptor === null) {
            $this->error('No fresha descriptor registered.');

            return self::FAILURE;
        }

        $incomplete = IntegrationConnection::query()
            ->where('platform', 'fresha')
            ->where('is_active', true)
            ->with('user:id,handle')
            ->get()
            ->reject(fn (IntegrationConnection $c): bool => $descriptor->isComplete($c));

        foreach ($incomplete as $connection) {
            $this->line(sprintf(
                '  %s  seeded=%s  url=%s',
                $connection->user->handle ?? $connection->user_id,
                $connection->payload['source'] ?? 'user',
                $connection->payload['url'] ?? '(none)',
            ));
        }

        $this->info("{$incomplete->count()} incomplete booking connection(s).");

        if (! $this->option('apply')) {
            $this->comment('Dry run — pass --apply to act.');

            return self::SUCCESS;
        }

        if ($this->option('invalidate')) {
            foreach ($incomplete as $connection) {
                if ($connection->user !== null) {
                    // Task 5 changed page_order for these sites; a published
                    // sitepage keeps serving the stale order until it re-renders.
                    $this->invalidate($connection);
                }
            }
            $this->info('Sitepage caches invalidated.');
        }

        return self::SUCCESS;
    }

    /**
     * Same path IntegrationConnectionObserver::saved() takes on a payload change,
     * called directly because this command changes no payload — only the
     * page_order these rows produce changed, under its feet, when the
     * completeness gate shipped. Both halves of that path are needed here too:
     * the CDN purge below, AND rolling site.updated_at so the
     * public.profile:{handle}:{ts} Redis cache key (IndividualProfileController)
     * rotates — without it the sitepage keeps serving the stale page_order
     * until something unrelated touches the site. The observer scopes its
     * touch() to hasCompletenessPredicate() platforms; that check is skipped
     * here because every row this command reaches already failed
     * $descriptor->isComplete($c) using fresha's own descriptor — a platform
     * with no completeness predicate can never fail that check, so every row
     * in $incomplete is provably on a hasCompletenessPredicate() platform.
     */
    private function invalidate(IntegrationConnection $connection): void
    {
        $this->refresher->refresh($connection);
        $connection->user?->site?->touch();
    }
}
