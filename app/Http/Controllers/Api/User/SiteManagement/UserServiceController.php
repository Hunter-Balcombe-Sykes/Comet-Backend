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
// through ServiceCollections. Each keeps a §C2 legacy branch for the
// site.services rows that live on until slice 7 drops the table.
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

        // §C2: the dashboard list is a MERGE of both halves — owner-authored
        // from content.*, Fresha (site.services WHERE source IS NOT NULL,
        // untouched, 3b's rows) from the legacy table. site.services still
        // physically holds every pre-cutover manual row too, but those are
        // superseded by their content.* projection and never read from here
        // again — the `whereNull('source')` half of the old query is gone
        // for good, not merged back in.
        $manual = app(ManualServiceItems::class);
        $sectionId = $manual->sectionId($pro->site);
        $rows = $manual->rows($pro->id, $sectionId, includeRemoved: $includeArchived || $onlyArchived);
        if ($onlyArchived) {
            $rows = $rows->filter(fn ($row) => $row->removed_at !== null)->values();
        }
        $manualServices = $manual->toServiceModels($pro->id, $rows);

        $freshaQuery = Service::query()
            ->where('user_id', $pro->id)
            ->whereNotNull('source');
        if ($onlyArchived) {
            $freshaQuery->onlyTrashed();
        } elseif ($includeArchived) {
            $freshaQuery->withTrashed();
        }
        $freshaServices = $freshaQuery->with('categories:id')->orderBy('sort_order')->orderBy('created_at')->get();

        // §NEW-I1 (review round 2): sort the MERGED collection by
        // sort_order, not a blind manual-then-Fresha concatenation — a
        // manual item's transient sort_order is populated from
        // section_items.sort_key (ManualServiceItems::hydrate()), and
        // reorder()/reorderLayout() now number both halves from the same
        // shared position index (renumberLegacySortOrder()'s docblock), so
        // this reconstructs the caller's actual combined order instead of
        // always grouping every manual service ahead of every Fresha one.
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

        // Categories list (for grouped UI): still site.service_categories.
        // Task 9 is the one moving these seven routes onto
        // content.collections; until it lands, an owner-authored service
        // assigned to a content collection (Task 10's updateCategory) carries
        // a membership whose id has no match in THIS list, so it falls into
        // uncategorised_services below rather than under its own heading.
        // Fresha services keep their real eager-loaded memberships.
        $catQuery = ServiceCategory::query()
            ->where('user_id', $pro->id);

        if ($onlyArchived) {
            $catQuery->onlyTrashed();
        } elseif ($includeArchived) {
            $catQuery->withTrashed();
        }

        $categories = $catQuery->orderBy('sort_order')->orderBy('created_at')->get();

        // Multi-category: a service appears under EVERY category it belongs
        // to; zero memberships = Uncategorised. Transient (manual) Service
        // models carry a pre-set categories relation
        // (ManualServiceItems::hydrate()) holding their real content.*
        // memberships — never lazily loaded, so a content-item id is never
        // queried against site.service_category_assignments.
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

        // §C2: not a manual content item — fall back to the untouched Fresha
        // half (site.services WHERE source IS NOT NULL). Not withTrashed():
        // matches the pre-cutover implicit route-model-binding, which never
        // resolved a soft-deleted row for GET either.
        $fresha = Service::query()->where('user_id', $pro->id)->whereNotNull('source')->find($service);
        if ($fresha === null) {
            abort(404, 'Service not found.');
        }

        $this->authorizeForUser($pro, 'view', $fresha);

        return $this->success(['service' => new ServiceResource($fresha)]);
    }

    public function update(UpdateServiceRequest $request, string $service): JsonResponse
    {
        $pro = $this->currentUser($request);
        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);

        $row = $manual->find($pro->id, $service, $manual->sectionId($pro->site));

        if ($row === null) {
            // §C2: not a manual content item — fall back to the untouched,
            // pre-cutover Fresha path (site.services WHERE source IS NOT
            // NULL). Kept byte-for-byte: editing content detaches a
            // projected row from the live sync (is_manual), which is what
            // makes the resync/resyncBulk verbs meaningful — without this
            // branch nothing could ever set is_manual=true again.
            $fresha = Service::query()->where('user_id', $pro->id)->whereNotNull('source')->find($service);
            if ($fresha === null) {
                abort(404, 'Service not found.');
            }

            $this->authorizeForUser($pro, 'update', $fresha);

            $data = $request->validated();

            if (array_key_exists('category_id', $data)) {
                $this->assertCategoryBelongsToProfessional($pro->id, $data['category_id']);
            }

            $fresha->fill($data);

            $contentFields = ['title', 'description', 'price_cents', 'currency_code', 'duration_minutes'];
            $contentChanged = array_intersect(array_keys($fresha->getDirty()), $contentFields) !== [];
            if ($fresha->source === 'fresha' && ! $fresha->is_manual && $contentChanged) {
                $fresha->is_manual = true;
            }

            $fresha->save();

            if ($fresha->source === 'fresha') {
                app(FreshaServiceProjector::class)->refreshBlob($pro);
            }

            return $this->success(['service' => new ServiceResource($fresha->fresh())]);
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
        $row = $this->freshaContentRow($pro->id, $service, liveOnly: false);
        if ($row !== null) {
            $liveRow = $this->freshaContentRow($pro->id, $service, liveOnly: true);
            $this->authorizeForUser($pro, 'update', $this->freshaServiceModel($pro->id, $manual, $liveRow ?? $row));

            if ($liveRow === null) {
                return $this->error('This service is no longer offered on Fresha — keep your edited version or delete it.', 422);
            }

            // Already live-synced (no override) — idempotent success, same as
            // the pre-cutover `! $service->is_manual` early return.
            if (ManualOverride::query()->where('item_id', (string) $liveRow->id)->exists()) {
                ManualOverride::query()->where('item_id', (string) $liveRow->id)->delete();
                $this->invalidateAfterResync($pro);
            }

            // Re-hydrated from the same row, not re-read: deleting an
            // override changes no column ON the row (headline_cache is
            // recomputed by the projection path, not here) — only
            // is_manual, which freshaServiceModel() derives live.
            return $this->success(['service' => new ServiceResource($this->freshaServiceModel($pro->id, $manual, $liveRow))]);
        }

        // §C2: not a content item at all — the untouched legacy path.
        $fresha = Service::query()->where('user_id', $pro->id)->find($service);
        if ($fresha === null) {
            abort(404, 'Service not found.');
        }

        $this->authorizeForUser($pro, 'update', $fresha);

        if ($fresha->source !== 'fresha') {
            return $this->error('Only Fresha-synced services can be resynced.', 422);
        }
        if (! $fresha->is_manual) {
            return $this->success(['service' => new ServiceResource($fresha)]);
        }

        $projector = app(FreshaServiceProjector::class);
        if (! $projector->revert($pro, $fresha)) {
            return $this->error('This service is no longer offered on Fresha — keep your edited version or delete it.', 422);
        }
        $projector->refreshBlob($pro);

        return $this->success(['service' => new ServiceResource($fresha->fresh())]);
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

        $resynced = 0;
        $skipped = 0;

        // content.* half: every Fresha service item this professional owns
        // that carries at least one override. An item with no override was
        // never detached and is not a candidate — the exact set the legacy
        // `is_manual = true` filter selected.
        $candidates = $this->freshaContentQuery($pro->id, liveOnly: false)
            ->when($ids !== [], fn ($query) => $query->whereIn('i.id', $ids))
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('content.manual_overrides as mo')
                ->whereColumn('mo.item_id', 'i.id'))
            ->distinct()
            ->pluck('i.id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($candidates !== []) {
            $live = array_flip(
                $this->freshaContentQuery($pro->id, liveOnly: true)
                    ->whereIn('i.id', $candidates)
                    ->distinct()
                    ->pluck('i.id')
                    ->map(fn ($id) => (string) $id)
                    ->all()
            );

            $revertable = array_values(array_filter($candidates, fn ($id) => isset($live[$id])));
            $skipped += count($candidates) - count($revertable);

            if ($revertable !== []) {
                ManualOverride::query()->whereIn('item_id', $revertable)->delete();
                $resynced += count($revertable);
                $this->invalidateAfterResync($pro);
            }
        }

        // §C2: the untouched legacy half, unchanged.
        $query = Service::query()
            ->where('user_id', $pro->id)
            ->where('source', 'fresha')
            ->where('is_manual', true);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $projector = app(FreshaServiceProjector::class);
        $legacyResynced = 0;
        foreach ($query->get() as $row) {
            $projector->revert($pro, $row) ? $legacyResynced++ : $skipped++;
        }
        if ($legacyResynced > 0) {
            $projector->refreshBlob($pro);
        }
        $resynced += $legacyResynced;

        return $this->success(['resynced' => $resynced, 'skipped' => $skipped]);
    }

    // Move a single service into a category (or Uncategorized). Deliberately
    // separate from update(): this endpoint's only writable input is category_id,
    // so it never re-exposes the raw sort_order field update() accepts. The moved
    // service is appended at max(sort_order)+1 across ALL of the owner's live
    // services (services_user_sort_order_uq is global-per-user, not per-category),
    // the same append restore() uses. The advisory lock shares reorderLayout()'s
    // key so the two read-modify-write paths can never interleave.
    public function updateCategory(UpdateServiceCategoryAssignmentRequest $request, string $service): JsonResponse
    {
        $pro = $this->currentUser($request);

        // §C2: the legacy half, resolved FIRST and scoped to `source IS NOT
        // NULL` exactly as show()/update()/destroy()/restore() scope theirs.
        //
        // Resolution order is inverted from those four deliberately, and it
        // cannot change any answer: the two id spaces are disjoint (a
        // content.items uuid is never a site.services uuid — a backfilled
        // item carries its legacy id in the source_items COORD, not as its
        // own id), so whichever store is asked first, exactly one of them
        // ever matches. Asking site.services first is what keeps every id
        // that worked before this slice on a byte-identical path, touching
        // no content.* table at all.
        //
        // A legacy owner-authored (source IS NULL) row is superseded by its
        // content.* projection and stays unaddressable here — it 404s by
        // being unreachable, which is where 3a's policy gate's 404 went when
        // the gate came off.
        $legacy = Service::query()->where('user_id', $pro->id)->whereNotNull('source')->find($service);

        if ($legacy === null) {
            // Slice 3b: owner-authored services are assignable now that
            // content.collections exists (ServicePolicy::updateCategory()'s
            // gate came off in the same commit as
            // ManualServiceItems::publicList()'s 'Services' constant — see
            // that docblock; neither half may move alone).
            $manual = app(ManualServiceItems::class);
            $row = $manual->find($pro->id, $service, $manual->sectionId($pro->site));
            if ($row === null) {
                abort(404, 'Service not found.');
            }

            return $this->assignOwnerServiceCategory($request, $pro, $manual, $row);
        }

        $service = $legacy;

        $this->authorizeForUser($pro, 'updateCategory', $service);

        $categoryIds = $this->assignmentCategoryIds($request->validated());
        foreach ($categoryIds as $categoryId) {
            $this->assertCategoryBelongsToProfessional($pro->id, $categoryId);
        }

        // Fix C (whole-branch review pt.2): unified onto the services:{user}
        // advisory key (was service-layout:{user}) — this max(sort_order)+1
        // append renumbers the SAME globally-unique (user_id, sort_order)
        // constraint that FreshaServiceProjector::sync(), InsertWithSortOrder,
        // and ReorderService's flat reorder already serialise on via that key;
        // a different key on this side let a reorderLayout()/sync() race slip
        // past both and hit the constraint. Routed through AdvisoryLock so it
        // also inherits the 5s bound + typed AdvisoryLockTimeoutException
        // (→ 423) the services key already has, instead of staying unbounded.
        try {
            DB::connection('pgsql')->transaction(function () use ($pro, $service, $categoryIds) {
                AdvisoryLock::acquire("services:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                $current = $service->categories()->pluck('site.service_categories.id')->map(fn ($id) => (string) $id)->sort()->values()->all();
                $target = collect($categoryIds)->sort()->values()->all();

                // No-op: nothing to reindex, keep the current sort_order.
                if ($current === $target) {
                    return;
                }

                $service->categories()->sync($categoryIds);

                $max = Service::query()
                    ->where('user_id', $pro->id)
                    ->whereNull('deleted_at')
                    ->max('sort_order');

                $service->update([
                    'sort_order' => is_null($max) ? 0 : ((int) $max + 1),
                ]);
            });
        } catch (AdvisoryLockTimeoutException) {
            // Same contention/423 as store()/reorder() above.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        return $this->success(['service' => new ServiceResource($service->fresh())]);
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

    public function destroy(Request $request, string $service): JsonResponse
    {
        $pro = $this->currentUser($request);
        $manual = app(ManualServiceItems::class);

        $row = $manual->find($pro->id, $service, $manual->sectionId($pro->site));

        if ($row === null) {
            // §C2: not a manual content item — fall back to the untouched
            // Fresha path (site.services WHERE source IS NOT NULL). Kept
            // byte-for-byte: spec §3.7 — deleted_origin='user' IS load-
            // bearing, FreshaServiceProjector:180-195 reads it to decide
            // whether a returning service is restored or stays suppressed.
            // Without this branch a scraped service the owner deletes comes
            // back on the next sync.
            $fresha = Service::query()->where('user_id', $pro->id)->whereNotNull('source')->find($service);
            if ($fresha === null) {
                abort(404, 'Service not found.');
            }

            $this->authorizeForUser($pro, 'delete', $fresha);

            if ($fresha->source === 'fresha') {
                $fresha->deleted_origin = 'user';
                $fresha->saveQuietly();
            }

            $fresha->delete();

            if ($fresha->source === 'fresha') {
                app(FreshaServiceProjector::class)->refreshBlob($pro);
            }

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

    // Flat reorder. §C2: an id is looked up in whichever store owns it —
    // manual (content.*) or Fresha (site.services) — an id neither
    // recognises 422s, same as the pre-cutover single-table check.
    //
    // §NEW-C1/I1 (review round 2): both halves are renumbered from ONE
    // shared position index in the SAME transaction — never a per-half
    // renumber. services_user_sort_order_uq is GLOBAL per user, so
    // renumbering only the Fresha subset to a dense 0..N-1 collided with the
    // legacy manual rows ServiceBackfiller never deletes (500 for any
    // professional holding both halves); a shared index is also what lets a
    // merged read reconstruct an interleaved manual+Fresha order instead of
    // always grouping manual ahead of Fresha regardless of what was
    // submitted. See renumberLegacySortOrder()'s docblock.
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
        $writer = app(ManualServiceWriter::class);
        $ids = $request->input('ids', []);

        $sectionId = $manual->sectionId($site);
        $manualRows = $manual->rows($pro->id, $sectionId, includeRemoved: false);
        $manualRowsById = $manualRows->keyBy(fn ($r) => (string) $r->id);
        $manualIdSet = array_flip($manualRowsById->keys()->all());
        $freshaIdSet = array_flip(
            Service::query()->where('user_id', $pro->id)->whereNotNull('source')
                ->orderBy('sort_order')->pluck('id')->map(fn ($id) => (string) $id)->all()
        );

        foreach ($ids as $id) {
            if (! isset($manualIdSet[$id]) && ! isset($freshaIdSet[$id])) {
                abort(422, 'One or more items are invalid.');
            }
        }

        // The submitted ids first, then every other live id (manual OR
        // Fresha) this request didn't mention, in its current relative
        // order — ONE combined authority for both writes below.
        $remainder = array_values(array_diff(
            [...$manualRowsById->keys()->all(), ...array_keys($freshaIdSet)],
            $ids,
        ));
        $fullOrder = array_merge($ids, $remainder);

        try {
            DB::connection('pgsql')->transaction(function () use ($pro, $site, $writer, $manualRowsById, $fullOrder) {
                AdvisoryLock::acquire("services:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                // Manual half: section_items.sort_key, keyed by $fullOrder's
                // own position — not a recompacted counter, so it stays on
                // the SAME numbering scale renumberLegacySortOrder() uses
                // below. Excluded (hidden) items carry no sort_key by design
                // (ManualServiceWriter::exclude) — accepted (no 422) but
                // stay unpositioned; they get a real position the next time
                // they're reactivated (update()'s re-pin branch).
                foreach ($fullOrder as $rank => $id) {
                    $row = $manualRowsById->get($id);
                    if ($row !== null && ($row->state ?? null) !== 'excluded') {
                        $writer->pin($site, $id, (float) $rank);
                    }
                }

                // Legacy half (Fresha + still-present legacy manual rows) —
                // see renumberLegacySortOrder()'s docblock for why this
                // covers more than just the ids the caller sent.
                $this->renumberLegacySortOrder($pro->id, $writer, $fullOrder);
            });
        } catch (AdvisoryLockTimeoutException) {
            // U2: same contention/423 as store() above.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $writer->invalidate([(string) $site->id]);
        // EDGE-1: renumberLegacySortOrder() is a raw query-builder mass
        // update, so ServiceObserver never fires for the Fresha half either
        // — touch explicitly (fires SiteObserver: Redis + Cloudflare + cache
        // warm), mirroring what ReorderService's afterCommit callback used
        // to do for this exact reorder.
        $site->touch();
        app(UserCacheService::class)->invalidateServices($pro->id);

        return $this->success(['ok' => true]);
    }

    // Full layout reorder. §C3: category blocks + service_category_assignments
    // membership are Fresha's concern (every live category belongs to
    // Fresha, spec §1.1) and are restored here byte-for-byte from the
    // pre-cutover implementation, including all four validations. Manual
    // (content.*) ids carry no category concept — they may appear in ANY
    // block (validation does not reject it, since there is nowhere else for
    // the frontend to put an uncategorised-by-construction item), but they
    // are never written into service_category_assignments; only their
    // FLATTENED, first-occurrence position across every block feeds
    // section_items.sort_key. Every one of the owner's live services —
    // Fresha AND manual — must be covered by the payload, checked separately
    // per half so the error path can't silently accept a payload that omits
    // one half entirely.
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

        $payload = $request->validated();

        try {
            DB::connection('pgsql')->transaction(function () use ($pro, $site, $manual, $writer, $payload) {
                AdvisoryLock::acquire("services:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                $activeCategoryIds = ServiceCategory::query()
                    ->where('user_id', $pro->id)
                    ->pluck('id')
                    ->all();
                $activeCategorySet = array_flip($activeCategoryIds);

                $activeFreshaServiceIds = Service::query()
                    ->where('user_id', $pro->id)
                    ->whereNotNull('source')
                    ->pluck('id')
                    ->all();
                $activeFreshaServiceSet = array_flip($activeFreshaServiceIds);

                $sectionId = $manual->sectionId($site);
                $manualRows = $manual->rows($pro->id, $sectionId, includeRemoved: false);
                $manualRowsById = $manualRows->keyBy(fn ($r) => (string) $r->id);
                $manualIdSet = array_flip($manualRowsById->keys()->all());

                $providedCategoryIds = [];
                $seenPerBlock = [];
                $membershipsByService = [];
                $uncategorisedIds = [];
                $manualIdsSeen = [];

                foreach ($payload['categories'] as $bi => $catBlock) {
                    $catId = $catBlock['id'] ?? null;

                    if ($catId !== null) {
                        if (! isset($activeCategorySet[$catId])) {
                            abort(422, 'One or more category IDs are invalid.');
                        }
                        $providedCategoryIds[] = $catId;
                    }

                    foreach ($catBlock['service_ids'] as $sid) {
                        $isFresha = isset($activeFreshaServiceSet[$sid]);
                        $isManual = isset($manualIdSet[$sid]);
                        if (! $isFresha && ! $isManual) {
                            abort(422, 'One or more service IDs are invalid.');
                        }
                        // Multi-category: a service MAY appear in several category
                        // blocks (one membership per block) — but never twice in the
                        // same block, and never both categorised AND uncategorised.
                        if (isset($seenPerBlock[$bi][$sid])) {
                            abort(422, 'Duplicate service IDs detected within a category block.');
                        }
                        $seenPerBlock[$bi][$sid] = true;

                        // Manual ids never enter FRESHA's membership map
                        // (site.service_category_assignments), whatever block
                        // they were placed in — only first-occurrence order
                        // matters for them here (tracked below). Task 10 gave
                        // them memberships of their own in
                        // content.collection_items, but this layout endpoint
                        // is not a writer of those: PATCH
                        // /services/{id}/category is. Teaching it to write
                        // them means resolving each block's id in the right
                        // id space first — see updateCategory()'s two
                        // branches — and belongs with Task 9's cutover.
                        if ($isManual) {
                            $manualIdsSeen[$sid] = true;

                            continue;
                        }

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

                // Every active FRESHA service must appear somewhere (its
                // memberships, or the uncategorised block for zero
                // memberships) — unchanged from the pre-cutover check.
                $coveredFreshaIds = array_unique([...array_keys($membershipsByService), ...array_keys($uncategorisedIds)]);
                if (count($coveredFreshaIds) !== count($activeFreshaServiceIds)) {
                    abort(422, 'Layout payload must include all service IDs for this professional.');
                }

                // Every live MANUAL service must appear somewhere too — a
                // separate coverage check since manual ids never reach
                // $membershipsByService/$uncategorisedIds above.
                if (count($manualIdsSeen) !== count($manualIdSet)) {
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

                // Apply category order + service order (both halves,
                // flattened into ONE first-occurrence traversal — §NEW-C1/I1
                // review round 2) + Fresha MEMBERSHIPS.
                $categorySort = 0;
                $orderedAllServiceIds = [];
                $orderedFreshaServiceIds = [];

                foreach ($payload['categories'] as $catBlock) {
                    $catId = $catBlock['id'] ?? null;

                    if ($catId !== null) {
                        ServiceCategory::query()
                            ->where('user_id', $pro->id)
                            ->where('id', $catId)
                            ->update(['sort_order' => $categorySort++]);
                    }

                    foreach ($catBlock['service_ids'] as $serviceId) {
                        if (! in_array($serviceId, $orderedAllServiceIds, true)) {
                            $orderedAllServiceIds[] = $serviceId;
                        }
                        if (! isset($manualIdSet[$serviceId]) && ! in_array($serviceId, $orderedFreshaServiceIds, true)) {
                            $orderedFreshaServiceIds[] = $serviceId;
                        }
                    }
                }

                // Membership sync per FRESHA service (replace-set semantics)
                // — unchanged from the pre-cutover implementation, restricted
                // to the Fresha subset (manual ids carry no category concept
                // at all, per the flattening comment above).
                foreach ($orderedFreshaServiceIds as $serviceId) {
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

                // MANUAL service order → section_items.sort_key, keyed by
                // $orderedAllServiceIds' own position (not a recompacted
                // counter) — the same numbering scale renumberLegacySortOrder()
                // uses below (§NEW-I1). Excluded (hidden) items carry no
                // sort_key by design (ManualServiceWriter::exclude) —
                // covered above (no 422), but stay unpositioned; they get a
                // real position the next time they're reactivated (update()'s
                // re-pin branch).
                foreach ($orderedAllServiceIds as $rank => $sid) {
                    if (! isset($manualIdSet[$sid])) {
                        continue;
                    }
                    $row = $manualRowsById->get($sid);
                    if ($row !== null && ($row->state ?? null) !== 'excluded') {
                        $writer->pin($site, $sid, (float) $rank);
                    }
                }

                // §NEW-C1: renumber the WHOLE legacy set (Fresha + the
                // still-present legacy manual rows), never just the Fresha
                // subset — see renumberLegacySortOrder()'s docblock.
                $this->renumberLegacySortOrder($pro->id, $writer, $orderedAllServiceIds);
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
            // §C2: not a manual content item — fall back to the untouched
            // Fresha path (site.services WHERE source IS NOT NULL),
            // withTrashed() since the row is soft-deleted by definition here.
            $fresha = Service::query()->where('user_id', $pro->id)->whereNotNull('source')->withTrashed()->find($service);
            if ($fresha === null) {
                abort(404, 'Service not found.');
            }

            $this->authorizeForUser($pro, 'update', $fresha);

            if (! $fresha->trashed()) {
                return $this->success(['restored' => true, 'service' => new ServiceResource($fresha->fresh())]);
            }

            DB::transaction(function () use ($pro, $fresha) {
                // Compute the next sort_order BEFORE restoring. The partial
                // unique index (user_id, sort_order) WHERE deleted_at IS
                // NULL is global per professional — another service may
                // have claimed this slot while this one was soft-deleted.
                $max = Service::query()
                    ->where('user_id', $pro->id)
                    ->whereNull('deleted_at')
                    ->max('sort_order');

                $fresha->sort_order = is_null($max) ? 0 : ((int) $max + 1);
                // Restoring a suppressed Fresha service is the owner
                // explicitly un-deleting it — clear the suppression so sync
                // treats it live again.
                if ($fresha->source === 'fresha') {
                    $fresha->deleted_origin = null;
                }
                $fresha->saveQuietly();

                $fresha->restore();
            });

            if ($fresha->source === 'fresha') {
                app(FreshaServiceProjector::class)->refreshBlob($pro);
            }

            return $this->success(['restored' => true, 'service' => new ServiceResource($fresha->fresh())]);
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
     * The two-surface split's OTHER side, as a resolve-by-id.
     *
     * `ManualServiceItems` scopes to `content.sources.kind = 'manual'` and
     * `FreshaServiceItems` to `'connection'`; both expose LIST reads only,
     * and resync needs to resolve ONE id. This is that query, in one place,
     * used by both resync() and resyncBulk() — not a per-call-site copy.
     *
     * `$liveOnly = false` still matches an item whose source_item has been
     * removed_at'd: that is "no longer offered on Fresha", which must answer
     * 422, and 422 requires finding the row first.
     */
    private function freshaContentQuery(string $userId, bool $liveOnly): Builder
    {
        $query = DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as si', 'si.item_id', '=', 'i.id')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('i.user_id', $userId)
            ->where('i.kind', 'service')
            ->whereNull('i.removed_at')
            ->where('cs.kind', 'connection');

        if ($liveOnly) {
            $query->whereNull('si.removed_at');
        }

        return $query;
    }

    /**
     * One Fresha content item in `ManualServiceItems::rows()`' column shape,
     * so its hydration can be reused rather than re-written. `sort_key` and
     * `state` are literal NULLs: a Fresha item carries no `services` pool
     * curation (it is not on that surface at all), and hydrate() reads both
     * columns unconditionally.
     *
     * Those two NULLs are CAST rather than bare. A bare `null` in a SELECT
     * list is type `unknown` to Postgres, and this SELECT is DISTINCT —
     * unknown is exactly the kind of thing that resolves silently under
     * SQLite and can fail to find an equality operator on the real database.
     */
    private function freshaContentRow(string $userId, string $itemId, bool $liveOnly): ?object
    {
        return $this->freshaContentQuery($userId, $liveOnly)
            ->where('i.id', $itemId)
            ->distinct()
            ->first([
                'i.id', 'i.headline_cache', 'i.removed_at', 'i.created_at', 'i.updated_at',
                'cs.id as source_id',
                DB::raw('CAST(null AS text) as sort_key'),
                DB::raw('CAST(null AS text) as state'),
            ]);
    }

    /**
     * A Fresha content item as the legacy-shaped Service model
     * ServiceResource maps to the wire.
     *
     * `source`/`is_manual` are restated after hydration because
     * ManualServiceItems' mapping hardcodes the owner-authored answer
     * (null/false) — correct for its own rows, wrong for a connection-sourced
     * one. In content.* "detached from the sync" IS "carries a
     * `content.manual_overrides` row", so `is_manual` is derived from that
     * and nothing else; the dashboard's "sync broken" chip keeps reading the
     * same two fields it always has.
     */
    private function freshaServiceModel(string $userId, ManualServiceItems $manual, object $row): Service
    {
        $model = $manual->toServiceModel($userId, $row);
        $model->source = 'fresha';
        $model->is_manual = ManualOverride::query()->where('item_id', (string) $row->id)->exists();

        return $model;
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

    /**
     * §NEW-C1 (review round 2): renumber site.services.sort_order for the
     * professional's WHOLE live legacy set — Fresha rows AND the
     * still-present legacy owner-authored rows ServiceBackfiller never
     * deletes — never just the Fresha subset. services_user_sort_order_uq
     * (baseline migration) is `UNIQUE (user_id, sort_order) WHERE deleted_at
     * IS NULL`, GLOBAL per user and NOT scoped by source: renumbering only
     * Fresha rows to a dense 0..N-1 lands on slots the legacy manual rows
     * still occupy (they were never deleted at backfill, only superseded),
     * and every reorder 500s for a professional holding both halves.
     *
     * $order's own array index — NOT a per-half recompacted counter — is
     * the target rank, with gaps allowed (the index only requires
     * distinctness among live rows, never contiguity). That is what lets
     * this share exactly the same numbering the caller's manual
     * `section_items.sort_key` pins use (§NEW-I1) — sorting the merged
     * dashboard/public read by that shared rank reconstructs the caller's
     * actual combined order instead of grouping every manual service ahead
     * of every Fresha one regardless of what was submitted.
     *
     * WRITE-ONLY BOOKKEEPING for owner-authored rows: nothing reads
     * site.services.sort_order for them post-cutover — the public path
     * reads content.*, the dashboard list reads ManualServiceItems ordered
     * by section_items.sort_key. This write exists SOLELY to keep the
     * shared unique index satisfiable while both halves still share one
     * table, and it dies with site.services in slice 7. That is NOT a
     * second source of truth for those rows — do not "fix" this back.
     *
     * @param  list<string>  $order  ids in desired order (array position =
     *                               rank) — a mix of content.items (manual)
     *                               and site.services (Fresha) ids
     */
    private function renumberLegacySortOrder(string $userId, ManualServiceWriter $writer, array $order): void
    {
        // Eloquent's SoftDeletes global scope already excludes trashed rows
        // — this IS "every live row the unique index spans" for this user.
        $legacyIdSet = array_flip(
            Service::query()->where('user_id', $userId)->pluck('id')->map(fn ($id) => (string) $id)->all()
        );
        if ($legacyIdSet === []) {
            return;
        }

        // legacy row id => target sort_order. $order's own array index
        // (NOT a recompacted counter) is the target — deliberately: reusing
        // ReorderService::reorder() here was tried and reverted, because its
        // own internal two-pass recomputes a DENSE 0..N-1 over only the
        // legacy-resolvable subset, discarding the gaps left by manual ids
        // with no legacy row. That silently breaks §NEW-I1 the moment an
        // unresolvable (newly-created) manual id sits ahead of a Fresha id
        // in $order — the Fresha id compacts into a slot that no longer
        // matches its position in $order, and the merged dashboard/public
        // read (sorted by this same rank on both sides) puts it in the
        // wrong place relative to the manual ids around it. Keeping the raw,
        // gapped index is what makes the two domains' numbers comparable.
        $targets = [];
        foreach ($order as $rank => $id) {
            $legacyId = isset($legacyIdSet[$id]) ? $id : $this->legacyIdForManualItem($writer, $userId, $id);
            if ($legacyId === null || isset($targets[$legacyId])) {
                continue;
            }
            $targets[$legacyId] = $rank;
        }

        // A live row this request never addressed at all — a legacy manual
        // row whose content item wasn't in $order (e.g. already superseded),
        // or a Fresha row the caller didn't mention — keeps its current
        // relative order, appended after every resolved id.
        $nextRank = count($order);
        foreach (array_keys($legacyIdSet) as $id) {
            if (! isset($targets[$id])) {
                $targets[$id] = $nextRank++;
            }
        }

        // Two-pass parking-value renumber (the same technique
        // ReorderService::reorder() uses, reimplemented rather than reused
        // for the reason above): the unique index is checked per statement,
        // not deferred, so a single pass can transiently collide with a row
        // that hasn't been updated yet but still occupies the target value.
        $offset = (int) Service::query()->where('user_id', $userId)->max('sort_order') + 1000;
        foreach ($targets as $id => $rank) {
            Service::query()->where('user_id', $userId)->where('id', $id)->update(['sort_order' => $offset + $rank]);
        }
        foreach ($targets as $id => $rank) {
            Service::query()->where('user_id', $userId)->where('id', $id)->update(['sort_order' => $rank]);
        }
    }

    /**
     * A manual (content.*) item's legacy site.services counterpart, if any
     * is still live. ServiceBackfiller coords are 'manual:{legacy_uuid}' —
     * the legacy identifier survives the table's eventual drop (slice 7).
     * A service created through the cut-over endpoints mints a fresh random
     * uuid coord instead ('manual:'.Str::uuid()) and was never derived from
     * a legacy row — null, correctly, since there is nothing to renumber.
     */
    private function legacyIdForManualItem(ManualServiceWriter $writer, string $userId, string $itemId): ?string
    {
        $coord = $writer->coordFor($userId, $itemId);
        if ($coord === null || ! str_starts_with($coord, 'manual:')) {
            return null;
        }

        $legacyId = substr($coord, strlen('manual:'));

        // Default Eloquent scope excludes soft-deleted rows — "still live"
        // is exactly what this needs (a trashed legacy row is outside the
        // partial unique index and needs no protecting).
        return Service::query()->where('id', $legacyId)->where('user_id', $userId)->exists() ? $legacyId : null;
    }
}
