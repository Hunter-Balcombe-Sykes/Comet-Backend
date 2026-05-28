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

    /**
     * Claim a triaged case for review (triaged → under_review).
     * Throws IllegalCaseTransition when the case is not in triaged status (race condition guard).
     *
     * @throws IllegalCaseTransition
     */
    public function take(ModerationCase $case, PartnaStaff $staff): ModerationCase
    {
        return DB::transaction(function () use ($case, $staff) {
            $this->sm->transition($case, 'under_review');
            $case->save();

            $this->audit->recordStaffAction(
                $staff, 'case.taken', 'ModerationCase', $case->id, []
            );

            return $case;
        });
    }

    /**
     * Release a case back to the triaged queue (under_review → triaged).
     * Throws IllegalCaseTransition when the case is not under review.
     *
     * @throws IllegalCaseTransition
     */
    public function release(ModerationCase $case, PartnaStaff $staff): ModerationCase
    {
        return DB::transaction(function () use ($case, $staff) {
            $this->sm->transition($case, 'triaged');
            $case->save();

            $this->audit->recordStaffAction(
                $staff, 'case.released', 'ModerationCase', $case->id, []
            );

            return $case;
        });
    }
}
