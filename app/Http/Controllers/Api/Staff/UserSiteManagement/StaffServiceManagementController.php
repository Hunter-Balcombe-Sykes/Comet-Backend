<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffReorderServiceLayoutRequest;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffReorderServiceRequest;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffStoreServiceRequest;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffUpdateServiceRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Http\Resources\ServiceResource;
use App\Models\Core\Site\Site;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use App\Services\Cache\UserCacheService;
use App\Services\Content\ManualServiceItems;
use App\Services\Content\ManualServiceWriter;
use App\Services\Content\ServiceCollections;
use App\Services\Site\AdvisoryLock;
use App\Services\Site\AdvisoryLockTimeoutException;
use App\Services\Site\LegacyServiceSortOrder;
use App\Services\User\SectionVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * V2: Staff manages services with CRUD, complex reordering, and hard delete.
 *
 * Slice 3b (Task 11): all nine methods are off `site.services` for the
 * owner-authored half and onto the SAME collaborators the owner's own routes
 * use — `ManualServiceItems` (read), `ManualServiceWriter` (write),
 * `ServiceCollections` (categories). The Fresha half is still the untouched
 * `site.services WHERE source IS NOT NULL` rows, merged in exactly as
 * `UserServiceController::index()` merges them.
 *
 * The defect this closes is not staleness. Post-3a an owner-authored service
 * has NO `site.services` row at all, so route-model binding could not resolve
 * it and staff could not see, edit or delete it; and an edit to a row that DID
 * still exist wrote a lane nothing public reads, returning 200 while changing
 * nothing. A silent success on a customer-facing surface is worse than an
 * error, which is why every write path below is pinned by a test asserting the
 * PUBLIC read moved.
 *
 * Route-model binding for `{service}` is therefore gone: an Eloquent `Service`
 * can only bind against the legacy table, and a `content.items` id never
 * resolves there. Each method takes the raw id and resolves it owner-scoped
 * through `ManualServiceItems::find()` first, falling back to the legacy
 * Fresha row (§C2), 404-ing on a miss — the same shape
 * `UserServiceCategoryController` took when Task 9 cut it over.
 *
 * CACHE INVALIDATION IS THIS CONTROLLER'S JOB AND NOTHING CATCHES A MISS.
 * `ServiceCollections` and `ManualServiceWriter`'s private mutators
 * deliberately do not self-invalidate (they hold no site context), and there
 * is no CI check for a forgotten caller. Every write method here calls
 * `ManualServiceWriter::invalidate([$siteId])` — the ONE implementation of the
 * three lanes (BuildState bump / site.sites.updated_at / edge purge), never
 * re-rolled locally.
 */
class StaffServiceManagementController extends ApiController
{
    use ResolveCurrentSite;

