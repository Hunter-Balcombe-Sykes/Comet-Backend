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
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Site\AdvisoryLock;
use App\Services\Site\AdvisoryLockTimeoutException;
use App\Services\Site\InsertWithSortOrder;
use App\Services\Site\ReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Service CRUD + reorder for professional's mini-site.
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
        // single-flight, busted by ServiceObserver on any write.
        // Filtered or grouped views fall through to the raw query because
        // their cache keys would either explode in cardinality or duplicate
        // the categories join logic.
        if (! $includeArchived && ! $onlyArchived && ! $grouped) {
            return $this->success([
                'services' => app(UserCacheService::class)->getDashboardServices($pro->id),
                'filters' => [
                    'include_archived' => false,
                    'only_archived' => false,
                ],
            ]);
        }

        $servicesQuery = Service::query()
            ->where('user_id', $pro->id);

        if ($onlyArchived) {
            $servicesQuery->onlyTrashed();
        } elseif ($includeArchived) {
            $servicesQuery->withTrashed();
        }

        // Bound the query at scale (B18/API-4). True pagination is a frontend-coordinated change, deferred.
        $services = $servicesQuery->with('categories:id')->orderBy('sort_order')->orderBy('created_at')->limit((int) config('partna.limits.pagination.services_max', 500))->get();

        if (! $grouped) {
            return $this->success([
                'services' => ServiceResource::collection($services),
                'filters' => [
                    'include_archived' => $includeArchived,
                    'only_archived' => $onlyArchived,
                ],
            ]);
        }

        // Categories list (for grouped UI)
        $catQuery = ServiceCategory::query()
            ->where('user_id', $pro->id);

        if ($onlyArchived) {
            $catQuery->onlyTrashed();
        } elseif ($includeArchived) {
            $catQuery->withTrashed();
        }

        $categories = $catQuery->orderBy('sort_order')->orderBy('created_at')->get();

        // Multi-category: a service appears under EVERY category it belongs to;
        // zero memberships = Uncategorised.
        $categoryIds = fn (Service $s) => $s->categories->map(fn ($c) => (string) $c->id)->all();

        // Grouped payload: each category exposes the ServiceCategoryResource shape
        // plus a nested `services` array of ServiceResource items. Hand-rolled
        // arrays previously leaked raw model fields (audit P1-05).
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

        $categoryIds = $this->requestedCategoryIds($data);
        foreach ($categoryIds as $categoryId) {
            $this->assertCategoryBelongsToProfessional($pro->id, $categoryId);
        }

        try {
            // The unique constraint services_professional_sort_order_uq ON
            // (user_id, sort_order) WHERE deleted_at IS NULL is global per
            // professional — the max-lookup considers EVERY live service
            // regardless of category so a new service never collides with an
            // existing row at sort_order=0.
            $service = InsertWithSortOrder::run(
                Service::query()
                    ->where('user_id', $pro->id)
                    ->whereNull('deleted_at'),
                "services:{$pro->id}",
                function (int $next) use ($pro, $data, $categoryIds) {
                    // SEC-1: relation ->create() sets user_id via the FK, not mass-assignment.
                    $service = $pro->services()->create([
                        'title' => $data['title'],
                        'description' => $data['description'] ?? null,
                        'price_cents' => $data['price_cents'],
                        'currency_code' => $data['currency_code'] ?? 'AUD',
                        'duration_minutes' => $data['duration_minutes'] ?? null,
                        'is_active' => $data['is_active'] ?? true,
                        'sort_order' => $data['sort_order'] ?? $next,
                    ]);
                    if ($categoryIds !== []) {
                        $service->categories()->attach($categoryIds);
                    }

                    return $service->fresh();
                },
                AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS,
            );
        } catch (AdvisoryLockTimeoutException) {
            // U2: a background ConnectFetchJob (or another dashboard write) held
            // the services lock past the bound — expected contention, not a bug,
            // so no Log::error() (unlike the generic catch below): same 423 every
            // other interactive platform-connection write returns on contention.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        } catch (\Throwable $e) {
            // Log the actual cause so the user sees the real error in server
            // logs instead of the generic "An error occurred" wrapper from
            // bootstrap/app.php. Re-throws so the wrapper still returns 500.
            Log::error('Service store failed', [
                'user_id' => $pro->id,
                'payload' => $data,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        return $this->success(['service' => new ServiceResource($service)], 201);
    }

    public function show(Request $request, Service $service): JsonResponse
    {
        $pro = $this->currentUser($request);

        $this->authorizeForUser($pro, 'view', $service);

        return $this->success(['service' => new ServiceResource($service)]);
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $pro = $this->currentUser($request);

        $this->authorizeForUser($pro, 'update', $service);

        $data = $request->validated();

        if (array_key_exists('category_id', $data)) {
            $this->assertCategoryBelongsToProfessional($pro->id, $data['category_id']);
        }

        $service->fill($data);

        // Editing a Fresha-synced service's CONTENT detaches it from the live
        // sync (is_manual — the scheduled re-scrape no longer overwrites it;
        // the dashboard shows the "sync broken" warning + revert). Toggling
        // is_active (the public show/hide) or sort_order is curation, not
        // content — it never breaks sync.
        $contentFields = ['title', 'description', 'price_cents', 'currency_code', 'duration_minutes'];
        $contentChanged = array_intersect(array_keys($service->getDirty()), $contentFields) !== [];
        if ($service->source === 'fresha' && ! $service->is_manual && $contentChanged) {
            $service->is_manual = true;
        }

        $service->save();

        // Any change to a projected row re-composes the public booking blob
        // (edited fields serialize into it; is_active re-derives hiddenServiceIds).
        if ($service->source === 'fresha') {
            app(FreshaServiceProjector::class)->refreshBlob($pro);
        }

        return $this->success(['service' => new ServiceResource($service->fresh())]);
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

        $this->authorizeForUser($pro, 'update', $service);

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

        DB::transaction(function () use ($pro, $service, $categoryIds) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-layout:{$pro->id}"]);

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

        return $this->success(['service' => new ServiceResource($service->fresh())]);
    }

    public function destroy(Request $request, Service $service): JsonResponse
    {
        $pro = $this->currentUser($request);

        $this->authorizeForUser($pro, 'delete', $service);

        // Deleting a Fresha-synced service records SUPPRESSION: the soft-deleted
        // row with deleted_origin='user' is what the next sync consults to never
        // resurrect this serviceId (PurgeSoftDeleted excludes these rows).
        if ($service->source === 'fresha') {
            $service->deleted_origin = 'user';
            $service->saveQuietly();
        }

        $service->delete();

        if ($service->source === 'fresha') {
            app(FreshaServiceProjector::class)->refreshBlob($pro);
        }

        return $this->success(['deleted' => true]);
    }

    // Old flat reorder (kept for compatibility)
    public function reorder(ReorderServiceRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);

        // SEC-6: pending-deletion + ownership gate via ServicePolicy, matching
        // this controller's own store()/update()/destroy().
        // SEC-1: user_id is no longer fillable — direct assignment so the policy still sees the owner.
        $skeleton = new Service;
        $skeleton->user_id = $pro->id;
        $this->authorizeForUser($pro, 'update', $skeleton);

        // EDGE-1 (audit): mass `update()` inside ReorderService bypasses Eloquent
        // events, so ServiceObserver never touches the site. Touch explicitly in
        // afterCommit to fire SiteObserver — Redis invalidation + Cloudflare edge
        // purge + cache warm (mirrors UserGalleryController::reorder).
        try {
            app(ReorderService::class)->reorder(
                $request->input('ids', []),
                Service::query()->where('user_id', $pro->id),
                "services:{$pro->id}",
                fn () => $site->touch(),
                AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS,
            );
        } catch (AdvisoryLockTimeoutException) {
            // U2: same contention/423 as store() above.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        return $this->success(['ok' => true]);
    }

    // NEW: full layout reorder (categories + services within each category)
    public function reorderLayout(ReorderServiceLayoutRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);

        // SEC-6: pending-deletion + ownership gate via ServicePolicy, matching
        // this controller's own store()/update()/destroy()/reorder().
        // SEC-1: user_id is no longer fillable — direct assignment so the policy still sees the owner.
        $skeleton = new Service;
        $skeleton->user_id = $pro->id;
        $this->authorizeForUser($pro, 'update', $skeleton);

        $payload = $request->validated();

        DB::transaction(function () use ($pro, $payload) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-layout:{$pro->id}"]);

            $activeCategoryIds = ServiceCategory::query()
                ->where('user_id', $pro->id)
                ->pluck('id')
                ->all();
            $activeCategorySet = array_flip($activeCategoryIds);

            $activeServiceIds = Service::query()
                ->where('user_id', $pro->id)
                ->pluck('id')
                ->all();
            $activeServiceSet = array_flip($activeServiceIds);

            $providedCategoryIds = [];
            $seenPerBlock = [];
            $membershipsByService = [];
            $uncategorisedIds = [];

            foreach ($payload['categories'] as $bi => $catBlock) {
                $catId = $catBlock['id'] ?? null;

                if ($catId !== null) {
                    if (! isset($activeCategorySet[$catId])) {
                        abort(422, 'One or more category IDs are invalid.');
                    }
                    $providedCategoryIds[] = $catId;
                }

                foreach ($catBlock['service_ids'] as $sid) {
                    if (! isset($activeServiceSet[$sid])) {
                        abort(422, 'One or more service IDs are invalid.');
                    }
                    // Multi-category: a service MAY appear in several category
                    // blocks (one membership per block) — but never twice in the
                    // same block, and never both categorised AND uncategorised.
                    if (isset($seenPerBlock[$bi][$sid])) {
                        abort(422, 'Duplicate service IDs detected within a category block.');
                    }
                    $seenPerBlock[$bi][$sid] = true;

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

            // Every active service must appear somewhere (its memberships, or
            // the uncategorised block for zero memberships).
            $coveredIds = array_unique([...array_keys($membershipsByService), ...array_keys($uncategorisedIds)]);
            if (count($coveredIds) !== count($activeServiceIds)) {
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

            // Apply category order + service order + MEMBERSHIPS. The layout
            // payload is the full membership map now: a service's memberships
            // become exactly the category blocks it appears in (zero for the
            // uncategorised block). Services get a GLOBAL running sort_order
            // from their FIRST occurrence across every bucket — the partial
            // UNIQUE index (user_id, sort_order) is professional-scoped, and
            // the display orders by category sort_order then service
            // sort_order, so first-occurrence order preserves each category's
            // internal order while satisfying the index.
            //
            // sort_order applies in two passes: the unique index is checked on
            // every individual UPDATE (not deferred), so a single pass can
            // transiently collide with a row that hasn't been updated yet but
            // still occupies the target value (e.g. a straight swap). Pass 1
            // parks every service at a temporary value far above any real
            // sort_order; pass 2 writes the real 0..N-1 values.
            $categorySort = 0;
            $orderedServiceIds = [];

            foreach ($payload['categories'] as $catBlock) {
                $catId = $catBlock['id'] ?? null;

                if ($catId !== null) {
                    ServiceCategory::query()
                        ->where('user_id', $pro->id)
                        ->where('id', $catId)
                        ->update(['sort_order' => $categorySort++]);
                }

                foreach ($catBlock['service_ids'] as $serviceId) {
                    if (! in_array($serviceId, $orderedServiceIds, true)) {
                        $orderedServiceIds[] = $serviceId;
                    }
                }
            }

            foreach ($orderedServiceIds as $i => $serviceId) {
                Service::query()
                    ->where('user_id', $pro->id)
                    ->where('id', $serviceId)
                    ->update(['sort_order' => 1_000_000 + $i]);
            }

            foreach ($orderedServiceIds as $i => $serviceId) {
                Service::query()
                    ->where('user_id', $pro->id)
                    ->where('id', $serviceId)
                    ->update(['sort_order' => $i]);
            }

            // Membership sync per service (replace-set semantics).
            foreach ($orderedServiceIds as $serviceId) {
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
        });

        // EDGE-1 (audit): reorderLayout bypasses ReorderService entirely (raw
        // DB::transaction + per-row query-builder update()), so no observer ever
        // fires here either — touch explicitly after commit, same reasoning as
        // reorder() above.
        $site->touch();

        return $this->success(['ok' => true]);
    }

    public function restore(Request $request, Service $service): JsonResponse
    {
        $pro = $this->currentUser($request);

        $this->authorizeForUser($pro, 'update', $service);

        if (! $service->trashed()) {
            return $this->success(['restored' => true, 'service' => new ServiceResource($service->fresh())]);
        }

        DB::transaction(function () use ($pro, $service) {
            // Compute the next sort_order BEFORE restoring. The partial unique
            // index (user_id, sort_order) WHERE deleted_at IS NULL is
            // global per professional — category_id is not part of it. Another
            // service may have claimed this slot while this one was soft-deleted,
            // so restore() would violate the constraint if called first.
            $max = Service::query()
                ->where('user_id', $pro->id)
                ->whereNull('deleted_at')
                ->max('sort_order');

            $service->sort_order = is_null($max) ? 0 : ((int) $max + 1);
            // Restoring a suppressed Fresha service is the owner explicitly
            // un-deleting it — clear the suppression so sync treats it live again.
            if ($service->source === 'fresha') {
                $service->deleted_origin = null;
            }
            $service->saveQuietly(); // update sort_order while still soft-deleted

            $service->restore();
        });

        if ($service->source === 'fresha') {
            app(FreshaServiceProjector::class)->refreshBlob($pro);
        }

        return $this->success(['restored' => true, 'service' => new ServiceResource($service->fresh())]);
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
}
