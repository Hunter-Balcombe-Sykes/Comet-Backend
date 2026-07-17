<?php

namespace App\Http\Controllers\Api\Staff;

use App\DTOs\Moderation\DecisionDto;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Staff\DecideOnCaseRequest;
use App\Http\Requests\Staff\EscalateCaseRequest;
use App\Http\Requests\Staff\TriageCaseRequest;
use App\Http\Resources\Moderation\CaseDetailResource;
use App\Http\Resources\Moderation\CaseResource;
use App\Http\Resources\Moderation\DecisionResource;
use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\CaseAlreadyResolved;
use App\Services\Moderation\CaseAlreadyTaken;
use App\Services\Moderation\IllegalCaseTransition;
use App\Services\Moderation\ModerationCaseService;
use App\Services\Moderation\ModerationDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff moderation queue management.
 * All routes require AAL2 (enforced by require.aal2 middleware on the staff route group).
 * Auth actor is always `$request->attributes->get('partna_staff')` — never $request->user().
 *
 * Response contract matches every sibling staff controller (B5): the list goes
 * through paginatedResponse() ({data, meta}) rather than a native resource
 * envelope, and domain-rule failures return $this->error() with a machine
 * discriminator `code` instead of abort() (whose bare `{message}` the frontend
 * can't branch on). findOrFail() still surfaces a 404 for unknown ids.
 */
class StaffCaseController extends ApiController
{
    use ReturnsPaginatedResponse;

    public function index(Request $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'viewAny', ModerationCase::class);

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

        $page = $query->paginate((int) config('partna.staff.pagination.per_page', 25));
        $page->through(fn (ModerationCase $case) => CaseResource::make($case)->resolve());

        return $this->success($this->paginatedResponse($page));
    }

    /**
     * GET /api/staff/cases/{id} — full case detail with eager-loaded relations.
     * Returns CaseDetailResource (case + signals + evidence + decisions).
     * PII fields (reporter_email, reporter_ip_hash) are never exposed via CaseSignalResource.
     */
    public function show(Request $request, string $caseId): CaseDetailResource
    {
        $case = ModerationCase::query()
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
    public function triage(TriageCaseRequest $request, string $caseId): CaseResource|JsonResponse
    {
        $case = ModerationCase::query()->findOrFail($caseId);
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'triage', $case);

        try {
            $updated = app(ModerationCaseService::class)
                ->triage($case, $staff, $request->toDto());
        } catch (IllegalCaseTransition $e) {
            return $this->error($e->getMessage(), 422, [], ['code' => 'illegal_transition']);
        }

        return new CaseResource($updated);
    }

    /**
     * POST /api/staff/cases/{id}/take — assign the case to this staff member (under_review).
     */
    public function take(Request $request, string $caseId): CaseResource|JsonResponse
    {
        $case = ModerationCase::query()->findOrFail($caseId);
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'take', $case);

        try {
            $updated = app(ModerationCaseService::class)->take($case, $staff);
        } catch (CaseAlreadyTaken $e) {
            return $this->error($e->getMessage(), 409, [], ['code' => 'case_already_taken']);
        } catch (IllegalCaseTransition $e) {
            return $this->error($e->getMessage(), 422, [], ['code' => 'illegal_transition']);
        }

        return new CaseResource($updated);
    }

    /**
     * POST /api/staff/cases/{id}/release — return a case from under_review → triaged.
     */
    public function release(Request $request, string $caseId): CaseResource|JsonResponse
    {
        $case = ModerationCase::query()->findOrFail($caseId);
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'release', $case);

        try {
            $updated = app(ModerationCaseService::class)->release($case, $staff);
        } catch (IllegalCaseTransition $e) {
            return $this->error($e->getMessage(), 422, [], ['code' => 'illegal_transition']);
        }

        return new CaseResource($updated);
    }

    /**
     * POST /api/staff/cases/{id}/decide — record a moderation decision.
     * Dispatches outcome jobs (suspend, hide, notify) via ModerationActionDispatcher.
     * CSAM override requires second_staff_approval_id ≠ deciding staff (validated by request).
     */
    public function decide(DecideOnCaseRequest $request, string $caseId): DecisionResource|JsonResponse
    {
        $case = ModerationCase::query()->findOrFail($caseId);
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'decide', $case);

        try {
            $decision = app(ModerationDecisionService::class)
                ->decide($case, $staff, $request->toDto());
        } catch (CaseAlreadyResolved $e) {
            return $this->error($e->getMessage(), 409, [], ['code' => 'case_already_resolved']);
        } catch (IllegalCaseTransition|\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, [], ['code' => 'illegal_transition']);
        }

        return new DecisionResource($decision);
    }

    /**
     * POST /api/staff/cases/{id}/escalate — escalate a case to an external authority.
     * Wraps the escalation target into a decision via ModerationDecisionService.
     */
    public function escalate(EscalateCaseRequest $request, string $caseId): DecisionResource|JsonResponse
    {
        $case = ModerationCase::query()->findOrFail($caseId);
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'escalate', $case);

        $esc = $request->toDto();
        $dto = new DecisionDto(
            decisionType: $esc->toDecisionType(),
            reason: $esc->notes,
            secondStaffApprovalId: null,
        );

        try {
            $decision = app(ModerationDecisionService::class)->decide($case, $staff, $dto);
        } catch (CaseAlreadyResolved $e) {
            return $this->error($e->getMessage(), 409, [], ['code' => 'case_already_resolved']);
        } catch (IllegalCaseTransition $e) {
            return $this->error($e->getMessage(), 422, [], ['code' => 'illegal_transition']);
        }

        return new DecisionResource($decision);
    }
}
