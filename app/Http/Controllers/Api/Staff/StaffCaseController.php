<?php

namespace App\Http\Controllers\Api\Staff;

use App\DTOs\Moderation\DecisionDto;
use App\DTOs\Moderation\EscalationDto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\DecideOnCaseRequest;
use App\Http\Requests\Staff\EscalateCaseRequest;
use App\Http\Requests\Staff\TriageCaseRequest;
use App\Http\Resources\Moderation\CaseDetailResource;
use App\Http\Resources\Moderation\CaseResource;
use App\Http\Resources\Moderation\DecisionResource;
use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\IllegalCaseTransition;
use App\Services\Moderation\ModerationCaseService;
use App\Services\Moderation\ModerationDecisionService;
use Illuminate\Http\Request;

/**
 * Staff moderation queue management.
 * All routes require AAL2 (enforced by require.aal2 middleware on the staff route group).
 * Auth actor is always `$request->attributes->get('partna_staff')` — never $request->user().
 */
class StaffCaseController extends Controller
{
    public function index(Request $request)
    {
        $query = ModerationCase::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('case_type')) {
            $query->where('case_type', $type);
        }
        if ($sev = $request->query('severity_gte')) {
            $query->where('severity', '>=', (int) $sev);
        }

        $query->orderByDesc('severity')->orderBy('priority')->orderBy('created_at');

        return CaseResource::collection($query->paginate(25));
    }

    /**
     * GET /api/staff/cases/{id} — full case detail with eager-loaded relations.
     * Returns CaseDetailResource (case + signals + evidence + decisions).
     * PII fields (reporter_email, reporter_ip_hash) are never exposed via CaseSignalResource.
     */
    public function show(Request $request, string $caseId): CaseDetailResource
    {
        $case  = ModerationCase::query()
            ->with(['signals', 'evidence', 'decisions'])
            ->findOrFail($caseId);
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'view', $case);

        return new CaseDetailResource($case);
    }

    /**
     * POST /api/staff/cases/{id}/triage — move case to triaged status.
     * Optionally adjusts priority and records triage notes.
     */
    public function triage(TriageCaseRequest $request, string $caseId): CaseResource
    {
        $case  = ModerationCase::query()->findOrFail($caseId);
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'triage', $case);

        try {
            $updated = app(ModerationCaseService::class)
                ->triage($case, $staff, $request->toDto());
        } catch (IllegalCaseTransition $e) {
            abort(422, $e->getMessage());
        }

        return new CaseResource($updated);
    }

    /**
     * POST /api/staff/cases/{id}/take — assign the case to this staff member (under_review).
     */
    public function take(Request $request, string $caseId): CaseResource
    {
        $case  = ModerationCase::query()->findOrFail($caseId);
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'take', $case);

        try {
            $updated = app(ModerationCaseService::class)->take($case, $staff);
        } catch (IllegalCaseTransition $e) {
            abort(422, $e->getMessage());
        }

        return new CaseResource($updated);
    }

    /**
     * POST /api/staff/cases/{id}/release — return a case from under_review → triaged.
     */
    public function release(Request $request, string $caseId): CaseResource
    {
        $case  = ModerationCase::query()->findOrFail($caseId);
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'release', $case);

        try {
            $updated = app(ModerationCaseService::class)->release($case, $staff);
        } catch (IllegalCaseTransition $e) {
            abort(422, $e->getMessage());
        }

        return new CaseResource($updated);
    }

    /**
     * POST /api/staff/cases/{id}/decide — record a moderation decision.
     * Dispatches outcome jobs (suspend, hide, notify) via ModerationActionDispatcher.
     * CSAM override requires second_staff_approval_id ≠ deciding staff (validated by request).
     */
    public function decide(DecideOnCaseRequest $request, string $caseId): DecisionResource
    {
        $case  = ModerationCase::query()->findOrFail($caseId);
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'decide', $case);

        try {
            $decision = app(ModerationDecisionService::class)
                ->decide($case, $staff, $request->toDto());
        } catch (IllegalCaseTransition|\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return new DecisionResource($decision);
    }

    /**
     * POST /api/staff/cases/{id}/escalate — escalate a case to an external authority.
     * Wraps the escalation target into a decision via ModerationDecisionService.
     */
    public function escalate(EscalateCaseRequest $request, string $caseId): DecisionResource
    {
        $case  = ModerationCase::query()->findOrFail($caseId);
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'escalate', $case);

        $esc = $request->toDto();
        $dto = new DecisionDto(
            decisionType:          $esc->toDecisionType(),
            reason:                $esc->notes,
            secondStaffApprovalId: null,
        );

        try {
            $decision = app(ModerationDecisionService::class)->decide($case, $staff, $dto);
        } catch (IllegalCaseTransition $e) {
            abort(422, $e->getMessage());
        }

        return new DecisionResource($decision);
    }
}
