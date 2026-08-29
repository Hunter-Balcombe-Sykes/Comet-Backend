<?php

namespace App\Exceptions\Moderation;

use RuntimeException;

// Reported to Nightwatch by `moderation:sla-scan` when one or more open/
// triaged/under_review cases are inside the SLA warning window (#W1-LIFE-1).
// ONE aggregate exception per scan run, never one per case — the scan runs
// every 15 minutes against a 120-minute warning window, so an un-suppressed
// alert would page ~8x per case before it breaches. The per-run cooldown
// (partna.moderation.sla.alert_cooldown_seconds) is what actually caps the
// paging; this exception just carries the aggregate shape Nightwatch renders.
class ModerationSlaBreachRiskException extends RuntimeException
{
    public function __construct(
        public readonly int $count,
        public readonly int $maxSeverity,
        public readonly int $soonestDueInMinutes,
    ) {
        // The scan has no lower bound on sla_due_at, so an already-breached case
        // yields a negative figure. Render that as "overdue by" rather than
        // "due in -12 minutes" — this string is what on-call reads first.
        $overdue = $soonestDueInMinutes < 0;
        $magnitude = abs($soonestDueInMinutes);

        parent::__construct(sprintf(
            '%d moderation case%s approaching SLA breach (max severity %d, soonest %s %d minute%s)',
            $count,
            $count === 1 ? '' : 's',
            $maxSeverity,
            $overdue ? 'overdue by' : 'due in',
            $magnitude,
            $magnitude === 1 ? '' : 's'
        ));
    }
}
