<?php

namespace App\Services\Moderation;

use App\Models\Moderation\ModerationCase;

/**
 * Pure FSM for moderation.cases.status transitions. No side effects.
 * Used by ModerationCaseService — the only legal write path.
 */
class CaseStateMachine
{
    private const LEGAL_TRANSITIONS = [
        'open' => ['triaged', 'auto_actioned', 'resolved'],
        'triaged' => ['under_review', 'resolved'],
        'under_review' => ['resolved', 'triaged'],
        'auto_actioned' => ['resolved'],
        'resolved' => [],   // terminal
    ];

    public function transition(ModerationCase $case, string $to): void
    {
        $from = $case->status;

        if (! isset(self::LEGAL_TRANSITIONS[$from])) {
            throw IllegalCaseTransition::forStatuses($from, $to);
        }

        if (! in_array($to, self::LEGAL_TRANSITIONS[$from], strict: true)) {
            throw IllegalCaseTransition::forStatuses($from, $to);
        }

        $case->status = $to;
    }

    public function canTransition(ModerationCase $case, string $to): bool
    {
        $from = $case->status;

        return isset(self::LEGAL_TRANSITIONS[$from])
            && in_array($to, self::LEGAL_TRANSITIONS[$from], strict: true);
    }
}
