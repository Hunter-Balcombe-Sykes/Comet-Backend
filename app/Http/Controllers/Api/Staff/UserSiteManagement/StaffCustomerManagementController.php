<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesSearchQueries;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\Staff\UserSite\StaffUpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff manages a professional's customers (view, update, archive, restore, hard delete).
class StaffCustomerManagementController extends ApiController
{
    use HandlesSearchQueries;
    use NormalizesPerPage;
    use ReturnsPaginatedResponse;

    /**
     * GET /api/staff/professionals/{professional}/customers?q=...&per_page=...&page=...
     */
    public function index(Request $request, User $professional): JsonResponse
    {
        // #SEC-5: staff-dashboard read surface — any staff role, staffView is
        // the audit-trail seam (see routes/api/staff.php:143-145).
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);

        $perPage = $this->normalizePerPage($request, (int) config('partna.staff.pagination.per_page', 25), (int) config('partna.staff.pagination.per_page_max', 100));
        $searchLike = $this->prepareSearchLike($request, 'q')
            ?? $this->prepareSearchLike($request, 'search');

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');

        $query = Customer::query()
            ->where('user_id', $professional->id)
            ->orderByDesc('created_at');

        if ($onlyArchived) {
            $query->onlyTrashed();
        } elseif ($includeArchived) {
            $query->withTrashed();
        }

        if ($searchLike) {
            $query->where(function ($qq) use ($searchLike) {
                $qq->where('full_name', 'ilike', $searchLike)
                    ->orWhere('email', 'ilike', $searchLike)
                    ->orWhere('phone', 'ilike', $searchLike);
            });
        }

        $page = $query->paginate($perPage)->appends($request->query());

        $payload = $this->paginatedResponse($page, 'customers', [
            'filters' => [
                'include_archived' => $includeArchived,
                'only_archived' => $onlyArchived,
            ],
        ]);
        $payload['customers'] = CustomerResource::collection($page->items())->resolve();

        return $this->success($payload);
    }

    /**
     * GET /api/staff/professionals/{professional}/customers/{id}
     */
    public function show(Request $request, User $professional, Customer $customer): JsonResponse
    {
        // #SEC-2: gate the STAFF ACTOR (staffView, any role), not the professional.
        // Route group already scopes via ->scopeBindings() and User::customers(),
        // so this survives a future refactor that drops scopeBindings.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);

        $includeArchived = $request->boolean('include_archived');

        if (! $includeArchived && $customer->trashed()) {
            abort(404);
        }

        return $this->success(['customer' => new CustomerResource($customer)]);
    }

    /**
     * PATCH /api/staff/professionals/{professional}/customers/{id}
     */
    public function update(StaffUpdateCustomerRequest $request, User $professional, Customer $customer): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor, matching the
        // staff.admin route middleware this action already sits behind.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        if ($customer->trashed()) {
            abort(404);
        }

        $customer->fill($request->validated());
        $customer->save();

        return $this->success(['customer' => new CustomerResource($customer->fresh())]);
    }

    /**
     * DELETE /api/staff/professionals/{professional}/customers/{id}
     */
    public function destroy(Request $request, User $professional, Customer $customer): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — this action lives in the staff.admin
        // route group already; the policy call is defence-in-depth.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        if (! $customer->trashed()) {
            $customer->delete();
        }

        return $this->success(['archived' => true]);
    }

    public function restore(Request $request, User $professional, Customer $customer): JsonResponse
    {
        // #SEC-2: staffManage (admin-only). Unlike update/destroy, restore()
        // lives in the non-admin route group — the policy is the actual
        // enforcement point here, mirroring UserSelfPolicy's own
        // destroy/restore precedent for the User model (StaffUserController).
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        if ($customer->trashed()) {
            $customer->restore();
        }

        return $this->success(['restored' => true, 'customer' => new CustomerResource($customer->fresh())]);
    }

    public function forceDestroy(Request $request, User $professional, Customer $customer): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — this action lives in the staff.admin
        // route group already; the policy call is defence-in-depth.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $customer->forceDelete();

        return $this->success(['deleted' => true]);
    }
}
