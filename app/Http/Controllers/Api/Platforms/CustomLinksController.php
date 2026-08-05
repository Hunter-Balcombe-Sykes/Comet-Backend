<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\AddCustomLinkRequest;
use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\RouteContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// The 'custom' integration — arbitrary user-attached URLs rendered as a
// Links section on the sitepage. Each link is one connection row
// (resource_id 'link-<hash>'): the page is fetched once at add time and its
// favicon, logo (og:image), name, and description are snapshotted into the
// payload. Just a titled, branded outbound link — no tracking, no
// commerce metadata, no refresh loop.
class CustomLinksController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    private const MAX_LINKS = 20;

    public function __construct(
        private readonly LinkCardScraper $scraper,
        private readonly LinkRouter $router,
    ) {}

    protected function platform(): string
    {
        return Platform::Custom->value;
    }

    // GET /api/platforms/custom/links — every attached link, ordered.
    public function links(Request $request): JsonResponse
    {
        return $this->success(['links' => $this->linksData($this->currentUser($request))]);
    }

    // POST /api/platforms/custom/links — attach a URL. Routes through LinkRouter
    // first: if the URL is a known platform it becomes the right connection type
    // (booking / reservations / social / …) instead of a custom link; otherwise
    // it falls through to the custom-link write below, unchanged.
    //
    // Response contract (the plan's option (a) — additive, frontend untouched):
    // the routed branches keep the same 202-shaped envelope and ADD an optional
    // `routedTo`. Verified against the dashboard: custom-links-section.tsx's
    // handleAdd() reads ONLY `body.links` (guarded by Array.isArray) and then
    // calls resetPlatformStatuses() — it never reads `status`, `link` or
    // `statusUrl` from this endpoint. So a routed connection returns the
    // refreshed `links` list (unchanged, since no custom link was written) and
    // the dashboard re-renders correctly without knowing about `routedTo` yet.
    // Do NOT switch to a shape that omits `links`: a routed add would then
    // silently leave the list stale until the next GET.
    public function addLink(AddCustomLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $url = $this->scraper->normalizeUrl($request->validated()['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }

        // Route FIRST, outside withConnectionLock(), so LinkRouter's own locks
        // (booking XOR, per-platform seed) are never nested inside the
        // custom-platform connection lock — nesting them invites a deadlock,
        // the same rule CustomLinkSeeder's dispatch comment already states.
        // maxProbes: 0 — a MANUAL add deliberately never commerce-probes. The
        // probe exists for SCANNED links, where an unclassified URL might be the
        // business's own store nobody told us about (signup-v2 C4). Here the user
        // has explicitly said "add this as a link", and probing first would mean
        // an ordinary unclassified URL (a personal blog, a news article) returns
        // 'pending' with no card in the list until the probe misses and the job's
        // seedCustom() fallback runs — the card used to appear instantly. So an
        // unclassified manual URL falls straight through to the custom-link write
        // below, exactly as before, while a CLASSIFIED one (Fresha, Booksy,
        // OpenTable) still routes. Flip this to 1 if manual adds should probe.
        $result = $this->router->route($user, $url, new RouteContext(maxProbes: 0));

        if ($result->outcome === 'seeded' || $result->outcome === 'pending') {
            return $this->success([
                'status' => $result->outcome === 'seeded' ? 'ok' : 'pending',
                'routedTo' => ['platform' => $result->platform, 'category' => $result->category],
                'links' => $this->linksData($user),
            ], 202);
        }

        // outcome === 'custom' or 'skipped' — proceed with custom-link write.
        $payload = ['kind' => 'link', ...$this->scraper->minimalCard($url)];
        $rid = 'link-'.substr(sha1(strtolower($url)), 0, 16);

        return $this->withConnectionLock($user, function () use ($user, $payload, $rid, $url) {
            $existing = $this->linkRows($user)->firstWhere('resource_id', $rid);
            if (! $existing && $this->linkRows($user)->count() >= self::MAX_LINKS) {
                return $this->error('You can add up to '.self::MAX_LINKS.' links.', 422);
            }

            $this->writePendingLinkCard($user, $payload, $rid, resourceKind: 'link');
            EnrichLinkCardJob::dispatch((string) $user->id, $this->platform(), $rid, $url)->afterCommit();

            return $this->success([
                'status' => 'pending',
                'link' => $this->cardData($rid, $payload),
                'statusUrl' => url("/api/platforms/custom/links/{$rid}/status"),
            ], 202);
        });
    }

    // GET /api/platforms/custom/links/{id}/status — poll link-card enrichment.
    public function linkStatus(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->linkCardStatusResponse($user, $id, fn () => [
            'links' => $this->linksData($user),
        ]);
    }

    // PUT /api/platforms/custom/links/order — persist the user's manual order
    // (W13 reorder). `ids` is the full desired order; rows omitted from it
    // keep their relative order after the listed ones. sort_order is the same
    // column connectionsFor() and the public resolver already order by, so
    // the dashboard, payload, and sitepage all follow.
    public function reorderLinks(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:'.self::MAX_LINKS],
            'ids.*' => ['string'],
        ])['ids'];

        return $this->withConnectionLock($user, function () use ($user, $ids) {
            $rows = $this->linkRows($user)->keyBy('resource_id');
            foreach ($ids as $id) {
                if (! $rows->has($id)) {
                    return $this->error('Link not found.', 404);
                }
            }

            $position = 0;
            foreach ($ids as $id) {
                $rows[$id]->update(['sort_order' => $position++]);
            }
            foreach ($rows as $rid => $row) {
                if (! in_array($rid, $ids, true)) {
                    $row->update(['sort_order' => $position++]);
                }
            }

            // A pure sort_order shuffle fires NOTHING by itself:
            // IntegrationConnectionObserver::saved() gates on payload/
            // display_settings/is_active, so neither the edge purge nor the
            // site touch ran and the public payload key (site.updated_at)
            // stayed pinned on the old order for the full TTL. Same bug
            // class ServiceObserver::touchParentSite() documents; Gallery's
            // reorder touches via ReorderService's afterCommit.
            $user->site?->touch();

            return $this->success(['links' => $this->linksData($user)]);
        });
    }

    // DELETE /api/platforms/custom/links/{id} — remove one link.
    public function removeLink(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $id) {
            if (! $this->linkRows($user)->firstWhere('resource_id', $id)) {
                return $this->error('Link not found.', 404);
            }
            $this->forgetConnection($user, $id);

            return $this->success(['links' => $this->linksData($user)]);
        });
    }

    // DELETE /api/platforms/custom — remove every link.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user) {
            $this->forgetAllConnections($user);

            return $this->success(['links' => []]);
        });
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * Single-card response shape for the 202 body — mirrors one entry of
     * linksData() so the dashboard can render the placeholder immediately.
     *
     * @return array<string,mixed>
     */
    private function cardData(string $rid, array $payload): array
    {
        $card = CardPayload::fromArray($payload);

        return [
            'id' => $rid,
            'url' => $card->url(),
            'name' => $card->name(),
            'description' => $card->description(),
            'favicon' => $card->favicon(),
            'logo' => $card->logo(),
        ];
    }

    /**
     * Link rows ('link-*'), ordered.
     *
     * @return Collection<int, IntegrationConnection>
     */
    private function linkRows(User $user)
    {
        return $this->connectionsFor($user)->filter(
            fn (IntegrationConnection $row) => $row->resource_kind === 'link',
        )->values();
    }

    /** @return list<array<string,mixed>> */
    private function linksData(User $user): array
    {
        // link_item ranks are keyed by the link's URL (payload.url) — what
        // ContentFreshness/ComputeContentPopularityScores write as the
        // link_item content_key, not the connection's resource_id. The
        // dashboard's Smart order switch sorts on popularityRank; until
        // 2026-08-04 this payload never carried it, so "engagement order"
        // silently meant "stored order". Fail-open: a read fault degrades to
        // null ranks.
        $ranks = app(ContentPopularityReader::class)
            ->forSite($user->site()->value('id'));
        $linkRanks = $ranks['link_item'] ?? [];

        return $this->linkRows($user)->map(function (IntegrationConnection $row) use ($linkRanks): array {
            $card = CardPayload::fromArray($row->payload);

            return [
                'id' => $row->resource_id,
                'url' => $card->url(),
                'name' => $card->name(),
                'description' => $card->description(),
                'favicon' => $card->favicon(),
                'logo' => $card->logo(),
                'popularityRank' => $linkRanks[(string) $card->url()] ?? null,
            ];
        })->values()->all();
    }
}
