<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesSearchQueries;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\Staff\UserSite\StaffUpdateUserRequest;
use App\Http\Requests\Api\Staff\UserSite\StaffUpdateUserStatusRequest;
use App\Http\Resources\Staff\StaffUserListResource;
use App\Http\Resources\UserStaffResource;
use App\Models\Core\User\User;
use Exception;
use Illuminate\Auth\Access\Response as GateResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Staff browses, searches, and manages professionals (status updates, archive, restore, hard delete). Primary staff dashboard entry point.
class StaffUserController extends ApiController
{
    use HandlesSearchQueries;
    use NormalizesPerPage;
    use ReturnsPaginatedResponse;

    /**
     * GET /api/staff/professionals?q=...&status=...&per_page=...
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status'); // optional: active|suspended
        $perPage = $this->normalizePerPage($request, (int) config('partna.staff.pagination.per_page', 25), (int) config('partna.staff.pagination.per_page_max', 100));
        $searchLike = $this->prepareSearchLike($request, 'q');

        $query = User::query()
            ->with(['site'])
            ->orderByDesc('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if ($searchLike) {
            $query->where(function ($qq) use ($searchLike) {
                $qq->whereRaw('handle ILIKE ?', [$searchLike])
                    ->orWhereRaw('display_name ILIKE ?', [$searchLike])
                    ->orWhereRaw('primary_email ILIKE ?', [$searchLike])
                    ->orWhereRaw('phone ILIKE ?', [$searchLike])
                    ->orWhereRaw('first_name ILIKE ?', [$searchLike])
                    ->orWhereRaw('last_name ILIKE ?', [$searchLike])
                    ->orWhereHas('site', function ($s) use ($searchLike) {
                        $s->whereRaw('subdomain ILIKE ?', [$searchLike]);
                    });
            });
        }

        $page = $query->paginate($perPage);

        // PII gate: only admin staff may see raw email + phone in the list view.
        $staff = $request->attributes->get('partna_staff');
        $showPii = $staff && $staff->isAdmin();

        // Keep response light for list-view
        $professionals = $page->getCollection()->map(
            fn (User $p) => (new StaffUserListResource($p, $showPii))->toArray($request)
        );

        $payload = $this->paginatedResponse($page, 'professionals');
        $payload['professionals'] = $professionals;

        return $this->success($payload);
    }

    /**
     * GET /api/staff/professionals/{professional}
     */
    public function show(User $professional): JsonResponse
    {
        $professional->load(['site']);

        return $this->success([
            'professional' => new UserStaffResource($professional),
            'site' => $professional->site ? [
                'id' => $professional->site->id,
                'subdomain' => $professional->site->subdomain,
                'is_published' => (bool) $professional->site->is_published,
                // skeleton_id replaces theme — skeletons are code constants, not DB rows.
                'skeleton_id' => $professional->site->skeleton_id,
            ] : null,
        ]);
    }

    /**
     * PATCH /api/staff/professionals/{professional}/status
     * Body: { "status": "active" | "suspended" }
     */
    public function updateStatus(StaffUpdateUserStatusRequest $request, User $professional): JsonResponse
    {
        $gate = $this->requiresFreshAal2($request);
        if (! $gate->allowed()) {
            return response()->json([
                'message' => $gate->message() ?: 'Recent MFA verification required',
                'code' => 'mfa_fresh_required',
            ], $gate->status() ?? 401);
        }

        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $data = $request->validated();

        $professional->status = $data['status'];
        $professional->save();

        return $this->success([
            'professional' => new UserStaffResource($professional->fresh()),
        ]);
    }