    public function index(Request $request, User $professional): JsonResponse
    {
        // #SEC-5: staff-dashboard read surface — any staff role.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);

        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('only_archived');
        $grouped = $request->boolean('grouped');

        $services = $this->mergedServices($professional, $includeArchived, $onlyArchived);

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

        // Two id spaces, both live during the transition, and a service's
        // memberships only ever point into one of them: an owner-authored
        // (content.*) service is filed into `content.collections`, a Fresha
        // (site.services) one into `site.service_categories`. Listing only one
        // space is what put every categorised owner service into
        // `uncategorised_services` — the gap Task 9 left open when the owner's
        // category routes moved and this controller did not.
        $categories = $this->collectionRows($professional, $includeArchived, $onlyArchived)
            ->concat($this->legacyCategories($professional, $includeArchived, $onlyArchived))
            // mirrors the user-facing cap (config default 200)
            ->take((int) config('partna.limits.pagination.service_categories_max', 200))
            ->values();

        // Multi-category: a service appears under EVERY category it belongs
        // to; zero memberships = Uncategorised. Manual (transient) Service
        // models carry a pre-set categories relation holding their real
        // content.collections memberships (ManualServiceItems::hydrate()), so
        // a content-item id is never queried against
        // site.service_category_assignments.
        $categoryIds = fn (Service $s) => $s->categories->map(fn ($c) => (string) $c->id)->all();

        $categoryPayload = $categories->map(function (object $category) use ($services, $categoryIds) {
            $id = (string) $category->id;
            $members = $services->filter(fn (Service $s) => in_array($id, $categoryIds($s), true))->values();

            return array_merge(
                (new ServiceCategoryResource($category))->resolve(),
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

        // #SVC-1: category_ids (multi) or the legacy single category_id.
        // Ownership of EVERY supplied id is asserted, not just the first. The
        // id space is content.collections now — a staff create lands an
        // owner-authored content item, and only a collection can hold one.
        $collections = app(ServiceCollections::class);
        $categoryIds = $this->requestedCategoryIds($data);
        foreach ($categoryIds as $categoryId) {
            $this->assertCollectionBelongsToProfessional($collections, $professional->id, $categoryId);
        }

        $site = $this->currentSite($professional);
        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);

        // writeManualItem() MUST NOT run inside a surrounding transaction
        // (ProjectionWriter's own docblock) — it manages its own boundaries.
        $payload = (object) [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price_cents' => $data['price_cents'],
            'currency_code' => $data['currency_code'] ?? 'AUD',
            'duration_minutes' => $data['duration_minutes'] ?? null,
        ];
        $itemId = $writer->write($professional->id, 'manual:'.(string) Str::uuid(), $writer->projectionFor($payload));

        $isActive = (bool) ($data['is_active'] ?? true);
        // Staff (unlike the owner's own create) may name an explicit slot.
        // Ordering lives in site.section_items.sort_key now, so the legacy
        // integer is honoured as that key rather than as a services.sort_order.
        $explicitSortKey = array_key_exists('sort_order', $data) && $data['sort_order'] !== null
            ? (float) $data['sort_order']
            : null;

        try {
            DB::connection('pgsql')->transaction(function () use ($professional, $site, $manual, $writer, $itemId, $isActive, $explicitSortKey) {
                AdvisoryLock::acquire("services:{$professional->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                if ($isActive) {
                    // Re-resolve the section id INSIDE the lock: two concurrent
                    // first-ever creates could otherwise both observe "no
                    // section yet" and race to the same sort_key.
                    $writer->pin($site, $itemId, $explicitSortKey ?? $this->nextSortKey($manual->sectionId($site)));
                } else {
                    $writer->exclude($site, $itemId);
                }
            });
        } catch (AdvisoryLockTimeoutException) {
            // I1: the content write above already committed (it cannot run
            // inside this transaction), so a bare 423 would leave a live,
            // uncurated item rendering publicly while the caller was told the
            // create failed. Compensate rather than orphan it — the same
            // reasoning, and the same single exception, as
            // UserServiceController::store().
            $writer->markRemoved($itemId);

            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        if ($categoryIds !== []) {
            // null source_id = the owner-authored membership lane. assign() is
            // single-collection per source by design (its rule 4), so a
            // multi-id payload collapses to its first entry — same as the
            // owner's own PATCH /services/{id}/category.
            $collections->assign($professional->id, $itemId, $categoryIds[0], null);
        }

        $this->invalidate($professional, $site, $writer);

        $row = $manual->find($professional->id, $itemId, $manual->sectionId($site));
        if ($row === null) {
            // Unreachable in practice: the item was just written live and
            // find() re-reads the same manual source. Loud rather than a
            // contextless 500 if it ever is.
            Log::error('Staff service store: item vanished immediately after write', ['item_id' => $itemId, 'user_id' => $professional->id]);
            abort(500, 'Service could not be read back after creation.');
        }

        return $this->success(['service' => new ServiceResource($manual->toServiceModel($professional->id, $row))], 201);
    }

    public function show(Request $request, User $professional, string $service): JsonResponse
    {
        // #SEC-2: gate the STAFF ACTOR (staffView, any role), not the professional.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);

        $includeArchived = $request->boolean('include_archived');
        $manual = app(ManualServiceItems::class);

        // The route is declared ->withTrashed(), so the lookup includes
        // removed rows and include_archived decides afterwards — the same
        // order the pre-cutover binding + trashed() check produced.
        $row = $manual->find($professional->id, $service, $manual->sectionId($professional->site), includeRemoved: true);
        if ($row !== null) {
            if (! $includeArchived && $row->removed_at !== null) {
                abort(404);
            }

            return $this->success(['service' => new ServiceResource($manual->toServiceModel($professional->id, $row))]);
        }

        $legacy = $this->legacyService($professional, $service, withTrashed: true);
        if ($legacy === null) {
            abort(404);
        }
        if (! $includeArchived && $legacy->trashed()) {
            abort(404);
        }

        return $this->success(['service' => new ServiceResource($legacy)]);
    }

    public function update(StaffUpdateServiceRequest $request, User $professional, string $service): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);
        $row = $manual->find($professional->id, $service, $manual->sectionId($professional->site));

        if ($row === null) {
            return $this->updateLegacy($request, $professional, $service);
        }

        $data = $request->validated();
        $current = $manual->toServiceModel($professional->id, $row);

        // #SVC-1: category_ids (multi) REPLACES the membership set; the legacy
        // single category_id maps to [id] (or [] for an explicit null). Null
        // (not []) means the request never addressed memberships at all, which
        // is a different thing from "replace them with nothing".
        $collections = app(ServiceCollections::class);
        $categoryIds = null;
        if (array_key_exists('category_id', $data) || array_key_exists('category_ids', $data)) {
            $categoryIds = $this->assignmentCategoryIds($data);
            foreach ($categoryIds as $categoryId) {
                $this->assertCollectionBelongsToProfessional($collections, $professional->id, $categoryId);
            }
            unset($data['category_id'], $data['category_ids']);
        }

        // Merge onto the CURRENT projected values — every field is optional on
        // a PATCH, so an is_active-only request must not blank out whatever
        // content.* already carries for the fields it didn't send.
        $payload = (object) [
            'title' => $data['title'] ?? $current->title,
            'description' => array_key_exists('description', $data) ? $data['description'] : $current->description,
            'price_cents' => $data['price_cents'] ?? $current->price_cents,
            'currency_code' => $data['currency_code'] ?? $current->currency_code,
            'duration_minutes' => array_key_exists('duration_minutes', $data) ? $data['duration_minutes'] : $current->duration_minutes,
        ];

        $site = $this->currentSite($professional);

        // Same coord as the original write — writeManualItem() is idempotent
        // on it, so this UPDATES the existing item rather than minting a second.
        $coord = $writer->coordFor($professional->id, (string) $row->id) ?? ('manual:'.$row->id);
        // B1: the fields the caller actually sent THIS request, so
        // projectionFor() can tell an explicit clear from an untouched field
        // merely carrying a null value forward.
        $forceFacets = array_intersect(array_keys($data), ['description', 'duration_minutes']);
        $itemId = $writer->write($professional->id, $coord, $writer->projectionFor($payload, $forceFacets));

        // is_active moves the row between pin (visible) and exclude (hidden) —
        // content.* has no boolean column for it.
        $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $current->is_active;
        $wasPinned = ($row->state ?? null) === 'pinned';
        $explicitSortKey = array_key_exists('sort_order', $data) && $data['sort_order'] !== null
            ? (float) $data['sort_order']
            : null;

        try {
            DB::connection('pgsql')->transaction(function () use ($professional, $site, $manual, $writer, $itemId, $isActive, $wasPinned, $row, $explicitSortKey) {
                AdvisoryLock::acquire("services:{$professional->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                if ($isActive) {
                    // Already pinned: keep its position unless the request
                    // named one. Newly activated (was excluded, or never
                    // curated): append.
                    $sortKey = $explicitSortKey
                        ?? ($wasPinned && $row->sort_key !== null
                            ? (float) $row->sort_key
                            : $this->nextSortKey($manual->sectionId($site)));
                    $writer->pin($site, $itemId, $sortKey);
                } else {
                    $writer->exclude($site, $itemId);
                }
            });
        } catch (AdvisoryLockTimeoutException) {
            // No orphan compensation needed here (unlike store()): the item
            // already existed with a valid curation row before this request
            // and simply keeps its last-good state.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        if ($categoryIds !== null) {
            $collections->assign($professional->id, $itemId, $categoryIds[0] ?? null, null);
        }

        $this->invalidate($professional, $site, $writer);

        $freshRow = $manual->find($professional->id, $itemId, $manual->sectionId($site));

        return $this->success(['service' => new ServiceResource($manual->toServiceModel($professional->id, $freshRow ?? $row))]);
    }

    public function destroy(Request $request, User $professional, string $service): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);
        $row = $manual->find($professional->id, $service, $manual->sectionId($professional->site));

