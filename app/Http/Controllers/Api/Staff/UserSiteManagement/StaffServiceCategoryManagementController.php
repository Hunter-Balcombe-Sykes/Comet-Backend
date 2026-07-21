<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffReorderServiceCategoryRequest;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffStoreServiceCategoryRequest;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffUpdateServiceCategoryRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use App\Services\Site\InsertWithSortOrder;
use App\Services\Site\ReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Staff manages service categories with full CRUD, reordering, and hard delete.
class StaffServiceCategoryManagementController extends ApiController
{
    public function index(Request $request, User $professional): JsonResponse
    {
        // #SEC-5: staff-dashboard read surface — any staff role.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');

        $q = ServiceCategory::query()
            ->where('user_id', $professional->id);

        if ($onlyArchived) {
            $q->onlyTrashed();
        } elseif ($includeArchived) {
            $q->withTrashed();
        }

        $categories = $q->orderBy('sort_order')->orderBy('created_at')->get();

        return $this->success([
            'categories' => ServiceCategoryResource::collection($categories),
            'filters' => [
                'include_archived' => $includeArchived,
                'only_archived' => $onlyArchived,
            ],
        ]);
    }

    public function store(StaffStoreServiceCategoryRequest $request, User $professional): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);
        $data = $request->validated();

        $category = InsertWithSortOrder::run(
            ServiceCategory::query()->where('user_id', $professional->id),
            "service-categories:{$professional->id}",
            function (int $next) use ($professional, $data) {
                // SEC-1: relation ->create() sets user_id via the FK, not mass-assignment.
                $category = $professional->serviceCategories()->create([
                    'title' => $data['title'],
                    'sort_order' => $data['sort_order'] ?? $next,
                ]);

                return $category->fresh();
            },
        );

        return $this->success(['category' => new ServiceCategoryResource($category)], 201);
    }

    public function show(Request $request, User $professional, ServiceCategory $category): JsonResponse
    {
        // #SEC-2: gate the STAFF ACTOR (staffView, any role), not the professional.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);

        $includeArchived = $request->boolean('include_archived');
        if (! $includeArchived && $category->trashed()) {
            abort(404);
        }

        return $this->success(['category' => new ServiceCategoryResource($category)]);
    }

    public function update(StaffUpdateServiceCategoryRequest $request, User $professional, ServiceCategory $category): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);
        if ($category->trashed()) {
            abort(404);
        }

        $category->fill($request->validated());
        $category->save();

        return $this->success(['category' => new ServiceCategoryResource($category->fresh())]);
    }

    public function destroy(Request $request, User $professional, ServiceCategory $category): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);
        if ($category->trashed()) {
            abort(404);
        }

        DB::transaction(function () use ($professional, $category) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-layout:{$professional->id}"]);

            // Move services to Uncategorized (category_id = null) and append to end
            $toMove = Service::query()
                ->where('user_id', $professional->id)
                ->where('category_id', $category->id)
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            if (! empty($toMove)) {
                $maxNull = Service::query()
                    ->where('user_id', $professional->id)
                    ->whereNull('category_id')
                    ->max('sort_order');

                $i = is_null($maxNull) ? 0 : ((int) $maxNull + 1);

                foreach ($toMove as $serviceId) {
                    Service::query()
                        ->where('user_id', $professional->id)
                        ->where('id', $serviceId)
                        ->update([
                            'category_id' => null,
                            'sort_order' => $i++,
                        ]);
                }
            }

            $category->delete();
        });

        return $this->success(['deleted' => true]);
    }

    public function reorder(StaffReorderServiceCategoryRequest $request, User $professional): JsonResponse
    {
        // #SEC-2: previously had zero authorization at all.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        app(ReorderService::class)->reorder(
            $request->input('ids', []),
            ServiceCategory::query()->where('user_id', $professional->id),
            "service-categories:{$professional->id}",
        );

        return $this->success(['ok' => true]);
    }

    public function forceDestroy(Request $request, User $professional, ServiceCategory $category): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        // Optional: also uncategorise services on hard delete (FK ON DELETE SET NULL handles it if in DB)
        $category->forceDelete();

        return $this->success(['deleted' => true, 'hard' => true]);
    }

    public function restore(Request $request, User $professional, ServiceCategory $category): JsonResponse
    {
        // #SEC-2: staffManage (admin-only). restore() lives in the non-admin
        // route group — the policy is the actual enforcement point here,
        // mirroring UserSelfPolicy's destroy/restore precedent for the User model.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        if ($category->trashed()) {
            $category->restore();

            $max = ServiceCategory::query()
                ->where('user_id', $professional->id)
                ->max('sort_order');

            $category->sort_order = is_null($max) ? 0 : ((int) $max + 1);
            $category->save();
        }

        return $this->success(['restored' => true, 'category' => new ServiceCategoryResource($category->fresh())]);
    }
}
