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
//  · this count is NO LONGER the same set the inbox renders. index() now
//    hides an intent whose account the user connected by another route, and
//    nothing settles that row, so this alarm over-counts by however many of
//    those exist. It drifts upward and never down. Fixing that means giving
//    the settle a home (a connection-create observer, or a scheduled
//    routing:settle-connected) — until then, read a slow rise here as
//    possibly-stale rather than as a detector regression.
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
