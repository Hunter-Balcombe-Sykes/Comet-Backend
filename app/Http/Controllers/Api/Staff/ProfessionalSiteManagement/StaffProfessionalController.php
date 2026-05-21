<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesSearchQueries;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\Staff\ProfessionalSite\StaffUpdateProfessionalRequest;
use App\Http\Resources\ProfessionalStaffResource;
use App\Models\Core\Professional\Professional;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Staff browses, searches, and manages professionals (status updates, archive, restore, hard delete). Primary staff dashboard entry point.
class StaffProfessionalController extends ApiController
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
        $perPage = $this->normalizePerPage($request, 25, 100);
        $searchLike = $this->prepareSearchLike($request, 'q');

        $query = Professional::query()
            ->with(['site.theme'])
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

        // Keep response light for list-view
        $professionals = $page->getCollection()->map(function (Professional $p) {
            $site = $p->site;
            $theme = $site?->theme;

            return [
                'id' => $p->id,
                'handle' => $p->handle,
                'display_name' => $p->display_name,
                'status' => $p->status,
                'primary_email' => $p->primary_email,
                'phone' => $p->phone,
                'created_at' => optional($p->created_at)->toISOString(),
                'updated_at' => optional($p->updated_at)->toISOString(),

                'site' => $site ? [
                    'id' => $site->id,
                    'subdomain' => $site->subdomain,
                    'is_published' => (bool) $site->is_published,
                    'theme' => $theme ? [
                        'id' => $theme->id,
                        'key' => $theme->key ?? null,
                        'name' => $theme->name ?? null,
                    ] : null,
                ] : null,
            ];
        });

        $payload = $this->paginatedResponse($page, 'professionals');
        $payload['professionals'] = $professionals;

        return $this->success($payload);
    }

    /**
     * GET /api/staff/professionals/{professional}
     */
    public function show(Professional $professional): JsonResponse
    {
        $professional->load(['site.theme', 'services', 'blocks']);

        return $this->success([
            'professional' => new ProfessionalStaffResource($professional),
            'site' => $professional->site ? [
                'id' => $professional->site->id,
                'subdomain' => $professional->site->subdomain,
                'is_published' => (bool) $professional->site->is_published,
                'theme' => $professional->site->theme ? [
                    'id' => $professional->site->theme->id,
                    'key' => $professional->site->theme->key ?? null,
                    'name' => $professional->site->theme->name ?? null,
                ] : null,
            ] : null,
        ]);
    }

    /**
     * PATCH /api/staff/professionals/{professional}/status
     * Body: { "status": "active" | "suspended" }
     */
    public function updateStatus(Request $request, Professional $professional): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended'],
        ]);

        $professional->status = $data['status'];
        $professional->save();

        return $this->success([
            'professional' => new ProfessionalStaffResource($professional->fresh()),
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
            $existing = Professional::query()->whereIn('id', $ids)->get(['id'])->pluck('id')->all();
            $missing = array_values(array_diff($ids, $existing));

            if (! empty($existing)) {
                Professional::query()
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
                'professional_id' => $id,
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
        StaffUpdateProfessionalRequest $request,
        Professional $professional,
    ) {
        DB::transaction(function () use ($professional, $request): void {
            $professional->fill($request->validated());
            $professional->save();
        });

        return $this->success([
            'professional' => new ProfessionalStaffResource($professional->fresh()),
        ]);
    }

    /**
     * Soft delete - Normal staff operation
     * DELETE /api/staff/professionals/{professional}
     */
    public function destroy(Professional $professional): JsonResponse
    {
        // Soft delete (sets deleted_at)
        if (! $professional->trashed()) {
            $professional->delete();
        }

        return $this->success([
            'message' => 'Professional archived successfully',
            'archived' => true,
        ]);
    }

    public function restore(Professional $professional): JsonResponse
    {
        if ($professional->trashed()) {
            $professional->restore();
        }

        return $this->success([
            'message' => 'Professional restored successfully',
            'professional' => new ProfessionalStaffResource($professional->fresh()),
        ]);
    }

    public function forceDestroy(Professional $professional): JsonResponse
    {

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
