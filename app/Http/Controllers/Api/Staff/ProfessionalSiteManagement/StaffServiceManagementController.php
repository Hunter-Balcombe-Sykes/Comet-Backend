<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\ProfessionalSite\Services\StaffReorderServiceLayoutRequest;
use App\Http\Requests\Api\Staff\ProfessionalSite\Services\StaffReorderServiceRequest;
use App\Http\Requests\Api\Staff\ProfessionalSite\Services\StaffStoreServiceRequest;
use App\Http\Requests\Api\Staff\ProfessionalSite\Services\StaffUpdateServiceRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Http\Resources\ServiceResource;
use App\Models\Core\Professional\User;
use App\Models\Core\Professional\Service;
use App\Models\Core\Professional\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Staff manages services with CRUD, complex reordering, and hard delete capability.
class StaffServiceManagementController extends ApiController
{
    public function index(Request $request, User $professional): JsonResponse
    {
        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');
        $grouped = $request->boolean('grouped');

        $servicesQ = Service::query()
            ->where('professional_id', $professional->id);

        if ($onlyArchived) {
            $servicesQ->onlyTrashed();
        } elseif ($includeArchived) {
            $servicesQ->withTrashed();
        }

        $services = $servicesQ
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        if (! $grouped) {
            return $this->success([
                'services' => ServiceResource::collection($services),
                'filters' => [
                    'include_archived' => $includeArchived,
                    'only_archived' => $onlyArchived,
                    'grouped' => false,
                ],
            ]);
        }

        $catsQ = ServiceCategory::query()
            ->where('professional_id', $professional->id);

        if ($onlyArchived) {
            $catsQ->onlyTrashed();
        } elseif ($includeArchived) {
            $catsQ->withTrashed();
        }

        $categories = $catsQ->orderBy('sort_order')->orderBy('created_at')->get();

        $servicesByCategory = $services->groupBy(fn (Service $s) => $s->category_id ?? '__uncategorised__');

        $categoryPayload = $categories->map(function (ServiceCategory $c) use ($servicesByCategory) {
            return array_merge(
                (new ServiceCategoryResource($c))->resolve(),
                ['services' => ServiceResource::collection($servicesByCategory->get($c->id, collect())->values())->resolve()],
            );
        })->values();

        return $this->success([
            'categories' => $categoryPayload,
            'uncategorised_services' => ServiceResource::collection($servicesByCategory->get('__uncategorised__', collect())->values()),
            'filters' => [
                'include_archived' => $includeArchived,
                'only_archived' => $onlyArchived,
                'grouped' => true,
            ],
        ]);
    }

    public function store(StaffStoreServiceRequest $request, User $professional): JsonResponse
    {
        $this->authorizeForUser($professional, 'create', new Service(['professional_id' => $professional->id]));
        $data = $request->validated();

        $this->assertCategoryBelongsToProfessional($professional->id, $data['category_id'] ?? null);

        $service = DB::transaction(function () use ($professional, $data) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["services:{$professional->id}"]);

            if (! array_key_exists('sort_order', $data) || $data['sort_order'] === null) {
                $max = Service::query()
                    ->where('professional_id', $professional->id)
                    ->where('category_id', $data['category_id'] ?? null)
                    ->max('sort_order');

                $data['sort_order'] = is_null($max) ? 0 : ((int) $max + 1);
            }

            $service = Service::query()->create([
                'professional_id' => $professional->id,
                'category_id' => $data['category_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'price_cents' => $data['price_cents'],
                'currency_code' => $data['currency_code'] ?? 'AUD',
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $data['sort_order'],
            ]);

            return $service->fresh();
        });

