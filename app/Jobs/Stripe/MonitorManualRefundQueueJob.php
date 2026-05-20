<?php

namespace App\Jobs\Stripe;

use App\Models\Commerce\CommissionPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Daily digest of CommissionPayouts that need manual refund attention from ops.
//
// Flag sources (any one drives inclusion):
//   - mid-flight refund (refund webhook arrived while payout was collecting/transferring) →
//     handleOrderRefund flagged needs_manual_refund=true
//   - post-payout Stripe Transfer Reversal failed (typically insufficient
//     connected-account balance) → clawbackCompletedPayout flagged the payout
//   - auto-refund-after-transfer-failure failed → CommissionPayoutService flagged it
//
// Output: a single Log::warning per run with the list of open payouts. Nightwatch
// is configured to alert on warning+ for the stripe queue, so ops sees this in their
// daily check. A future iteration can wire this into a dedicated Slack channel.
//
// Scheduled: daily at 08:00 UTC (after the morning VoidExpired sweep).
class MonitorManualRefundQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // No backoff — tries=1 means no retry, so backoff is moot, but required for hygiene.
    public int $backoff = 0;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('stripe');
    }

    public function handle(): void
    {
        $baseQuery = CommissionPayout::query()
            ->where('needs_manual_refund', true)
            ->whereNotIn('status', ['cancelled', 'failed']);

        // SCALE-2: separate count query for true backlog size, then cap the fetch
        // at 200 rows so a large backlog doesn't OOM the job or flood the log line.
        // The logged 'count' always reflects the real total, not the capped fetch.
        $totalCount = (clone $baseQuery)->count();

        if ($totalCount === 0) {
            Log::info('payout.manual_refund_digest.empty');

            return;
        }

        $open = (clone $baseQuery)
            ->with(['brandProfessional:id,display_name', 'affiliateProfessional:id,display_name'])
            ->orderBy('updated_at')
            ->limit(200)
            ->get();

        $lines = $open->map(fn (CommissionPayout $p) => sprintf(
            '%s | %s → %s | status=%s gross=%d %s',
            $p->id,
            $p->brandProfessional?->display_name ?? '?',
            $p->affiliateProfessional?->display_name ?? '?',
            $p->status,
            (int) $p->gross_commission_cents,
            strtoupper((string) $p->currency_code),
        ))->all();

        Log::warning('payout.manual_refund_digest', [
            'count' => $totalCount,
            'payouts' => $lines,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        Log::error('MonitorManualRefundQueueJob failed', [
            'message' => $e->getMessage(),
        ]);
    }
}
