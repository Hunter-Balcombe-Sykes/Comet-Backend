<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesSearchQueries;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\Staff\StaffForceDestroyRequest;
use App\Http\Requests\Api\Staff\UserSite\StaffBulkUpdateStatusRequest;
use App\Http\Requests\Api\Staff\UserSite\StaffUpdateUserRequest;
use App\Http\Requests\Api\Staff\UserSite\StaffUpdateUserStatusRequest;
use App\Http\Resources\Staff\StaffUserListResource;
use App\Http\Resources\UserStaffResource;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use App\Services\Auth\Aal2FreshnessGate;
use App\Services\PreAccount\ClaimSiteService;
use App\Services\User\AccountDeletionService;
use Illuminate\Auth\Access\Response as GateResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// V2: Staff browses, searches, and manages professionals (status updates, archive, restore, hard delete). Primary staff dashboard entry point.
class StaffUserController extends ApiController
{
    use HandlesSearchQueries;
    use NormalizesPerPage;
    use ReturnsPaginatedResponse;

    /**
     * GET /api/staff/professionals?q=...&status=...&sector=...&account_type=...&per_page=...
     * OV-A: q also matches sector; sector/account_type are exact-match filters.
     *
     * No UserSelfPolicy::staffView call here (#SEC-101) — it authorizes against a
     * single User target and this is a paginated collection; per-row authorize calls
     * would be N gate evaluations for a currently-unconditional ability. The PII gate
     * ($showPii below) is the enforcement point for this endpoint, same as show().
     * #W1-SEC-3: $showPii now also shapes the `q` search predicate, not just the
     * response — a column hidden from a caller must not be searchable by that
     * caller either (see the q closure below).
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status'); // optional: active|suspended
        $perPage = $this->normalizePerPage($request, (int) config('partna.staff.pagination.per_page', 25), (int) config('partna.staff.pagination.per_page_max', 100));
        $searchLike = $this->prepareSearchLike($request, 'q');

        // Hoisted above the query so both the search predicate and the response
        // shaping below read the same value and cannot drift (#W1-SEC-3).
        $staff = $request->attributes->get('partna_staff');
        $showPii = $staff && $staff->isAdmin();

        $query = User::query()
            ->with(['site'])
            ->orderByDesc('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $sector = $request->query('sector');
        if (is_string($sector) && $sector !== '') {
            $query->where('sector', $sector);
        }

        $accountType = $request->query('account_type');
        if (is_string($accountType) && in_array($accountType, ['partna', 'business'], true)) {
            $query->where('account_type', $accountType);
        }

        if ($searchLike) {
            $query->where(function ($qq) use ($searchLike, $showPii) {
                $qq->whereRaw('handle ILIKE ?', [$searchLike])
                    ->orWhereRaw('display_name ILIKE ?', [$searchLike]);

                // #W1-SEC-3: a column hidden from this caller must not be a
                // search predicate either — matching on it (even via ILIKE,
                // even without echoing the field back) is an existence oracle
                // over the exact value $showPii exists to withhold.
                if ($showPii) {
                    $qq->orWhereRaw('primary_email ILIKE ?', [$searchLike])
                        ->orWhereRaw('phone ILIKE ?', [$searchLike]);
                }

                $qq->orWhereRaw('first_name ILIKE ?', [$searchLike])
                    ->orWhereRaw('last_name ILIKE ?', [$searchLike])
                    ->orWhereRaw('sector ILIKE ?', [$searchLike])
                    ->orWhereHas('site', function ($s) use ($searchLike) {
                        $s->whereRaw('subdomain ILIKE ?', [$searchLike]);
                    });
            });
        }

        $page = $query->paginate($perPage);

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
     * OV-A: detail now carries the integrations summary + architecture/design
     * summary so the staff user page renders from one call.
     *
     * PII gate (#SEC-101): only admin staff see the sensitive fields on
     * UserStaffResource (email, phone, address, admin_notes, auth_user_id) —
     * mirrors the $showPii pattern already used by index().
     */
    public function show(Request $request, User $professional): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);
        $showPii = $staff && $staff->isAdmin();

        // Task 18: preAccountBuild is eager-loaded unconditionally (not just for
        // status='unclaimed') so staff can see the marketing-pipeline origin record
        // even after the professional has claimed and gone active — UserStaffResource
        // gates the block on whether a build actually exists, not just on status.
        $professional->load(['site', 'preAccountBuild']);

        $integrations = $professional->integrationConnections()
            ->orderBy('platform')
            ->get(['id', 'platform', 'is_active', 'last_refreshed_at', 'last_refresh_status'])
            ->groupBy('platform')
            ->map(fn ($group, $platform) => [
                'platform' => (string) $platform,
                'connection_count' => $group->count(),
                'is_active' => $group->contains(fn ($row) => (bool) $row->is_active),
                'last_refreshed_at' => $group->pluck('last_refreshed_at')->max(),
                'has_refresh_error' => $group->contains(fn ($row) => $row->last_refresh_status === 'error'),
            ])
            ->values();

        $designKitVars = $professional->site?->designKitVars() ?? [];

        return $this->success([
            'professional' => new UserStaffResource($professional, $showPii),
            'site' => $professional->site ? [
                'id' => $professional->site->id,
                'subdomain' => $professional->site->subdomain,
                'is_published' => (bool) $professional->site->is_published,
            ] : null,
            'integrations' => $integrations,
            'design_summary' => $professional->site ? [
                'stored_var_count' => count($designKitVars),
                // The high-signal identity vars for an at-a-glance summary; the
                // full partial kit ships too for the staff design editor.
                // Lookup keys are raw `site.design_kits` column names —
                // designKitVars() returns the row verbatim, so a stale alias
                // reads as null forever rather than failing loudly.
                'accent_color' => $designKitVars['color_accent'] ?? null,
                'font_family' => $designKitVars['typography_font_family'] ?? null,
                'design_kit' => $designKitVars,
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

        // staffManage above already enforces admin-only, so PII is always shown here.
        return $this->success([
            'professional' => new UserStaffResource($professional->fresh(), true),
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
    public function bulkUpdateStatus(StaffBulkUpdateStatusRequest $request): JsonResponse
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

        $ids = array_values(array_unique($request->validated('ids')));
        $status = $request->validated('status');

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
        $gate = $this->requiresFreshAal2($request);
        if (! $gate->allowed()) {
            return response()->json([
                'message' => $gate->message() ?: 'Recent MFA verification required',
                'code' => 'mfa_fresh_required',
            ], $gate->status() ?? 401);
        }

        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $validated = $request->validated();

        DB::transaction(function () use ($professional, $validated): void {
            // SEC-2: admin_notes is no longer fillable (self-service UpdateUserRequest
            // doesn't validate it, so leaving it fillable would let self-service writes
            // through fill() reach it too). StaffUpdateUserRequest DOES validate it, so
            // set it explicitly before the mass-assignable fields.
            if (array_key_exists('admin_notes', $validated)) {
                $professional->admin_notes = $validated['admin_notes'];
            }
            $professional->fill($validated);
            $professional->save();
        });

        // staffManage above already enforces admin-only, so PII is always shown here.
        return $this->success([
            'professional' => new UserStaffResource($professional->fresh(), true),
        ]);
    }

    /**
     * Soft delete - Normal staff operation
     * DELETE /api/staff/professionals/{professional}
     */
    public function destroy(Request $request, User $professional): JsonResponse
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
        $gate = $this->requiresFreshAal2($request);
        if (! $gate->allowed()) {
            return response()->json([
                'message' => $gate->message() ?: 'Recent MFA verification required',
                'code' => 'mfa_fresh_required',
            ], $gate->status() ?? 401);
        }

        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        if ($professional->trashed()) {
            $professional->restore();
        }

        // staffManage above already enforces admin-only, so PII is always shown here.
        return $this->success([
            'message' => 'Professional restored successfully',
            'professional' => new UserStaffResource($professional->fresh(), true),
        ]);
    }

    /**
     * Fresh-AAL2 gate for high-risk staff actions (updateStatus / bulkUpdateStatus
     * / update / destroy / restore / forceDestroy / releaseClaim). Local wrapper
     * (not a policy) following MfaController's convention: these actions have no
     * model to authorize against and a controller cannot call a protected policy
     * method. Uses the staff fresh window (config('partna.mfa.fresh_window_seconds',
     * 300)); the scan is the shared Aal2FreshnessGate so this path can no longer
     * drift from BasePolicy.
     */
    private function requiresFreshAal2(Request $request, ?int $maxAgeSeconds = null): GateResponse
    {
        $maxAgeSeconds ??= (int) config('partna.mfa.fresh_window_seconds', 300);

        return app(Aal2FreshnessGate::class)->check($request, $maxAgeSeconds);
    }

    public function forceDestroy(StaffForceDestroyRequest $request, User $professional): JsonResponse
    {
        $gate = $this->requiresFreshAal2($request);
        if (! $gate->allowed()) {
            return response()->json([
                'message' => $gate->message() ?: 'Recent MFA verification required',
                'code' => 'mfa_fresh_required',
            ], $gate->status() ?? 401);
        }

        // Admin-only even if the route group ever widened to support staff.
        /** @var PartnaStaff $staff */
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffForceDelete', $professional);

        $handle = $professional->handle;

        // Full immediate purge: pseudonymise + delete the Supabase auth user (frees the
        // email) + hard-delete + retire KV. Skips the 30-day grace period.
        $result = app(AccountDeletionService::class)->adminPurgeNow(
            professional: $professional,
            staffActorId: (string) $staff->id,
            staffActorHandle: (string) ($staff->name ?? $staff->primary_email ?? ''),
            reason: (string) $request->validated('reason'),
            overrideObligations: (bool) $request->validated('override_obligations', false),
            request: $request,
        );

        if (! ($result['success'] ?? false)) {
            return $this->error(
                $result['error'] ?? 'Account deletion failed.',
                $result['code'] ?? 400,
                [],
                isset($result['reasons']) ? ['reasons' => $result['reasons']] : [],
            );
        }

        return $this->success([
            'message' => "Professional '{$handle}' permanently deleted",
            'permanently_deleted' => true,
            'email_freed' => true,
        ]);
    }

    /**
     * Release a claim — the NON-destructive counterpart to forceDestroy().
     *
     * A wrongly-claimed pre-account site previously had only one recovery:
     * /force, which destroys the scraped site and makes the rightful owner
     * rebuild it. This unbinds the claimer and returns the row to 'unclaimed'
     * so they can claim it through the ordinary public endpoint.
     *
     * Owner ruling 2026-08-25: the site returns to OPEN first-come — there is
     * deliberately no email lock — so a release is only safe while staff are in
     * contact with the rightful owner. And the release ALWAYS proceeds: content
     * the previous claimer added produces a WARNING in the response, never a
     * refusal. That warning is the control — a non-empty one means they used the
     * account and /force, not this, is the correct tool.
     */
    public function releaseClaim(Request $request, User $professional): JsonResponse
    {
        $gate = $this->requiresFreshAal2($request);
        if (! $gate->allowed()) {
            return response()->json([
                'message' => $gate->message() ?: 'Recent MFA verification required',
                'code' => 'mfa_fresh_required',
            ], $gate->status() ?? 401);
        }

        /** @var PartnaStaff $staff */
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffReleaseClaim', $professional);

        // Counted BEFORE the release: this is what the incoming owner would
        // inherit, so it must describe the state staff are deciding about.
        $warnings = $this->releaseClaimWarnings($professional);

        try {
            app(ClaimSiteService::class)->release($professional);
        } catch (RuntimeException $e) {
            // $extra (not $errors) puts `code` at the TOP level, matching the
            // discriminator contract ClaimController and PreAccountBuildController use.
            return match ($e->getMessage()) {
                'NOT_CLAIMED' => $this->error(
                    'This site is not currently claimed, so there is nothing to release.',
                    409, [], ['code' => 'NOT_CLAIMED']
                ),
                'NOT_PRE_ACCOUNT' => $this->error(
                    'This account has no pre-account build, so it has no claim to release.',
                    409, [], ['code' => 'NOT_PRE_ACCOUNT']
                ),
                default => throw $e,
            };
        }

        return $this->success([
            'released' => true,
            'warnings' => $warnings,
            'message' => $warnings === []
                ? 'Claim released. The site is unclaimed and open to be claimed again.'
                : 'Claim released, but the previous claimer had added content — review it before the owner claims, or force-delete instead.',
        ]);
    }

    /**
     * What the incoming owner would inherit from the previous claimer.
     *
     * Only non-zero categories are returned, so an empty array is an unambiguous
     * "clean release" signal rather than a row of zeros staff have to read.
     *
     * @return array<string, int>
     */
    private function releaseClaimWarnings(User $professional): array
    {
        $siteId = $professional->site?->id;

        return array_filter([
            'customers' => Customer::query()->where('user_id', $professional->id)->count(),
            'enquiries' => Enquiry::query()->where('user_id', $professional->id)->count(),
            'integration_connections' => IntegrationConnection::query()->where('user_id', $professional->id)->count(),
            'media' => $siteId ? SiteMedia::query()->where('site_id', $siteId)->count() : 0,
        ], fn (int $count) => $count > 0);
    }
}
