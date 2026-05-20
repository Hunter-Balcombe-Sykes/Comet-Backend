<?php

namespace App\Jobs\Notifications;

use App\Enums\BrandStatus;
use App\Models\Core\Professional\Professional;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Daily sweep that nudges brand professionals stuck early in the onboarding
// funnel (still in BrandStatus::Onboarding or ShopifyLinked) at day 3, 10,
// and 30 since signup. One in-app notification per (brand, milestone),
// deduped by NotificationPublisher's insertOrIgnore on dedupe_key — so the
// sweep is safely re-runnable and each brand gets at most three nudges total.
// Scheduled: daily at 09:00 UTC via routes/console.php.
class NudgeStuckOnboardingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Surface deterministic failures fast — fail after 2 consecutive throws
    // instead of burning the full backoff window before Horizon alerts.
    public int $maxExceptions = 2;

    public int $backoff = 60;

    public int $timeout = 300;

    /**
     * Day-since-signup milestones to nudge at. Each entry defines the day
     * window, the notification severity, and the title/body copy. Order
     * isn't significant — we run a separate query per milestone.
     *
     * @var array<int, array{day: int, severity: string, title: string, body: string}>
     */
    private const MILESTONES = [
        [
            'day' => 3,
            'severity' => 'Info',
            'title' => 'Finish setting up your brand',
            'body' => 'Connect Shopify to start running affiliate campaigns. It takes about 5 minutes.',
        ],
        [
            'day' => 10,
            'severity' => 'Info',
            'title' => 'Need a hand with setup?',
            'body' => 'Your brand is still waiting on setup. Reply to this notification or visit the dashboard if you need help.',
        ],
        [
            'day' => 30,
            'severity' => 'Warning',
            'title' => "Your brand isn't live yet",
            'body' => "It's been a month since signup and your brand still isn't connected. Finish setup to start earning.",
        ],
    ];

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(NotificationPublisher $publisher): void
    {
        foreach (self::MILESTONES as $milestone) {
            $this->sweepMilestone($publisher, $milestone);
        }
    }

    /**
     * @param  array{day: int, severity: string, title: string, body: string}  $milestone
     */
    private function sweepMilestone(NotificationPublisher $publisher, array $milestone): void
    {
        $day = $milestone['day'];

        // 24-hour window: brands whose created_at falls between (now - day - 1)
        // and (now - day). Catches each brand exactly once per milestone, even
        // if the sweep runs slightly off-schedule. Combined with the per-day
        // dedupe key, no brand ever gets the same milestone nudge twice.
        $windowStart = now()->subDays($day + 1);
        $windowEnd = now()->subDays($day);

        // LEFT JOIN brand_profiles: a freshly-signed-up brand may not have a
        // brand_profiles row yet (created lazily on first status sync). Treat
        // null as Onboarding — that cohort is exactly who we most want to nudge.
        DB::table('core.professionals as p')
            ->leftJoin('brand.brand_profiles as bp', 'bp.professional_id', '=', 'p.id')
            // account_type is the source of truth; professional_type is the legacy
            // dual-write column still in migration. Coalesce so the query works
            // during the §28.1 SET NOT NULL promotion window.
            ->where(function ($q): void {
                $q->where('p.account_type', 'brand')
                    ->orWhere(function ($q2): void {
                        $q2->whereNull('p.account_type')
                            ->where('p.professional_type', 'brand');
                    });
            })
            ->whereNull('p.deleted_at')
            ->whereBetween('p.created_at', [$windowStart, $windowEnd])
            ->where(function ($q): void {
                $q->whereNull('bp.brand_status')
                    ->orWhereIn('bp.brand_status', [
                        BrandStatus::Onboarding->value,
                        BrandStatus::ShopifyLinked->value,
                    ]);
            })
            ->select(['p.id', 'p.first_name', 'p.display_name'])
            ->orderBy('p.id')
            ->chunkById(500, function ($chunk) use ($publisher, $day, $milestone) {
                $proIds = collect($chunk)->pluck('id')->filter()->all();
                $pros = $proIds === []
                    ? collect()
                    : Professional::query()->whereIn('id', $proIds)->get()->keyBy('id');

                foreach ($chunk as $row) {
                    try {
                        $proId = trim((string) ($row->id ?? ''));
                        if ($proId === '') {
                            continue;
                        }

                        // Defence-in-depth capability gate (§28.10 / §28.11). The SQL
                        // query above filters to brand-typed rows. If the row maps to
                        // a real Professional and they don't require Stripe-Connect
                        // (override or transitioned-away), skip.
                        //
                        // FAIL-CLOSED: missing Professional row indicates a data-integrity
                        // bug — skip rather than nudging a phantom recipient. Tests must
                        // seed real Professional rows for this path.
                        $pro = $pros->get($proId);
                        if (! $pro || ! AccountCapabilities::for($pro)->requires_stripe_connect) {
                            continue;
                        }

                        $publisher->publish(
                            professionalId: $proId,
                            frontendType: $milestone['severity'],
                            category: 'profile_tasks',
                            title: $milestone['title'],
                            body: $milestone['body'],
                            dedupeKey: "onboarding.nudge.{$proId}.day_{$day}",
                            ctaUrl: '/account/overview',
                            primaryActionLabel: 'Continue setup',
                            retentionConfigKey: 'profile_task',
                        );
                    } catch (\Throwable $e) {
                        report($e);
                        Log::warning('NudgeStuckOnboardingJob failed for professional', [
                            'professional_id' => $row->id ?? null,
                            'day' => $day,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            }, 'p.id', 'id');
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        Log::error('NudgeStuckOnboardingJob failed', [
            'message' => $e->getMessage(),
        ]);
    }
}
