<?php

namespace App\Http\Controllers\Api\Site;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Sections\StorePageRequest;
use App\Http\Requests\Api\User\Sections\UpdatePageRequest;
use App\Http\Resources\Site\PageResource;
use App\Models\Core\Site\Page;
use App\Models\Core\User\User;
use App\Services\Content\PageCapabilities;
use App\Site\Documents\SiteCacheLanes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pages — the navigation unit of a sitepage (plan §7).
 *
 * One nav row per PAGE, never per section: a menu with five groups is still
 * one "Menu" link. That is why pages, not sections, own `key`, `label` and
 * `is_hidden`.
 *
 * Capability is enforced HERE, at write time. The read-time capability filter
 * died with SitepageId, so a page an account may not have must fail to be
 * created rather than exist and be silently dropped at render.
 */
class PageController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        $pages = Page::query()
            ->where('site_id', $site->id)
            ->with(['sections' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        return $this->success(['pages' => PageResource::collection($pages)]);
    }

    public function store(StorePageRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $data = $request->validated();

        $page = new Page($data);
        $page->site_id = $site->id;
        $page->setRelation('site', $site);

        $this->authorizeForUser($user, 'create', $page);
        $this->assertCapability($user, $data['capability'] ?? null);
        $this->assertKeyIsFree($site->id, $data['key']);

        $page->save();

        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success(['page' => new PageResource($page)], 201);
    }

    public function update(UpdatePageRequest $request, string $pageId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $page = $this->findPage($site->id, $pageId);

        $page->setRelation('site', $site);
        $this->authorizeForUser($user, 'update', $page);

        $data = $request->validated();

        if (array_key_exists('capability', $data)) {
            $this->assertCapability($user, $data['capability']);
        }

        if (array_key_exists('key', $data) && $data['key'] !== $page->key) {
            $this->assertKeyIsFree($site->id, $data['key']);
        }

        $page->fill($data)->save();

        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success(['page' => new PageResource($page->fresh())]);
    }

    /**
     * Deleting a page deletes its sections (FK CASCADE) and with them their
     * pins — which is why the dashboard confirms first. Nothing in
     * content.items is touched: a page is an arrangement, not a container.
     */
    public function destroy(Request $request, string $pageId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $page = $this->findPage($site->id, $pageId);

        $page->setRelation('site', $site);
        $this->authorizeForUser($user, 'delete', $page);

        $page->delete();

        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success(['deleted' => true]);
    }

    /**
     * 404, never 403: an actor who reached here submitted a valid UUID, and
     * confirming that it exists somewhere else would hand them an enumeration
     * oracle.
     */
    private function findPage(string $siteId, string $pageId): Page
    {
        $page = Page::query()->where('id', $pageId)->where('site_id', $siteId)->first();

        if ($page === null) {
            abort(404, 'Page not found.');
        }

        return $page;
    }

    private function assertCapability(User $user, ?string $capability): void
    {
        if (! PageCapabilities::allows($user, $capability)) {
            throw ValidationException::withMessages([
                'capability' => 'Your account does not have that page type.',
            ]);
        }
    }

    /**
     * Checked in PHP rather than left to the unique index: a raw constraint
     * violation surfaces as a 500, and "that address is already used" is a
     * thing the user can act on.
     */
    private function assertKeyIsFree(string $siteId, string $key): void
    {
        if (Page::query()->where('site_id', $siteId)->where('key', $key)->exists()) {
            throw ValidationException::withMessages([
                'key' => 'You already have a page at that address.',
            ]);
        }
    }
}
