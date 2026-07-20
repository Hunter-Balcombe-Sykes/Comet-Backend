<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Services\ReorderServiceLayoutRequest;
use App\Http\Requests\Api\User\Services\ReorderServiceRequest;
use App\Http\Requests\Api\User\Services\StoreServiceRequest;
use App\Http\Requests\Api\User\Services\UpdateServiceRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Http\Resources\ServiceResource;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Services\Cache\UserCacheService;
use App\Services\Site\InsertWithSortOrder;
use App\Services\Site\ReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Service CRUD + reorder for professional's mini-site.
class UserServiceController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');
        $grouped = $request->boolean('grouped');

        // Hot path: dashboard's default services list (no archive toggle, no
        // grouping). Served from UserCacheService — 30-min TTL,
        // single-flight, busted by ServiceObserver on any write.
        // Filtered or grouped views fall through to the raw query because
        // their cache keys would either explode in cardinality or duplicate
        // the categories join logic.
        if (! $includeArchived && ! $onlyArchived && ! $grouped) {
            return $this->success([
                'services' => app(UserCacheService::class)->getDashboardServices($pro->id),
                'filters' => [
                    'include_archived' => false,
                    'only_archived' => false,
                ],
            ]);
        }

        $servicesQuery = Service::query()
            ->where('user_id', $pro->id);

        if ($onlyArchived) {
            $servicesQuery->onlyTrashed();
        } elseif ($includeArchived) {
            $servicesQuery->withTrashed();
        }

        // Bound the query at scale (B18/API-4). True pagination is a frontend-coordinated change, deferred.
        $services = $servicesQuery->orderBy('sort_order')->orderBy('created_at')->limit(500)->get();

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
            ->where('user_id', $pro->id);

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
        $pro = $this->currentUser($request);
        $this->authorizeForUser($pro, 'create', new Service(['user_id' => $pro->id]));
        $data = $request->validated();

        $this->assertCategoryBelongsToProfessional($pro->id, $data['category_id'] ?? null);

        try {
            // The unique constraint services_professional_sort_order_uq ON
            // (user_id, sort_order) WHERE deleted_at IS NULL is global per
            // professional — the max-lookup considers EVERY live service
            // regardless of category so a new service never collides with an
            // existing row at sort_order=0.
            $service = InsertWithSortOrder::run(
                Service::query()
                    ->where('user_id', $pro->id)
                    ->whereNull('deleted_at'),
                "services:{$pro->id}",
                function (int $next) use ($pro, $data) {
                    $service = Service::query()->create([
                        'user_id' => $pro->id,
                        'category_id' => $data['category_id'] ?? null,
                        'title' => $data['title'],
                        'description' => $data['description'] ?? null,
                        'price_cents' => $data['price_cents'],
                        'currency_code' => $data['currency_code'] ?? 'AUD',
                        'duration_minutes' => $data['duration_minutes'] ?? null,
                        'is_active' => $data['is_active'] ?? true,
                        'sort_order' => $data['sort_order'] ?? $next,
                    ]);

                    return $service->fresh();
                },
            );
        } catch (\Throwable $e) {
            // Log the actual cause so the user sees the real error in server
            // logs instead of the generic "An error occurred" wrapper from
            // bootstrap/app.php. Re-throws so the wrapper still returns 500.
            Log::error('Service store failed', [
                'user_id' => $pro->id,
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
        $pro = $this->currentUser($request);

        $this->authorizeForUser($pro, 'view', $service);

        return $this->success(['service' => new ServiceResource($service)]);
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $pro = $this->currentUser($request);

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
        $pro = $this->currentUser($request);

        $this->authorizeForUser($pro, 'delete', $service);

        $service->delete();

        return $this->success(['deleted' => true]);
    }

    // Old flat reorder (kept for compatibility)
    public function reorder(ReorderServiceRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);

        // EDGE-1 (audit): mass `update()` inside ReorderService bypasses Eloquent
        // events, so ServiceObserver never touches the site. Touch explicitly in
        // afterCommit to fire SiteObserver — Redis invalidation + Cloudflare edge
        // purge + cache warm (mirrors UserGalleryController::reorder).
        app(ReorderService::class)->reorder(
            $request->input('ids', []),
            Service::query()->where('user_id', $pro->id),
            "services:{$pro->id}",
            fn () => $site->touch(),
        );

        return $this->success(['ok' => true]);
    }

    // NEW: full layout reorder (categories + services within each category)
    public function reorderLayout(ReorderServiceLayoutRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);
        $payload = $request->validated();

        DB::transaction(function () use ($pro, $payload) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-layout:{$pro->id}"]);

            $activeCategoryIds = ServiceCategory::query()
                ->where('user_id', $pro->id)
                ->pluck('id')
                ->all();
            $activeCategorySet = array_flip($activeCategoryIds);

            $activeServiceIds = Service::query()
                ->where('user_id', $pro->id)
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

            // Apply category order + service order. Services get a GLOBAL running
            // sort_order across every bucket, not one that restarts at each category —
            // services_user_sort_order_uq is a partial UNIQUE index on (user_id,
            // sort_order), scoped to the whole professional, not per category, so two
            // services in two different buckets both landing on 0 collides (23505).
            // The display view already orders by category sort_order then service
            // sort_order, so a monotonic global counter preserves each category's
            // internal order while satisfying the index.
            //
            // Applied in two passes: the unique index is checked on every individual
            // UPDATE (not deferred), so a single pass can transiently collide with a
            // row that hasn't been updated yet but still occupies the target value
            // (e.g. a straight swap between two services). Pass 1 parks every
            // reordered service at a temporary value far above any real sort_order,
            // unique among themselves; pass 2 writes the real 0..N-1 values once every
            // row involved has vacated that range. service_categories carries no
            // equivalent unique index (only a plain sort index + a unique-title
            // index), so its write stays single-pass.
            $categorySort = 0;
            $serviceUpdates = [];
            $globalServiceIndex = 0;

            foreach ($payload['categories'] as $catBlock) {
                $catId = $catBlock['id'] ?? null;

                if ($catId !== null) {
                    ServiceCategory::query()
                        ->where('user_id', $pro->id)
                        ->where('id', $catId)
                        ->update(['sort_order' => $categorySort++]);
                }

                foreach ($catBlock['service_ids'] as $serviceId) {
                    $serviceUpdates[] = [
                        'serviceId' => $serviceId,
                        'categoryId' => $catId,
                        'sortOrder' => $globalServiceIndex++,
                    ];
                }
            }

            foreach ($serviceUpdates as $update) {
                Service::query()
                    ->where('user_id', $pro->id)
                    ->where('id', $update['serviceId'])
                    ->update(['sort_order' => 1_000_000 + $update['sortOrder']]);
            }

            foreach ($serviceUpdates as $update) {
                Service::query()
                    ->where('user_id', $pro->id)
                    ->where('id', $update['serviceId'])
                    ->update([
                        'category_id' => $update['categoryId'],
                        'sort_order' => $update['sortOrder'],
                    ]);
            }
        });

        // EDGE-1 (audit): reorderLayout bypasses ReorderService entirely (raw
        // DB::transaction + per-row query-builder update()), so no observer ever
        // fires here either — touch explicitly after commit, same reasoning as
        // reorder() above.
        $site->touch();

        return $this->success(['ok' => true]);
    }

    public function restore(Request $request, Service $service): JsonResponse
    {
        $pro = $this->currentUser($request);

        $this->authorizeForUser($pro, 'update', $service);

        if (! $service->trashed()) {
            return $this->success(['restored' => true, 'service' => new ServiceResource($service->fresh())]);
        }

        DB::transaction(function () use ($pro, $service) {
            // Compute the next sort_order BEFORE restoring. The partial unique
            // index (user_id, sort_order) WHERE deleted_at IS NULL is
            // global per professional — category_id is not part of it. Another
            // service may have claimed this slot while this one was soft-deleted,
            // so restore() would violate the constraint if called first.
            $max = Service::query()
                ->where('user_id', $pro->id)
                ->whereNull('deleted_at')
                ->max('sort_order');

            $service->sort_order = is_null($max) ? 0 : ((int) $max + 1);
            $service->saveQuietly(); // update sort_order while still soft-deleted

            $service->restore();
        });

        return $this->success(['restored' => true, 'service' => new ServiceResource($service->fresh())]);
    }

    private function assertCategoryBelongsToProfessional(string $userId, ?string $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        $ok = ServiceCategory::query()
            ->where('id', $categoryId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $ok) {
            abort(422, 'Category is invalid.');
        }
    }
}
