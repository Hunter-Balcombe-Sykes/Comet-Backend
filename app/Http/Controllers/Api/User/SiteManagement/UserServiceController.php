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
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Services\Cache\UserCacheService;
use App\Services\Content\ManualServiceItems;
use App\Services\Content\ManualServiceWriter;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Site\AdvisoryLock;
use App\Services\Site\AdvisoryLockTimeoutException;
use App\Services\User\SectionVisibilityService;
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
// resync/resyncBulk/updateCategory and every /service-categories/* route are
// Fresha-shaped and stay on site.services untouched — 3b's job.
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
        // single-flight, busted explicitly by every write method below (the
        // content.* write paths bypass Eloquent, so ServiceObserver never
        // fires for them).
        if (! $includeArchived && ! $onlyArchived && ! $grouped) {
            return $this->success([
                'services' => app(UserCacheService::class)->getDashboardServices($pro->id),
                'filters' => [
                    'include_archived' => false,
                    'only_archived' => false,
                ],
            ]);
        }

        $manual = app(ManualServiceItems::class);
        $sectionId = $manual->sectionId($pro->site);
        $rows = $manual->rows($pro->id, $sectionId, includeRemoved: $includeArchived || $onlyArchived);
        if ($onlyArchived) {
            $rows = $rows->filter(fn ($row) => $row->removed_at !== null)->values();
        }

        // Bound the query at scale (B18/API-4). True pagination is a frontend-coordinated change, deferred.
        $limit = (int) config('partna.limits.pagination.services_max', 500);
        $services = $manual->toServiceModels($pro->id, $rows->take($limit));

        if (! $grouped) {
            return $this->success([
                'services' => ServiceResource::collection($services),
                'filters' => [
                    'include_archived' => $includeArchived,
                    'only_archived' => $onlyArchived,
                ],
            ]);
        }

        // Categories list (for grouped UI). Every live category belongs to
        // Fresha and owner-authored services carry zero memberships (spec
        // §1.1/§2) — every category shows an empty member list here, exactly
        // reproducing pre-cutover behaviour for these rows (the pivot table
        // never held a manual-service row either).
        $catQuery = ServiceCategory::query()
            ->where('user_id', $pro->id);

        if ($onlyArchived) {
            $catQuery->onlyTrashed();
        } elseif ($includeArchived) {
            $catQuery->withTrashed();
        }

        $categories = $catQuery->orderBy('sort_order')->orderBy('created_at')->get();

        $categoryPayload = $categories->map(fn (ServiceCategory $c) => array_merge(
            (new ServiceCategoryResource($c))->resolve(),
            ['services' => []],
        ))->values();

        return $this->success([
            'categories' => $categoryPayload,
            'uncategorised_services' => ServiceResource::collection($services),
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

        // Category assignment has no content.* destination yet (spec §7 —
        // 3b lands content.collections). Ownership is still asserted for
        // wire-compat (a foreign category id still 422s), but no membership
        // is persisted — ServicePolicy::updateCategory() already blocks
        // (re)assigning one on a manual service, so accepting one only at
        // creation would be an inconsistent, one-way door.
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
        if ($row === null) {
            abort(404, 'Service not found.');
        }

        $model = $manual->toServiceModel($pro->id, $row);
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
            abort(404, 'Service not found.');
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
        $itemId = $writer->write($pro->id, $coord, $writer->projectionFor($payload));

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
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $writer->invalidate([(string) $site->id]);
        app(UserCacheService::class)->invalidateServices($pro->id);
        $this->reevaluateVisibility($pro->id, (string) $site->id);

        $freshRow = $manual->find($pro->id, $itemId, $manual->sectionId($site));
        $freshModel = $manual->toServiceModel($pro->id, $freshRow);

        return $this->success(['service' => new ServiceResource($freshModel)]);
    }

    // POST /services/{service}/resync — revert one detached ("sync broken")
    // Fresha service back to the live-synced version from the stored raw scrape.
    public function resync(Request $request, Service $service): JsonResponse
    {
        $pro = $this->currentUser($request);

        $this->authorizeForUser($pro, 'update', $service);

        if ($service->source !== 'fresha') {
            return $this->error('Only Fresha-synced services can be resynced.', 422);
        }
        if (! $service->is_manual) {
            return $this->success(['service' => new ServiceResource($service)]);
        }

        $projector = app(FreshaServiceProjector::class);
        if (! $projector->revert($pro, $service)) {
            return $this->error('This service is no longer offered on Fresha — keep your edited version or delete it.', 422);
        }
        $projector->refreshBlob($pro);

        return $this->success(['service' => new ServiceResource($service->fresh())]);
    }

    // POST /services/resync — bulk revert: the given ids, or EVERY detached
    // Fresha service when ids are omitted. Rows whose service left Fresha are
    // skipped (nothing to revert to) and reported.
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

        $query = Service::query()
            ->where('user_id', $pro->id)
            ->where('source', 'fresha')
            ->where('is_manual', true);
        if (! empty($validated['ids'])) {
            $query->whereIn('id', $validated['ids']);
        }

        $projector = app(FreshaServiceProjector::class);
        $resynced = 0;
        $skipped = 0;
        foreach ($query->get() as $row) {
            $projector->revert($pro, $row) ? $resynced++ : $skipped++;
        }
        if ($resynced > 0) {
            $projector->refreshBlob($pro);
        }

        return $this->success(['resynced' => $resynced, 'skipped' => $skipped]);
    }

    // Move a single service into a category (or Uncategorized). Deliberately
    // separate from update(): this endpoint's only writable input is category_id,
    // so it never re-exposes the raw sort_order field update() accepts. The moved
    // service is appended at max(sort_order)+1 across ALL of the owner's live
    // services (services_user_sort_order_uq is global-per-user, not per-category),
    // the same append restore() uses. The advisory lock shares reorderLayout()'s
    // key so the two read-modify-write paths can never interleave.
    public function updateCategory(UpdateServiceCategoryAssignmentRequest $request, Service $service): JsonResponse
    {
        $pro = $this->currentUser($request);

        // Slice 3a: manual (owner-authored) services are excluded here —
        // see ServicePolicy::updateCategory()'s docblock, cross-referenced
        // with the 'category' => 'Services' constant in
        // SitepageDataResolverService::buildServicesData().
        $this->authorizeForUser($pro, 'updateCategory', $service);

        // Multi-category: category_ids REPLACES the membership set; the legacy
        // single category_id spelling maps to [id] (or [] for explicit null —
        // "move to Uncategorised"). Explicitly-supplied ids validate ownership.
        $validated = $request->validated();
        $categoryIds = array_key_exists('category_ids', $validated) && is_array($validated['category_ids'])
            ? array_values(array_unique(array_map('strval', $validated['category_ids'])))
            : (($validated['category_id'] ?? null) !== null ? [(string) $validated['category_id']] : []);
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

    public function destroy(Request $request, string $service): JsonResponse
    {
        $pro = $this->currentUser($request);
        $manual = app(ManualServiceItems::class);

        $row = $manual->find($pro->id, $service, $manual->sectionId($pro->site));
        if ($row === null) {
            abort(404, 'Service not found.');
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

    // Flat reorder, manual-only: repositions the caller's owner-authored
    // (content.*-backed) services. A Fresha id is no longer reachable through
    // this endpoint post-cutover — the dashboard list this feeds
    // (index()/getDashboardServices()) no longer surfaces one either.
    public function reorder(ReorderServiceRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);
        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);

        $ids = $request->input('ids', []);

        try {
            DB::connection('pgsql')->transaction(function () use ($pro, $site, $manual, $writer, $ids) {
                AdvisoryLock::acquire("services:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                $sectionId = $manual->sectionId($site);
                $rows = $manual->rows($pro->id, $sectionId, includeRemoved: false);
                $rowsById = $rows->keyBy(fn ($r) => (string) $r->id);
                $allIds = $rowsById->keys()->all();

                $allSet = array_flip($allIds);
                foreach ($ids as $id) {
                    if (! isset($allSet[$id])) {
                        abort(422, 'One or more items are invalid.');
                    }
                }

                $newOrder = array_merge($ids, array_values(array_diff($allIds, $ids)));

                // Excluded (hidden) items carry no sort_key by design
                // (ManualServiceWriter::exclude) — a hidden id is accepted
                // (no 422) but stays unpositioned; it gets a real position
                // the next time it's reactivated (update()'s re-pin branch).
                $rank = 0.0;
                foreach ($newOrder as $id) {
                    $row = $rowsById->get($id);
                    if ($row !== null && ($row->state ?? null) !== 'excluded') {
                        $writer->pin($site, $id, $rank);
                        $rank++;
                    }
                }
            });
        } catch (AdvisoryLockTimeoutException) {
            // U2: same contention/423 as store() above.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $writer->invalidate([(string) $site->id]);
        app(UserCacheService::class)->invalidateServices($pro->id);

        return $this->success(['ok' => true]);
    }

    // Full layout reorder, manual-only. The payload's category blocks are
    // Fresha's concern (every live category belongs to Fresha, spec §1.1) —
    // 3b re-scopes this endpoint for real category+service ordering. Until
    // then, only the FLATTENED, first-occurrence traversal order of
    // service_ids across every block is honoured (the same flattening the
    // pre-cutover implementation already did before applying it to
    // sort_order); any id that isn't one of this owner's live manual
    // services (Fresha, foreign, or stale) is silently skipped rather than
    // rejected, since this endpoint no longer has a lane for it.
    public function reorderLayout(ReorderServiceLayoutRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);
        $manual = app(ManualServiceItems::class);
        $writer = app(ManualServiceWriter::class);

        $payload = $request->validated();

        try {
            DB::connection('pgsql')->transaction(function () use ($pro, $site, $manual, $writer, $payload) {
                AdvisoryLock::acquire("services:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                $sectionId = $manual->sectionId($site);
                $rows = $manual->rows($pro->id, $sectionId, includeRemoved: false);
                $rowsById = $rows->keyBy(fn ($r) => (string) $r->id);
                $activeServiceSet = array_flip($rowsById->keys()->all());

                $orderedServiceIds = [];
                $seenIds = [];
                foreach ($payload['categories'] as $catBlock) {
                    foreach ($catBlock['service_ids'] as $sid) {
                        if (! isset($activeServiceSet[$sid]) || isset($seenIds[$sid])) {
                            continue;
                        }
                        $seenIds[$sid] = true;
                        $orderedServiceIds[] = $sid;
                    }
                }

                // Every one of THIS owner's live manual services must be
                // covered — a partial payload is rejected rather than
                // silently reordering only some of them.
                if (count($orderedServiceIds) !== $rowsById->count()) {
                    abort(422, 'Layout payload must include all service IDs for this professional.');
                }

                $rank = 0.0;
                foreach ($orderedServiceIds as $sid) {
                    $row = $rowsById->get($sid);
                    if (($row->state ?? null) !== 'excluded') {
                        $writer->pin($site, $sid, $rank);
                        $rank++;
                    }
                }
            });
        } catch (AdvisoryLockTimeoutException) {
            // Same contention/423 as store()/reorder() above.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

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
            abort(404, 'Service not found.');
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
