<?php

namespace App\Console\Commands\Moderation;

use App\Models\Moderation\Decision;
use App\Services\Moderation\ModerationAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pre-appeals stop-gap: reverse a prior decision by writing a new 'dismiss'
 * decision with supersedes_decision_id pointing at the original.
 *
 * This command does NOT undo the effects (e.g., unsuspend the user) — it
 * only records the reversal decision and an audit trail entry. Restoring
 * the account must be done separately via staff UI or other commands.
 *
 * A proper appeals system (Plan D) will make this obsolete.
 */
class ModerationReverseDecisionCommand extends Command
{
    protected $signature = 'moderation:reverse-decision {decision_id} {--reason=}';

    protected $description = 'Reverse a prior decision (pre-appeals stop-gap); creates a new decision with supersedes_decision_id.';

    public function handle(ModerationAuditService $audit): int
    {
        $reason = $this->option('reason') ?: 'unspecified';
        $original = Decision::query()->find($this->argument('decision_id'));

        if ($original === null) {
            $this->error('Decision not found.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($original, $audit, $reason) {
            $reversal = Decision::create([
                'case_id' => $original->case_id,
                'decision_type' => 'dismiss',
                'reason' => "Reversal of {$original->id}: {$reason}",
                'decided_by_staff_id' => null,
                'decided_by_system' => true,
                'auto_actioned' => false,
                'supersedes_decision_id' => $original->id,
            ]);

            $audit->recordSystemAction(
                'decision.reversed',
                'Decision',
                $original->id,
                ['reversal_decision_id' => $reversal->id, 'reason' => $reason],
            );
        });

        $this->info('Decision reversed.');

        return self::SUCCESS;
    }
}
