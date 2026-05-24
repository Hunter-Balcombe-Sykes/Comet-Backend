<?php

namespace App\Http\Controllers\Api\Professional\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Api\Professional\Services\ReorderServiceLayoutRequest;
use App\Http\Requests\Api\Professional\Services\ReorderServiceRequest;
use App\Http\Requests\Api\Professional\Services\StoreServiceRequest;
use App\Http\Requests\Api\Professional\Services\UpdateServiceRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Http\Resources\ServiceResource;
use App\Models\Core\Professional\Service;
use App\Models\Core\Professional\ServiceCategory;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Service CRUD + reorder for professional's mini-site.
class ProfessionalServiceController extends ApiController
{
    use ResolveCurrentProfessional;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');
        $grouped = $request->boolean('grouped');

        // Hot path: dashboard's default services list (no archive toggle, no
        // grouping). Served from ProfessionalCacheService — 30-min TTL,
        // single-flight, busted by ServiceObserver on any write.
        // Filtered or grouped views fall through to the raw query because
        // their cache keys would either explode in cardinality or duplicate
        // the categories join logic.
        if (! $includeArchived && ! $onlyArchived && ! $grouped) {
            return $this->success([
                'services' => app(ProfessionalCacheService::class)->getDashboardServices($pro->id),
                'filters' => [
                    'include_archived' => false,
                    'only_archived' => false,
                ],
            ]);
        }

        $servicesQuery = Service::query()
            ->where('professional_id', $pro->id);

        if ($onlyArchived) {
            $servicesQuery->onlyTrashed();
        } elseif ($includeArchived) {
            $servicesQuery->withTrashed();
        }

        $services = $servicesQuery->orderBy('sort_order')->orderBy('created_at')->get();

        if (! $grouped) {
            return $this->success([
                'services' => ServiceResource::collection($services),
                'filters' => [
                    'include_archived' => $includeArchived,
                    'only_archived' => $onlyArchived,
                ],
            ]);
        }

        // Categories list (for grouped UI)
        $catQuery = ServiceCategory::query()
            ->where('professional_id', $pro->id);

        if ($onlyArchived) {
            $catQuery->onlyTrashed();
        } elseif ($includeArchived) {
            $catQuery->withTrashed();
        }

        $categories = $catQuery->orderBy('sort_order')->orderBy('created_at')->get();

        $servicesByCategory = $services->groupBy(fn (Service $s) => $s->category_id ?? '__uncategorised__');

        // Grouped payload: each category exposes the ServiceCategoryResource shape
        // plus a nested `services` array of ServiceResource items. Hand-rolled
        // arrays previously leaked raw model fields (audit P1-05).
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

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $this->authorizeForUser($pro, 'create', new Service(['professional_id' => $pro->id]));
        $data = $request->validated();

        $this->assertCategoryBelongsToProfessional($pro->id, $data['category_id'] ?? null);

        try {
            $service = DB::transaction(function () use ($pro, $data) {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["services:{$pro->id}"]);

                if (! array_key_exists('sort_order', $data) || $data['sort_order'] === null) {
                    // The unique constraint
                    //   services_professional_sort_order_uq
                    //   ON (professional_id, sort_order) WHERE deleted_at IS NULL
                    // is global per professional — it does NOT include
                    // category_id. So the max-lookup must consider EVERY live
                    // service for this professional regardless of category,
                    // otherwise a new service in a different category (or with
                    // null category) would compute sort_order=0 and collide
                    // with an existing live row at sort_order=0.
                    $max = Service::query()
                        ->where('professional_id', $pro->id)
                        ->whereNull('deleted_at')
                        ->max('sort_order');

                    $data['sort_order'] = is_null($max) ? 0 : ((int) $max + 1);
                }

                $service = Service::query()->create([
                    'professional_id' => $pro->id,
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
        } catch (\Throwable $e) {
            // Log the actual cause so the user sees the real error in server
            // logs instead of the generic "An error occurred" wrapper from
            // bootstrap/app.php. Re-throws so the wrapper still returns 500.
            \Illuminate\Support\Facades\Log::error('Service store failed', [
                'professional_id' => $pro->id,
                'payload' => $data,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        return $this->success(['service' => new ServiceResource($service)], 201);
    }

    public function show(Request $request, Service $service): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $this->authorizeForUser($pro, 'view', $service);

        return $this->success(['service' => new ServiceResource($service)]);
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $this->authorizeForUser($pro, 'update', $service);

        $data = $request->validated();

        if (array_key_exists('category_id', $data)) {
            $this->assertCategoryBelongsToProfessional($pro->id, $data['category_id']);
        }

        $service->fill($data);
        $service->save();

        return $this->success(['service' => new ServiceResource($service->fresh())]);
    }

    public function destroy(Request $request, Service $service): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $this->authorizeForUser($pro, 'delete', $service);

        $service->delete();

        return $this->success(['deleted' => true]);
    }

    // Old flat reorder (kept for compatibility)
    public function reorder(ReorderServiceRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $ids = array_values(array_unique($request->validated()['ids']));

        DB::transaction(function () use ($pro, $ids) {

            $allIds = Service::query()
                ->where('professional_id', $pro->id)
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
                    ->where('professional_id', $pro->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return $this->success(['ok' => true]);
    }

    // NEW: full layout reorder (categories + services within each category)
    public function reorderLayout(ReorderServiceLayoutRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $payload = $request->validated();

        DB::transaction(function () use ($pro, $payload) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-layout:{$pro->id}"]);

            $activeCategoryIds = ServiceCategory::query()
                ->where('professional_id', $pro->id)
                ->pluck('id')
                ->all();
            $activeCategorySet = array_flip($activeCategoryIds);

            $activeServiceIds = Service::query()
                ->where('professional_id', $pro->id)
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
            $providedServiceIds = array_values($providedServiceIds);
            if (count($providedServiceIds) !== count(array_unique($providedServiceIds))) {
                abort(422, 'Duplicate service IDs detected in layout payload.');
            }
            if (count($providedServiceIds) !== count($activeServiceIds)) {
                abort(422, 'Layout payload must include all service IDs for this professional.');
            }

            // Ensure all categories are included (excluding uncategorised null bucket)
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
                        ->where('professional_id', $pro->id)
                        ->where('id', $catId)
                        ->update(['sort_order' => $categorySort++]);
                }

                foreach ($catBlock['service_ids'] as $i => $serviceId) {
                    Service::query()
                        ->where('professional_id', $pro->id)
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

    public function restore(Request $request, Service $service): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $this->authorizeForUser($pro, 'update', $service);

        if (! $service->trashed()) {
            return $this->success(['restored' => true, 'service' => new ServiceResource($service->fresh())]);
        }

        DB::transaction(function () use ($pro, $service) {
            // Compute the next sort_order BEFORE restoring. The partial unique
            // index (professional_id, sort_order) WHERE deleted_at IS NULL is
            // global per professional — category_id is not part of it. Another
            // service may have claimed this slot while this one was soft-deleted,
            // so restore() would violate the constraint if called first.
            $max = Service::query()
                ->where('professional_id', $pro->id)
                ->whereNull('deleted_at')
                ->max('sort_order');

            $service->sort_order = is_null($max) ? 0 : ((int) $max + 1);
            $service->saveQuietly(); // update sort_order while still soft-deleted

            $service->restore();
        });

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
