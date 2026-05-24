<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesSearchQueries;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\Staff\ProfessionalSite\StaffUpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\User;
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
        $perPage = $this->normalizePerPage($request, 25, 100);
        $searchLike = $this->prepareSearchLike($request, 'q')
            ?? $this->prepareSearchLike($request, 'search');

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');

        $query = Customer::query()
            ->where('professional_id', $professional->id)
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
        // Defence in depth: route group already scopes via ->scopeBindings() and
        // User::customers(). The explicit check survives a future refactor
        // that drops scopeBindings, and matches the pattern used by sibling staff
        // controllers (StaffServiceManagementController etc).
        $this->authorizeForUser($professional, 'view', $customer);

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
        $this->authorizeForUser($professional, 'update', $customer);

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
    public function destroy(User $professional, Customer $customer): JsonResponse
    {
        $this->authorizeForUser($professional, 'delete', $customer);

        if (! $customer->trashed()) {
            $customer->delete();
        }

        return $this->success(['archived' => true]);
    }

    public function restore(User $professional, Customer $customer): JsonResponse
    {
        $this->authorizeForUser($professional, 'update', $customer);

        if ($customer->trashed()) {
            $customer->restore();
        }

        return $this->success(['restored' => true, 'customer' => new CustomerResource($customer->fresh())]);
    }

    public function forceDestroy(User $professional, Customer $customer): JsonResponse
    {
        $this->authorizeForUser($professional, 'delete', $customer);

        $customer->forceDelete();

        return $this->success(['deleted' => true]);
    }
}