        return $this->success(['service' => new ServiceResource($service)], 201);
    }

    public function show(Request $request, User $professional, Service $service): JsonResponse
    {
        $this->authorizeForUser($professional, 'view', $service);

        $includeArchived = $request->boolean('include_archived');
        if (! $includeArchived && $service->trashed()) {
            abort(404);
        }

        return $this->success(['service' => new ServiceResource($service)]);
    }

    public function update(StaffUpdateServiceRequest $request, User $professional, Service $service): JsonResponse
    {
        $this->authorizeForUser($professional, 'update', $service);
        if ($service->trashed()) {
            abort(404);
        }

        $data = $request->validated();

        if (array_key_exists('category_id', $data)) {
            $this->assertCategoryBelongsToProfessional($professional->id, $data['category_id']);

            // If moving categories and no explicit sort_order, place at end of new bucket
            if (($data['category_id'] ?? null) !== $service->category_id && ! array_key_exists('sort_order', $data)) {
                $max = Service::query()
                    ->where('professional_id', $professional->id)
                    ->where('category_id', $data['category_id'] ?? null)
                    ->max('sort_order');

                $data['sort_order'] = is_null($max) ? 0 : ((int) $max + 1);
            }
        }

        $service->fill($data);
        $service->save();

        return $this->success(['service' => new ServiceResource($service->fresh())]);
    }

    public function destroy(User $professional, Service $service): JsonResponse
    {
        $this->authorizeForUser($professional, 'delete', $service);
        if ($service->trashed()) {
            abort(404);
        }

        $service->delete();

        return $this->success(['deleted' => true]);
    }

    public function reorder(StaffReorderServiceRequest $request, User $professional): JsonResponse
    {
        $ids = array_values(array_unique($request->validated()['ids'] ?? []));

        DB::transaction(function () use ($professional, $ids) {

            $allIds = Service::query()
                ->where('professional_id', $professional->id)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);

            foreach ($ids as $id) {
                if (! isset($allSet[$id])) {
                    abort(422, 'One or more service IDs are invalid.');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder = array_merge($ids, $remaining);

            foreach ($newOrder as $i => $id) {
                Service::query()
                    ->where('professional_id', $professional->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return $this->success(['ok' => true]);
    }

    // NEW: full layout reorder (categories + services)
    public function reorderLayout(StaffReorderServiceLayoutRequest $request, User $professional): JsonResponse
    {
        $payload = $request->validated();

        DB::transaction(function () use ($professional, $payload) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-layout:{$professional->id}"]);

            $activeCategoryIds = ServiceCategory::query()
                ->where('professional_id', $professional->id)
                ->pluck('id')
                ->all();
            $activeCategorySet = array_flip($activeCategoryIds);

            $activeServiceIds = Service::query()
                ->where('professional_id', $professional->id)
                ->pluck('id')
                ->all();
            $activeServiceSet = array_flip($activeServiceIds);

            $providedCategoryIds = [];
            $providedServiceIds = [];

            foreach ($payload['categories'] as $catBlock) {
                $catId = $catBlock['id'] ?? null;

                if ($catId !== null) {
                    if (! isset($activeCategorySet[$catId])) {
                        abort(422, 'One or more category IDs are invalid.');
                    }
                    $providedCategoryIds[] = $catId;
                }

                foreach ($catBlock['service_ids'] as $sid) {
                    if (! isset($activeServiceSet[$sid])) {
                        abort(422, 'One or more service IDs are invalid.');
                    }
                    $providedServiceIds[] = $sid;
                }
            }

            // Ensure every service appears exactly once
            if (count($providedServiceIds) !== count(array_unique($providedServiceIds))) {
                abort(422, 'Duplicate service IDs detected in layout payload.');
            }
            if (count($providedServiceIds) !== count($activeServiceIds)) {
                abort(422, 'Layout payload must include all service IDs for this professional.');
            }

            // Ensure all categories included (excluding null bucket)
            $providedCategoryIds = array_values(array_unique($providedCategoryIds));
            sort($providedCategoryIds);
            $sortedActive = $activeCategoryIds;
            sort($sortedActive);

            if ($providedCategoryIds !== $sortedActive) {
                abort(422, 'Layout payload must include all category IDs (use one block with id=null for uncategorised).');
            }

            // Apply category order + service order
            $categorySort = 0;
            foreach ($payload['categories'] as $catBlock) {
                $catId = $catBlock['id'] ?? null;

                if ($catId !== null) {
                    ServiceCategory::query()
                        ->where('professional_id', $professional->id)
                        ->where('id', $catId)
                        ->update(['sort_order' => $categorySort++]);
                }

                foreach ($catBlock['service_ids'] as $i => $serviceId) {
                    Service::query()
                        ->where('professional_id', $professional->id)
                        ->where('id', $serviceId)
                        ->update([
                            'category_id' => $catId,
                            'sort_order' => $i,
                        ]);
                }
            }
        });

        return $this->success(['ok' => true]);
    }

    public function forceDestroy(User $professional, Service $service): JsonResponse
    {
        $this->authorizeForUser($professional, 'delete', $service);

        $service->forceDelete();

        return $this->success(['deleted' => true, 'hard' => true]);
    }

    public function restore(User $professional, Service $service): JsonResponse
    {
        $this->authorizeForUser($professional, 'update', $service);

        if ($service->trashed()) {
            $service->restore();
        }

        return $this->success(['restored' => true, 'service' => new ServiceResource($service->fresh())]);
    }

    private function assertCategoryBelongsToProfessional(string $professionalId, ?string $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        $ok = ServiceCategory::query()
            ->where('id', $categoryId)
            ->where('professional_id', $professionalId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $ok) {
            abort(422, 'Category is invalid.');
        }
    }
}
