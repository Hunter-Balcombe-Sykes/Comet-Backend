<?php

namespace App\Http\Controllers\Api\Professional\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Api\Professional\Services\ReorderServiceCategoryRequest;
use App\Http\Requests\Api\Professional\Services\StoreServiceCategoryRequest;
use App\Http\Requests\Api\Professional\Services\UpdateServiceCategoryRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\Core\Professional\Service;
use App\Models\Core\Professional\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Full CRUD + reorder for service categories. Deleting a category moves its services to uncategorized.
class ProfessionalServiceCategoryController extends ApiController
{
    use ResolveCurrentProfessional;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');

        $q = ServiceCategory::query()
            ->where('professional_id', $pro->id);

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

    public function store(StoreServiceCategoryRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $this->authorizeForUser($pro, 'create', new ServiceCategory(['professional_id' => $pro->id]));
        $data = $request->validated();

        $category = DB::transaction(function () use ($pro, $data) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-categories:{$pro->id}"]);

            if (! array_key_exists('sort_order', $data) || $data['sort_order'] === null) {
                $max = ServiceCategory::query()
                    ->where('professional_id', $pro->id)
                    ->max('sort_order');

                $data['sort_order'] = is_null($max) ? 0 : ((int) $max + 1);
            }

            $category = ServiceCategory::query()->create([
                'professional_id' => $pro->id,
                'title' => $data['title'],
                'sort_order' => $data['sort_order'],
            ]);

            return $category->fresh();
        });

        return $this->success(['category' => new ServiceCategoryResource($category)], 201);
    }

    public function show(Request $request, ServiceCategory $category): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $this->authorizeForUser($pro, 'view', $category);

        // Optional include_archived behavior
        $includeArchived = $request->boolean('include_archived');
        if (! $includeArchived && $category->trashed()) {
            abort(404);
        }

        return $this->success(['category' => new ServiceCategoryResource($category)]);
    }

    public function update(UpdateServiceCategoryRequest $request, ServiceCategory $category): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $this->authorizeForUser($pro, 'update', $category);
        if ($category->trashed()) {
            abort(404);
        }

        $category->fill($request->validated());
        $category->save();

        return $this->success(['category' => new ServiceCategoryResource($category->fresh())]);
    }

    public function destroy(Request $request, ServiceCategory $category): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $this->authorizeForUser($pro, 'delete', $category);
        if ($category->trashed()) {
            abort(404);
        }

        DB::transaction(function () use ($pro, $category) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-layout:{$pro->id}"]);

            // Move services to Uncategorized (category_id = null) and place them at the end
            $toMove = Service::query()
                ->where('professional_id', $pro->id)
                ->where('category_id', $category->id)
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            if (! empty($toMove)) {
                $maxNull = Service::query()
                    ->where('professional_id', $pro->id)
                    ->whereNull('category_id')
                    ->max('sort_order');

                $i = is_null($maxNull) ? 0 : ((int) $maxNull + 1);

                foreach ($toMove as $serviceId) {
                    Service::query()
                        ->where('professional_id', $pro->id)
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

    public function reorder(ReorderServiceCategoryRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $ids = array_values(array_unique($request->validated()['ids']));

        DB::transaction(function () use ($pro, $ids) {

            $allIds = ServiceCategory::query()
                ->where('professional_id', $pro->id)
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
                    ->where('professional_id', $pro->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return $this->success(['ok' => true]);
    }

    public function restore(Request $request, ServiceCategory $category): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $this->authorizeForUser($pro, 'update', $category);

        if (! $category->trashed()) {
            return $this->success(['restored' => true, 'category' => new ServiceCategoryResource($category->fresh())]);
        }

        DB::transaction(function () use ($pro, $category) {
            $category->restore();

            $max = ServiceCategory::query()
                ->where('professional_id', $pro->id)
                ->max('sort_order');

            $category->sort_order = is_null($max) ? 0 : ((int) $max + 1);
            $category->save();
        });

        return $this->success(['restored' => true, 'category' => new ServiceCategoryResource($category->fresh())]);
    }
}
