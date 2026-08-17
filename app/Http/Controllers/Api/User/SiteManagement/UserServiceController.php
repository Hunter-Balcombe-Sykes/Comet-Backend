<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Services\ReorderServiceLayoutRequest;
use App\Http\Requests\Api\User\Services\ReorderServiceRequest;
use App\Http\Requests\Api\User\Services\StoreServiceRequest;
use App\Http\Requests\Api\User\Services\UpdateServiceCategoryAssignmentRequest;
use App\Http\Requests\Api\User\Services\UpdateServiceRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Http\Resources\ServiceResource;
use App\Models\Content\ManualOverride;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use App\Services\Cache\UserCacheService;
use App\Services\Content\FreshaServiceItems;
use App\Services\Content\ManualServiceItems;
use App\Services\Content\ManualServiceWriter;
use App\Services\Content\ServiceCollections;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Site\AdvisoryLock;
use App\Services\Site\AdvisoryLockTimeoutException;
use App\Services\User\SectionVisibilityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// V2: Service CRUD + reorder for professional's mini-site.
//
// Slice 3a (Task 5): index/store/show/update/destroy/reorder/restore/
// reorderLayout — the 8 owner-authored routes — read and write content.*
// through ManualServiceItems (read) / ManualServiceWriter (write), the same
// two collaborators ServiceBackfiller and SitepageDataResolverService use.
//
// Slice 3b (Task 10): resync/resyncBulk/updateCategory follow. An owner edit
// is a content.manual_overrides row, so resync DELETES those rows;
// category membership is content.collections/collection_items, written
// through ServiceCollections.
//
// Services cutover (2026-08-17): the §C2 legacy branches are GONE. Every verb
// resolves content.items ids only — owner-authored through ManualServiceItems
// (content.sources.kind='manual'), Fresha through FreshaServiceItems
// ('connection') — and a legacy site.services uuid resolves nowhere (spec
// ruling 1, recorded on the wire manifest). Ordering for both halves is
// site.section_items.sort_key; there is one id space and one scale.
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
        // single-flight. Content.* writes bypass Eloquent (ServiceObserver
        // never fires for them) so every write method below busts this key
        // explicitly; the Fresha (site.services) half is still model writes
        // and stays covered by ServiceObserver as before.
        if (! $includeArchived && ! $onlyArchived && ! $grouped) {
            return $this->success([
                'services' => app(UserCacheService::class)->getDashboardServices($pro->id),
                'filters' => [
                    'include_archived' => false,
                    'only_archived' => false,
                ],
            ]);
        }

        // The dashboard list is a MERGE of both halves, and since the services
        // cutover both come from content.*: owner-authored through
        // ManualServiceItems (kind='manual'), Fresha through
        // FreshaServiceItems (kind='connection'). One id space, one ordering
        // scale (site.section_items.sort_key), no legacy table.
        $manual = app(ManualServiceItems::class);
        $sectionId = $manual->sectionId($pro->site);
        $rows = $manual->rows($pro->id, $sectionId, includeRemoved: $includeArchived || $onlyArchived);
        if ($onlyArchived) {
            $rows = $rows->filter(fn ($row) => $row->removed_at !== null)->values();
        }
        $manualServices = $manual->toServiceModels($pro->id, $rows);

        $fresha = app(FreshaServiceItems::class);
        $freshaRows = $fresha->managementRows($pro->id, $sectionId, includeRemoved: $includeArchived || $onlyArchived);
        if ($onlyArchived) {
            $freshaRows = $freshaRows->filter(fn ($row) => $row->removed_at !== null)->values();
        }
        $freshaServices = $fresha->toServiceModels($pro->id, $freshaRows, $fresha->hiddenServiceIds($pro->id));

        // §NEW-I1 (review round 2): sort the MERGED collection by
        // sort_order, not a blind manual-then-Fresha concatenation — a
        // manual item's transient sort_order is populated from
        // section_items.sort_key (ManualServiceItems::hydrate()), and
        // reorder()/reorderLayout() number both halves from the same shared
        // position index on site.section_items.sort_key (spec §3.4), so this
        // reconstructs the caller's actual combined order instead of always
        // grouping every manual service ahead of every Fresha one.
        $limit = (int) config('partna.limits.pagination.services_max', 500);
        $services = $manualServices->concat($freshaServices)
            ->sortBy('sort_order')->values()->take($limit);

        if (! $grouped) {
            return $this->success([
                'services' => ServiceResource::collection($services),
                'filters' => [
                    'include_archived' => $includeArchived,
                    'only_archived' => $onlyArchived,
                ],
            ]);
        }

        // C1's two-id-space merge is retired with the space itself (services
        // cutover): every service's memberships point into content.collections
        // now, so listing that one space is listing all of them. The defect C1
        // fixed — a categorised service matching NO block AND failing the
        // `=== []` uncategorised filter, vanishing from the dashboard — cannot
        // recur while there is one space. Mirrors
        // StaffServiceManagementController::index(); the two dashboards must
        // not disagree about the same data.
        $collectionRows = app(ServiceCollections::class)
            ->list($pro->id, includeRemoved: $includeArchived || $onlyArchived);
        if ($onlyArchived) {
            $collectionRows = $collectionRows->filter(fn (object $row) => $row->removed_at !== null)->values();
        }
        // ServiceCollections rows carry no user_id column (every read is
        // already owner-scoped) but the wire has always exposed the field.
        $collectionRows = $collectionRows->map(function (object $row) use ($pro) {
            $row->user_id = (string) $pro->id;

            return $row;
        })->values();

        $categories = $collectionRows
            // The same cap /service-categories and both staff surfaces apply.
            ->take((int) config('partna.limits.pagination.service_categories_max', 200))
            ->values();

        // Multi-category: a service appears under EVERY category it belongs
        // to; zero memberships = Uncategorised. Transient (manual) Service
        // models carry a pre-set categories relation
        // (ManualServiceItems::hydrate()) holding their real content.*
        // memberships — never lazily loaded, so a content-item id is never
        // queried against site.service_category_assignments.
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

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        // SEC-1: user_id is no longer fillable — direct assignment so the policy still sees the owner.
        $skeleton = new Service;
        $skeleton->user_id = $pro->id;
        $this->authorizeForUser($pro, 'create', $skeleton);
        $data = $request->validated();

        // Category-at-creation is still not persisted. Ownership is asserted
        // for wire-compat (a foreign category id still 422s), but the id is
        // then dropped.
        //
        // CARRY FORWARD (Task 10 → whoever owns the create path next): the
        // reason this was safe has changed. It used to be that
        // ServicePolicy::updateCategory() blocked assignment outright, so
        // accepting one only at creation would have been a one-way door;
        // Task 10 opened assignment, so a create + immediate PATCH now
        // reaches a state a create alone cannot. Wiring it here is NOT a
        // one-liner: this validates against site.service_categories while
        // updateCategory's content path validates against
        // content.collections, and which id space the create endpoint
        // accepts is Task 9's call, not this task's.
        $categoryIds = $this->requestedCategoryIds($data);
        foreach ($categoryIds as $categoryId) {
            $this->assertCategoryBelongsToProfessional($pro->id, $categoryId);
        }

        $site = $this->currentSite($pro);
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
        $coord = 'manual:'.(string) Str::uuid();
        $itemId = $writer->write($pro->id, $coord, $writer->projectionFor($payload));

        $isActive = (bool) ($data['is_active'] ?? true);

        try {
            DB::connection('pgsql')->transaction(function () use ($pro, $site, $manual, $writer, $itemId, $isActive) {
                AdvisoryLock::acquire("services:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                if ($isActive) {
                    // Re-resolve the section id INSIDE the lock: a concurrent
                    // first-ever create for this professional could otherwise
                    // both observe "no section yet" and race to sort_key 1.0.
                    $sectionId = $manual->sectionId($site);
                    $writer->pin($site, $itemId, $this->nextSortKey($sectionId));
                } else {
                    $writer->exclude($site, $itemId);
                }
            });
        } catch (AdvisoryLockTimeoutException) {
            // I1: writeManualItem() above cannot run inside this (or any)
            // transaction (ProjectionWriter's own docblock forbids nesting
            // it), so it already committed a brand-new content item before
            // this lock was even attempted. Left alone, a lock timeout here
            // would report 423 ("failed") to the caller while a live item —
            // with no site.section_items row at all — sits in content.*;
            // absent curation reads as VISIBLE (buildServicesData()'s own
            // "no row = shown" rule), so it would render publicly, uncurated,
            // and invalidate() below would never run. Compensate by marking
            // it removed rather than leaving that orphan: this is the ONLY
            // caller of markRemoved() outside the explicit delete path, and
            // it exists solely to undo a create the caller was told failed.
            $writer->markRemoved($itemId);

            // U2: a background write (or another dashboard write) held the
            // services lock past the bound — expected contention, not a bug.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $writer->invalidate([(string) $site->id]);
        app(UserCacheService::class)->invalidateServices($pro->id);
        $this->reevaluateVisibility($pro->id, (string) $site->id);

        $row = $manual->find($pro->id, $itemId, $manual->sectionId($site));
        $model = $row !== null ? $manual->toServiceModel($pro->id, $row) : null;

        if ($model === null) {
            // Unreachable in practice: the item was just written live and
            // find() re-reads the same manual source. Loud rather than a
            // silent 500 with no context if it ever is.
            Log::error('Service store: item vanished immediately after write', ['item_id' => $itemId, 'user_id' => $pro->id]);
            abort(500, 'Service could not be read back after creation.');
        }

        return $this->success(['service' => new ServiceResource($model)], 201);
    }

    public function show(Request $request, string $service): JsonResponse
    {
        $pro = $this->currentUser($request);
        $manual = app(ManualServiceItems::class);

        $row = $manual->find($pro->id, $service, $manual->sectionId($pro->site));
        if ($row !== null) {
            $model = $manual->toServiceModel($pro->id, $row);
            $this->authorizeForUser($pro, 'view', $model);

            return $this->success(['service' => new ServiceResource($model)]);
        }

        // Fresha half: a connection-sourced content item (spec §3.2). Legacy
        // site.services uuids are gone — ruling 1: they 404 by being
        // unaddressable, and the wire manifest records the break. Not
        // includeRemoved: matches the pre-cutover implicit route-model
        // binding, which never resolved a soft-deleted row for GET either.
        $fresha = app(FreshaServiceItems::class);
        $row = $fresha->findRow($pro->id, $service, $manual->sectionId($pro->site));
        if ($row === null) {
            abort(404, 'Service not found.');
        }

        $model = $fresha->toServiceModel($pro->id, $row, $fresha->hiddenServiceIds($pro->id));
        $this->authorizeForUser($pro, 'view', $model);

        return $this->success(['service' => new ServiceResource($model)]);
    }

    public function update(UpdateServiceRequest $request, string $service): JsonResponse
    {
        $pro = $this->currentUser($request);
        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);

        $row = $manual->find($pro->id, $service, $manual->sectionId($pro->site));

        if ($row === null) {
            return $this->updateFresha($request, $pro, $service);
        }

        $current = $manual->toServiceModel($pro->id, $row);
        $this->authorizeForUser($pro, 'update', $current);

        $data = $request->validated();

        // Merge onto the CURRENT projected values — every UpdateServiceRequest
        // field is `sometimes`, so an is_active-only PATCH must not blank out
        // whatever content.* already carries for the fields it didn't send.
        $payload = (object) [
            'title' => $data['title'] ?? $current->title,
            'description' => array_key_exists('description', $data) ? $data['description'] : $current->description,
            'price_cents' => $data['price_cents'] ?? $current->price_cents,
            'currency_code' => $data['currency_code'] ?? $current->currency_code,
            'duration_minutes' => array_key_exists('duration_minutes', $data) ? $data['duration_minutes'] : $current->duration_minutes,
        ];

        $site = $this->currentSite($pro);

        // Same coord as the original write — writeManualItem() is idempotent
        // on it, so this UPDATES the existing item rather than minting a
        // second one. Falls back to the legacy-shaped coord for a row this
        // request itself is about to create a source_item for (defensive;
        // every item reached through find() already has one).
        $coord = $writer->coordFor($pro->id, (string) $row->id) ?? ('manual:'.$row->id);
        // B1: fields the caller actually sent THIS request — not $payload's
        // keys, which are always fully populated by the merge above. Tells
        // projectionFor() which null values are an explicit clear (write it)
        // versus an untouched field merely carrying its current value
        // forward (leave the facet alone if that value happens to be null).
        $forceFacets = array_intersect(array_keys($data), ['description', 'duration_minutes']);
        $itemId = $writer->write($pro->id, $coord, $writer->projectionFor($payload, $forceFacets));

        // is_active moves the row between pin (visible) and exclude (hidden)
        // — content.* has no boolean column for it. Always re-applied
        // (idempotent either way) so a content-only edit reaffirms the
        // current state instead of silently drifting from it.
        $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $current->is_active;
        $wasPinned = ($row->state ?? null) === 'pinned';

        try {
            DB::connection('pgsql')->transaction(function () use ($pro, $site, $manual, $writer, $itemId, $isActive, $wasPinned, $row) {
                AdvisoryLock::acquire("services:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                if ($isActive) {
                    // Already pinned: keep its existing position. Newly
                    // activated (was excluded, or never curated): append.
                    $sortKey = $wasPinned && $row->sort_key !== null
                        ? (float) $row->sort_key
                        : $this->nextSortKey($manual->sectionId($site));
                    $writer->pin($site, $itemId, $sortKey);
                } else {
                    $writer->exclude($site, $itemId);
                }
            });
        } catch (AdvisoryLockTimeoutException) {
            // I1: unlike store(), no orphan compensation needed here — the
            // item already existed with a valid pin/exclude row BEFORE this
            // request (every item reached through find() is already
            // curated), and this catch fires before that row is touched, so
            // it simply keeps its last-good state. The content write above
            // (writer->write()) may have already committed the new
            // title/price/etc — a genuine partial-failure window (the
            // caller sees 423 but the content changed) that a full fix would
            // need to snapshot-and-revert; accepted as milder than store()'s
            // "brand new uncurated public item" risk, which is the one this
            // exception exists to prevent.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $writer->invalidate([(string) $site->id]);
        app(UserCacheService::class)->invalidateServices($pro->id);
        $this->reevaluateVisibility($pro->id, (string) $site->id);

        $freshRow = $manual->find($pro->id, $itemId, $manual->sectionId($site));
        $freshModel = $manual->toServiceModel($pro->id, $freshRow);

        return $this->success(['service' => new ServiceResource($freshModel)]);
    }

    /**
     * POST /services/{service}/resync — revert one owner-edited ("sync
     * broken") Fresha service back to what the connector last synced.
     *
     * Slice 3b §3.5: the verb maps exactly, it is not a new concept. In
     * content.* an owner edit IS a `content.manual_overrides` row (one per
     * frozen column — see App\Models\Content\ManualOverride), so reverting
     * is DELETING those rows: the per-source facet values the connector
     * wrote are already there and speak again the moment the override is
     * gone. Nothing is restored from a stored raw scrape, because nothing
     * was ever overwritten.
     *
     * The 422 keeps its exact meaning too — "no longer offered on Fresha" is
     * "the item has no LIVE content.source_items row on a connection
     * source". The owner's edit is deliberately left in place in that case:
     * it is the only copy of that service left, and the error copy says so.
     *
     * §C2 (slice 3a): a legacy `site.services` id stays addressable. The
     * legacy branch below is unchanged and still reaches the 61 untouched
     * Fresha rows that live there until slice 7 drops the table.
     */
    public function resync(Request $request, string $service): JsonResponse
    {
        $pro = $this->currentUser($request);
        $manual = app(ManualServiceItems::class);

        // Owner-authored content item: never synced from anywhere, so there
        // is nothing to revert to. Same 422 (and same copy) the pre-cutover
        // code returned for a `source IS NULL` site.services row.
        $manualRow = $manual->find($pro->id, $service, $manual->sectionId($pro->site));
        if ($manualRow !== null) {
            $this->authorizeForUser($pro, 'update', $manual->toServiceModel($pro->id, $manualRow));

            return $this->error('Only Fresha-synced services can be resynced.', 422);
        }

        // Fresha content item. Resolved WITHOUT the live-source filter first
        // so a service that left the vendor's menu answers 422 (the real
        // outcome) rather than 404 (a nonexistent one).
        $fresha = app(FreshaServiceItems::class);
        $row = $fresha->findRow($pro->id, $service, null, liveOnly: false);
        if ($row === null) {
            abort(404, 'Service not found.');
        }

        $liveRow = $fresha->findRow($pro->id, $service, null);
        $this->authorizeForUser($pro, 'update', $fresha->toServiceModel($pro->id, $liveRow ?? $row));

        if ($liveRow === null) {
            return $this->error('This service is no longer offered on Fresha — keep your edited version or delete it.', 422);
        }

        // Already live-synced (no override) — idempotent success, same as
        // the pre-cutover `! $service->is_manual` early return.
        if (ManualOverride::query()->where('item_id', (string) $liveRow->id)->exists()) {
            ManualOverride::query()->where('item_id', (string) $liveRow->id)->delete();
            // The blob folds overrides now (Task 2), so dropping them must
            // recompose it — otherwise the booking wire keeps serving the
            // owner's reverted text until the next refresh.
            app(FreshaServiceProjector::class)->refreshBlob($pro);
            $this->invalidateAfterResync($pro);
        }

        // Re-hydrated from the same row, not re-read: deleting an override
        // changes no column ON the row (headline_cache is recomputed by the
        // projection path, not here) — only is_manual, which toServiceModel()
        // derives live from the override rows.
        return $this->success(['service' => new ServiceResource($fresha->toServiceModel($pro->id, $liveRow))]);
    }

    // POST /services/resync — bulk revert: the given ids, or EVERY owner-edited
    // Fresha service when ids are omitted. Services that left Fresha are
    // skipped (nothing to revert to) and reported. Both halves are counted
    // into ONE pair of totals — the response shape is two integers and stays
    // two integers, whichever store the professional's rows live in.
    public function resyncBulk(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        // SEC-1: user_id is not mass-assignable — direct assignment so the policy sees the owner.
        $skeleton = new Service;
        $skeleton->user_id = $pro->id;
        $this->authorizeForUser($pro, 'update', $skeleton);

        $validated = $request->validate([
            'ids' => ['sometimes', 'array', 'max:500'],
            'ids.*' => ['uuid'],
        ]);
        $ids = $validated['ids'] ?? [];

        // Every Fresha service item this professional owns that carries at
        // least one override. An item with no override was never detached and
        // is not a candidate — the exact set the legacy `is_manual = true`
        // filter selected.
        $fresha = app(FreshaServiceItems::class);
        $candidates = $fresha->overriddenItemIds($pro->id, $ids);
        $live = array_flip($fresha->liveItemIds($pro->id, $candidates));

        $revertable = array_values(array_filter($candidates, fn ($id) => isset($live[$id])));
        $skipped = count($candidates) - count($revertable);
        $resynced = 0;

        if ($revertable !== []) {
            ManualOverride::query()->whereIn('item_id', $revertable)->delete();
            $resynced = count($revertable);
            app(FreshaServiceProjector::class)->refreshBlob($pro);
            $this->invalidateAfterResync($pro);
        }

        return $this->success(['resynced' => $resynced, 'skipped' => $skipped]);
    }

    // Move a single service into a category (or Uncategorized). Deliberately
    // separate from update(): this endpoint's only writable input is
    // category_id, so it never re-exposes the raw sort_order field update()
    // accepts. Both halves file into content.collections now — the legacy
    // append at max(sort_order)+1 went with the table, and with it the
    // advisory lock that existed solely to serialise that append against the
    // global services_user_sort_order_uq.
    public function updateCategory(UpdateServiceCategoryAssignmentRequest $request, string $service): JsonResponse
    {
        $pro = $this->currentUser($request);
        $manual = app(ManualServiceItems::class);

        $row = $manual->find($pro->id, $service, $manual->sectionId($pro->site));
        if ($row !== null) {
            return $this->assignOwnerServiceCategory($request, $pro, $manual, $row);
        }

        // Fresha half (spec §3.2): same collections space, same owner
        // membership lane — a Fresha service may be filed under any of the
        // owner's service-category collections now that both halves share one
        // id space. The projector can never delete this row (its
        // replace-by-source delete is scoped to the connection source), and
        // the reads prefer it, so the choice survives every sync.
        $fresha = app(FreshaServiceItems::class);
        $freshaRow = $fresha->findRow($pro->id, $service, $manual->sectionId($pro->site));
        if ($freshaRow === null) {
            abort(404, 'Service not found.');
        }

        $this->authorizeForUser($pro, 'updateCategory', $fresha->toServiceModel($pro->id, $freshaRow));

        $collections = app(ServiceCollections::class);
        $categoryIds = $this->assignmentCategoryIds($request->validated());
        foreach ($categoryIds as $categoryId) {
            if ($collections->find($pro->id, $categoryId) === null) {
                abort(422, 'Category is invalid.');
            }
        }

        $site = $this->currentSite($pro);
        $collections->assign($pro->id, (string) $freshaRow->id, $categoryIds[0] ?? null, null);

        app(ManualServiceWriter::class)->invalidate([(string) $site->id]);
        app(UserCacheService::class)->invalidateServices($pro->id);

        $freshRow = $fresha->findRow($pro->id, (string) $freshaRow->id, $manual->sectionId($site));

        return $this->success(['service' => new ServiceResource(
            $fresha->toServiceModel($pro->id, $freshRow ?? $freshaRow, $fresha->hiddenServiceIds($pro->id)),
        )]);
    }

    /**
     * updateCategory()'s content.* half: an owner-authored service's
     * membership, written through ServiceCollections (Task 8) and nothing
     * else — a second hand-rolled writer for content.collection_items is
     * exactly the duplication that produced three of slice 3a's
     * final-review blockers.
     *
     * No advisory lock and no sort_order append here, unlike the legacy
     * branch: both exist to keep `services_user_sort_order_uq` (global per
     * user) satisfiable, and an owner-authored service has no
     * `site.services` row to renumber. Its ordering is
     * `site.section_items.sort_key`, which category assignment does not
     * touch — a re-file must not silently move a service in the owner's
     * chosen order.
     *
     * ServiceCollections::assign() is single-collection per source by
     * design (its rule 4 replaces the item's memberships for that source),
     * so the multi-id `category_ids` spelling collapses to its first entry
     * on this path. The response is built from a re-read, so it reports what
     * was actually stored rather than what was asked for.
     */
    private function assignOwnerServiceCategory(
        UpdateServiceCategoryAssignmentRequest $request,
        User $pro,
        ManualServiceItems $manual,
        object $row,
    ): JsonResponse {
        $this->authorizeForUser($pro, 'updateCategory', $manual->toServiceModel($pro->id, $row));

        $collections = app(ServiceCollections::class);
        $categoryIds = $this->assignmentCategoryIds($request->validated());
        foreach ($categoryIds as $categoryId) {
            // Same 422 vocabulary as the legacy branch's
            // assertCategoryBelongsToProfessional(): a category that isn't
            // this professional's own is an invalid input, not a 404.
            if ($collections->find($pro->id, $categoryId) === null) {
                abort(422, 'Category is invalid.');
            }
        }

        $site = $this->currentSite($pro);
        $writer = app(ManualServiceWriter::class);

        // null source_id = the owner-authored membership lane, matching
        // ProjectionWriter's replace-by-source semantics: a connector can
        // never delete this row, and this can never delete a connector's.
        $collections->assign($pro->id, (string) $row->id, $categoryIds[0] ?? null, null);

        // ServiceCollections deliberately does not self-invalidate (it holds
        // no site context), and this write changes what the public page
        // renders — ManualServiceItems::publicList() reads the label. No CI
        // check enforces this; a miss serves a stale page until TTL.
        $writer->invalidate([(string) $site->id]);
        app(UserCacheService::class)->invalidateServices($pro->id);

        $freshRow = $manual->find($pro->id, (string) $row->id, $manual->sectionId($site));
        $freshModel = $manual->toServiceModel($pro->id, $freshRow ?? $row);

        return $this->success(['service' => new ServiceResource($freshModel)]);
    }

    /**
     * update()'s Fresha branch (spec §3.2). An owner edit IS a
     * content.manual_overrides row per field (the C2-compliant lock);
     * is_active rides the blob's hiddenServiceIds; price is vendor-owned
     * (D3a) and an edit 422s explicitly rather than silently reverting on the
     * public booking wire. Categories go through the owner membership lane
     * exactly as updateCategory()'s branch does.
     */
    private function updateFresha(UpdateServiceRequest $request, User $pro, string $service): JsonResponse
    {
        $manual = app(ManualServiceItems::class);
        $fresha = app(FreshaServiceItems::class);
        $sectionId = $manual->sectionId($pro->site);

        $row = $fresha->findRow($pro->id, $service, $sectionId);
        if ($row === null) {
            abort(404, 'Service not found.');
        }

        $current = $fresha->toServiceModel($pro->id, $row, $fresha->hiddenServiceIds($pro->id));
        $this->authorizeForUser($pro, 'update', $current);

        $data = $request->validated();

        // D3a (owner ruling 2026-08-16): an edited PRICE has no content.*
        // home — offers are a set-union collection and FacetRegistry excludes
        // collections from manual_overrides by design. An echo of the current
        // price passes; a change is an explicit 422, never a silent revert.
        if (array_key_exists('price_cents', $data) && (int) $data['price_cents'] !== (int) $current->price_cents) {
            return $this->error('Fresha prices come from Fresha and cannot be edited here.', 422);
        }

        if (array_key_exists('category_id', $data) || array_key_exists('category_ids', $data)) {
            $collections = app(ServiceCollections::class);
            $categoryIds = $this->assignmentCategoryIds($data);
            foreach ($categoryIds as $categoryId) {
                if ($collections->find($pro->id, $categoryId) === null) {
                    abort(422, 'Category is invalid.');
                }
            }
            // Owner lane (null source): survives every projector run, and the
            // reads prefer it over the connection lane's memberships.
            $collections->assign($pro->id, (string) $row->id, $categoryIds[0] ?? null, null);
        }

        foreach ([
            'title' => ['f_text', 'headline', fn ($v) => (string) $v],
            'description' => ['f_text', 'body', fn ($v) => $v],
            'duration_minutes' => ['f_duration', 'seconds', fn ($v) => $v === null ? null : ((int) $v) * 60],
        ] as $field => [$facet, $column, $transform]) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $override = ManualOverride::query()
                ->where('item_id', (string) $row->id)
                ->where('facet', $facet)
                ->where('column_name', $column)
                ->first() ?? new ManualOverride;
            $override->item_id = (string) $row->id;
            $override->facet = $facet;
            $override->column_name = $column;
            $override->value = $transform($data[$field]);
            $override->save();
        }

        if (array_key_exists('is_active', $data)) {
            app(FreshaServiceProjector::class)->setHidden($pro, (string) $row->record_key, ! (bool) $data['is_active']);
        }

        app(FreshaServiceProjector::class)->refreshBlob($pro);
        $this->invalidateAfterResync($pro);

        $freshRow = $fresha->findRow($pro->id, (string) $row->id, $sectionId);

        return $this->success(['service' => new ServiceResource(
            $fresha->toServiceModel($pro->id, $freshRow ?? $row, $fresha->hiddenServiceIds($pro->id)),
        )]);
    }

    public function destroy(Request $request, string $service): JsonResponse
    {
        $pro = $this->currentUser($request);
        $manual = app(ManualServiceItems::class);

        $row = $manual->find($pro->id, $service, $manual->sectionId($pro->site));

        if ($row === null) {
            // Fresha half: owner delete = items.removed_at (one-way — the
            // projection path never touches it, so a reappearing scrape
            // cannot resurrect it; spec §3.3, the content.* home of what
            // deleted_origin='user' used to carry). NEVER
            // source_items.removed_at, which IS cleared on reappearance.
            $fresha = app(FreshaServiceItems::class);
            $freshaRow = $fresha->findRow($pro->id, $service, $manual->sectionId($pro->site));
            if ($freshaRow === null) {
                abort(404, 'Service not found.');
            }

            $this->authorizeForUser($pro, 'delete', $fresha->toServiceModel($pro->id, $freshaRow));

            $site = $this->currentSite($pro);
            app(ManualServiceWriter::class)->markRemoved((string) $freshaRow->id);
            app(FreshaServiceProjector::class)->refreshBlob($pro);
            app(ManualServiceWriter::class)->invalidate([(string) $site->id]);
            app(UserCacheService::class)->invalidateServices($pro->id);
            $this->reevaluateVisibility($pro->id, (string) $site->id);

            return $this->success(['deleted' => true]);
        }

        $model = $manual->toServiceModel($pro->id, $row);
        $this->authorizeForUser($pro, 'delete', $model);

        $site = $this->currentSite($pro);
        $writer = app(ManualServiceWriter::class);
        // items.removed_at ONLY — NEVER source_items.removed_at, which is
        // cleared on reappearance and would resurrect a service its owner
        // deleted (parent spec §3.1/§3.5).
        $writer->markRemoved((string) $row->id);
        $writer->invalidate([(string) $site->id]);
        app(UserCacheService::class)->invalidateServices($pro->id);
        $this->reevaluateVisibility($pro->id, (string) $site->id);

        return $this->success(['deleted' => true]);
    }

    // Flat reorder. Both halves live in content.* now, so an id is looked up
    // in whichever READ owns it — ManualServiceItems (kind='manual') or
    // FreshaServiceItems (kind='connection') — and an id neither recognises
    // 422s, same as the pre-cutover single-table check.
    //
    // §NEW-C1/I1 survives the cutover unchanged in substance: both halves are
    // positioned from ONE shared index in the SAME transaction, never a
    // per-half renumber, so a merged read reconstructs the interleaved order
    // the caller actually submitted instead of grouping one half ahead of the
    // other. What changed is where that index is written — site.section_items
    // .sort_key on the services section for both halves (spec §3.4), rather
    // than sort_key for one and the global site.services.sort_order for the
    // other. The uniqueness hazard that drove the original note goes with the
    // table: sort_key carries no unique constraint.
    public function reorder(ReorderServiceRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);

        // SEC-6: pending-deletion + ownership gate via ServicePolicy —
        // restored; this was dropped by mistake in the content.* cutover.
        $skeleton = new Service;
        $skeleton->user_id = $pro->id;
        $this->authorizeForUser($pro, 'update', $skeleton);

        $manual = app(ManualServiceItems::class);
        $fresha = app(FreshaServiceItems::class);
        $writer = app(ManualServiceWriter::class);
        $ids = $request->input('ids', []);

        $sectionId = $manual->sectionId($site);
        $manualRowsById = $manual->rows($pro->id, $sectionId, includeRemoved: false)
            ->keyBy(fn ($r) => (string) $r->id);
        $freshaRowsById = $fresha->managementRows($pro->id, $sectionId)
            ->keyBy(fn ($r) => (string) $r->id);

        foreach ($ids as $id) {
            if (! $manualRowsById->has($id) && ! $freshaRowsById->has($id)) {
                abort(422, 'One or more items are invalid.');
            }
        }

        // The submitted ids first, then every other live id (manual OR
        // Fresha) this request didn't mention, in its current relative
        // order — one authority, one numbering scale (spec §3.4).
        $remainder = array_values(array_diff(
            [...$manualRowsById->keys()->all(), ...$freshaRowsById->keys()->all()],
            $ids,
        ));
        $fullOrder = array_merge($ids, $remainder);

        try {
            DB::connection('pgsql')->transaction(function () use ($pro, $site, $writer, $manualRowsById, $freshaRowsById, $fullOrder) {
                AdvisoryLock::acquire("services:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                // ONE loop, both halves: sort_key on the services section is
                // the shared scale. Excluded (hidden) MANUAL items carry no
                // sort_key by design (ManualServiceWriter::exclude) —
                // accepted (no 422) but left unpositioned until update()
                // re-pins them. Fresha items are always pinnable: their
                // hidden state rides the blob, not section state.
                //
                // Landmine, named on purpose: the pool DSL reads 'pinned' as
                // curated-in and the services pool rule is kind_is only
                // (PoolRegistry). The PUBLIC read is source-kind-filtered
                // (ManualServiceItems joins cs.kind = 'manual') so nothing
                // leaks today — but if the site.site_documents lane ever
                // becomes publicly readable, the services candidates rule
                // must gain a source-kind guard FIRST (slice 6 spec §4.3).
                foreach ($fullOrder as $rank => $id) {
                    $manualRow = $manualRowsById->get($id);
                    if ($manualRow !== null && ($manualRow->state ?? null) !== 'excluded') {
                        $writer->pin($site, $id, (float) $rank);
                    } elseif ($freshaRowsById->has($id)) {
                        $writer->pin($site, $id, (float) $rank);
                    }
                }
            });
        } catch (AdvisoryLockTimeoutException) {
            // U2: same contention/423 as store() above.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $writer->invalidate([(string) $site->id]);
        // EDGE-1: the pins above are Eloquent writes on site.section_items,
        // not on Service, so ServiceObserver never fires — touch explicitly
        // (fires SiteObserver: Redis + Cloudflare + cache warm), mirroring
        // what ReorderService's afterCommit callback used to do for this
        // exact reorder.
        $site->touch();
        app(UserCacheService::class)->invalidateServices($pro->id);

        return $this->success(['ok' => true]);
    }

    /**
     * Full layout reorder (categories + services).
     *
     * ONE category id space (`content.collections`, repositioned through
     * `ServiceCollections::reposition()`) and ONE service id space
     * (`content.items`) — the services cutover ended the dual-space era, so
     * the C1-era per-space validation, the cross-space 422s and the
     * `site.service_categories.sort_order` renumber are all gone. This mirrors
     * `StaffServiceManagementController::reorderLayout()` method for method —
     * the two surfaces run one approach over one dataset, rather than two
     * implementations that agree until they don't.
     *
     * NO MEMBERSHIPS ARE WRITTEN HERE, only ORDER — identical to the staff
     * twin, deliberately. `PATCH /services/{id}/category` is the writer of
     * `content.collection_items`; a layout endpoint that also rewrote them
     * would silently re-file a service any time a UI round-tripped it
     * through a different block.
     *
     * Every one of the owner's live services — Fresha AND manual — must be
     * covered by the payload, and every collection must appear.
     */
    public function reorderLayout(ReorderServiceLayoutRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);

        // SEC-6: pending-deletion + ownership gate via ServicePolicy,
        // matching this controller's own store()/update()/destroy()/reorder().
        $skeleton = new Service;
        $skeleton->user_id = $pro->id;
        $this->authorizeForUser($pro, 'update', $skeleton);

        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);
        $collections = app(ServiceCollections::class);

        $payload = $request->validated();

        try {
            DB::connection('pgsql')->transaction(function () use ($pro, $site, $manual, $writer, $collections, $payload) {
                AdvisoryLock::acquire("services:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                // ONE category id space (content.collections) and ONE service
                // id space (content.items) — read inside the lock, like every
                // other set here, so a concurrent write can't make the
                // coverage check disagree with what is then repositioned.
                $collectionIds = $collections->list($pro->id)->map(fn ($row) => (string) $row->id)->all();
                $collectionSet = array_flip($collectionIds);

                $sectionId = $manual->sectionId($site);
                $manualRowsById = $manual->rows($pro->id, $sectionId, includeRemoved: false)
                    ->keyBy(fn ($r) => (string) $r->id);
                $freshaRowsById = app(FreshaServiceItems::class)->managementRows($pro->id, $sectionId)
                    ->keyBy(fn ($r) => (string) $r->id);

                $orderedCollectionIds = [];
                $seenPerBlock = [];
                $idsSeen = [];

                foreach ($payload['categories'] as $bi => $catBlock) {
                    $catId = $catBlock['id'] ?? null;
                    if ($catId !== null) {
                        if (! isset($collectionSet[$catId])) {
                            abort(422, 'One or more category IDs are invalid.');
                        }
                        $orderedCollectionIds[] = $catId;
                    }

                    foreach ($catBlock['service_ids'] as $sid) {
                        if (! $manualRowsById->has($sid) && ! $freshaRowsById->has($sid)) {
                            abort(422, 'One or more service IDs are invalid.');
                        }
                        // Multi-category: a service MAY appear in several
                        // category blocks, but never twice in the same one.
                        if (isset($seenPerBlock[$bi][$sid])) {
                            abort(422, 'Duplicate service IDs detected within a category block.');
                        }
                        $seenPerBlock[$bi][$sid] = true;
                        $idsSeen[$sid] = true;
                    }
                }

                // Every live service — either half — must appear somewhere.
                // One check now, not two: the cross-space 422s ("a Fresha
                // service cannot be filed under an owner-authored category")
                // described a mismatch that can no longer exist.
                if (count($idsSeen) !== $manualRowsById->count() + $freshaRowsById->count()) {
                    abort(422, 'Layout payload must include all service IDs for this professional.');
                }
                $this->assertCoversEveryCategory($orderedCollectionIds, $collectionIds);

                if ($orderedCollectionIds !== []) {
                    $collections->reposition($pro->id, array_values(array_unique($orderedCollectionIds)));
                }

                // NO MEMBERSHIPS ARE WRITTEN HERE, only ORDER — unchanged
                // stance (slice 7 Task 12): PATCH /services/{id}/category is
                // the writer of content.collection_items.
                $orderedAllServiceIds = [];
                foreach ($payload['categories'] as $catBlock) {
                    foreach ($catBlock['service_ids'] as $serviceId) {
                        if (! in_array($serviceId, $orderedAllServiceIds, true)) {
                            $orderedAllServiceIds[] = $serviceId;
                        }
                    }
                }

                // Both halves, one traversal, one scale (spec §3.4). Excluded
                // (hidden) MANUAL items carry no sort_key by design and stay
                // unpositioned; a Fresha item's hidden state rides the blob,
                // so it is always pinnable.
                foreach ($orderedAllServiceIds as $rank => $sid) {
                    $manualRow = $manualRowsById->get($sid);
                    if ($manualRow !== null && ($manualRow->state ?? null) !== 'excluded') {
                        $writer->pin($site, $sid, (float) $rank);
                    } elseif ($freshaRowsById->has($sid)) {
                        $writer->pin($site, $sid, (float) $rank);
                    }
                }
            });
        } catch (AdvisoryLockTimeoutException) {
            // Same contention/423 as store()/reorder() above.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        // EDGE-1 (audit): reorderLayout bypasses ReorderService entirely (raw
        // DB::transaction + per-row query-builder update()), so no observer
        // ever fires here either — touch explicitly after commit for the
        // Fresha half, same reasoning as reorder() above. The manual half's
        // own three lanes fire via invalidate() below.
        $site->touch();
        $writer->invalidate([(string) $site->id]);
        app(UserCacheService::class)->invalidateServices($pro->id);

        return $this->success(['ok' => true]);
    }

    public function restore(Request $request, string $service): JsonResponse
    {
        $pro = $this->currentUser($request);
        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);

        $sectionId = $manual->sectionId($pro->site);
        $row = $manual->find($pro->id, $service, $sectionId, includeRemoved: true);

        if ($row === null) {
            // Fresha half: clearing items.removed_at is the owner explicitly
            // un-deleting. liveOnly: false so a service that ALSO departed
            // the vendor's menu (source_items.removed_at) is still
            // addressable here — the two removals are independent, and only
            // this one is the owner's to undo.
            $fresha = app(FreshaServiceItems::class);
            $freshaRow = $fresha->findRow($pro->id, $service, $sectionId, includeRemoved: true, liveOnly: false);
            if ($freshaRow === null) {
                abort(404, 'Service not found.');
            }

            $model = $fresha->toServiceModel($pro->id, $freshaRow);
            $this->authorizeForUser($pro, 'update', $model);

            if ($freshaRow->removed_at === null) {
                return $this->success(['restored' => true, 'service' => new ServiceResource($model)]);
            }

            $site = $this->currentSite($pro);
            $freshaWriter = app(ManualServiceWriter::class);
            $freshaWriter->clearRemoved((string) $freshaRow->id);
            app(FreshaServiceProjector::class)->refreshBlob($pro);
            $freshaWriter->invalidate([(string) $site->id]);
            app(UserCacheService::class)->invalidateServices($pro->id);
            $this->reevaluateVisibility($pro->id, (string) $site->id);

            $freshRow = $fresha->findRow($pro->id, (string) $freshaRow->id, $sectionId, includeRemoved: true, liveOnly: false);

            return $this->success(['restored' => true, 'service' => new ServiceResource(
                $fresha->toServiceModel($pro->id, $freshRow ?? $freshaRow, $fresha->hiddenServiceIds($pro->id)),
            )]);
        }

        $model = $manual->toServiceModel($pro->id, $row);
        $this->authorizeForUser($pro, 'update', $model);

        if ($row->removed_at === null) {
            return $this->success(['restored' => true, 'service' => new ServiceResource($model)]);
        }

        $site = $this->currentSite($pro);
        // Spec §3.5: legitimate ONLY from this explicit endpoint — the
        // one-way rule that stops a reappearing scrape resurrecting a
        // user-deleted row lives in ProjectionWriter and is untouched here.
        // No sort_key recompute needed (unlike the legacy sort_order
        // column): section_items.sort_key carries no uniqueness constraint,
        // so the item's pre-deletion curation state (pin+position, or
        // exclude) is safe to leave exactly as it was.
        $writer->clearRemoved((string) $row->id);
        $writer->invalidate([(string) $site->id]);
        app(UserCacheService::class)->invalidateServices($pro->id);
        $this->reevaluateVisibility($pro->id, (string) $site->id);

        $freshRow = $manual->find($pro->id, (string) $row->id, $sectionId, includeRemoved: true);
        $freshModel = $manual->toServiceModel($pro->id, $freshRow);

        return $this->success(['restored' => true, 'service' => new ServiceResource($freshModel)]);
    }

    /**
     * The membership ids a write addressed — category_ids (multi) or the
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
     * updateCategory()'s own id extraction, shared by both its branches.
     * Deliberately NOT requestedCategoryIds() (store()'s): there, an empty
     * `category_ids` falls back to `category_id`; here an explicitly-supplied
     * empty array is the caller REPLACING the membership set with nothing
     * ("move to Uncategorised"), which the fallback would swallow.
     *
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    private function assignmentCategoryIds(array $validated): array
    {
        return array_key_exists('category_ids', $validated) && is_array($validated['category_ids'])
            ? array_values(array_unique(array_map('strval', $validated['category_ids'])))
            : (($validated['category_id'] ?? null) !== null ? [(string) $validated['category_id']] : []);
    }

    /**
     * Deleting an override changes what the public page renders (the synced
     * values speak again), and the deletes above are raw — no observer, no
     * BuildState bump of their own. The site is resolved lazily, only once a
     * content-half resync actually happened, so a professional whose rows are
     * all legacy never trips currentSite()'s "no site" validation error on a
     * path that never needed a site before.
     */
    private function invalidateAfterResync(User $pro): void
    {
        $site = $this->currentSite($pro);
        app(ManualServiceWriter::class)->invalidate([(string) $site->id]);
        app(UserCacheService::class)->invalidateServices($pro->id);
    }

    /**
     * Every id in $expected must appear in $provided (order-insensitive) —
     * the layout payload's "send me the whole set" rule, applied separately
     * to each category id space so a payload covering one space fully and the
     * other not at all is still rejected. Same helper, same message, as
     * StaffServiceManagementController::assertCoversEvery().
     *
     * @param  list<string>  $provided
     * @param  list<string>  $expected
     */
    private function assertCoversEveryCategory(array $provided, array $expected): void
    {
        $provided = array_values(array_unique($provided));
        sort($provided);
        sort($expected);

        if ($provided !== $expected) {
            abort(422, 'Layout payload must include all category IDs (use one block with id=null for uncategorised).');
        }
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

    /**
     * Append: one above the current highest PINNED position. Fractional
     * keys (site.section_items.sort_key) mean a later drag between two
     * neighbours only ever rewrites the row that moved.
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
     * A create/update/delete/restore can change whether ANY live, visible
     * manual service exists — the input the 'services'/'booking' Block's
     * `is_enabled` gate reads (spec §3.4: BookingVisibility keeps its
     * current meaning, "at least one active manual service"). Mirrors
     * ServiceObserver::reevaluateBooking() — the content.* write paths
     * bypass Eloquent, so that observer never fires for them, and nothing
     * else reevaluates this gate on their behalf.
     */
    private function reevaluateVisibility(string $userId, string $siteId): void
    {
        $service = app(SectionVisibilityService::class);
        foreach (['booking', 'services'] as $blockType) {
            $service->reevaluateEnabled($userId, $siteId, $blockType);
        }
    }
}