        if ($row === null) {
            // §C2: the untouched legacy half. A trashed row 404s, as it did
            // when route-model binding resolved it.
            $legacy = $this->legacyService($professional, $service);
            if ($legacy === null) {
                abort(404);
            }

            $legacy->delete();

            return $this->success(['deleted' => true]);
        }

        $site = $this->currentSite($professional);
        // items.removed_at ONLY — NEVER source_items.removed_at, which is
        // cleared on reappearance and would resurrect a service its owner
        // deleted (parent spec §3.1/§3.5).
        $writer->markRemoved((string) $row->id);
        $this->invalidate($professional, $site, $writer);

        return $this->success(['deleted' => true]);
    }

    /**
     * Flat reorder. §C2: an id is looked up in whichever store owns it —
     * manual (content.*) or Fresha (site.services); an id neither recognises
     * 422s, same as the pre-cutover single-table check.
     *
     * Both halves are renumbered from ONE shared position index in the SAME
     * transaction, never per-half. `services_user_sort_order_uq` is
     * `UNIQUE (user_id, sort_order) WHERE deleted_at IS NULL` — GLOBAL per
     * user and NOT scoped by source — so renumbering only the Fresha subset to
     * a dense 0..N-1 collides with the legacy owner-authored rows
     * ServiceBackfiller never deletes. The shared index is also what lets the
     * merged read above reconstruct an interleaved manual+Fresha order instead
     * of always grouping every manual service ahead of every Fresha one.
     */
    public function reorder(StaffReorderServiceRequest $request, User $professional): JsonResponse
    {
        // #SEC-2: previously had zero authorization at all.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $site = $this->currentSite($professional);
        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);

        /** @var list<string> $ids */
        $ids = array_values(array_map('strval', (array) $request->input('ids', [])));

        $manualRowsById = $manual->rows($professional->id, $manual->sectionId($site), includeRemoved: false)
            ->keyBy(fn ($row) => (string) $row->id);
        $manualIdSet = array_flip($manualRowsById->keys()->all());
        $freshaIds = $this->liveFreshaIds($professional);
        $freshaIdSet = array_flip($freshaIds);

        foreach ($ids as $id) {
            if (! isset($manualIdSet[$id]) && ! isset($freshaIdSet[$id])) {
                abort(422, 'One or more items are invalid.');
            }
        }

        // The submitted ids first, then every other live id this request
        // didn't mention, in its current relative order — ONE combined
        // authority for both writes below.
        $fullOrder = array_merge($ids, array_values(array_diff(
            [...$manualRowsById->keys()->all(), ...$freshaIds],
            $ids,
        )));

        try {
            DB::connection('pgsql')->transaction(function () use ($professional, $site, $writer, $manualRowsById, $fullOrder) {
                AdvisoryLock::acquire("services:{$professional->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                $this->pinManualOrder($site, $writer, $manualRowsById, $fullOrder);
                app(LegacyServiceSortOrder::class)->renumber($professional->id, $fullOrder);
            });
        } catch (AdvisoryLockTimeoutException) {
            // U2: same contention/423 as store() above.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $this->invalidate($professional, $site, $writer);

        return $this->success(['ok' => true]);
    }

    /**
     * Full layout reorder (categories + services).
     *
     * Both category id spaces are accepted, because the grouped read above
     * emits both: a `site.service_categories` block orders that row's
     * sort_order and syncs its Fresha memberships (unchanged), a
     * `content.collections` block is repositioned through
     * `ServiceCollections::reposition()`. A block's space also decides which
     * services may sit in it — a Fresha service cannot hold a collection
     * membership and an owner-authored one cannot hold a legacy pivot row, so
     * a mismatch is a 422 rather than a silent membership drop.
     *
     * MEMBERSHIPS FOR OWNER-AUTHORED SERVICES ARE NOT WRITTEN HERE, only their
     * ORDER — same stance as `UserServiceController::reorderLayout()`.
     * `PATCH /services/{id}` (staff) and `PATCH /services/{id}/category`
     * (owner) are the writers of `content.collection_items`; a layout endpoint
     * that also rewrote them would silently re-file a service any time a UI
     * round-tripped it through a block of the other kind.
     */
    public function reorderLayout(StaffReorderServiceLayoutRequest $request, User $professional): JsonResponse
    {
        // #SEC-2: previously had zero authorization at all.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $site = $this->currentSite($professional);
        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);
        $collections = app(ServiceCollections::class);
        $payload = $request->validated();

        // Fix C (whole-branch review pt.2): unified onto the services:{user}
        // advisory key (was service-layout:{user}) — this renumbering hits the
        // SAME globally-unique (user_id, sort_order) constraint that
        // InsertWithSortOrder and ReorderService already serialise on via that
        // key, and inherits the 5s bound + typed exception (→ 423) with it.
        try {
            DB::connection('pgsql')->transaction(function () use ($professional, $site, $manual, $writer, $collections, $payload) {
                AdvisoryLock::acquire("services:{$professional->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                $legacyCategoryIds = ServiceCategory::query()->where('user_id', $professional->id)->pluck('id')
                    ->map(fn ($id) => (string) $id)->all();
                $legacyCategorySet = array_flip($legacyCategoryIds);

                $collectionIds = $collections->list($professional->id)->map(fn ($row) => (string) $row->id)->all();
                $collectionSet = array_flip($collectionIds);

                $freshaIds = $this->liveFreshaIds($professional);
                $freshaSet = array_flip($freshaIds);

                $manualRowsById = $manual->rows($professional->id, $manual->sectionId($site), includeRemoved: false)
                    ->keyBy(fn ($row) => (string) $row->id);
                $manualSet = array_flip($manualRowsById->keys()->all());

                $providedLegacyCategoryIds = [];
                $orderedCollectionIds = [];
                $seenPerBlock = [];
                $membershipsByService = [];
                $uncategorisedIds = [];
                $manualIdsSeen = [];

                foreach ($payload['categories'] as $blockIndex => $block) {
                    $categoryId = $block['id'] ?? null;
                    $isCollectionBlock = $categoryId !== null && isset($collectionSet[$categoryId]);

                    if ($categoryId !== null) {
                        if (! $isCollectionBlock && ! isset($legacyCategorySet[$categoryId])) {
                            abort(422, 'One or more category IDs are invalid.');
                        }
                        $isCollectionBlock
                            ? $orderedCollectionIds[] = $categoryId
                            : $providedLegacyCategoryIds[] = $categoryId;
                    }

                    foreach ($block['service_ids'] as $serviceId) {
                        $isFresha = isset($freshaSet[$serviceId]);
                        $isManual = isset($manualSet[$serviceId]);
                        if (! $isFresha && ! $isManual) {
                            abort(422, 'One or more service IDs are invalid.');
                        }
                        // Multi-category: one membership per category block;
                        // never twice in one block.
                        if (isset($seenPerBlock[$blockIndex][$serviceId])) {
                            abort(422, 'Duplicate service IDs detected within a category block.');
                        }
                        $seenPerBlock[$blockIndex][$serviceId] = true;

                        // A block's id space decides what may sit in it — see
                        // the docblock. Silently accepting the mismatch would
                        // drop the service's real membership on the next read.
                        if ($isCollectionBlock && ! $isManual) {
                            abort(422, 'A Fresha-synced service cannot be filed under an owner-authored category.');
                        }
                        if ($categoryId !== null && ! $isCollectionBlock && ! $isFresha) {
                            abort(422, 'An owner-authored service cannot be filed under a Fresha-synced category.');
                        }

                        if ($isManual) {
                            // Manual ids never enter FRESHA's membership map;
                            // only their first-occurrence position matters here.
                            $manualIdsSeen[$serviceId] = true;

                            continue;
                        }

                        if ($categoryId === null) {
                            $uncategorisedIds[$serviceId] = true;
                        } else {
                            $membershipsByService[$serviceId][] = $categoryId;
                        }
                    }
                }

                foreach ($uncategorisedIds as $serviceId => $_) {
                    if (isset($membershipsByService[$serviceId])) {
                        abort(422, 'A service cannot be both categorised and uncategorised.');
                    }
                }

                // Every live service — Fresha AND manual — must be covered,
                // checked per half so the payload can't silently omit one.
                $coveredFreshaIds = array_unique([...array_keys($membershipsByService), ...array_keys($uncategorisedIds)]);
                if (count($coveredFreshaIds) !== count($freshaIds) || count($manualIdsSeen) !== count($manualSet)) {
                    abort(422, 'Layout payload must include all service IDs for this professional.');
                }

                $this->assertCoversEvery($providedLegacyCategoryIds, $legacyCategoryIds);
                $this->assertCoversEvery($orderedCollectionIds, $collectionIds);

                // Apply category order (legacy rows renumbered in place;
                // collections through their own writer) + the flattened,
                // first-occurrence service order across EVERY block.
                $legacySort = 0;
                $orderedServiceIds = [];

                foreach ($payload['categories'] as $block) {
                    $categoryId = $block['id'] ?? null;

                    if ($categoryId !== null && isset($legacyCategorySet[$categoryId])) {
                        ServiceCategory::query()
                            ->where('user_id', $professional->id)
                            ->where('id', $categoryId)
                            ->update(['sort_order' => $legacySort++]);
                    }

                    foreach ($block['service_ids'] as $serviceId) {
                        if (! in_array($serviceId, $orderedServiceIds, true)) {
                            $orderedServiceIds[] = $serviceId;
                        }
                    }
                }

                if ($orderedCollectionIds !== []) {
                    $collections->reposition($professional->id, array_values(array_unique($orderedCollectionIds)));
                }

                // Fresha membership sync (replace-set semantics) — unchanged
                // from the pre-cutover implementation, restricted to the
                // Fresha subset.
                foreach ($orderedServiceIds as $serviceId) {
                    if (! isset($freshaSet[$serviceId])) {
                        continue;
                    }
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

                $this->pinManualOrder($site, $writer, $manualRowsById, $orderedServiceIds);
                app(LegacyServiceSortOrder::class)->renumber($professional->id, $orderedServiceIds);
            });
        } catch (AdvisoryLockTimeoutException) {
            // Same contention/423 as store()/reorder() above.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $this->invalidate($professional, $site, $writer);

        return $this->success(['ok' => true]);
    }

    /**
     * Hard delete. For an owner-authored service that means the
     * `content.items` row itself, not a tombstone — `markRemoved()` is what
     * `destroy()` does and the two verbs must stay distinguishable.
     *
     * `site.section_items` and `content.source_items` are cleared explicitly
     * rather than left to the schema: the first is ON DELETE CASCADE
     * (20260729150007) but the second is ON DELETE SET NULL, which would leave
     * an orphan source_item behind holding the item's coord. The typed facet
     * and `content.collection_items` rows are ON DELETE CASCADE and are left
     * to Postgres. There is no collaborator method for this — `destroy()`'s
     * one-way `items.removed_at` rule is deliberately the only deletion
     * `ManualServiceWriter` exposes — so this is new code, not a second copy
     * of an existing write.
     */
    public function forceDestroy(Request $request, User $professional, string $service): JsonResponse
    {
        // #SEC-2: staffManage (admin-only) — gates the staff actor.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);
        $row = $manual->find($professional->id, $service, $manual->sectionId($professional->site), includeRemoved: true);

        if ($row === null) {
            $legacy = $this->legacyService($professional, $service);
            if ($legacy === null) {
                abort(404);
            }

            $legacy->forceDelete();

            return $this->success(['deleted' => true, 'hard' => true]);
        }

        $itemId = (string) $row->id;
        $site = $this->currentSite($professional);

        DB::connection('pgsql')->transaction(function () use ($professional, $itemId) {
            DB::connection('pgsql')->table('site.section_items')->where('item_id', $itemId)->delete();
            DB::connection('pgsql')->table('content.source_items')->where('item_id', $itemId)->delete();
            DB::connection('pgsql')->table('content.items')
                ->where('id', $itemId)
                ->where('user_id', $professional->id)
                ->delete();
        });

        $this->invalidate($professional, $site, $writer);

        return $this->success(['deleted' => true, 'hard' => true]);
    }

    public function restore(Request $request, User $professional, string $service): JsonResponse
    {
        // #SEC-2: staffManage (admin-only). restore() lives in the non-admin
        // route group — the policy is the actual enforcement point here,
        // mirroring UserSelfPolicy's destroy/restore precedent for the User model.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);
        $sectionId = $manual->sectionId($professional->site);
        $row = $manual->find($professional->id, $service, $sectionId, includeRemoved: true);

        if ($row === null) {
            $legacy = $this->legacyService($professional, $service, withTrashed: true);
            if ($legacy === null) {
                abort(404);
            }

            if ($legacy->trashed()) {
                $legacy->restore();
            }

            return $this->success(['restored' => true, 'service' => new ServiceResource($legacy->fresh())]);
        }

        // Idempotent: restoring a live item is a no-op, and invalidating on it
        // would bump the build state for a request that changed nothing.
        if ($row->removed_at === null) {
            return $this->success(['restored' => true, 'service' => new ServiceResource($manual->toServiceModel($professional->id, $row))]);
        }

        $site = $this->currentSite($professional);
        // Spec §3.5: legitimate ONLY from an explicit restore — the one-way
        // rule that stops a reappearing scrape resurrecting a user-deleted row
        // lives in ProjectionWriter and is untouched here. No sort_key
        // recompute: section_items.sort_key carries no uniqueness constraint,
        // so the item's pre-deletion curation state is safe to leave as it was.
        $writer->clearRemoved((string) $row->id);
        $this->invalidate($professional, $site, $writer);

        $freshRow = $manual->find($professional->id, (string) $row->id, $sectionId, includeRemoved: true);

        return $this->success([
            'restored' => true,
            'service' => new ServiceResource($manual->toServiceModel($professional->id, $freshRow ?? $row)),
        ]);
    }

    /**
     * §C2: the untouched, pre-cutover Fresha update path. Kept because editing
     * a projected row detaches it from the live sync (is_manual), which is
     * what makes the owner's resync verbs meaningful — without this branch
     * nothing could ever set is_manual back to true.
     */
    private function updateLegacy(StaffUpdateServiceRequest $request, User $professional, string $service): JsonResponse
    {
        $legacy = $this->legacyService($professional, $service);
        if ($legacy === null) {
            abort(404);
        }

        $data = $request->validated();

        // Ownership of EVERY supplied id is asserted, not just the first —
        // against site.service_categories, which is the space a legacy row's
        // memberships live in.
        if (array_key_exists('category_id', $data) || array_key_exists('category_ids', $data)) {
            $categoryIds = $this->assignmentCategoryIds($data);
            foreach ($categoryIds as $categoryId) {
                $this->assertLegacyCategoryBelongsToProfessional($professional->id, $categoryId);
            }

            // Appends at global end when moving without an explicit
            // sort_order, mirroring the old per-bucket move.
            $target = collect($categoryIds)->sort()->values()->all();
            $current = $legacy->categories()->pluck('site.service_categories.id')->map(fn ($id) => (string) $id)->sort()->values()->all();
            if ($target !== $current) {
                $legacy->categories()->sync($categoryIds);
                if (! array_key_exists('sort_order', $data)) {
                    $max = Service::query()
                        ->where('user_id', $professional->id)
                        ->whereNull('deleted_at')
                        ->max('sort_order');
                    $data['sort_order'] = is_null($max) ? 0 : ((int) $max + 1);
                }
            }
            unset($data['category_id'], $data['category_ids']);
        }

        $legacy->fill($data);
        $legacy->save();

        return $this->success(['service' => new ServiceResource($legacy->fresh())]);
    }

    /**
     * The dashboard list: owner-authored rows from content.* merged with the
     * untouched Fresha half, sorted by the ONE shared rank both writes use.
     * `site.services` still physically holds every pre-cutover manual row too,
     * but those are superseded by their content.* projection and are never
     * read from here again — the `whereNull('source')` half of the old query
     * is gone for good, not merged back in.
     *
     * @return Collection<int, Service>
     */
    private function mergedServices(User $professional, bool $includeArchived, bool $onlyArchived): Collection
    {
        $manual = app(ManualServiceItems::class);
        $rows = $manual->rows($professional->id, $manual->sectionId($professional->site), includeRemoved: $includeArchived || $onlyArchived);
        if ($onlyArchived) {
            $rows = $rows->filter(fn ($row) => $row->removed_at !== null)->values();
        }
        $manualServices = $manual->toServiceModels($professional->id, $rows);

        $freshaQuery = Service::query()
            ->where('user_id', $professional->id)
            ->whereNotNull('source');
        if ($onlyArchived) {
            $freshaQuery->onlyTrashed();
        } elseif ($includeArchived) {
            $freshaQuery->withTrashed();
        }
        $freshaServices = $freshaQuery->with('categories:id')->orderBy('sort_order')->orderBy('created_at')->get();

        // mirrors the user-facing cap (config default 500)
        return $manualServices->concat($freshaServices)
            ->sortBy('sort_order')
            ->values()
            ->take((int) config('partna.limits.pagination.services_max', 500));
    }

    /**
     * The owner's content.collections categories, owner-stamped for the wire
     * (ServiceCollections rows carry no user_id column — every read is already
     * scoped to one owner — but the wire has always exposed the field).
     *
     * @return Collection<int, \stdClass>
     */
    private function collectionRows(User $professional, bool $includeArchived, bool $onlyArchived): Collection
    {
        $rows = app(ServiceCollections::class)->list($professional->id, includeRemoved: $includeArchived || $onlyArchived);

        if ($onlyArchived) {
            $rows = $rows->filter(fn (object $row) => $row->removed_at !== null)->values();
        }

        return $rows->map(function (object $row) use ($professional) {
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

    /** A legacy (Fresha) service row for this professional, or null. */
    private function legacyService(User $professional, string $service, bool $withTrashed = false): ?Service
    {
        $query = Service::query()->where('user_id', $professional->id)->whereNotNull('source');
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->find($service);
    }

    /** @return list<string> */
    private function liveFreshaIds(User $professional): array
    {
        return Service::query()
            ->where('user_id', $professional->id)
            ->whereNotNull('source')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Owner ordering for the manual half: a pin keyed by the id's own position
     * in $order — NOT a recompacted per-half counter, so it stays on the same
     * numbering scale LegacyServiceSortOrder uses. Excluded (hidden) items
     * carry no sort_key by design (ManualServiceWriter::exclude) — accepted,
     * but they stay unpositioned until reactivated.
     *
     * @param  Collection<string, \stdClass>  $manualRowsById
     * @param  list<string>  $order
     */
    private function pinManualOrder(Site $site, ManualServiceWriter $writer, Collection $manualRowsById, array $order): void
    {
        foreach ($order as $rank => $id) {
            $row = $manualRowsById->get($id);
            if ($row !== null && ($row->state ?? null) !== 'excluded') {
                $writer->pin($site, $id, (float) $rank);
            }
        }
    }

    /**
     * The three mandatory lanes come from ManualServiceWriter::invalidate()
     * — BuildState::bump, site.sites.updated_at, the edge purge — never
     * re-implemented here. $site->touch() then covers what no observer can
     * fire for a raw content.* write (SiteObserver's Redis payload bust, edge
     * purge and cache warm), and invalidateServices() busts the OWNER's
     * dashboard list, which a staff edit must not leave stale.
     */
    private function invalidate(User $professional, Site $site, ManualServiceWriter $writer): void
    {
        $writer->invalidate([(string) $site->id]);
        $site->touch();
        app(UserCacheService::class)->invalidateServices($professional->id);
        $this->reevaluateVisibility($professional->id, (string) $site->id);
    }

    /**
     * A create/update/delete/restore can change whether ANY live, visible
     * service exists — the input the 'services'/'booking' Block is_enabled
     * gate reads. Mirrors ServiceObserver::reevaluateBooking(); the content.*
     * write paths bypass Eloquent, so that observer never fires for them.
     */
    private function reevaluateVisibility(string $userId, string $siteId): void
    {
        $service = app(SectionVisibilityService::class);
        foreach (['booking', 'services'] as $blockType) {
            $service->reevaluateEnabled($userId, $siteId, $blockType);
        }
    }

    /**
     * Append: one above the current highest PINNED position. Fractional keys
     * mean a later drag between two neighbours only rewrites the row that moved.
     */
    private function nextSortKey(?string $sectionId): float
    {
        if ($sectionId === null) {
            return 1.0;
        }

        $highest = DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $sectionId)
            ->where('state', 'pinned')
            ->max('sort_key');

        return $highest === null ? 1.0 : ((float) $highest) + 1.0;
    }

    /**
     * The membership ids a CREATE addressed — category_ids (multi) or the
     * legacy single category_id; [] when neither was supplied.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function requestedCategoryIds(array $data): array
    {
        $ids = $data['category_ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            $ids = ($data['category_id'] ?? null) !== null ? [$data['category_id']] : [];
        }

        return array_values(array_unique(array_map('strval', $ids)));
    }

    /**
     * An UPDATE's membership ids. Deliberately NOT requestedCategoryIds():
     * there, an empty category_ids falls back to category_id; here an
     * explicitly-supplied empty array is the caller REPLACING the membership
     * set with nothing ("move to Uncategorised"), which that fallback would
     * swallow. Same split UserServiceController draws.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function assignmentCategoryIds(array $data): array
    {
        return array_key_exists('category_ids', $data) && is_array($data['category_ids'])
            ? array_values(array_unique(array_map('strval', $data['category_ids'])))
            : (($data['category_id'] ?? null) !== null ? [(string) $data['category_id']] : []);
    }

    /**
     * A category that isn't this professional's own is an invalid input, not a
     * 404 — the same 422 vocabulary the pre-cutover
     * assertCategoryBelongsToProfessional() used. Resolution goes through
     * ServiceCollections::find(), which owns the owner-scoping predicate; no
     * hand-rolled copy of it lives in this file.
     */
    private function assertCollectionBelongsToProfessional(ServiceCollections $collections, string $userId, ?string $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        if ($collections->find($userId, $categoryId) === null) {
            abort(422, 'Category is invalid.');
        }
    }

    /** The same check against the legacy table, for the untouched Fresha branch. */
    private function assertLegacyCategoryBelongsToProfessional(string $userId, ?string $categoryId): void
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

    /**
     * Every id in $expected must appear in $provided (order-insensitive) — the
     * layout payload's "you must send me the whole set" rule, applied
     * separately to each category id space.
     *
     * @param  list<string>  $provided
     * @param  list<string>  $expected
     */
    private function assertCoversEvery(array $provided, array $expected): void
    {
        $provided = array_values(array_unique($provided));
        sort($provided);
        sort($expected);

        if ($provided !== $expected) {
            abort(422, 'Layout payload must include all category IDs (use one block with id=null for uncategorised).');
        }
    }
}
