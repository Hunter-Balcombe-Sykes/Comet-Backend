<?php

namespace App\Console\Commands\Moderation;

use App\Exceptions\Moderation\ModerationSlaBreachRiskException;
use App\Models\Moderation\ModerationCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Periodically scan open moderation cases and warn when they are approaching
 * their SLA deadline. Runs every 15 minutes via the scheduler.
 *
 * Warning threshold is configurable via partna.moderation.sla.breach_warning_min
 * (default: 120 minutes before breach).
 *
 * Alert contract (#W1-LIFE-1): the per-case Log::warning below is a
 * diagnostic breadcrumb only — nothing reads it in real time. Any at-risk
 * case (count >= 1) also triggers ONE aggregate report() so Nightwatch's
 * exception-based alerting actually pages on-call; a severity-5 case an
 * hour from breach must page even alone, so this is a presence check, not a
 * count threshold. Anti-fatigue is handled entirely by a per-run cooldown
 * (partna.moderation.sla.alert_cooldown_seconds, default 1h) keyed on the
 * MAX severity seen this run — since the scan runs every 15 min against a
 * 120-min warning window, an un-suppressed alert would otherwise page ~8x
 * per case. An escalation to a higher severity band pages through the
 * cooldown on its own key rather than being muted by the lower band's.
 */
class ModerationSlaScanCommand extends Command
{
    protected $signature = 'moderation:sla-scan';

    protected $description = 'Warn on cases approaching SLA breach (configurable lead time).';

    public function handle(): int
    {
        $leadMinutes = (int) config('partna.moderation.sla.breach_warning_min', 120);
        $cutoff = now()->addMinutes($leadMinutes);

        $atRisk = ModerationCase::query()
            ->whereIn('status', ['open', 'triaged', 'under_review'])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', $cutoff)
            ->get(['id', 'severity', 'sla_due_at']);

        $soonestDueInMinutes = null;
        $maxSeverity = 0;

        foreach ($atRisk as $case) {
            $minutes = now()->diffInMinutes($case->sla_due_at, false);
            Log::warning('moderation.sla.breach_risk', [
                'case_id' => $case->id,
                'severity' => $case->severity,
                'due_in_minutes' => $minutes,
            ]);

            $maxSeverity = max($maxSeverity, (int) $case->severity);
            $soonestDueInMinutes = $soonestDueInMinutes === null
                ? $minutes
                : min($soonestDueInMinutes, $minutes);
        }

        if ($atRisk->isNotEmpty()) {
            $this->reportBreachRisk($atRisk->count(), $maxSeverity, (int) $soonestDueInMinutes);
        }

        $this->info("Scanned. {$atRisk->count()} cases near SLA breach.");

        return self::SUCCESS;
    }

    /**
     * Suppress repeat pages for the same severity band via Cache::add
     * (SETNX), keyed on max severity so an escalation pages through the
     * cooldown on its own key. On a cache fault, report() UNCONDITIONALLY —
     * this is trust & safety, so a broken suppression mechanism must
     * over-page, never under-page. Inverse of the analytics fail-open
     * contract: there, a broken guard silently drops writes; here, a broken
     * guard must not silently drop alerts.
     */
    private function reportBreachRisk(int $count, int $maxSeverity, int $soonestDueInMinutes): void
    {
        $ttl = (int) config('partna.moderation.sla.alert_cooldown_seconds', 3600);
        $key = "moderation:sla-breach-alert:severity-{$maxSeverity}";

        try {
            $claimed = Cache::add($key, 1, $ttl);
        } catch (Throwable $e) {
            Log::error('moderation.sla.alert_cooldown_cache_fault', [
                'exception' => $e->getMessage(),
            ]);
            $claimed = true;
        }

        if ($claimed) {
            report(new ModerationSlaBreachRiskException($count, $maxSeverity, $soonestDueInMinutes));
        }
    }
}
