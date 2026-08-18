<?php

namespace App\Http\Controllers\Api\Site;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Sections\UpsertSectionGroupRequest;
use App\Http\Resources\Site\SectionGroupResource;
use App\Models\Core\Site\Section;
use App\Models\Core\Site\SectionGroup;
use App\Models\Core\Site\Site;
use App\Site\Documents\SiteCacheLanes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-section group label and order overrides (plan §7).
 *
 * A `group_by` of `category` has collection rows to hang a label on. `month`,
 * `letter` and `source` do not — they produce keys. This table is where a
 * section says "call the 2026-07 group 'This month' and put it first", without
 * that opinion leaking into the cross-source collection data everything else
 * shares.
 *
 * Authorised via the parent section, like section_items.
 *
 * Adopts {@see SiteCacheLanes} for uniformity with the
 * rest of the curation surface (#PGR-36) even though no public reader of
 * `site.section_groups` was found anywhere in `app/Site` or
 * `app/Services/PublicSite` — the owner chose consistency across the write
 * surface over skipping the seam on a table nothing currently reads. Written
 * down so the next person doesn't mistake this for cargo-culted boilerplate.
 */
class SectionGroupController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function index(Request $request, string $sectionId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $section = $this->findSection($site, $sectionId);

        $this->authorizeForUser($user, 'view', $section);

        $groups = SectionGroup::query()
            ->where('section_id', $section->id)
            ->orderBy('sort_order')
            ->orderBy('group_key')
            ->get();

        return $this->success(['groups' => SectionGroupResource::collection($groups)]);
    }

    public function upsert(UpsertSectionGroupRequest $request, string $sectionId, string $groupKey): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $section = $this->findSection($site, $sectionId);

        $this->authorizeForUser($user, 'update', $section);

        $row = SectionGroup::query()
            ->where('section_id', $section->id)
            ->where('group_key', $groupKey)
            ->first() ?? new SectionGroup;

        $row->section_id = $section->id;
        $row->group_key = $groupKey;
        $row->fill($request->validated());
        $row->save();

        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success(['group' => new SectionGroupResource($row)]);
    }

    /** Delete the override; the group falls back to its natural label and order. */
    public function destroy(Request $request, string $sectionId, string $groupKey): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $section = $this->findSection($site, $sectionId);

        $this->authorizeForUser($user, 'update', $section);

        $deleted = SectionGroup::query()
            ->where('section_id', $section->id)
            ->where('group_key', $groupKey)
            ->delete();

        if ($deleted === 0) {
            abort(404, 'That group has no override in this section.');
        }

        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success(['deleted' => true]);
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
}
