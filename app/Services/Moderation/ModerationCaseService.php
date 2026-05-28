<?php

namespace App\Services\Moderation;

use App\DTOs\Moderation\TriageDto;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\DB;

/**
 * Case-side mutations: triage, take, release.
 * Decisions live in ModerationDecisionService.
 */
class ModerationCaseService
{
    public function __construct(
        private readonly CaseStateMachine $sm,
        private readonly ModerationAuditService $audit,
    ) {}

    /**
     * Transition an open case to triaged status.
     * Throws IllegalCaseTransition when the current status doesn't allow it.
     *
     * @throws IllegalCaseTransition
     */
    public function triage(ModerationCase $case, PartnaStaff $staff, TriageDto $dto): ModerationCase
    {
        return DB::transaction(function () use ($case, $staff, $dto) {
            // Validates the transition and mutates $case->status — throws on illegal transitions.
            $this->sm->transition($case, 'triaged');

            $case->triaged_by_staff_id = $staff->id;
            $case->triaged_at = now();
            if ($dto->priority !== null) {
                $case->priority = $dto->priority;
            }
            $case->save();

            $this->audit->recordStaffAction(
                $staff,
                'case.triaged',
                'ModerationCase',
                $case->id,
                ['notes' => $dto->notes, 'priority' => $case->priority],
            );

            return $case;
        });
    }
}
