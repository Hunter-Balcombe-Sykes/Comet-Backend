<?php

namespace App\Console\Commands;

use App\Exceptions\Routing\StuckSourceIntentBacklogException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// LIFE-19: backlog alarm for routing.source_intents stuck 'proposed'/'blocked'
// past the age gate. Structured as a near-copy of
// CheckPlatformRefreshBacklogCommand — that is the repo's threshold-alarm
// precedent.
//
// Deliberately an AGGREGATE alarm, not per-row: Verdict::intentState() only
// ever produces 'proposed' (block_reason='below_threshold') or 'blocked'
// (block_reason='conflict'|'cap_reached') — see PlacementPolicy/
// SourceReconciler. One stuck intent is a user who hasn't answered a question
// yet, not an incident. A mass of them, aged past the gate, is a detector
// regression or a misconfiguration — an engineering fault.
//
// Two corrections to the above as of 2026-08-26, both deliberate:
//
//  · 'unservable' is now a fourth reachable block_reason. CommerceProbeJob
//    writes it when the user accepted a suggestion and the seed could not
//    build it.
//  · index() now HIDES an intent whose account the user connected by another
//    route, so this count and the rendered inbox are no longer the same set.
//    routing:settle-connected closes those rows at 06:10, ten minutes before
//    this runs, which is what keeps the two from diverging. If that command
//    is ever disabled, this alarm starts over-counting and drifts upward with
//    no path back down — nobody can answer a card they cannot see.
//
// A third correction, 2026-09-03: 'sibling_branch' is a fifth reachable
// block_reason, and the FIRST one that is deliberately never rendered at all
// (a chain's other branches — SourceReconciler::isSettledWorkplaceSlot). It is
// excluded from the count below rather than settled out of band, because
// unlike the already-connected case there is no command that closes it: the
// row is a permanent record of a question that was answered by evidence
// instead of by a person.
class CheckStuckSourceIntentsCommand extends Command
{
    protected $signature = 'routing:stuck-intents';

    protected $description = 'Alert when too many routing.source_intents rows are stuck proposed/blocked past the age gate.';

    public function handle(): int
    {
        $ageDays = (int) config('partna.routing.intents.stuck_age_days');
        $threshold = (int) config('partna.routing.intents.stuck_alert_threshold');

        // Drives idx_source_intents_stuck.
        $stuck = DB::table('routing.source_intents')
            ->whereIn('state', ['proposed', 'blocked'])
            // A settled sibling branch is never rendered, so it can never be
            // answered — counting it would drift this alarm upward forever.
            // `!=` alone would drop every NULL-reason row (NULL != x is NULL).
            ->where(fn ($q) => $q->whereNull('block_reason')->orWhere('block_reason', '!=', 'sibling_branch'))
            ->where('first_seen_at', '<', now()->subDays($ageDays))
            ->count();

        if ($stuck > $threshold) {
            report(new StuckSourceIntentBacklogException($stuck, $threshold, $ageDays));
            $this->warn("Stuck source intents {$stuck} exceeds threshold {$threshold} — reported to Nightwatch.");
        } else {
            $this->info("Stuck source intents {$stuck} within threshold {$threshold}.");
        }

        return self::SUCCESS;
    }
}
