<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\AddCustomLinkRequest;
use App\Jobs\Content\EnrichPoolLinkJob;
use App\Models\Core\User\User;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Content\LinkPoolReader;
use App\Services\Content\LinkPoolWriter;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Arbitrary user-attached URLs rendered as a Links section on the sitepage.
//
// Convergence Phase 6: these are `content.items` of kind `link` in the
// `custom_links` pool, NOT `partna.custom_link` connections. Phase 3 carried the
// 23 existing connections onto the pool and named this move explicitly — "the
// pool is a SNAPSHOT until Phase 6 … moving the write path before then would
// mean building it twice" (parent spec §22). The endpoints and their JSON are
// unchanged so the dashboard keeps working; only the storage moved.
//
// Two deliberate consequences of that move, both inherited from Phase 3's own
// ruling rather than decided here:
//   - `favicon` and `logo` are always null. Minting content.media_assets for
//     third-party image URLs pulls slice 1a's borrowed-asset lane in for
//     decoration; if link cards want brand marks that is its own decision with
//     its own storage question.
//   - `id` is now a content item uuid, not `link-<hash>`. The dashboard
//     round-trips whatever ids this endpoint hands it, so the change is opaque
//     to it — but anything that PARSED the old prefix would break.
//
// ManagesIntegrationConnection is gone from this class: with no connection row
// there is no per-platform lock to take, and the pool write is a single
// idempotent upsert on a deterministic coord rather than a read-mutate-write
// cycle, so the lost-update hazard that lock existed for cannot arise.
class CustomLinksController extends ApiController
{
    use ResolveCurrentUser;

    private const MAX_LINKS = 20;

    public function __construct(
        private readonly LinkCardScraper $scraper,
        private readonly LinkRouter $router,
        private readonly LinkPoolWriter $writer,
        private readonly LinkPoolReader $reader,
    ) {}

    // GET /api/platforms/custom/links — every attached link, in pin order.
    public function links(Request $request): JsonResponse
    {
        return $this->success(['links' => $this->linksData($this->currentUser($request))]);
    }

    // POST /api/platforms/custom/links — attach a URL. Routes through LinkRouter
    // first: a URL that is a known platform becomes the right connection type
    // (booking / reservations / social / …) instead of a link; otherwise it
    // falls through to the pool write below.
    //
    // Response contract is unchanged (the plan's option (a)): the routed
    // branches keep the 202-shaped envelope and add `routedTo`. Verified against
    // the dashboard — custom-links-section.tsx's handleAdd() reads ONLY
    // `body.links` (Array.isArray-guarded). Do NOT switch to a shape that omits
    // `links`: a routed add would then leave the list stale until the next GET.
    public function addLink(AddCustomLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $url = $this->scraper->normalizeUrl($request->validated()['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }

        // maxProbes: 0 — a MANUAL add deliberately never commerce-probes. The
        // probe exists for SCANNED links, where an unclassified URL might be the
        // business's own store nobody told us about (signup-v2 C4). Here the user
        // has explicitly said "add this as a link", and probing first would mean
        // an ordinary unclassified URL returns 'pending' with no card in the list
        // until the probe misses. Flip to 1 if manual adds should probe.
        $result = $this->router->route($user, $url, new RouteContext(maxProbes: 0));

        if ($result->outcome === 'seeded' || $result->outcome === 'pending') {
            return $this->success([
                'status' => $result->outcome === 'seeded' ? 'ok' : 'pending',
                'routedTo' => ['platform' => $result->platform, 'category' => $result->category],
                'links' => $this->linksData($user),
            ], 202);
        }

        // outcome === 'custom' or 'skipped' — this really is a link.
        $existing = collect($this->reader->cards($user))
            ->first(fn (array $card) => is_string($card['url']) && $this->scraper->normalizeUrl($card['url']) === $url);

        if ($existing === null && count($this->reader->cards($user)) >= self::MAX_LINKS) {
            return $this->error('You can add up to '.self::MAX_LINKS.' links.', 422);
        }

        // The item exists the moment this returns — the pool write is
        // synchronous, unlike the connection lane's pending card. The title is
        // the URL host until EnrichPoolLinkJob upgrades it off-thread.
        $itemId = $this->writer->add($user, $url);
        EnrichPoolLinkJob::dispatch((string) $user->id, $url)->afterCommit();

        return $this->success([
            'status' => 'pending',
            'link' => collect($this->linksData($user))->firstWhere('id', $itemId),
            'statusUrl' => url("/api/platforms/custom/links/{$itemId}/status"),
        ], 202);
    }

    // GET /api/platforms/custom/links/{id}/status — poll title enrichment.
    //
    // 'ready' the moment the item exists, which is immediately: the card is
    // usable from the first response and only its TITLE improves later. The old
    // connection lane could answer 'pending' because it wrote a row with
    // last_refresh_status='pending'; a pool item carries no such field, and
    // inventing one to reproduce a spinner would be storing UI state in content.
    public function linkStatus(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $this->reader->owns($user, $id)) {
            return $this->error('Link not found.', 404);
        }

        return $this->success(['status' => 'ready', 'links' => $this->linksData($user)]);
    }

    // PUT /api/platforms/custom/links/order — persist the owner's order.
    public function reorderLinks(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:'.self::MAX_LINKS],
            'ids.*' => ['string'],
        ])['ids'];

        $known = array_column($this->reader->cards($user), 'id');
        foreach ($ids as $id) {
            if (! in_array($id, $known, true)) {
                return $this->error('Link not found.', 404);
            }
        }

        // Pin order IS the public order — sort_key is the column PoolResolver
        // reads — so the dashboard, the payload and the sitepage cannot disagree.
        // The connection lane needed an explicit $site->touch() here because a
        // pure sort_order shuffle fired no observer; SiteCacheLanes::bust() inside
        // reorder() covers all three lanes.
        $this->reader->reorder($user, $ids);

        return $this->success(['links' => $this->linksData($user)]);
    }

    // DELETE /api/platforms/custom/links/{id} — remove one link.
    public function removeLink(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $this->reader->remove($user, $id)) {
            return $this->error('Link not found.', 404);
        }

        return $this->success(['links' => $this->linksData($user)]);
    }

    // DELETE /api/platforms/custom — remove every link.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $this->reader->removeAll($user);

        return $this->success(['links' => []]);
    }

    // ── internals ────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function linksData(User $user): array
    {
        // link_item ranks are keyed by the link's URL, not its id — what
        // ContentFreshness/ComputeContentPopularityScores write as the link_item
        // content_key. Fail-open: a read fault degrades to null ranks.
        $ranks = app(ContentPopularityReader::class)->forSite($user->site?->id);
        $linkRanks = $ranks['link_item'] ?? [];

        return array_map(
            fn (array $card): array => [...$card, 'popularityRank' => $linkRanks[(string) $card['url']] ?? null],
            $this->reader->cards($user),
        );
    }
}
