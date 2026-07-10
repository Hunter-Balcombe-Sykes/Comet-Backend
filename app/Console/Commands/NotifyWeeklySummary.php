<?php

namespace App\Console\Commands;

use App\Models\Core\User\User;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Console\Command;

// OV-H: weekly "here's your week on Partna" in-app nudge (non-critical, Info).
//
// STUB by design: it wires the mechanism (scheduled fan-out → dedupe-per-week →
// in-app notification) with generic copy. A richer version — real per-user numbers
// (visits / clicks / top page pulled from AnalyticsQueryService) — is a follow-up
// (see the dashboard-batch Tail). Scoped to active users who have a site so brand-new
// empty accounts aren't nudged. One notification per user per ISO week (dedupe key).
class NotifyWeeklySummary extends Command
{
    protected $signature = 'partna:notify-weekly-summary {--dry-run}';

    protected $description = 'Publish the weekly in-app summary notification to active users with a site';

    public function handle(NotificationPublisher $publisher): int
    {
        // ISO year-week — the dedupe suffix that makes a re-run within the same week a no-op.
        $week = now()->format('o-\WW');
        $dryRun = (bool) $this->option('dry-run');
        $count = 0;

        User::query()
            ->where('status', 'active')
            ->has('site')
            ->select(['id'])
            ->chunkById(200, function ($users) use ($publisher, $week, $dryRun, &$count): void {
                foreach ($users as $user) {
                    $count++;
                    if ($dryRun) {
                        continue;
                    }

                    $publisher->publish(
                        userId: (string) $user->id,
                        frontendType: 'Info',
                        category: 'analytics_weekly',
                        title: 'Your week on Partna',
                        body: 'A new week, a fresh look at your page. Open your dashboard to see how your Partna page is performing and what to tweak next.',
                        dedupeKey: "analytics_weekly:{$user->id}:{$week}",
                        ctaUrl: '/account/overview',
                        primaryActionLabel: 'View dashboard',
                        retentionConfigKey: 'analytics_weekly',
                        critical: false,
                    );
                }
            });

        $this->info(($dryRun ? '[dry-run] would notify ' : 'Published weekly summary to ')."{$count} users (week {$week}).");

        return self::SUCCESS;
    }
}
