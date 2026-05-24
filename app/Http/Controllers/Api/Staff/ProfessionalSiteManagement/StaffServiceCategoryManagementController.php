<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\ProfessionalSite\Services\StaffReorderServiceCategoryRequest;
use App\Http\Requests\Api\Staff\ProfessionalSite\Services\StaffStoreServiceCategoryRequest;
use App\Http\Requests\Api\Staff\ProfessionalSite\Services\StaffUpdateServiceCategoryRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\Core\Professional\User;
use App\Models\Core\Professional\Service;
use App\Models\Core\Professional\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Staff manages service categories with full CRUD, reordering, and hard delete.
class StaffServiceCategoryManagementController extends ApiController
{
    public function index(Request $request, User $professional): JsonResponse
    {
        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');

        $q = ServiceCategory::query()
            ->where('professional_id', $professional->id);

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
        $this->authorizeForUser($professional, 'create', new ServiceCategory(['professional_id' => $professional->id]));
        $data = $request->validated();

        $category = DB::transaction(function () use ($professional, $data) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-categories:{$professional->id}"]);

            if (! array_key_exists('sort_order', $data) || $data['sort_order'] === null) {
                $max = ServiceCategory::query()
                    ->where('professional_id', $professional->id)
                    ->max('sort_order');

                $data['sort_order'] = is_null($max) ? 0 : ((int) $max + 1);
            }

            $category = ServiceCategory::query()->create([
                'professional_id' => $professional->id,
                'title' => $data['title'],
                'sort_order' => $data['sort_order'],
            ]);

            return $category->fresh();
        });

        return $this->success(['category' => new ServiceCategoryResource($category)], 201);
    }

    public function show(Request $request, User $professional, ServiceCategory $category): JsonResponse
    {
        $this->authorizeForUser($professional, 'view', $category);

        $includeArchived = $request->boolean('include_archived');
        if (! $includeArchived && $category->trashed()) {
            abort(404);
        }

        return $this->success(['category' => new ServiceCategoryResource($category)]);
    }

    public function update(StaffUpdateServiceCategoryRequest $request, User $professional, ServiceCategory $category): JsonResponse
    {
        $this->authorizeForUser($professional, 'update', $category);
        if ($category->trashed()) {
            abort(404);
        }

        $category->fill($request->validated());
        $category->save();

        return $this->success(['category' => new ServiceCategoryResource($category->fresh())]);
    }

    public function destroy(User $professional, ServiceCategory $category): JsonResponse
    {
        $this->authorizeForUser($professional, 'delete', $category);
        if ($category->trashed()) {
            abort(404);
        }

        DB::transaction(function () use ($professional, $category) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-layout:{$professional->id}"]);

            // Move services to Uncategorized (category_id = null) and append to end
            $toMove = Service::query()
                ->where('professional_id', $professional->id)
                ->where('category_id', $category->id)
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            if (! empty($toMove)) {
                $maxNull = Service::query()
                    ->where('professional_id', $professional->id)
                    ->whereNull('category_id')
                    ->max('sort_order');

                $i = is_null($maxNull) ? 0 : ((int) $maxNull + 1);

                foreach ($toMove as $serviceId) {
                    Service::query()
                        ->where('professional_id', $professional->id)
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
        $ids = array_values(array_unique($request->validated()['ids']));

        DB::transaction(function () use ($professional, $ids) {

            $allIds = ServiceCategory::query()
                ->where('professional_id', $professional->id)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);

            foreach ($ids as $id) {
                if (! isset($allSet[$id])) {
                    abort(422, 'One or more category IDs are invalid.');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder = array_merge($ids, $remaining);

            foreach ($newOrder as $i => $id) {
                ServiceCategory::query()
                    ->where('professional_id', $professional->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return $this->success(['ok' => true]);
    }

    public function forceDestroy(User $professional, ServiceCategory $category): JsonResponse
    {
        $this->authorizeForUser($professional, 'delete', $category);

        // Optional: also uncategorise services on hard delete (FK ON DELETE SET NULL handles it if in DB)
        $category->forceDelete();

        return $this->success(['deleted' => true, 'hard' => true]);
    }

    public function restore(User $professional, ServiceCategory $category): JsonResponse
    {
        $this->authorizeForUser($professional, 'update', $category);

        if ($category->trashed()) {
            $category->restore();

            $max = ServiceCategory::query()
                ->where('professional_id', $professional->id)
                ->max('sort_order');

            $category->sort_order = is_null($max) ? 0 : ((int) $max + 1);
            $category->save();
        }

        return $this->success(['restored' => true, 'category' => new ServiceCategoryResource($category->fresh())]);
    }
}
