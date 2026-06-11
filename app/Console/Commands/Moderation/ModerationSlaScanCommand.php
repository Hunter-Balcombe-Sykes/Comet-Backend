<?php

namespace App\Console\Commands\Moderation;

use App\Models\Moderation\ModerationCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodically scan open moderation cases and warn when they are approaching
 * their SLA deadline. Runs every 15 minutes via the scheduler.
 *
 * Warning threshold is configurable via partna.moderation.sla.breach_warning_min
 * (default: 120 minutes before breach).
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

        foreach ($atRisk as $case) {
            $minutes = now()->diffInMinutes($case->sla_due_at, false);
            Log::warning('moderation.sla.breach_risk', [
                'case_id' => $case->id,
                'severity' => $case->severity,
                'due_in_minutes' => $minutes,
            ]);
        }

        $this->info("Scanned. {$atRisk->count()} cases near SLA breach.");

        return self::SUCCESS;
    }
}
