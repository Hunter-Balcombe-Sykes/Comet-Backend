<?php

namespace App\Http\Controllers\Api\Staff\FeatureFlag;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\FeatureFlag\CreateOverrideRequest;
use App\Http\Resources\FeatureFlagOverrideResource;
use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Services\FeatureFlags\FeatureFlagService;
use App\Services\FeatureFlags\OverrideScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

// Staff admin endpoints to set / clear per-professional or per-brand flag overrides.
class StaffFeatureFlagOverrideController extends ApiController
{
    public function __construct(private FeatureFlagService $service) {}

    /** GET /staff/feature-flags/{key}/overrides — list overrides for a flag (paginated, newest first). */
    public function index(Request $request, string $key): JsonResponse
    {
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');

        $flag = FeatureFlag::findOrFail($key);
        $overrides = $flag->overrides()->orderBy('created_at', 'desc')->paginate(50);

        return FeatureFlagOverrideResource::collection($overrides)->response();
    }

    /** POST /staff/feature-flags/{key}/overrides — upsert an override for a brand or professional. */
    public function store(CreateOverrideRequest $request, string $key): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        abort_if($staff === null, 401, 'Unauthenticated');

        $flag = FeatureFlag::findOrFail($key);
        $data = $request->validated();

        $scope = OverrideScope::forUser($data['user_id']);

        $cacheInvalidated = $this->service->setOverride(
            $key,
            $scope,
            (bool) $data['enabled'],
            $data['reason'] ?? null,
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            $staff->id,
        );

        // Fetch the upserted row to return in the response.
        $created = FeatureFlagOverride::where('flag_key', $key)
            ->where('user_id', $scope->userId)
            ->whereNull('brand_id')
            ->first();

        $response = (new FeatureFlagOverrideResource($created))->response()->setStatusCode(201);

        if (! $cacheInvalidated) {
            $response->header('X-Cache-Stale', '1');
        }

        return $response;
    }

    /** DELETE /staff/feature-flags/overrides/{id} — remove an override by its UUID. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');

        $override = FeatureFlagOverride::findOrFail($id);

        // Delete by PK (unambiguous), then push-invalidate the scope's cache key.
        $override->delete();

        $cacheInvalidated = true;

        try {
            $this->service->forgetPro($override->user_id);
        } catch (Throwable $e) {
            $cacheInvalidated = false;
        }

        $response = response()->json(null, 204);

        if (! $cacheInvalidated) {
            $response->header('X-Cache-Stale', '1');
        }

        return $response;
    }
}
