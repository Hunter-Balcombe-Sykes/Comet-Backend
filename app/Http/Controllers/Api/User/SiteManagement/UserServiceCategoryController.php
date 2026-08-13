<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Services\ReorderServiceCategoryRequest;
use App\Http\Requests\Api\User\Services\StoreServiceCategoryRequest;
use App\Http\Requests\Api\User\Services\UpdateServiceCategoryRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\Content\Collection as ContentCollection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\UserCacheService;
use App\Services\Content\ManualServiceWriter;
use App\Services\Content\ServiceCollections;
use App\Services\Site\AdvisoryLock;
use App\Services\Site\AdvisoryLockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Slice 3b Task 9: full CRUD + reorder for service categories, now backed by
 * `content.collections` rows of kind='service_category' instead of
 * `site.service_categories`. The wire shape is unchanged — only the store
 * moved; `ServiceCategoryResource` does the column renaming (label→title,
 * position→sort_order, removed_at→deleted_at, is_user_created→source).
 *
 * Every table access goes through `ServiceCollections`, the one read/write
 * for these rows — no query builder against content.collections /
 * content.collection_items lives in this file, deliberately: three of slice
 * 3a's final-review blockers were a hand-rolled fourth copy of a predicate
 * that already existed.
 *
 * Route-model binding is gone. `ServiceCategory $category` bound an Eloquent
 * model against the legacy table, which a collection id can never resolve
 * against; each method now takes the raw id and resolves it through the
 * owner-scoped `ServiceCollections::find()`, 404-ing on a miss (404, not 403
 * — a 403 would confirm the id exists and hand out an enumeration oracle).
 */
