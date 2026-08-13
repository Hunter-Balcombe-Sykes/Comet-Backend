<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffReorderServiceCategoryRequest;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffStoreServiceCategoryRequest;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffUpdateServiceCategoryRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\Core\Site\Site;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use App\Services\Cache\UserCacheService;
use App\Services\Content\ManualServiceWriter;
use App\Services\Content\ServiceCollections;
use App\Services\Site\AdvisoryLock;
use App\Services\Site\AdvisoryLockTimeoutException;
use App\Services\Site\ReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * V2: Staff manages service categories with full CRUD, reordering, and hard delete.
 *
 * Slice 3b (Task 11b): the staff-side mirror of what Task 9 did to
 * `UserServiceCategoryController`. Categories are `content.collections` rows
 * of kind='service_category' now, reached through `ServiceCollections` — the
 * ONE read/write for them — with the untouched legacy
 * `site.service_categories` (Fresha) half still resolvable behind it, exactly
 * as `StaffServiceManagementController` keeps both service id spaces live for
 * the duration of the transition.
 *
 * The defect this closes was SILENT, which is what made it worse than an
 * error. Task 9 moved the owner's own `/service-categories` routes to
 * `content.collections` and left this controller writing
 * `site.service_categories`: a staff member could create a category for a
 * professional, receive a 201, and the owner would never see it — not on their
 * dashboard, not on their public page, with nothing surfaced to either party.
 * Every write path below is therefore pinned by a test asserting the OWNER's
 * read moved, never merely that the staff response was 200.
 *
 * Route-model binding for `{serviceCategory}` is gone. An Eloquent
 * `ServiceCategory` can only bind against the legacy table and a collection id
 * never resolves there, so each method takes the raw id and resolves it
 * owner-scoped through `ServiceCollections::find()` first, falling back to the
 * legacy row, and 404s on a miss (404, not 403 — a 403 would confirm the id
 * exists and hand out an enumeration oracle).
 *
 * Authorization stays where it was: the STAFF ACTOR is gated against the
 * professional (`staffView` / `staffManage` on UserPolicy). `ContentCollectionPolicy`
 * is the OWNER's gate — its methods type-hint a `User` actor and cannot take a
 * `PartnaStaff` — so the owner-scoping that policy performs is supplied here
 * by `ServiceCollections`' own user_id predicate plus the 404.
 *
 * CACHE INVALIDATION IS THIS CONTROLLER'S JOB AND NOTHING CATCHES A MISS.
 * `ServiceCollections` deliberately does not self-invalidate (it holds only a
 * user_id and no site context) and there is no CI check for a forgotten
 * caller. Every write method calls `invalidate()` below, which delegates the
 * three lanes to `ManualServiceWriter::invalidate()` — never re-rolled here.
 */
class StaffServiceCategoryManagementController extends ApiController
{
    use ResolveCurrentSite;

