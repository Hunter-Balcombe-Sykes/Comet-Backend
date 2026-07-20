<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\StaffCancelDeletionRequest;
use App\Http\Requests\Api\Staff\StaffInitiateDeletionRequest;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Services\User\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

// V2: Admin-side counterpart to UserAccountDeletionController.
// Admin is already authenticated via Supabase JWT and gated by staff.admin
// middleware, so we skip the email-token roundtrip.
class StaffAccountDeletionController extends ApiController
{
    public function __construct(
        private readonly AccountDeletionService $deletionService,
    ) {}

    /**
     * POST /staff/professionals/{professional}/deletion/initiate
     * Body: { reason: string (10-500), override_obligations?: bool }
     */
    public function initiate(
        StaffInitiateDeletionRequest $request,
        User $professional,
    ): JsonResponse {
        /** @var PartnaStaff $staff */
        $staff = $request->attributes->get('partna_staff');
        // #SEC-3: staffManage (admin-only) — defence-in-depth on top of the
        // staff.admin route middleware for this destructive, GDPR-flow action.
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $result = $this->deletionService->adminInitiate(
            professional: $professional,
            staffActorId: (string) $staff->id,
            staffActorHandle: (string) ($staff->name ?? $staff->primary_email ?? ''),
            reason: (string) $request->validated('reason'),
            overrideObligations: (bool) $request->validated('override_obligations', false),
            request: $request,
        );

        if (! $result['success']) {
            $errors = isset($result['reasons']) ? ['reasons' => $result['reasons']] : [];

            return $this->error($result['error'] ?? 'Initiation failed.', $result['code'], $errors);
        }

        return $this->success([
            'message' => 'Account deletion scheduled.',
            'deletes_at' => $result['deletes_at'],
        ]);
    }

    /**
     * POST /staff/professionals/{professional}/deletion/cancel
     */
    public function cancel(StaffCancelDeletionRequest $request, User $professional): JsonResponse
    {
        /** @var PartnaStaff $staff */
        $staff = $request->attributes->get('partna_staff');
        // #SEC-3: staffManage (admin-only) — defence-in-depth on top of the
        // staff.admin route middleware for this destructive, GDPR-flow action.
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $reason = $request->validated('reason');

        $result = $this->deletionService->adminCancel(
            professional: $professional,
            staffActorId: (string) $staff->id,
            staffActorHandle: (string) ($staff->name ?? $staff->primary_email ?? ''),
            reason: $reason !== null ? (string) $reason : null,
            request: $request,
        );

        if (! $result['success']) {
            return $this->error($result['error'] ?? 'Cancel failed.', $result['code']);
        }

        return $this->success([
            'message' => 'Account deletion cancelled.',
        ]);
    }

    /**
     * GET /staff/professionals/{professional}/deletion
     * Returns current deletion state + recent audit entries. Available to all
     * staff (not just admin) so support can answer "where is my erasure
     * request" questions without elevated privileges.
     */
    public function show(User $professional): JsonResponse
    {
        $deletesAt = null;
        if ($professional->deletion_confirmed_at) {
            $retentionDays = (int) config('partna.soft_delete_retention_days', 30);
            $deletesAt = Carbon::parse((string) $professional->deletion_confirmed_at)
                ->addDays($retentionDays)
                ->toIso8601String();
        }

        // Select non-PII columns only — support staff don't need staff identity;
        // admin investigations can hit the DB directly.
        $auditEntries = UserDeletionAuditEntry::query()
            ->where('user_id', $professional->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'event', 'actor_type', 'reason', 'metadata', 'created_at']);

        return $this->success([
            'status' => $professional->status,
            'deletion_requested_at' => optional($professional->deletion_requested_at)->toIso8601String(),
            'deletion_confirmed_at' => optional($professional->deletion_confirmed_at)->toIso8601String(),
            'deletes_at' => $deletesAt,
            'previous_status' => $professional->deletion_previous_status,
            'audit_entries' => $auditEntries,
        ]);
    }
}