class UserServiceCategoryController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');

        $collections = app(ServiceCollections::class);
        $rows = $collections->list($pro->id, includeRemoved: $includeArchived || $onlyArchived);

        if ($onlyArchived) {
            $rows = $rows->filter(fn (object $row) => $row->removed_at !== null)->values();
        }

        // Bound the result at scale (B18/API-4), same ceiling as before the
        // cutover. True pagination is a frontend-coordinated change, deferred.
        $rows = $rows->take((int) config('partna.limits.pagination.service_categories_max', 200))->values();

        return $this->success([
            'categories' => ServiceCategoryResource::collection(
                $rows->map(fn (object $row) => $this->stampOwner($row, $pro))
            ),
            'filters' => [
                'include_archived' => $includeArchived,
                'only_archived' => $onlyArchived,
            ],
        ]);
    }

    public function store(StoreServiceCategoryRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        // ContentCollectionPolicy exposes view/update/delete, not create —
        // 'update' against an owner-stamped skeleton carries exactly the two
        // gates a create needs (ownership + pending-deletion), and mirrors
        // what UserServiceController::reorder() already does for its own
        // pre-write check. No inline abort_unless: CI fails the build on one.
        $this->authorizeForUser($pro, 'update', $this->collectionSkeleton($pro));

        $data = $request->validated();
        $site = $this->currentSite($pro);
        $collections = app(ServiceCollections::class);

        try {
            $id = DB::connection('pgsql')->transaction(function () use ($pro, $data, $collections): string {
                AdvisoryLock::acquire("service-categories:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                $id = $collections->create($pro->id, (string) $data['title']);

                // create() always appends. An explicit sort_order (still
                // accepted by StoreServiceCategoryRequest) is honoured by
                // renumbering the whole set with the new id spliced into that
                // slot, inside the same lock — the read-modify-write the
                // legacy InsertWithSortOrder held the same key for.
                if (array_key_exists('sort_order', $data)) {
                    $collections->reposition($pro->id, $this->splicedOrder($collections, $pro->id, $id, (int) $data['sort_order']));
                }

                return $id;
            });
        } catch (AdvisoryLockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $this->invalidate($pro, $site);

        return $this->success(['category' => new ServiceCategoryResource($this->readBack($collections, $pro, $id))], 201);
    }

    public function show(Request $request, string $category): JsonResponse
    {
        $pro = $this->currentUser($request);
        $collections = app(ServiceCollections::class);

        // The route is declared ->withTrashed(), so the lookup includes
        // removed rows and the include_archived flag decides afterwards.
        $row = $collections->find($pro->id, $category, includeRemoved: true);
        if ($row === null) {
            abort(404, 'Service category not found.');
        }

        $this->authorizeForUser($pro, 'view', $this->collectionSkeleton($pro, $category));

        if (! $request->boolean('include_archived') && $row->removed_at !== null) {
            abort(404, 'Service category not found.');
        }

        return $this->success(['category' => new ServiceCategoryResource($this->stampOwner($row, $pro))]);
    }

    public function update(UpdateServiceCategoryRequest $request, string $category): JsonResponse
    {
        $pro = $this->currentUser($request);
        $collections = app(ServiceCollections::class);

        // Live rows only — editing a removed category 404s, as it did before.
        if ($collections->find($pro->id, $category) === null) {
            abort(404, 'Service category not found.');
        }

        $this->authorizeForUser($pro, 'update', $this->collectionSkeleton($pro, $category));

        $data = $request->validated();
        $site = $this->currentSite($pro);

        try {
            DB::connection('pgsql')->transaction(function () use ($pro, $collections, $category, $data): void {
                AdvisoryLock::acquire("service-categories:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                if (array_key_exists('title', $data)) {
                    $collections->rename($pro->id, $category, (string) $data['title']);
                }

                if (array_key_exists('sort_order', $data)) {
                    $collections->reposition($pro->id, $this->splicedOrder($collections, $pro->id, $category, (int) $data['sort_order']));
                }
            });
        } catch (AdvisoryLockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $this->invalidate($pro, $site);

        return $this->success(['category' => new ServiceCategoryResource($this->readBack($collections, $pro, $category))]);
    }

    public function destroy(Request $request, string $category): JsonResponse
    {
        $pro = $this->currentUser($request);
        $collections = app(ServiceCollections::class);

        if ($collections->find($pro->id, $category) === null) {
            abort(404, 'Service category not found.');
        }

        $this->authorizeForUser($pro, 'delete', $this->collectionSkeleton($pro, $category));

        $site = $this->currentSite($pro);

        // Deliberate change from the legacy path, ratified in review:
        // deleting a category does NOT tear its memberships down. The legacy
        // destroy() deleted every site.service_category_assignments row;
        // content.collections.removed_at is a filter instead, so a later
        // restore() brings the group back intact rather than empty.
        //
        // What makes that safe is SectionCandidates' in_collection rule
        // skipping removed collections — without that filter an item would
        // keep rendering through a group the owner had deleted, since the
        // memberships are still there. That filter is LOAD-BEARING for this
        // method, not an optimisation: this is the first (and, with
        // restore(), only) writer of removed_at, so the two ship together and
        // must not be separated.
        $collections->remove($pro->id, $category);

        $this->invalidate($pro, $site);

        return $this->success(['deleted' => true]);
    }

    public function reorder(ReorderServiceCategoryRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);

        // SEC-5: pending-deletion + ownership gate, matching store() above.
        $this->authorizeForUser($pro, 'update', $this->collectionSkeleton($pro));

        $collections = app(ServiceCollections::class);
        /** @var list<string> $ids */
        $ids = array_values(array_map(fn ($id) => (string) $id, (array) $request->input('ids', [])));

        try {
            DB::connection('pgsql')->transaction(function () use ($pro, $collections, $ids): void {
                AdvisoryLock::acquire("service-categories:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                // reposition() drops ids that don't exist or belong to another
                // user before renumbering, so a foreign id consumes no slot
                // and cannot bump one of this owner's rows out of place.
                $collections->reposition($pro->id, $ids);
            });
        } catch (AdvisoryLockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $this->invalidate($pro, $site);

        return $this->success(['ok' => true]);
    }

    public function restore(Request $request, string $category): JsonResponse
    {
        $pro = $this->currentUser($request);
        $collections = app(ServiceCollections::class);

        $row = $collections->find($pro->id, $category, includeRemoved: true);
        if ($row === null) {
            abort(404, 'Service category not found.');
        }

        $this->authorizeForUser($pro, 'update', $this->collectionSkeleton($pro, $category));

        $site = $this->currentSite($pro);

        // Idempotent: restoring a live category is a no-op on the row.
        //
        // Deliberate change from the legacy path, ratified in review: the
        // category is restored IN PLACE rather than pushed to max+1. The old
        // renumber existed because site.service_categories.sort_order could
        // collide; ServiceCollections::create() already appends past removed
        // rows (its own docblock: "so a later restore doesn't collide"), so
        // there is nothing left to renumber around and the owner gets their
        // category back where they left it.
        if ($row->removed_at !== null) {
            $collections->restore($pro->id, $category);
        }

        $this->invalidate($pro, $site);

        return $this->success([
            'restored' => true,
            'category' => new ServiceCategoryResource($this->readBack($collections, $pro, $category)),
        ]);
    }

    /**
     * The three mandatory lanes come from ManualServiceWriter::invalidate()
     * — BuildState::bump, site.sites.updated_at, the edge purge — never
     * re-implemented here. $site->touch() then covers what the retired
     * ServiceCategoryObserver used to: SiteObserver's Redis payload bust,
     * edge purge and cache warm, which a raw UPDATE cannot fire. Category
     * titles ride in the dashboard services payload, hence invalidateServices.
     */
    private function invalidate(User $pro, Site $site): void
    {
        app(ManualServiceWriter::class)->invalidate([(string) $site->id]);
        $site->touch();
        app(UserCacheService::class)->invalidateServices($pro->id);
    }

    /**
     * A skeleton row for the policy. user_id is tenancy and never fillable,
     * so it is assigned directly — otherwise ContentCollectionPolicy reads a
     * null owner off the raw attributes and denies as 404.
     */
    private function collectionSkeleton(User $pro, ?string $id = null): ContentCollection
    {
        $collection = new ContentCollection;
        $collection->user_id = $pro->id;

        if ($id !== null) {
            $collection->id = $id;
        }

        return $collection;
    }

    /**
     * ServiceCollections rows carry no user_id column — every read is already
     * scoped to one owner — but the wire has always exposed it, so stamp the
     * resolved owner back on rather than dropping the field.
     */
    private function stampOwner(object $row, User $pro): object
    {
        $row->user_id = (string) $pro->id;

        return $row;
    }

    /** Re-read a row after a write, for the response body. */
    private function readBack(ServiceCollections $collections, User $pro, string $id): object
    {
        $row = $collections->find($pro->id, $id, includeRemoved: true);

        if ($row === null) {
            // Unreachable in practice: the row was written in this request
            // and find() re-reads the same owner-scoped query. Loud rather
            // than a contextless 500 if it ever is.
            Log::error('Service category vanished immediately after write', ['collection_id' => $id, 'user_id' => $pro->id]);
            abort(500, 'Service category could not be read back.');
        }

        return $this->stampOwner($row, $pro);
    }

    /**
     * The owner's current category order with $id moved to $index — the
     * ordered id list reposition() consumes, for the optional sort_order both
     * the store and update requests still accept.
     *
     * list() hides machine-derived collections that have no live members, so
     * such a row is absent here; reposition() appends every id it wasn't
     * given after the ones it was, keeping their relative order, so a hidden
     * collection simply sorts behind the visible ones instead of being
     * scrambled.
     *
     * @return list<string>
     */
    private function splicedOrder(ServiceCollections $collections, string $userId, string $id, int $index): array
    {
        $ordered = $collections->list($userId, includeRemoved: true)
            ->map(fn (object $row) => (string) $row->id)
            ->reject(fn (string $value) => $value === $id)
            ->values()
            ->all();

        array_splice($ordered, max(0, min($index, count($ordered))), 0, [$id]);

        return $ordered;
    }
}
