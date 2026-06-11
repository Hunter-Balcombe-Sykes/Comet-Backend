<?php

namespace App\Http\Controllers\Api\User\Customers;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesSearchQueries;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\User\Customer\StoreCustomerRequest;
use App\Http\Requests\Api\User\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Core\User\Customer;
use App\Services\User\ConfirmationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: CRUD for customer contacts. Supports lead capture from public sites and email subscriber management.
class UserCustomerController extends ApiController
{
    use HandlesSearchQueries;
    use NormalizesPerPage;
    use ResolveCurrentSite;
    use ResolveCurrentUser;
    use ReturnsPaginatedResponse;

    public function index(Request $request)
    {
        $pro = $this->currentUser($request);

        $perPage = $this->normalizePerPage($request, 25, 100);
        $searchLike = $this->prepareSearchLike($request, 'search');

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');
        $marketingOptIn = $request->query('marketing_opt_in');  // null, 'true', 'false'

        $query = Customer::query()
            ->where('user_id', $pro->id)
            ->orderByDesc('created_at');

        if ($onlyArchived) {
            $query->onlyTrashed();
        } elseif ($includeArchived) {
            $query->withTrashed();
        }

        // Filter by marketing opt-in status (uses cached field for performance)
        if ($marketingOptIn !== null) {
            $isOptedIn = filter_var($marketingOptIn, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isOptedIn !== null) {
                $query->where('marketing_opt_in_cached', $isOptedIn);
            }
        }

        if ($searchLike) {
            // Postgres: like for case-insensitive search
            $query->where(function ($q) use ($searchLike) {
                $q->where('full_name', 'ilike', $searchLike)
                    ->orWhere('email', 'ilike', $searchLike)
                    ->orWhere('phone', 'ilike', $searchLike);
            });
        }

        $paginator = $query->paginate($perPage)->appends($request->query());

        $payload = $this->paginatedResponse($paginator, 'customers', [
            'filters' => [
                'include_archived' => $includeArchived,
                'only_archived' => $onlyArchived,
                'marketing_opt_in' => $marketingOptIn,
            ],
        ]);
        // P1-05: wrap items in the explicit CustomerResource allowlist instead
        // of shipping raw Eloquent rows (would auto-leak future / hidden columns).
        $payload['customers'] = CustomerResource::collection($paginator->items())->resolve();
        // P1-06: dual-key `meta` + `pagination` for one release cycle. Staff
        // mirror already uses `meta`; this brings professional in line while
        // keeping current frontend reads working.
        // TODO(B4): drop `pagination` key once frontend confirms it reads `meta`.
        $payload['pagination'] = $payload['meta'];

        return $this->success($payload);
    }

    public function store(StoreCustomerRequest $request)
    {
        $pro = $this->currentUser($request);

        $skeleton = new Customer(['user_id' => $pro->id]);
        $this->authorizeForUser($pro, 'create', $skeleton);

        $data = $request->validated();
        $data['source'] = $data['source'] ?? 'manual';

        // Check if customer with this email already exists (excluding soft-deleted)
        $customer = $pro->customers()
            ->where('email', $data['email'])
            ->first();

        if ($customer) {
            // Update existing customer with new data
            $customer->update([
                'full_name' => $data['full_name'],
                'phone' => $data['phone'] ?? $customer->phone,
                'notes' => $data['notes'] ?? $customer->notes,
                'source' => $data['source'],
                'marketing_opt_in_cached' => $data['marketing_opt_in_cached'] ?? $customer->marketing_opt_in_cached,
            ]);
        } else {
            // Create new customer
            $customer = $pro->customers()->create($data);
        }

        return $this->success(['customer' => new CustomerResource($customer)], 201);
    }

    public function show(Request $request, Customer $customer)
    {
        $pro = $this->currentUser($request);
        $this->authorizeForUser($pro, 'view', $customer);

        $includeArchived = $request->boolean('include_archived');
        if (! $includeArchived && method_exists($customer, 'trashed') && $customer->trashed()) {
            abort(404);
        }

        return $this->success(['customer' => new CustomerResource($customer)]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $pro = $this->currentUser($request);

        $this->authorizeForUser($pro, 'update', $customer);
        if (method_exists($customer, 'trashed') && $customer->trashed()) {
            abort(404);
        }

        $customer->fill($request->validated());
        $customer->save();

        return $this->success(['customer' => new CustomerResource($customer->fresh())]);
    }

    // Archive Soft Delete
    public function destroy(Request $request, Customer $customer)
    {
        $pro = $this->currentUser($request);
        $this->authorizeForUser($pro, 'delete', $customer);

        if (! $customer->trashed()) {
            $customer->delete(); // soft delete (archive)
        }

        if ($this->shouldRememberConfirmationPreference($request)) {
            app(ConfirmationPreferenceService::class)->enableForProfessional(
                (string) $pro->id,
                ConfirmationPreferenceService::ACTION_DELETE_CUSTOMER
            );
        }

        return $this->success(['archived' => true]);
    }

    // Restore (un-archive)
    public function restore(Request $request, Customer $customer): JsonResponse
    {
        $pro = $this->currentUser($request);
        $this->authorizeForUser($pro, 'update', $customer);

        if (method_exists($customer, 'trashed') && $customer->trashed()) {
            $customer->restore();
        }

        return $this->success(['restored' => true, 'customer' => new CustomerResource($customer->fresh())]);
    }

    private function shouldRememberConfirmationPreference(Request $request): bool
    {
        return $request->boolean('remember_confirmation_preference')
            || $request->boolean('always_allow_confirmation')
            || $request->boolean('dont_ask_again');
    }
}
