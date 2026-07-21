<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffReorderServiceLayoutRequest;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffReorderServiceRequest;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffStoreServiceRequest;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffUpdateServiceRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Http\Resources\ServiceResource;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use App\Services\Site\InsertWithSortOrder;
use App\Services\Site\ReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Staff manages services with CRUD, complex reordering, and hard delete capability.
class StaffServiceManagementController extends ApiController
{
    public function index(Request $request, User $professional): JsonResponse
    {
        // #SEC-5: staff-dashboard read surface — any staff role.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');
        $grouped = $request->boolean('grouped');

        $servicesQ = Service::query()
            ->where('user_id', $professional->id);

        if ($onlyArchived) {
            $servicesQ->onlyTrashed();
        } elseif ($includeArchived) {
            $servicesQ->withTrashed();
        }

        // mirrors user-facing cap (UserServiceController::index limit(500))
        $services = $servicesQ
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->limit(500)
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
            ->where('user_id', $professional->id);

        if ($onlyArchived) {
            $catsQ->onlyTrashed();
        } elseif ($includeArchived) {
            $catsQ->withTrashed();
        }

        // mirrors user-facing cap (UserServiceCategoryController::index limit(200))
        $categories = $catsQ->orderBy('sort_order')->orderBy('created_at')->limit(200)->get();

        // Multi-category: a service appears under EVERY category it belongs to;
        // zero memberships = Uncategorised (mirrors UserServiceController::index).
        $services->loadMissing('categories:id');
        $categoryIds = fn (Service $s) => $s->categories->map(fn ($c) => (string) $c->id)->all();

        $categoryPayload = $categories->map(function (ServiceCategory $c) use ($services, $categoryIds) {
            $members = $services->filter(fn (Service $s) => in_array((string) $c->id, $categoryIds($s), true))->values();

            return array_merge(
                (new ServiceCategoryResource($c))->resolve(),
                ['services' => ServiceResource::collection($members)->resolve()],
            );
        })->values();

        $uncategorised = $services->filter(fn (Service $s) => $categoryIds($s) === [])->values();

        return $this->success([
            'categories' => $categoryPayload,
            'uncategorised_services' => ServiceResource::collection($uncategorised),
            'filters' => [
                'include_archived' => $includeArchived,
                'only_archived' => $onlyArchived,
                'grouped' => true,
            ],
        ]);
    }

