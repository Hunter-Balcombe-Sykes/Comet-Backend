<?php

namespace App\Jobs\Notifications;

use App\Models\Commerce\Order;
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

// Weekly sales/commission rollup notification for all active professionals.
// Scheduled: every Monday at 09:00 UTC via routes/console.php.
class SendWeeklyAnalyticsNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    // Surface deterministic failures fast — fail after the first throw instead
    // of burning the full backoff window before Horizon alerts.
    public int $maxExceptions = 1;

    public int $backoff = 60;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(NotificationPublisher $publisher): void
    {
        $yearWeek = now()->format('o-W'); // ISO year + week number
        // Window is [now-7d 00:00, now 00:00) so "last 7 days" never includes today.
        $windowStart = now()->subDays(7)->startOfDay();
        $windowEnd = now()->startOfDay();

        // Individuals have no commerce activity — exclude them at query level so
        // we never run the rollup query or publish a notification for them.
        // Only brand and partner account types have orders/commissions to report.
        // Dual-read during the §28.1 SET NOT NULL promotion window: prefer
        // account_type when set, fall back to professional_type for legacy rows.
        DB::table('core.professionals')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where(function ($q): void {
                $q->whereIn('account_type', ['brand', 'partner'])
                    ->orWhere(function ($q2): void {
                        $q2->whereNull('account_type')
                            ->whereIn('professional_type', ['brand', 'affiliate']);
                    });
            })
            ->orderBy('id')
            ->chunkById(200, function ($professionals) use ($publisher, $yearWeek, $windowStart, $windowEnd): void {
                $ids = $professionals->pluck('id')->all();

                // Per-affiliate weekly orders + commission_cents from
                // commerce.orders. Excludes stub/cancelled/voided/refunded so
                // we never tell affiliates about a sale that won't pay out.
                // One batched query per chunk preserves the prior <=1
                // query/chunk contract enforced by
                // SendWeeklyAnalyticsNotificationJobQueryCountTest.
                $metricsByPro = DB::table('commerce.orders')
                    ->whereIn('affiliate_professional_id', $ids)
                    ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
                    ->where('occurred_at', '>=', $windowStart)
                    ->where('occurred_at', '<', $windowEnd)
                    ->select('affiliate_professional_id')
                    ->selectRaw('COUNT(*) as orders')
                    ->selectRaw('COALESCE(SUM(commission_cents), 0) as commission_cents')
                    ->groupBy('affiliate_professional_id')
                    ->get()
                    ->keyBy('affiliate_professional_id');

                // Defence-in-depth capability gate (§28.10 / §28.11). SQL query
                // already excludes individuals, but a brand or partner who had
                // commission/order dashboards capability-revoked (override) should
                // not get the digest. Loads as Eloquent so account_type cast hits.
                $proModels = Professional::query()->whereIn('id', $ids)->get()->keyBy('id');

                foreach ($professionals as $professional) {
                    try {
                        // Defence-in-depth capability gate (§28.10 / §28.11). SQL
                        // already excludes individuals; this also drops anyone whose
                        // commission dashboard capability got revoked via override.
                        //
                        // FAIL-CLOSED: missing Professional row indicates a data-integrity
                        // bug — skip rather than sending the digest to a phantom pro.
                        $proModel = $proModels->get($professional->id);
                        if (! $proModel || ! AccountCapabilities::for($proModel)->shows_commissions_dashboard) {
                            continue;
                        }

                        $metrics = $metricsByPro->get($professional->id);
                        $this->notifyProfessional($publisher, $professional, $metrics, $yearWeek);
                    } catch (\Throwable $e) {
                        report($e);
                        Log::warning('Weekly analytics notification failed', [
                            'professional_id' => $professional->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        Log::error('SendWeeklyAnalyticsNotificationJob permanently failed', [
            'error' => $e->getMessage(),
        ]);
    }

    private function notifyProfessional(
        NotificationPublisher $publisher,
        object $professional,
        ?object $metrics,
        string $yearWeek,
    ): void {
        $orders = (int) ($metrics?->orders ?? 0);
        $commissionCents = (int) ($metrics?->commission_cents ?? 0);

        if ($orders === 0 && $commissionCents === 0) {
            return;
        }

        $body = "Last 7 days: {$orders} sale".($orders !== 1 ? 's' : '');
        if ($commissionCents > 0) {
            $commission = 'A$'.number_format($commissionCents / 100, 2);
            $body .= ", {$commission} in commissions";
        }
        $body .= '.';

        $publisher->publish(
            professionalId: $professional->id,
            frontendType: 'Info',
            category: 'analytics_weekly',
            title: 'Your weekly analytics',
            body: $body,
            dedupeKey: "analytics.weekly.{$professional->id}.{$yearWeek}",
            ctaUrl: '/account/store?section=analytics',
            retentionConfigKey: 'analytics_weekly',
        );
    }
}
