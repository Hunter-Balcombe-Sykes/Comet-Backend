<?php

namespace App\Http\Controllers\Api\Site;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Sections\UpsertSectionItemRequest;
use App\Http\Resources\Site\SectionItemResource;
use App\Models\Content\Item;
use App\Models\Core\Site\Section;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pins and excludes — the only two curation verbs (plan §7, C4).
 *
 * An ingest run may never write either of these rows. That is the whole point
 * of moving curation off the item and onto the section: a re-scrape can add,
 * change or remove content, and it still cannot un-hide something the user
 * hid or un-pin something they pinned.
 *
 * Authorisation is on the parent SECTION. section_items carry no user_id and
 * are only ever reachable through a section route, so authorising the section
 * IS authorising its rows (the MenuItem precedent).
 *
 * This is the SECOND pin path onto `site.section_items` — {@see
 * \App\Http\Controllers\Api\Content\PoolController} is the other — and as of
 * #PGR-36 it discharges the same three cache lanes {@see
 * \App\Site\Documents\SiteCacheLanes} that `PoolController::poolChanged()`
 * does (`PoolController.php:226`), not just a build-state bump.
 */
class SectionItemController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function index(Request $request, string $sectionId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $section = $this->findSection($site, $sectionId);

        $this->authorizeForUser($user, 'view', $section);

        $curation = SectionItem::query()
            ->where('section_id', $section->id)
            ->orderBy('state')
            ->orderByRaw('sort_key is null, sort_key')
            ->get();

        return $this->success(['curation' => SectionItemResource::collection($curation)]);
    }

    /**
     * Pin or exclude. Upsert rather than create: the table's unique key is
     * (section, item), so pressing "hide" on something you had pinned must
     * change the row rather than fail — the user changed their mind, they did
     * not make a mistake.
     */
    public function upsert(UpsertSectionItemRequest $request, string $sectionId, string $itemId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $section = $this->findSection($site, $sectionId);

        $this->authorizeForUser($user, 'update', $section);

        $item = $this->findItem($user->id, $itemId);
        $this->authorizeForUser($user, 'view', $item);

        $data = $request->validated();

        // Slice 6 §4.3: reviews may be hidden but not promoted — owner
        // decision 2026-08-13. Refused here rather than in the request rule
        // because the rule has no pool context; PoolRegistry::allowsPin is the
        // one source of truth and the pool lane reads the same const. A
        // capability rule about the pool, not authorization on a resource,
        // so 422 rather than a policy.
        $pool = PoolRegistry::poolForSectionKey((string) $section->key);
        if ($data['state'] === SectionItem::STATE_PINNED && $pool !== null && ! PoolRegistry::allowsPin($pool)) {
            abort(422, 'Reviews can be hidden, but not pinned.');
        }

        $row = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('item_id', $item->id)
            ->first() ?? new SectionItem;

        $row->section_id = $section->id;
        $row->item_id = (string) $item->id;
        $row->state = $data['state'];
        // An exclusion has no position. Storing one would leave a stale sort
        // key to resurface if the row later became a pin again.
        $row->sort_key = $data['state'] === SectionItem::STATE_PINNED
            ? ($data['sort_key'] ?? $this->nextSortKey($section))
            : null;

        if (! $row->exists) {
            $row->created_at = now();
        }

        $row->save();

        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success(['curation' => new SectionItemResource($row)]);
    }

    /** Clear the row: the item goes back to whatever the rule says about it. */
    public function destroy(Request $request, string $sectionId, string $itemId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $section = $this->findSection($site, $sectionId);

        $this->authorizeForUser($user, 'update', $section);

        $deleted = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('item_id', $itemId)
            ->delete();

        if ($deleted === 0) {
            abort(404, 'That item is not pinned or hidden in this section.');
        }

        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success(['deleted' => true]);
    }

    /**
     * Append: one above the current highest. Fractional keys mean a later drag
     * between two neighbours only ever writes the row that moved.
     */
    private function nextSortKey(Section $section): float
    {
        $highest = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('state', SectionItem::STATE_PINNED)
            ->max('sort_key');

        return $highest === null ? 1.0 : ((float) $highest) + 1.0;
    }

    private function findSection(Site $site, string $sectionId): Section
    {
        $section = Section::query()->where('id', $sectionId)->where('site_id', $site->id)->first();

        if ($section === null) {
            abort(404, 'Section not found.');
        }

        $section->setRelation('site', $site);

        return $section;
    }

    /**
     * Scoped to the caller's own items: pinning someone else's item would
     * publish their content on your page, so a foreign id is a 404 rather
     * than a row that quietly does nothing.
     */
    private function findItem(string $userId, string $itemId): Item
    {
        $item = Item::query()->where('id', $itemId)->where('user_id', $userId)->first();

        if ($item === null) {
            abort(404, 'Item not found.');
        }

        return $item;
    }
}