    public function store(StaffStoreServiceRequest $request, User $professional): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);
        $data = $request->validated();

        $this->assertCategoryBelongsToProfessional($professional->id, $data['category_id'] ?? null);

        $service = InsertWithSortOrder::run(
            Service::query()
                ->where('user_id', $professional->id)
                ->whereNull('deleted_at'),
            "services:{$professional->id}",
            function (int $next) use ($professional, $data) {
                $service = Service::query()->create([
                    'user_id' => $professional->id,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'price_cents' => $data['price_cents'],
                    'currency_code' => $data['currency_code'] ?? 'AUD',
                    'duration_minutes' => $data['duration_minutes'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                    'sort_order' => $data['sort_order'] ?? $next,
                ]);

                if (($data['category_id'] ?? null) !== null) {
                    $service->categories()->attach($data['category_id']);
                }

                return $service->fresh();
            },
        );

        return $this->success(['service' => new ServiceResource($service)], 201);
    }

    public function show(Request $request, User $professional, Service $service): JsonResponse
    {
        // #SEC-2: gate the STAFF ACTOR (staffView, any role), not the professional.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);

        $includeArchived = $request->boolean('include_archived');
        if (! $includeArchived && $service->trashed()) {
            abort(404);
        }

        return $this->success(['service' => new ServiceResource($service)]);
    }

    public function update(StaffUpdateServiceRequest $request, User $professional, Service $service): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);
        if ($service->trashed()) {
            abort(404);
        }

        $data = $request->validated();

        if (array_key_exists('category_id', $data)) {
            $this->assertCategoryBelongsToProfessional($professional->id, $data['category_id']);

            // Multi-category: the legacy single category_id REPLACES the
            // membership set ([] for null). Appends at global end when moving
            // without an explicit sort_order, mirroring the old per-bucket move.
            $target = $data['category_id'] !== null ? [(string) $data['category_id']] : [];
            $current = $service->categories()->pluck('site.service_categories.id')->map(fn ($id) => (string) $id)->all();
            if ($target != $current) {
                $service->categories()->sync($target);
                if (! array_key_exists('sort_order', $data)) {
                    $max = Service::query()
                        ->where('user_id', $professional->id)
                        ->whereNull('deleted_at')
                        ->max('sort_order');
                    $data['sort_order'] = is_null($max) ? 0 : ((int) $max + 1);
                }
            }
            unset($data['category_id']);
        }

        $service->fill($data);
        $service->save();

        return $this->success(['service' => new ServiceResource($service->fresh())]);
    }

    public function destroy(Request $request, User $professional, Service $service): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);
        if ($service->trashed()) {
            abort(404);
        }

        $service->delete();

        return $this->success(['deleted' => true]);
    }

    public function reorder(StaffReorderServiceRequest $request, User $professional): JsonResponse
    {
        // #SEC-2: previously had zero authorization at all.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        app(ReorderService::class)->reorder(
            $request->input('ids', []),
            Service::query()->where('user_id', $professional->id),
            "services:{$professional->id}",
        );

        return $this->success(['ok' => true]);
    }

    // NEW: full layout reorder (categories + services)
    public function reorderLayout(StaffReorderServiceLayoutRequest $request, User $professional): JsonResponse
    {
        // #SEC-2: previously had zero authorization at all.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $payload = $request->validated();

        DB::transaction(function () use ($professional, $payload) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-layout:{$professional->id}"]);

            $activeCategoryIds = ServiceCategory::query()
                ->where('user_id', $professional->id)
                ->pluck('id')
                ->all();
            $activeCategorySet = array_flip($activeCategoryIds);

            $activeServiceIds = Service::query()
                ->where('user_id', $professional->id)
                ->pluck('id')
                ->all();
            $activeServiceSet = array_flip($activeServiceIds);

            $providedCategoryIds = [];
            $seenPerBlock = [];
            $membershipsByService = [];
            $uncategorisedIds = [];

            foreach ($payload['categories'] as $bi => $catBlock) {
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
                    // Multi-category: one membership per category block; never
                    // twice in one block, never categorised AND uncategorised.
                    if (isset($seenPerBlock[$bi][$sid])) {
                        abort(422, 'Duplicate service IDs detected within a category block.');
                    }
                    $seenPerBlock[$bi][$sid] = true;

                    if ($catId === null) {
                        $uncategorisedIds[$sid] = true;
                    } else {
                        $membershipsByService[$sid][] = $catId;
                    }
                }
            }

            foreach ($uncategorisedIds as $sid => $_) {
                if (isset($membershipsByService[$sid])) {
                    abort(422, 'A service cannot be both categorised and uncategorised.');
                }
            }

            $coveredIds = array_unique([...array_keys($membershipsByService), ...array_keys($uncategorisedIds)]);
            if (count($coveredIds) !== count($activeServiceIds)) {
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

            // Apply category order + service order + memberships (mirrors
            // UserServiceController::reorderLayout — global first-occurrence
            // sort_order, two-pass park for the partial unique index, and the
            // payload defining the full membership map).
            $categorySort = 0;
            $orderedServiceIds = [];

            foreach ($payload['categories'] as $catBlock) {
                $catId = $catBlock['id'] ?? null;

                if ($catId !== null) {
                    ServiceCategory::query()
                        ->where('user_id', $professional->id)
                        ->where('id', $catId)
                        ->update(['sort_order' => $categorySort++]);
                }

                foreach ($catBlock['service_ids'] as $serviceId) {
                    if (! in_array($serviceId, $orderedServiceIds, true)) {
                        $orderedServiceIds[] = $serviceId;
                    }
                }
            }

            foreach ($orderedServiceIds as $i => $serviceId) {
                Service::query()
                    ->where('user_id', $professional->id)
                    ->where('id', $serviceId)
                    ->update(['sort_order' => 1_000_000 + $i]);
            }
            foreach ($orderedServiceIds as $i => $serviceId) {
                Service::query()
                    ->where('user_id', $professional->id)
                    ->where('id', $serviceId)
                    ->update(['sort_order' => $i]);
            }

            foreach ($orderedServiceIds as $serviceId) {
                $target = array_values(array_unique($membershipsByService[$serviceId] ?? []));
                $current = DB::table('site.service_category_assignments')
                    ->where('service_id', $serviceId)->pluck('service_category_id')->map(fn ($id) => (string) $id)->all();
                $toDetach = array_diff($current, $target);
                $toAttach = array_diff($target, $current);
                if ($toDetach !== []) {
                    DB::table('site.service_category_assignments')
                        ->where('service_id', $serviceId)->whereIn('service_category_id', $toDetach)->delete();
                }
                foreach ($toAttach as $categoryId) {
                    DB::table('site.service_category_assignments')->insert([
                        'service_id' => $serviceId,
                        'service_category_id' => $categoryId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        return $this->success(['ok' => true]);
    }

    public function forceDestroy(Request $request, User $professional, Service $service): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $service->forceDelete();

        return $this->success(['deleted' => true, 'hard' => true]);
    }

    public function restore(Request $request, User $professional, Service $service): JsonResponse
    {
        // #SEC-2: staffManage (admin-only). restore() lives in the non-admin
        // route group — the policy is the actual enforcement point here,
        // mirroring UserSelfPolicy's destroy/restore precedent for the User model.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        if ($service->trashed()) {
            $service->restore();
        }

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