    public function index(Request $request, User $professional): JsonResponse
    {
        // #SEC-5: staff-dashboard read surface — any staff role.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');

        // Two id spaces, both live during the transition: an owner-authored
        // category is a content.collections row, a Fresha one is still a
        // site.service_categories row that its site.services members point at.
        // Listing only one of them is the same gap Task 9 left open here.
        $categories = $this->collectionRows($professional, $includeArchived, $onlyArchived)
            ->concat($this->legacyCategories($professional, $includeArchived, $onlyArchived))
            // mirrors the user-facing cap (config default 200)
            ->take((int) config('partna.limits.pagination.service_categories_max', 200))
            ->values();

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

        $site = $this->currentSite($professional);
        $collections = app(ServiceCollections::class);

        try {
            $id = DB::connection('pgsql')->transaction(function () use ($professional, $data, $collections): string {
                AdvisoryLock::acquire("service-categories:{$professional->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                $id = $collections->create($professional->id, (string) $data['title']);

                // create() always appends. The explicit sort_order staff may
                // still send is honoured by renumbering the whole set with the
                // new id spliced into that slot, inside the same lock — the
                // read-modify-write the legacy InsertWithSortOrder held the
                // same key for.
                if (array_key_exists('sort_order', $data)) {
                    $collections->reposition($professional->id, $this->splicedOrder($collections, $professional->id, $id, (int) $data['sort_order']));
                }

                return $id;
            });
        } catch (AdvisoryLockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $this->invalidate($professional, $site);

        return $this->success(['category' => new ServiceCategoryResource($this->readBack($collections, $professional, $id))], 201);
    }

    public function show(Request $request, User $professional, string $serviceCategory): JsonResponse
    {
        // #SEC-2: gate the STAFF ACTOR (staffView, any role), not the professional.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);

        $includeArchived = $request->boolean('include_archived');

        // The route is declared ->withTrashed(), so the lookup includes
        // removed rows and include_archived decides afterwards — the same
        // order the pre-cutover binding + trashed() check produced.
        $collections = app(ServiceCollections::class);
        $row = $collections->find($professional->id, $serviceCategory, includeRemoved: true);

        if ($row !== null) {
            if (! $includeArchived && $row->removed_at !== null) {
                abort(404);
            }

            return $this->success(['category' => new ServiceCategoryResource($this->stampOwner($row, $professional))]);
        }

        $legacy = $this->legacyCategory($professional, $serviceCategory, withTrashed: true);
        if ($legacy === null) {
            abort(404);
        }
        if (! $includeArchived && $legacy->trashed()) {
            abort(404);
        }

        return $this->success(['category' => new ServiceCategoryResource($legacy)]);
    }

    public function update(StaffUpdateServiceCategoryRequest $request, User $professional, string $serviceCategory): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $collections = app(ServiceCollections::class);
        // Live rows only — editing a removed category 404s, as it did before.
        if ($collections->find($professional->id, $serviceCategory) === null) {
            return $this->updateLegacy($request, $professional, $serviceCategory);
        }

        $data = $request->validated();
        $site = $this->currentSite($professional);

        try {
            DB::connection('pgsql')->transaction(function () use ($professional, $collections, $serviceCategory, $data): void {
                AdvisoryLock::acquire("service-categories:{$professional->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                if (array_key_exists('title', $data)) {
                    $collections->rename($professional->id, $serviceCategory, (string) $data['title']);
                }

                if (array_key_exists('sort_order', $data)) {
                    $collections->reposition($professional->id, $this->splicedOrder($collections, $professional->id, $serviceCategory, (int) $data['sort_order']));
                }
            });
        } catch (AdvisoryLockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $this->invalidate($professional, $site);

        return $this->success(['category' => new ServiceCategoryResource($this->readBack($collections, $professional, $serviceCategory))]);
    }

    public function destroy(Request $request, User $professional, string $serviceCategory): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $collections = app(ServiceCollections::class);
        $row = $collections->find($professional->id, $serviceCategory);
        $site = $this->currentSite($professional);

        if ($row === null) {
            $this->destroyLegacy($professional, $serviceCategory);
            $this->invalidate($professional, $site);

            return $this->success(['deleted' => true]);
        }

        // Same deliberate change from the legacy path that
        // UserServiceCategoryController::destroy() made: memberships are NOT
        // torn down. content.collections.removed_at is a filter instead, so a
        // later restore() brings the group back intact rather than empty —
        // which is safe only because SectionCandidates' in_collection rule
        // skips removed collections. That filter is LOAD-BEARING here.
        $collections->remove($professional->id, $serviceCategory);

        $this->invalidate($professional, $site);

        return $this->success(['deleted' => true]);
    }

    /**
     * Both id spaces are renumbered, each dense within its own store and each
     * preserving the relative order the caller submitted. They are NOT put on
     * one shared index (unlike StaffServiceManagementController::reorder):
     * `content.collections.position` and `site.service_categories.sort_order`
     * are read by index() as two concatenated blocks, never interleaved, so a
     * shared index would encode an order nothing reads back.
     *
     * An id in neither store still 422s, exactly as ReorderService's own
     * membership check did before the cutover — including a category that
     * belongs to a DIFFERENT professional, which is what keeps a foreign id
     * from silently consuming a slot.
     */
    public function reorder(StaffReorderServiceCategoryRequest $request, User $professional): JsonResponse
    {
        // #SEC-2: previously had zero authorization at all.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $site = $this->currentSite($professional);
        $collections = app(ServiceCollections::class);

        /** @var list<string> $ids */
        $ids = array_values(array_map('strval', (array) $request->input('ids', [])));

        $collectionIds = $collections->list($professional->id, includeRemoved: true)
            ->map(fn (object $row) => (string) $row->id)
            ->all();
        $collectionIdSet = array_flip($collectionIds);
        $legacyIdSet = array_flip($this->legacyCategoryIds($professional));

        $orderedCollectionIds = [];
        $orderedLegacyIds = [];
        foreach ($ids as $id) {
            if (isset($collectionIdSet[$id])) {
                $orderedCollectionIds[] = $id;
            } elseif (isset($legacyIdSet[$id])) {
                $orderedLegacyIds[] = $id;
            } else {
                abort(422, 'One or more items are invalid.');
            }
        }

        $lockKey = "service-categories:{$professional->id}";

        try {
            // ONE transaction, ONE lock acquisition, both stores. These used to
            // be two independent scopes — the collections write in its own
            // transaction, then ReorderService::reorder() opening a second one
            // and re-taking the same key. They never collided (each store's own
            // renumber is internally collision-safe), but a competing writer
            // could take the key in the gap and apply its WHOLE reorder, so this
            // request committed one store's order against the other's: a
            // half-applied layout, from two individually-correct requests.
            DB::connection('pgsql')->transaction(function () use ($professional, $collections, $orderedCollectionIds, $orderedLegacyIds, $lockKey): void {
                AdvisoryLock::acquire($lockKey, AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                if ($orderedCollectionIds !== []) {
                    // reposition() appends every id it wasn't given after the
                    // ones it was, keeping their relative order, so a partial
                    // list never leaves two rows sharing a position.
                    $collections->reposition($professional->id, $orderedCollectionIds);
                }

                if ($orderedLegacyIds !== []) {
                    // renumberLocked(), not reorder(): we already hold the key
                    // and the transaction, and reorder() would open its own.
                    app(ReorderService::class)->renumberLocked(
                        $orderedLegacyIds,
                        ServiceCategory::query()->where('user_id', $professional->id),
                        $lockKey,
                    );
                }
            });
        } catch (AdvisoryLockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $this->invalidate($professional, $site);

        return $this->success(['ok' => true]);
    }

    public function forceDestroy(Request $request, User $professional, string $serviceCategory): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $collections = app(ServiceCollections::class);
        $row = $collections->find($professional->id, $serviceCategory, includeRemoved: true);
        $site = $this->currentSite($professional);

        if ($row === null) {
            $legacy = $this->legacyCategory($professional, $serviceCategory, withTrashed: true);
            if ($legacy === null) {
                abort(404);
            }

            // Memberships auto-detach: site.service_category_assignments.service_category_id
            // is ON DELETE CASCADE (20260721180000_service_multi_category.sql), not SET NULL.
            $legacy->forceDelete();
            $this->invalidate($professional, $site);

            return $this->success(['deleted' => true, 'hard' => true]);
        }

        // Ownership was established by find() above, so the deletes key off
        // the primary key alone rather than restating ServiceCollections'
        // user_id/kind predicate — a hand-rolled copy of that predicate is
        // exactly what three of slice 3a's final-review blockers were.
        //
        // The memberships go, the member ITEMS do not: a category hard-delete
        // is not a service deletion, and content.items.removed_at is one-way.
        // content.collection_items is ON DELETE CASCADE in Postgres; deleting
        // it explicitly keeps the SQLite test lane honest and makes the intent
        // legible rather than implicit in the DDL.
        DB::connection('pgsql')->transaction(function () use ($serviceCategory): void {
            DB::connection('pgsql')->table('content.collection_items')
                ->where('collection_id', $serviceCategory)->delete();
            DB::connection('pgsql')->table('content.collections')
                ->where('id', $serviceCategory)->delete();
        });

        $this->invalidate($professional, $site);

        return $this->success(['deleted' => true, 'hard' => true]);
    }

    public function restore(Request $request, User $professional, string $serviceCategory): JsonResponse
    {
        // #SEC-2: staffManage (admin-only). restore() lives in the non-admin
        // route group — the policy is the actual enforcement point here,
        // mirroring UserSelfPolicy's destroy/restore precedent for the User model.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $collections = app(ServiceCollections::class);
        $row = $collections->find($professional->id, $serviceCategory, includeRemoved: true);
        $site = $this->currentSite($professional);

        if ($row === null) {
            $legacy = $this->legacyCategory($professional, $serviceCategory, withTrashed: true);
            if ($legacy === null) {
                abort(404);
            }

            if ($legacy->trashed()) {
                $legacy->restore();

                $max = ServiceCategory::query()
                    ->where('user_id', $professional->id)
                    ->max('sort_order');

                $legacy->sort_order = is_null($max) ? 0 : ((int) $max + 1);
                $legacy->save();
            }

            $this->invalidate($professional, $site);

            return $this->success(['restored' => true, 'category' => new ServiceCategoryResource($legacy->fresh())]);
        }

        // Idempotent, and restored IN PLACE rather than pushed to max+1 —
        // ServiceCollections::create() already appends past removed rows, so
        // there is no position to renumber around and the owner gets their
        // category back where they left it (Task 9's ratified change).
        if ($row->removed_at !== null) {
            $collections->restore($professional->id, $serviceCategory);
        }

        $this->invalidate($professional, $site);

        return $this->success([
            'restored' => true,
            'category' => new ServiceCategoryResource($this->readBack($collections, $professional, $serviceCategory)),
        ]);
    }

    /** The legacy (Fresha) rename branch — unchanged behaviour, just reached by explicit lookup instead of route-model binding. */
    private function updateLegacy(StaffUpdateServiceCategoryRequest $request, User $professional, string $serviceCategory): JsonResponse
    {
        $legacy = $this->legacyCategory($professional, $serviceCategory);
        if ($legacy === null) {
            abort(404);
        }

        $legacy->fill($request->validated());
        $legacy->save();

        $this->invalidate($professional, $this->currentSite($professional));

        return $this->success(['category' => new ServiceCategoryResource($legacy->fresh())]);
    }

    /** The legacy (Fresha) delete branch: detach members, then soft-delete. */
    private function destroyLegacy(User $professional, string $serviceCategory): void
    {
        $legacy = $this->legacyCategory($professional, $serviceCategory);
        if ($legacy === null) {
            abort(404);
        }

        DB::transaction(function () use ($professional, $legacy) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-layout:{$professional->id}"]);

            // Multi-category: detach members (a service left with zero
            // memberships is Uncategorised); sort_order untouched.
            DB::table('site.service_category_assignments')
                ->where('service_category_id', $legacy->id)
                ->delete();

            $legacy->delete();
        });
    }

    /**
     * The professional's content.collections categories, owner-stamped for the
     * wire (ServiceCollections rows carry no user_id column — every read is
     * already scoped to one owner — but the wire has always exposed it).
     *
     * @return Collection<int, \stdClass>
     */
    private function collectionRows(User $professional, bool $includeArchived, bool $onlyArchived): Collection
    {
        $rows = app(ServiceCollections::class)->list($professional->id, includeRemoved: $includeArchived || $onlyArchived);

        if ($onlyArchived) {
            $rows = $rows->filter(fn (object $row) => $row->removed_at !== null)->values();
        }

        // Inlined rather than routed through stampOwner(): that helper takes
        // and returns `object` (find() declares `?object`), which WIDENS the
        // \stdClass the query builder hands back and contradicts this method's
        // declared Collection<int, \stdClass> — the same narrowing ServiceCollections'
        // own normalizeRow() docblock says not to give up.
        return $rows->map(function (\stdClass $row) use ($professional): \stdClass {
            $row->user_id = (string) $professional->id;

            return $row;
        })->values();
    }

    /** @return Collection<int, ServiceCategory> */
    private function legacyCategories(User $professional, bool $includeArchived, bool $onlyArchived): Collection
    {
        $query = ServiceCategory::query()->where('user_id', $professional->id);

        if ($onlyArchived) {
            $query->onlyTrashed();
        } elseif ($includeArchived) {
            $query->withTrashed();
        }

        return $query->orderBy('sort_order')->orderBy('created_at')->get();
    }

    /** A legacy (Fresha) category row for this professional, or null — the 404-not-403 owner scope. */
    private function legacyCategory(User $professional, string $serviceCategory, bool $withTrashed = false): ?ServiceCategory
    {
        $query = ServiceCategory::query()->where('user_id', $professional->id);
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->find($serviceCategory);
    }

    /** @return list<string> */
    private function legacyCategoryIds(User $professional): array
    {
        return ServiceCategory::query()
            ->withTrashed()
            ->where('user_id', $professional->id)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * The three mandatory lanes come from ManualServiceWriter::invalidate()
     * — BuildState::bump, site.sites.updated_at, the edge purge — never
     * re-implemented here. $site->touch() then covers what no observer can
     * fire for a raw content.* write (SiteObserver's Redis payload bust, edge
     * purge and cache warm), and invalidateServices() busts the OWNER's
     * dashboard list, which a staff category edit must not leave stale —
     * category titles ride in that payload.
     */
    private function invalidate(User $professional, Site $site): void
    {
        app(ManualServiceWriter::class)->invalidate([(string) $site->id]);
        $site->touch();
        app(UserCacheService::class)->invalidateServices($professional->id);
    }

    /** ServiceCollections rows carry no user_id column; the wire has always exposed one. */
    private function stampOwner(object $row, User $professional): object
    {
        $row->user_id = (string) $professional->id;

        return $row;
    }

    /** Re-read a row after a write, for the response body. */
    private function readBack(ServiceCollections $collections, User $professional, string $id): object
    {
        $row = $collections->find($professional->id, $id, includeRemoved: true);

        if ($row === null) {
            // Unreachable in practice: the row was written in this request and
            // find() re-reads the same owner-scoped query. Loud rather than a
            // contextless 500 if it ever is.
            Log::error('Staff service category vanished immediately after write', ['collection_id' => $id, 'user_id' => $professional->id]);
            abort(500, 'Service category could not be read back.');
        }

        return $this->stampOwner($row, $professional);
    }

    /**
     * The professional's current category order with $id moved to $index — the
     * ordered id list reposition() consumes, for the optional sort_order both
     * the store and update requests still accept.
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
