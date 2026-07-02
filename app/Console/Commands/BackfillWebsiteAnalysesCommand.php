<?php

namespace App\Console\Commands;

use App\Jobs\Design\AnalyzeConnectionWebsitesJob;
use App\Jobs\Design\AnalyzePreviousWebsiteJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\OutsideWebsitesFactor;
use Illuminate\Console\Command;

// One-shot backfill for data that predates the website brand-signal factors:
// queues previous-website analyses (workplaces with a URL but no matching
// analysis) and outside-website analyses (users with custom links / shop
// brands). New writes self-trigger via the observers; this covers history.
class BackfillWebsiteAnalysesCommand extends Command
{
    protected $signature = 'design:backfill-website-analyses {--user= : Limit to one user by handle}';

    protected $description = 'Queue brand-signal analyses for pre-existing previous-website URLs and outside-connected websites';

    public function handle(): int
    {
        $handle = $this->option('user');
        $userIds = $handle !== null
            ? User::query()->where('handle_lc', strtolower(trim((string) $handle)))->pluck('id')
            : null;

        if ($userIds !== null && $userIds->isEmpty()) {
            $this->error("No user found for handle [{$handle}].");

            return self::FAILURE;
        }

        // Previous-website: any workplace whose URL and analysis disagree.
        $previous = 0;
        Workplace::query()
            ->whereNotNull('previous_website')
            ->when($userIds, fn ($q) => $q->whereIn(
                'site_id',
                Site::query()->whereIn('user_id', $userIds)->select('id'),
            ))
            ->orderBy('site_id')
            ->chunk(100, function ($workplaces) use (&$previous) {
                foreach ($workplaces as $workplace) {
                    $url = trim((string) $workplace->previous_website);
                    $analysis = $workplace->previous_website_analysis;
                    if ($url !== '' && (! is_array($analysis) || ($analysis['url'] ?? null) !== $url)) {
                        AnalyzePreviousWebsiteJob::dispatch((string) $workplace->site_id);
                        $previous++;
                    }
                }
            });

        // Outside websites: one coalescing job per user with any source connection.
        $outsideUserIds = IntegrationConnection::query()
            ->active()
            ->whereIn('platform', OutsideWebsitesFactor::SOURCE_PLATFORMS)
            ->when($userIds, fn ($q) => $q->whereIn('user_id', $userIds))
            ->distinct()
            ->pluck('user_id');
        foreach ($outsideUserIds as $userId) {
            AnalyzeConnectionWebsitesJob::dispatch((string) $userId);
        }

        $this->info("Queued {$previous} previous-website analyses and {$outsideUserIds->count()} outside-website jobs.");

        return self::SUCCESS;
    }
}
