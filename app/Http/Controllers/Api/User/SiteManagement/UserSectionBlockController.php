<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\User\Site\UpsertSectionBlockRequest;
use App\Http\Resources\SectionBlockResource;
use App\Models\Core\Site\Block;
use App\Services\User\SectionVisibilityService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Manages site section visibility (gallery, services, shop, booking, bio). Account-type restrictions apply.
class UserSectionBlockController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    public function index(Request $request)
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);

        // All accounts are individual; all configured section types are allowed.
        $allowedSections = config('partna.section_block_types', []);
        $allSections = $allowedSections;
        $unavailableSections = [];

        // Read all section blocks once. We need every type (not just allowed)
        // to detect drift correctly — `syncAllowedSections` historically ran
        // unconditionally inside a write transaction + advisory lock on every
        // GET, serializing concurrent dashboard polls. The lazy fast path here
        // keeps that guarantee while skipping the lock when state is already in sync.
        $allSectionBlocks = $pro->sectionBlocks()
            ->where('site_id', $site->id)
            ->orderBy('sort_order')
            ->get();

        if ($this->needsSyncForAllowed($allSectionBlocks, $allowedSections)) {
            $this->syncAllowedSections($pro->id, $site->id, $allowedSections);
            $allSectionBlocks = $pro->sectionBlocks()
                ->where('site_id', $site->id)
                ->orderBy('sort_order')
                ->get();
        }

        // Returns both published and drafted sections so the dashboard can
        // render the Draft → Live toggle for each. The is_enabled filter
        // used to hide drafts but was always-true for allowed sections
        // anyway — dropping it is explicit, not behavioural.
        $sections = $allSectionBlocks
            ->filter(fn (Block $b) => in_array($b->block_type, $allowedSections, true))
            ->values();

        // Batched visibility: replaces N×checkVisibilityRequirements() (each
        // doing 1–4 exists() queries) with one pass that loads each data-source
        // at most once across all sections.
        $visibilityMap = $this->visibilityService->batchCheck(
            (string) $pro->id,
            (string) $site->id,
            $sections,
        );

        return $this->success([
            'sections' => $sections
                ->map(fn (Block $section) => new SectionBlockResource(
                    $section,
                    $visibilityMap[(string) $section->block_type] ?? [true, null],
                ))
                ->values(),
            'allowed_sections' => array_values($allowedSections),
            'unavailable_sections' => $unavailableSections,
        ]);
    }

    /**
     * Determine whether the stored section blocks have drifted from the allowed
     * set — i.e. an allowed type has no row yet. `is_enabled` is no longer a
     * sync-managed field (observers + reevaluateEnabled own it now), so we
     * deliberately do NOT treat is_enabled=false as drift; that's a legitimate
     * state meaning "data requirements aren't met yet."
     *
     * @param  \Illuminate\Support\Collection<int, Block>  $sectionBlocks
     * @param  array<int, string>  $allowedSections
     */
    private function needsSyncForAllowed($sectionBlocks, array $allowedSections): bool
    {
        $byType = $sectionBlocks->keyBy('block_type');

        foreach ($allowedSections as $type) {
            if (! is_string($type)) {
                continue;
            }
            if (! $byType->has($type)) {
                return true;
            }
        }

        return false;
    }

    public function upsert(UpsertSectionBlockRequest $request, string $blockType)
    {
        $pro = $this->currentUser($request);

        $site = $this->currentSite($pro);

        $data = $request->validated();
        $allowedSections = config('partna.section_block_types', []);
        if (! in_array($blockType, $allowedSections, true)) {
            // 422 not 403: unknown blockType is invalid input, not an authz failure.
            return $this->error('This section type is not recognised.', 422);
        }

        $this->syncAllowedSections($pro->id, $site->id, $allowedSections);
        $existingBlock = Block::query()
            ->where('user_id', $pro->id)
            ->where('site_id', $site->id)
            ->where('block_group', Block::GROUP_SECTIONS)
            ->where('block_type', $blockType)
            ->first();

        $requestedPublicationState = is_string($data['publication_state'] ?? null)
            ? mb_strtolower(trim((string) $data['publication_state']))
            : null;
        $nextIsLive = match (true) {
            $requestedPublicationState === 'live' => true,
            $requestedPublicationState === 'draft' => false,
            array_key_exists('is_active', $data) => (bool) $data['is_active'],
            $existingBlock !== null => (bool) $existingBlock->is_active,
            default => false,
        };
        $currentlyIsLive = $existingBlock ? (bool) $existingBlock->is_active : false;
        $isPublishing = $nextIsLive && ! $currentlyIsLive;

        // Keep setup requirements tied to publishing Live state.
        // For countdown, pass through the incoming settings — its requirement
        // (a valid timeline) lives in the payload itself, not in an external
        // resource, so first-time publish with timeline + live in the same
        // request must see the pending values, not the pre-save stored ones.
        if ($isPublishing) {
            [$canBeVisible, $reason] = $this->visibilityService->checkVisibilityRequirements(
                (string) $pro->id,
                (string) $site->id,
                $blockType,
                is_array($data['settings'] ?? null) ? $data['settings'] : null,
            );
            if (! $canBeVisible) {
                return $this->error($reason, 422);
            }
        }

        $block = DB::transaction(function () use ($pro, $site, $data, $blockType, $nextIsLive) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);

            $block = Block::query()->firstOrNew([
                'user_id' => $pro->id,
                'site_id' => $site->id,
                'block_group' => Block::GROUP_SECTIONS,
                'block_type' => $blockType,
            ]);

            if (! $block->exists) {
                // Use max+1, not count(), to stay gap-safe against the partial
                // unique index on (site_id, block_group, sort_order) WHERE
                // block_group = 'sections'. count() collides if any row was
                // hard-deleted. ?? -1 then +1 yields 0 for an empty set.
                $maxSortOrder = Block::query()
                    ->where('site_id', $site->id)
                    ->where('block_group', Block::GROUP_SECTIONS)
                    ->max('sort_order') ?? -1;
                $block->sort_order = (int) $maxSortOrder + 1;
                $block->settings = $data['settings'] ?? [];
            }

            $block->is_active = $nextIsLive;

            // merge settings (PATCH semantics)
            if (array_key_exists('settings', $data)) {
                $existing = is_array($block->settings) ? $block->settings : [];
                $incoming = is_array($data['settings']) ? $data['settings'] : [];
                $block->settings = array_replace_recursive($existing, $incoming);
            } elseif (! $block->exists) {
                $block->settings = [];
            }

            // Re-evaluate is_enabled from the post-merge state. Pending settings
            // (countdown timeline, contact email) are passed through so first-time
            // publish where settings + Live arrive together sees the same merged
            // shape that's about to be saved. Public render path filters on
            // is_enabled, so any drift here would silently hide the section.
            [$canBeEnabled] = $this->visibilityService->checkVisibilityRequirements(
                (string) $pro->id,
                (string) $site->id,
                $blockType,
                is_array($data['settings'] ?? null) ? $data['settings'] : null,
            );
            $block->is_enabled = $canBeEnabled;

            $block->save();

            return $block->fresh();
        });

        // Keep professional.bio in sync with the "bio" section text (only when text was sent)
        if (
            $blockType === 'bio'
            && array_key_exists('settings', $data)
            && is_array($data['settings'])
            && array_key_exists('text', $data['settings'])
        ) {
            $pro->bio = data_get($block->settings, 'text'); // merged + saved value
            $pro->save();
        }

        return $this->success([
            'section' => new SectionBlockResource($block->fresh()),
        ], $block->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Reorder section blocks for the current professional. Accepts an `ids` array
     * representing the new order; any sections owned by this site that aren't in
     * the array keep their current relative order and follow the supplied ids.
     *
     * The two-pass renumber (offset by max+1000, then 0..n) avoids transient
     * unique-violation collisions if a unique index on sort_order is ever added.
     */
    public function reorder(ReorderBlocksRequest $request)
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);

        $ids = array_values(array_unique($request->validated()['ids'] ?? []));

        DB::transaction(function () use ($pro, $site, $ids) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);

            $allIds = Block::query()
                ->where('user_id', $pro->id)
                ->where('site_id', $site->id)
                ->where('block_group', Block::GROUP_SECTIONS)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);

            foreach ($ids as $id) {
                if (! isset($allSet[$id])) {
                    abort(422, 'One or more sections are invalid');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder = array_merge($ids, $remaining);
            $offset = (int) Block::query()
                ->where('user_id', $pro->id)
                ->where('site_id', $site->id)
                ->where('block_group', Block::GROUP_SECTIONS)
                ->max('sort_order') + 1000;

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('user_id', $pro->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', Block::GROUP_SECTIONS)
                    ->where('id', $id)
                    ->update(['sort_order' => $offset + $i]);
            }

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('user_id', $pro->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', Block::GROUP_SECTIONS)
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        // Mass-update via the query builder bypasses BlockObserver. Explicit
        // Site touch fires SiteObserver::saved → CloudflareCachePurgeJob +
        // §28.8 cache key rotation. Without this, sort_order changes don't
        // reflect at <handle>.partna.au for up to 5 min.
        $site->touch();

        return $this->success(['ok' => true]);
    }

    public function remove(Request $request, string $blockType)
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);
        $allowedSections = config('partna.section_block_types', []);
        if (! in_array($blockType, $allowedSections, true)) {
            // 422 not 403: unknown blockType is invalid input, not an authz failure.
            return $this->error('This section type is not recognised.', 422);
        }

        $this->syncAllowedSections($pro->id, $site->id, $allowedSections);
        $block = Block::query()
            ->where('user_id', $pro->id)
            ->where('site_id', $site->id)
            ->where('block_group', Block::GROUP_SECTIONS)
            ->where('block_type', $blockType)
            ->first();

        if ($block) {
            // DELETE behaves as "move to draft" for backward compatibility.
            // is_enabled (the requirements gate) is owned by the visibility
            // observers — leaving it untouched here means a remove can't
            // accidentally re-enable a section whose data went away.
            $block->is_active = false;
            $block->save();
        }

        return $this->success([
            'ok' => true,
            'section' => $block ? new SectionBlockResource($block->fresh()) : null,
        ]);
    }

    /**
     * Ensure every account-type-allowed section has a row. Never touches existing
     * rows' is_enabled / is_active — those are owned by the visibility observers
     * and the pro's Draft/Live toggle respectively. New rows are seeded with
     * is_enabled reflecting the current data state (so a freshly-created gallery
     * row starts is_enabled=false until the pro uploads images).
     *
     * Never changes sort_order for existing blocks — only assigns one to new blocks
     * (max existing + 1) to avoid conflicts with the partial unique index on
     * (site_id, block_group, sort_order) WHERE block_group = 'sections'.
     *
     * protected (not private) so a test subclass can stub it to a no-op, which
     * exercises upsert()'s new-block branch — that branch is otherwise only
     * reachable via a concurrent-request race, since this sync pre-creates the
     * row before upsert()'s firstOrNew(). See SectionBlockUpsertSortOrderTest.
     *
     * @param  array<int, string>  $allowedSections
     */
    protected function syncAllowedSections(string $userId, string $siteId, array $allowedSections): Collection
    {
        $orderedAllowed = array_values(array_unique(array_filter($allowedSections, static fn ($value) => is_string($value))));

        return DB::transaction(function () use ($userId, $siteId, $orderedAllowed) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$siteId}"]);

            // Query ALL section blocks (not just allowed types) so the max sort_order
            // calculation accounts for every existing row and new blocks are never
            // inserted at a position already held by a non-allowed block.
            $allBlocks = Block::query()
                ->where('user_id', $userId)
                ->where('site_id', $siteId)
                ->where('block_group', Block::GROUP_SECTIONS)
                ->get();

            $byType = $allBlocks->keyBy('block_type');
            $maxSortOrder = $allBlocks->max('sort_order') ?? -1;

            foreach ($orderedAllowed as $blockType) {
                $existing = $byType->get($blockType);
                if ($existing) {
                    // Existing row: leave is_enabled / is_active untouched.
                    continue;
                }

                $block = new Block([
                    'user_id' => $userId,
                    'site_id' => $siteId,
                    'block_group' => Block::GROUP_SECTIONS,
                    'block_type' => $blockType,
                ]);

                $block->settings = [];
                $block->is_active = false;
                $block->sort_order = ++$maxSortOrder;

                // Seed is_enabled honestly from current data state. One exists()
                // per new block — only fires on first-time setup, so the cost is
                // one-shot, not hot-path.
                [$canBeEnabled] = $this->visibilityService->checkVisibilityRequirements(
                    $userId,
                    $siteId,
                    $blockType,
                );
                $block->is_enabled = $canBeEnabled;

                $block->save();
                $byType->put($blockType, $block);
            }

            return $byType->values();
        });
    }
}
