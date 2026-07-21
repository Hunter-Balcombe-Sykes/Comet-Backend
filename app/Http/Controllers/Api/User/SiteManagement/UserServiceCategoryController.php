<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Services\ReorderServiceCategoryRequest;
use App\Http\Requests\Api\User\Services\StoreServiceCategoryRequest;
use App\Http\Requests\Api\User\Services\UpdateServiceCategoryRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Services\Site\InsertWithSortOrder;
use App\Services\Site\ReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Full CRUD + reorder for service categories. Deleting a category moves its services to uncategorized.
class UserServiceCategoryController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');

        $q = ServiceCategory::query()
            ->where('user_id', $pro->id);

        if ($onlyArchived) {
            $q->onlyTrashed();
        } elseif ($includeArchived) {
            $q->withTrashed();
        }

        // Bound the query at scale (B18/API-4). True pagination is a frontend-coordinated change, deferred.
        $categories = $q->orderBy('sort_order')->orderBy('created_at')->limit((int) config('partna.limits.pagination.service_categories_max', 200))->get();

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
        $pro = $this->currentUser($request);
        // SEC-1: user_id is no longer fillable — direct assignment so the policy still sees the owner.
        $skeleton = new ServiceCategory;
        $skeleton->user_id = $pro->id;
        $this->authorizeForUser($pro, 'create', $skeleton);
        $data = $request->validated();

        $category = InsertWithSortOrder::run(
            ServiceCategory::query()->where('user_id', $pro->id),
            "service-categories:{$pro->id}",
            function (int $next) use ($pro, $data) {
                // SEC-1: relation ->create() sets user_id via the FK, not mass-assignment.
                $category = $pro->serviceCategories()->create([
                    'title' => $data['title'],
                    'sort_order' => $data['sort_order'] ?? $next,
                ]);

                return $category->fresh();
            },
        );

        return $this->success(['category' => new ServiceCategoryResource($category)], 201);
    }

    public function show(Request $request, ServiceCategory $category): JsonResponse
    {
        $pro = $this->currentUser($request);

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
        $pro = $this->currentUser($request);

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
        $pro = $this->currentUser($request);

        $this->authorizeForUser($pro, 'delete', $category);
        if ($category->trashed()) {
            abort(404);
        }

        DB::transaction(function () use ($pro, $category) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-layout:{$pro->id}"]);

            // Multi-category: deleting a category DETACHES its members. A
            // service that belonged only to this category is left with zero
            // memberships — i.e. Uncategorised, the same end state as the old
            // ON-DELETE-SET-NULL move; one that also lives in other categories
            // keeps those memberships. sort_order is untouched (it's the
            // global per-user order, no longer per-bucket).
            DB::table('site.service_category_assignments')
                ->where('service_category_id', $category->id)
                ->delete();

            $category->delete();
        });

        return $this->success(['deleted' => true]);
    }

    public function reorder(ReorderServiceCategoryRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);

        // SEC-5: pending-deletion + ownership gate via ServicePolicy, matching
        // this controller's own store().
        // SEC-1: user_id is no longer fillable — direct assignment so the policy still sees the owner.
        $skeleton = new ServiceCategory;
        $skeleton->user_id = $pro->id;
        $this->authorizeForUser($pro, 'update', $skeleton);

        // EDGE-1 (audit): mass `update()` inside ReorderService bypasses Eloquent
        // events, so ServiceCategoryObserver never touches the site. Touch
        // explicitly in afterCommit to fire SiteObserver — Redis invalidation +
        // Cloudflare edge purge + cache warm (mirrors UserGalleryController::reorder).
        app(ReorderService::class)->reorder(
            $request->input('ids', []),
            ServiceCategory::query()->where('user_id', $pro->id),
            "service-categories:{$pro->id}",
            fn () => $site->touch(),
        );

        return $this->success(['ok' => true]);
    }

    public function restore(Request $request, ServiceCategory $category): JsonResponse
    {
        $pro = $this->currentUser($request);

        $this->authorizeForUser($pro, 'update', $category);

        if (! $category->trashed()) {
            return $this->success(['restored' => true, 'category' => new ServiceCategoryResource($category->fresh())]);
        }

        DB::transaction(function () use ($pro, $category) {
            $category->restore();

            $max = ServiceCategory::query()
                ->where('user_id', $pro->id)
                ->max('sort_order');

            $category->sort_order = is_null($max) ? 0 : ((int) $max + 1);
            $category->save();
        });

        return $this->success(['restored' => true, 'category' => new ServiceCategoryResource($category->fresh())]);
    }
}
