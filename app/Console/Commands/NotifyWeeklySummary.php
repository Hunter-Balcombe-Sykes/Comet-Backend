<?php

namespace App\Console\Commands;

use App\Mail\Account\WeeklyDigestMail;
use App\Mail\Support\CategoryUnsubscribe;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\User\User;
use App\Services\Analytics\AnalyticsQueryService;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

// OV-H: weekly "here's your week on Partna" nudge (non-critical, Info) — in-app
// always, email when the analytics_weekly preference allows it. Scoped to
// active users who have a site so brand-new empty accounts aren't nudged. One
// notification per user per ISO week (dedupe key); the email rides the same
// boundary, gated on the row being genuinely new THIS run rather than on
// NotificationPublisher::publish()'s return value — publish() always returns
// the row (new or existing) on an insertOrIgnore conflict, so it can't be used
// to detect "just created" by itself.
class NotifyWeeklySummary extends Command
{
    protected $signature = 'partna:notify-weekly-summary {--dry-run}';

    protected $description = 'Publish the weekly summary notification (+ digest email) to active users with a site';

    public function handle(NotificationPublisher $publisher, AnalyticsQueryService $analytics): int
    {
        $week = now()->format('o-\WW');
        $dryRun = (bool) $this->option('dry-run');

        // Last FULL ISO week (Mon-Sun), so Monday's run reports a closed window.
        $from = now()->subWeek()->startOfWeek();
        $to = now()->subWeek()->endOfWeek();
        $weekLabel = $from->format('j M').'–'.$to->format('j M');

        $emailEnabled = (bool) config('partna.notifications.email_enabled', false);
        $count = 0;
        $emailed = 0;

        User::query()
            ->where('status', 'active')
            ->has('site')
            ->select(['id', 'display_name', 'primary_email'])
            ->chunkById(200, function ($users) use ($publisher, $analytics, $week, $weekLabel, $from, $to, $dryRun, $emailEnabled, &$count, &$emailed): void {
                foreach ($users as $user) {
                    $visits = $analytics->visitsAggregate((string) $user->id, $from, $to);
                    $clicks = $analytics->clicksAggregate((string) $user->id, $from, $to);

                    $totalVisits = (int) $visits->total_visits;
                    $totalTaps = (int) $clicks->total_clicks;

                    // Quiet week → no digest at all. A "0 visits" email is a
                    // churn letter, and the in-app nudge would just restate it.
                    if ($totalVisits === 0 && $totalTaps === 0) {
                        continue;
                    }

                    $count++;
                    if ($dryRun) {
                        continue;
                    }

                    $dedupeKey = "analytics_weekly:{$user->id}:{$week}";

                    // Checked BEFORE publish(): publish() always returns the row
                    // (new or pre-existing) on its insertOrIgnore conflict, so
                    // its return value can't tell "just created" from "already
                    // had one this week" — this existence check is the only
                    // reliable signal, and it must run first.
                    $isNewThisWeek = ! Notification::query()
                        ->where('user_id', $user->id)
                        ->where('dedupe_key', $dedupeKey)
                        ->exists();

                    $publisher->publish(
                        userId: (string) $user->id,
                        frontendType: 'Info',
                        category: 'analytics_weekly',
                        title: 'Your week on Partna',
                        body: "Last week ({$weekLabel}): {$totalVisits} visits from {$visits->unique_visitors} visitors, {$totalTaps} taps. Open analytics for the full picture.",
                        dedupeKey: $dedupeKey,
                        ctaUrl: '/account/overview',
                        primaryActionLabel: 'View dashboard',
                        retentionConfigKey: 'analytics_weekly',
                        critical: false,
                    );

                    if (! $isNewThisWeek || ! $emailEnabled) {
                        continue;
                    }

                    if (! NotificationPublisher::resolveEmailEnabled((string) $user->id, 'analytics_weekly')) {
                        continue;
                    }

                    $email = (string) ($user->primary_email ?? '');
                    if ($email === '') {
                        continue;
                    }

                    $top = $analytics->topLinks((string) $user->id, $from, $to)->first();

                    Mail::to($email)->queue(new WeeklyDigestMail(
                        recipientEmail: $email,
                        displayName: $user->display_name,
                        weekLabel: $weekLabel,
                        visits: $totalVisits,
                        visitors: (int) $visits->unique_visitors,
                        taps: $totalTaps,
                        topLinkLabel: $top?->label ?? $top?->platform,
                        topLinkClicks: $top ? (int) $top->clicks : null,
                        unsubscribeUrl: CategoryUnsubscribe::urlFor((string) $user->id, 'analytics_weekly'),
                    ));
                    $emailed++;
                }
            });

        $this->info(($dryRun ? '[dry-run] would notify ' : 'Published weekly summary to ')."{$count} users, emailed {$emailed} (week {$week}).");

        return self::SUCCESS;
    }
}
