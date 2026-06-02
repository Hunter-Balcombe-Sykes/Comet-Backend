<?php

namespace App\Http\Controllers\Api\Staff\FeatureFlag;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\Staff\FeatureFlag\CreateFeatureFlagRequest;
use App\Http\Requests\Api\Staff\FeatureFlag\UpdateFeatureFlagRequest;
use App\Http\Resources\FeatureFlagResource;
use App\Models\Core\FeatureFlag;
use App\Services\FeatureFlags\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff admin CRUD for feature flags. Flush the registry after every write so
// the in-process cache reflects changes immediately.
class StaffFeatureFlagController extends ApiController
{
    use ReturnsPaginatedResponse;

    public function __construct(private FeatureFlagService $service) {}

    /** GET /staff/feature-flags — list all flags with override counts. */
    public function index(Request $request): JsonResponse
    {
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');

        $flags = FeatureFlag::withCount('overrides')->orderBy('key')->paginate(50);
        $flags->through(fn (FeatureFlag $flag) => FeatureFlagResource::make($flag)->resolve());

        return $this->success($this->paginatedResponse($flags, 'flags'));
    }

    /** POST /staff/feature-flags — create a new flag. */
    public function store(CreateFeatureFlagRequest $request): JsonResponse
    {
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');

        $flag = FeatureFlag::create($request->validated());
        $this->service->flushRegistry();
        $flag->loadCount('overrides');

        return (new FeatureFlagResource($flag))->response()->setStatusCode(201);
    }

    /** PATCH /staff/feature-flags/{key} — update default_enabled or description. */
    public function update(UpdateFeatureFlagRequest $request, string $key): JsonResponse
    {
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');

        $flag = FeatureFlag::findOrFail($key);
        $flag->update($request->validated());
        $this->service->flushRegistry();
        $flag->loadCount('overrides');

        return (new FeatureFlagResource($flag))->response();
    }

    /** DELETE /staff/feature-flags/{key} — remove a flag and all its overrides. */
    public function destroy(Request $request, string $key): JsonResponse
    {
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');

        $flag = FeatureFlag::findOrFail($key);
        $flag->delete();
        $this->service->flushRegistry();

        return response()->json(null, 204);
    }
}
