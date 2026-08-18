<?php

namespace App\Http\Controllers\Api\Site;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Sections\StoreSectionRequest;
use App\Http\Requests\Api\User\Sections\UpdateSectionRequest;
use App\Http\Resources\Site\SectionResource;
use App\Models\Core\Site\Page;
use App\Models\Core\Site\Section;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\PageCapabilities;
use App\Site\Documents\SiteCacheLanes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Sections — what a page is made of (plan §7).
 *
 * A section decides its own membership from a bounded rule plus the user's
 * pins and excludes. There is no `is_selected` column anywhere, because
 * selection is a section's opinion about an item, not a property of the item:
 * the same track can be in Listen and not in Highlights without either
 * section lying.
 *
 * Every write discharges all three cache lanes ({@see
 * \App\Site\Documents\SiteCacheLanes}), not just a build-state bump (#PGR-36).
 * `rule` is editable through this endpoint, and a section's rule feeds {@see
 * \App\Site\Sections\SectionCandidates::ruleCandidates()}
 * (`SectionCandidates.php:93`) — the same resolver path a pin takes when
 * deciding what's on the page. Editing a rule can change page contents just
 * as directly as pinning an item does, so it needs the same purge, not a
 * lesser one.
 */
class SectionController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        $query = Section::query()
            ->where('site_id', $site->id)
            ->with(['curation', 'groups']);

        if ($request->filled('page_id')) {
            $query->where('page_id', $request->string('page_id')->toString());
        }

        $sections = $query->orderBy('sort_order')->orderBy('created_at')->get();

        return $this->success(['sections' => SectionResource::collection($sections)]);
    }

    public function show(Request $request, string $sectionId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $section = $this->findSection($site, $sectionId);

        $this->authorizeForUser($user, 'view', $section);
        $section->load(['curation', 'groups']);

        return $this->success(['section' => new SectionResource($section)]);
    }

    public function store(StoreSectionRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $data = $request->validated();

        $page = $this->findPage($site->id, $data['page_id']);

        $section = new Section($data);
        $section->site_id = $site->id;
        $section->page_id = $page->id;
        $section->setRelation('site', $site);

        $this->authorizeForUser($user, 'create', $section);
        $this->assertRuleIsPermitted($user, $data['rule'] ?? []);
        $this->assertKeyIsFree($site->id, $data['key'] ?? null, null);

        $section->save();

        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success(['section' => new SectionResource($section)], 201);
    }

    public function update(UpdateSectionRequest $request, string $sectionId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $section = $this->findSection($site, $sectionId);

        $this->authorizeForUser($user, 'update', $section);

        $data = $request->validated();

        if (array_key_exists('rule', $data)) {
            $this->assertRuleIsPermitted($user, $data['rule']);
        }

        if (array_key_exists('key', $data)) {
            $this->assertKeyIsFree($site->id, $data['key'], (string) $section->id);
        }

        if (array_key_exists('page_id', $data)) {
            // Re-resolved through the caller's own site: a page id from
            // somewhere else must 404, not silently move the section.
            $section->page_id = $this->findPage($site->id, $data['page_id'])->id;
            unset($data['page_id']);
        }

        $section->fill($data)->save();

        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success(['section' => new SectionResource($section->fresh()->load(['curation', 'groups']))]);
    }

    public function destroy(Request $request, string $sectionId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $section = $this->findSection($site, $sectionId);

        $this->authorizeForUser($user, 'delete', $section);

        $section->delete();

        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success(['deleted' => true]);
    }

    private function findSection(Site $site, string $sectionId): Section
    {
        $section = Section::query()->where('id', $sectionId)->where('site_id', $site->id)->first();

        if ($section === null) {
            abort(404, 'Section not found.');
        }

        // The policy resolves ownership through this relation and independently
        // re-checks site_id, so preloading it here is both the N+1 fix and the
        // spoofing guard.
        $section->setRelation('site', $site);

        return $section;
    }

    private function findPage(string $siteId, string $pageId): Page
    {
        $page = Page::query()->where('id', $pageId)->where('site_id', $siteId)->first();

        if ($page === null) {
            abort(404, 'Page not found.');
        }

        return $page;
    }

    /**
     * A rule naming a gated kind is that gated page wearing a different hat:
     * `kind_is menu_item` on a generic page is still a menu.
     *
     * @param  array<string, mixed>  $rule
     */
    private function assertRuleIsPermitted(User $user, array $rule): void
    {
        $denied = PageCapabilities::deniedKindIn($user, $rule);

        if ($denied !== null) {
            throw ValidationException::withMessages([
                'rule' => 'Your account does not have "'.str_replace('_', ' ', $denied).'" content.',
            ]);
        }
    }

    private function assertKeyIsFree(string $siteId, ?string $key, ?string $exceptSectionId): void
    {
        if ($key === null || $key === '') {
            return;
        }

        $exists = Section::query()
            ->where('site_id', $siteId)
            ->where('key', $key)
            ->when($exceptSectionId !== null, fn ($query) => $query->where('id', '!=', $exceptSectionId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'key' => 'Another section already uses that name.',
            ]);
        }
    }
}
