<?php

namespace App\Services\Moderation;

use App\DTOs\Moderation\DecisionDto;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The single legal write path for moderation.decisions.
 *
 * Writes a decision row, dispatches the action set via ModerationActionDispatcher,
 * transitions the case to resolved, and records an audit row — all in one DB transaction.
 * Jobs are dispatched afterCommit via ModerationActionDispatcher so a rollback
 * never produces orphaned Horizon jobs.
 */
class ModerationDecisionService
{
    public function __construct(
        private readonly CaseStateMachine $sm,
        private readonly ModerationActionDispatcher $dispatcher,
        private readonly ModerationAuditService $audit,
    ) {}

    /**
     * Record a staff decision against a moderation case.
     *
     * Transitions the case to 'resolved', writes action_log rows, and appends
     * an audit event. For CSAM override decisions, a second approver distinct
     * from the deciding staff is mandatory.
     *
     * @throws InvalidArgumentException if CSAM override validations fail
     */
    public function decide(ModerationCase $case, PartnaStaff $staff, DecisionDto $dto): Decision
    {
        // Validate before entering the transaction so we never partially write.
        $this->validateDecisionTypeForCase($case, $dto);
        $this->validateCsamOverride($dto, $staff);

        // Concurrency guard: a case another staffer already resolved is a conflict
        // (HTTP 409), not an illegal transition (422).
        if ($case->status === 'resolved') {
            throw new CaseAlreadyResolved($case->id);
        }

        $decision = DB::transaction(function () use ($case, $staff, $dto) {
            // forceCreate() is required because Decision guards 'id' via $guarded = ['id'].
            $decision = Decision::forceCreate([
                'id'                        => Str::uuid()->toString(),
                'case_id'                   => $case->id,
                'decision_type'             => $dto->decisionType,
                'reason'                    => $dto->reason,
                'decided_by_staff_id'       => $staff->id,
                'decided_by_system'         => false,
                'auto_actioned'             => false,
                'second_staff_approval_id'  => $dto->secondStaffApprovalId,
                'second_staff_approved_at'  => $dto->secondStaffApprovalId !== null ? now() : null,
                'decided_at'                => now(),
            ]);

            // Transition status first so the case is in a consistent state before
            // action_log rows reference it.
            $this->sm->transition($case, 'resolved');
            $case->resolved_at = now();
            $case->save();

            // Writes action_log rows inside the transaction; schedules Horizon jobs
            // via DB::afterCommit so they only fire on successful commit.
            $this->dispatcher->dispatchFor($decision);

            $this->audit->recordStaffAction(
                $staff,
                'case.decided',
                'ModerationCase',
                $case->id,
                ['decision_type' => $dto->decisionType, 'decision_id' => $decision->id],
            );

            return $decision;
        });

        // Observability breadcrumb (logged after commit so a rollback doesn't emit it).
        Log::info('moderation.decision', [
            'case_id'       => $case->id,
            'decision_id'   => $decision->id,
            'decision_type' => $dto->decisionType,
            'staff_id'      => $staff->id,
        ]);

        return $decision;
    }

    /**
     * Auto-action path for automated detectors (system-attributed decisions).
     * Writes a system-attributed decision (decided_by_system=true),
     * transitions the case to 'auto_actioned' (skipped if already there), dispatches
     * the action set via the existing dispatcher, and records a system audit row.
     * All writes are wrapped in a single DB transaction; jobs fire afterCommit.
     */
    public function decideAsSystem(ModerationCase $case, DecisionDto $dto): Decision
    {
        $decision = DB::transaction(function () use ($case, $dto) {
            $decision = Decision::forceCreate([
                'id'                        => Str::uuid()->toString(),
                'case_id'                   => $case->id,
                'decision_type'             => $dto->decisionType,
                'reason'                    => $dto->reason,
                'decided_by_staff_id'       => null,
                'decided_by_system'         => true,
                'auto_actioned'             => true,
                'second_staff_approval_id'  => null,
                'second_staff_approved_at'  => null,
                'decided_at'                => now(),
            ]);

            // Only transition if not already auto_actioned (e.g. case was 'open'
            // when the system fires; a re-decision against an already-actioned case
            // should not re-trigger the FSM).
            if ($this->sm->canTransition($case, 'auto_actioned')) {
                $this->sm->transition($case, 'auto_actioned');
                $case->auto_actioned = true;
                $case->save();
            }

            $this->dispatcher->dispatchFor($decision);

            $this->audit->recordSystemAction(
                'case.auto_decided',
                'ModerationCase',
                $case->id,
                ['decision_type' => $dto->decisionType, 'decision_id' => $decision->id],
            );

            return $decision;
        });

        // Observability breadcrumb (logged after commit so a rollback doesn't emit it).
        Log::warning('moderation.auto_action', [
            'case_id'       => $case->id,
            'decision_id'   => $decision->id,
            'decision_type' => $dto->decisionType,
        ]);

        return $decision;
    }

    /**
     * F8: a csam_match case can only be resolved by overriding the auto-action
     * (with a second approver). Any other decision_type (dismiss, warn, …) is
     * invalid for it and must be rejected with INVALID_DECISION_FOR_CASE_TYPE.
     */
    private function validateDecisionTypeForCase(ModerationCase $case, DecisionDto $dto): void
    {
        if ($case->case_type === 'csam_match' && $dto->decisionType !== 'override_csam_auto_action') {
            throw new InvalidArgumentException(
                'INVALID_DECISION_FOR_CASE_TYPE: csam_match cases can only be resolved via override_csam_auto_action'
            );
        }
    }

    /**
     * CSAM override decisions require a second approver to prevent a single staff
     * member from unilaterally overriding automated CSAM enforcement.
     */
    private function validateCsamOverride(DecisionDto $dto, PartnaStaff $staff): void
    {
        if ($dto->decisionType !== 'override_csam_auto_action') {
            return;
        }

        if ($dto->secondStaffApprovalId === null) {
            throw new InvalidArgumentException(
                'CSAM override requires second_staff_approval_id'
            );
        }

        if ($dto->secondStaffApprovalId === $staff->id) {
            throw new InvalidArgumentException(
                'CSAM override second staff must differ from deciding staff'
            );
        }
    }
}
