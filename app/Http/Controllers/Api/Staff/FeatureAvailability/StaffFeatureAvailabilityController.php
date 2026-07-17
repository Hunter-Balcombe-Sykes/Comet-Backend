<?php

namespace App\Http\Controllers\Api\Staff\FeatureAvailability;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\FeatureAvailability\UpsertFeatureAvailabilityRequest;
use App\Http\Resources\Staff\FeatureAvailabilityRuleResource;
use App\Jobs\Platforms\ReconcilePlatformTakedownJob;
use App\Models\Core\FeatureAvailabilityRule;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// OV-A: staff CRUD for feature/integration availability rules. PUT is an
// upsert on (feature_key, segment_id) so the dashboard's toggle is one call.
// Every write flushes the read-side cache.
class StaffFeatureAvailabilityController extends ApiController
{
    /** GET /staff/feature-availability — all rules, global rows first. */
    public function index(Request $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', FeatureAvailabilityRule::class);

        $rules = FeatureAvailabilityRule::query()
            ->with('segment:id,name')
            ->orderBy('feature_key')
            ->orderByRaw('segment_id NULLS FIRST')
            ->get();

        return $this->success([
            'rules' => FeatureAvailabilityRuleResource::collection($rules)->resolve(),
        ]);
    }

    /** PUT /staff/feature-availability — upsert one rule. */
    public function upsert(UpsertFeatureAvailabilityRequest $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', FeatureAvailabilityRule::class);

        $data = $request->validated();

        $rule = FeatureAvailabilityRule::query()->updateOrCreate(
            [
                'feature_key' => $data['feature_key'],
                'segment_id' => $data['segment_id'] ?? null,
            ],
            [
                'mode' => $data['mode'],
                'created_by' => $staff?->id,
            ],
        );

        FeatureAvailability::flush();

        // OV-A: a newly-disabled integration takes existing content down (global or
        // segment scope). Enable/other keys do nothing; re-enable never reactivates.
        if ($rule->mode === FeatureAvailabilityRule::MODE_DISABLED
            && ($platform = $rule->integrationPlatform()) !== null
            && app(PlatformRegistry::class)->has($platform)) {
            ReconcilePlatformTakedownJob::dispatch($platform, $rule->segment_id)->afterCommit();
        }

        $rule->load('segment:id,name');

        return $this->success(
            ['rule' => FeatureAvailabilityRuleResource::make($rule)->resolve()],
            $rule->wasRecentlyCreated ? 201 : 200,
        );
    }

    /** DELETE /staff/feature-availability/{rule} — back to default (available). */
    public function destroy(Request $request, FeatureAvailabilityRule $rule): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $rule);

        $rule->delete();
        FeatureAvailability::flush();

        return response()->json(null, 204);
    }
}