    /**
     * POST /api/staff/professionals/bulk-status
     * Body: { "ids": uuid[], "status": "active"|"suspended" }
     *
     * Compliance sweep — suspend or reactivate a wave of accounts in one request.
     * Capped at 100 IDs per request; throttled at the route layer (5/min).
     * Returns a per-row outcome map so partial misses (deleted accounts, unknown IDs)
     * surface to the caller without rolling back the whole batch.
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $gate = $this->requiresFreshAal2($request);
        if (! $gate->allowed()) {
            return response()->json([
                'message' => $gate->message() ?: 'Recent MFA verification required',
                'code' => 'mfa_fresh_required',
            ], $gate->status() ?? 401);
        }

        // No model target — authorize against User::class (policy model hint).
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffBulkManage', User::class);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid', 'distinct'],
            'status' => ['required', 'string', 'in:active,suspended'],
        ]);

        $ids = array_values(array_unique($data['ids']));
        $status = $data['status'];

        $updated = [];
        $missing = [];

        DB::transaction(function () use ($ids, $status, &$updated, &$missing): void {
            $existing = User::query()->whereIn('id', $ids)->get(['id'])->pluck('id')->all();
            $missing = array_values(array_diff($ids, $existing));

            if (! empty($existing)) {
                User::query()
                    ->whereIn('id', $existing)
                    ->update(['status' => $status]);
                $updated = $existing;
            }
        });

        // Audit log per professional (placeholder for #OPS-2 audit log). One entry per row
        // so a fraud sweep that suspended 80 accounts produces 80 traceable records.
        foreach ($updated as $id) {
            Log::info('staff-bulk-status: professional status changed', [
                'action' => 'staff-bulk-status',
                'user_id' => $id,
                'new_status' => $status,
            ]);
        }

        return $this->success([
            'updated_count' => count($updated),
            'updated_ids' => $updated,
            'missing_ids' => $missing,
            'status' => $status,
        ]);
    }

    public function update(
        StaffUpdateUserRequest $request,
        User $professional,
    ) {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        DB::transaction(function () use ($professional, $request): void {
            $professional->fill($request->validated());
            $professional->save();
        });

        return $this->success([
            'professional' => new UserStaffResource($professional->fresh()),
        ]);
    }

    /**
     * Soft delete - Normal staff operation
     * DELETE /api/staff/professionals/{professional}
     */
    public function destroy(Request $request, User $professional): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        // Soft delete (sets deleted_at)
        if (! $professional->trashed()) {
            $professional->delete();
        }

        return $this->success([
            'message' => 'Professional archived successfully',
            'archived' => true,
        ]);
    }

    public function restore(Request $request, User $professional): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        if ($professional->trashed()) {
            $professional->restore();
        }

        return $this->success([
            'message' => 'Professional restored successfully',
            'professional' => new UserStaffResource($professional->fresh()),
        ]);
    }

    /**
     * Inline copy of BasePolicy::requiresFreshAal2 / MfaController::requiresFreshAal2.
     *
     * Kept as a local copy (not a shared trait) following the convention established
     * in MfaController: these high-risk staff actions have no model to authorize against
     * and a controller cannot call a protected policy method. Uses the staff fresh window
     * (config('partna.mfa.fresh_window_seconds', 300)) rather than the unenroll window.
     */
    private function requiresFreshAal2(Request $request, ?int $maxAgeSeconds = null): GateResponse
    {
        $maxAgeSeconds ??= (int) config('partna.mfa.fresh_window_seconds', 300);
        $amr = $request->attributes->get('supabase_amr', []);
        $mfaMethods = ['totp', 'phone', 'webauthn'];

        $mostRecentMfaTs = null;
        foreach ($amr as $entry) {
            $method = $entry['method'] ?? null;
            if (in_array($method, $mfaMethods, true)) {
                $ts = (int) ($entry['timestamp'] ?? 0);
                if ($mostRecentMfaTs === null || $ts > $mostRecentMfaTs) {
                    $mostRecentMfaTs = $ts;
                }
            }
        }

        if ($mostRecentMfaTs === null) {
            return GateResponse::denyWithStatus(401, 'Recent MFA verification required');
        }

        return (time() - $mostRecentMfaTs) <= $maxAgeSeconds
            ? GateResponse::allow()
            : GateResponse::denyWithStatus(401, 'Recent MFA verification required');
    }

    public function forceDestroy(Request $request, User $professional): JsonResponse
    {
        $gate = $this->requiresFreshAal2($request);
        if (! $gate->allowed()) {
            return response()->json([
                'message' => $gate->message() ?: 'Recent MFA verification required',
                'code' => 'mfa_fresh_required',
            ], $gate->status() ?? 401);
        }

        // Explicit policy gate — defence-in-depth for the most destructive operation.
        // staffForceDelete is admin-only even if the route group ever grants access
        // to support staff (which would otherwise be able to permanently delete).
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffForceDelete', $professional);

        // Hard delete - PERMANENT
        $handle = $professional->handle;

        try {
            $professional->forceDelete();

            return $this->success([
                'message' => "Professional '{$handle}' permanently deleted",
                'permanently_deleted' => true,
            ]);
        } catch (Exception $e) {
            return $this->error(
                'Cannot delete:  Professional has related data that must be removed first.',
                409
            );
        }
    }
}
