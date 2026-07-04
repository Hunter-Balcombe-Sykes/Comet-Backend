<?php

namespace App\Console\Commands;

use App\Jobs\Design\AnalyzeConnectionWebsitesJob;
use App\Jobs\Design\AnalyzePreviousWebsiteJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\OutsideWebsitesFactor;
use App\Services\Design\WebsiteStyleAnalyzer;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Console\Command;

// Backfill/re-sweep for stored brand-signal analyses: queues previous-website
// analyses (URL/version/success mismatch) and outside-website analyses (users
// with custom links / shop brands). New writes self-trigger via the observers;
// this covers history and analyzer VERSION bumps. --retry-failures also strips
// failed connection analyses (e.g. scans stored while the scanner was
// unconfigured) so they re-run.
class BackfillWebsiteAnalysesCommand extends Command
{
    protected $signature = 'design:backfill-website-analyses {--user= : Limit to one user by handle} {--retry-failures : Also re-run connection analyses that previously failed}';

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
                    $stale = ! is_array($analysis)
                        || ($analysis['url'] ?? null) !== $url
                        || ($analysis['v'] ?? null) !== WebsiteStyleAnalyzer::VERSION
                        || ($analysis['ok'] ?? false) !== true;
                    if ($url !== '' && $stale) {
                        AnalyzePreviousWebsiteJob::dispatch((string) $workplace->site_id);
                        $previous++;
                    }
                }
            });

        // Outside websites: one coalescing job per user with any source connection.
        // Full rows are only needed for --retry-failures (payload mutation below);
        // built as a factory so each use gets its own query instance.
        $outsideConnections = fn () => IntegrationConnection::query()
            ->active()
            ->whereIn('platform', OutsideWebsitesFactor::SOURCE_PLATFORMS)
            ->when($userIds, fn ($q) => $q->whereIn('user_id', $userIds));

        // A current-version FAILED analysis converges (needs-analysis is false),
        // so it never retries organically. On demand, strip such entries —
        // quietly, so the observer doesn't double-dispatch; the explicit job
        // below re-analyzes.
        if ($this->option('retry-failures')) {
            foreach ($outsideConnections()->get() as $connection) {
                $payload = is_array($connection->payload) ? $connection->payload : [];
                $changed = false;
                if ($connection->platform === Platform::Custom->value) {
                    if ($this->stripFailedAnalysis($payload)) {
                        $changed = true;
                    }
                } else {
                    foreach ($payload as $key => $brand) {
                        if (is_array($brand) && $this->stripFailedAnalysis($payload[$key])) {
                            $changed = true;
                        }
                    }
                }
                if ($changed) {
                    IntegrationConnection::withoutEvents(fn () => $connection->update(['payload' => $payload]));
                }
            }
        }

        // Dedup pushed to Postgres (SELECT DISTINCT) instead of hydrating every
        // connection model just to read one column off each.
        $outsideUserIds = $outsideConnections()->distinct()->pluck('user_id');
        foreach ($outsideUserIds as $userId) {
            AnalyzeConnectionWebsitesJob::dispatch((string) $userId);
        }

        $this->info("Queued {$previous} previous-website analyses and {$outsideUserIds->count()} outside-website jobs.");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $entry Unsets a current-version failed styleAnalysis; true when changed. */
    private function stripFailedAnalysis(array &$entry): bool
    {
        $analysis = $entry['styleAnalysis'] ?? null;
        if (is_array($analysis)
            && ($analysis['v'] ?? null) === WebsiteStyleAnalyzer::VERSION
            && ($analysis['ok'] ?? false) !== true) {
            unset($entry['styleAnalysis']);

            return true;
        }

        return false;
    }
}
