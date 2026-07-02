<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\Platforms\Concerns\RefreshesLatestTile;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Requests\Platforms\SaveBandcampHighlightsRequest;
use App\Http\Resources\Platforms\BandcampConnectionResource;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\Payloads\FeedPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoints for Bandcamp — same shape as Apple Music: connect by
// URL (no auth — the /music page grid is scraped), an auto-latest release
// tile, and up to 5 user-curated highlight releases via the recent picker.
// Scraping lives in App\Services\Platforms\BandcampScraper.
class BandcampController extends SingleSelectionPlatformController
{
    use RefreshesLatestTile;

    private const MAX_HIGHLIGHTS = 5;

    // Flat back-compat tile fields copied verbatim from the latest release
    // (mirrors the Apple Music selection so sitepages render both identically).
    private const FLAT_FIELDS = ['name', 'thumbnail', 'link'];

    public function __construct(private readonly BandcampScraper $scraper) {}

    protected function platform(): string
    {
        return 'bandcamp';
    }

    protected function resourceClass(): string
    {
        return BandcampConnectionResource::class;
    }

    // Listen platform — multiple artist-page accounts (shop-style list).
    protected function supportsMultipleAccounts(): bool
    {
        return true;
    }

    // POST /api/platforms/bandcamp/connect — resolve the artist page, store the
    // latest release + artist profile. Highlights survive a same-page re-add.
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        $origin = $this->scraper->normalizeOrigin($validated['url']);
        if (! $origin) {
            return $this->error('Enter your Bandcamp page URL (yourname.bandcamp.com).', 422);
        }

        $profile = $this->scraper->fetchProfile($origin);
        if ($profile === null || $profile['items'] === []) {
            return $this->error('Could not find releases on that Bandcamp page.', 404);
        }
        // Enrich the latest tile with its buy price (1 fetch). Null-safe.
        $latest = $this->scraper->enrichPrices([$profile['items'][0]])[0];

        // Re-adding an already-connected page keeps that account's highlights.
        $kept = $this->preserveHighlights($user, 'url', $origin);

        $selection = [
            'url' => $origin,
            'artist' => $profile['name'],
            ...$this->flatTileFields($latest, self::FLAT_FIELDS),
            'latest' => $latest,
            'highlights' => $kept,
        ];
        // Prefer the latest release art for the tile; fall back to the page's
        // own og:image (artist avatar) when the release has none.
        $selection['thumbnail'] ??= $profile['thumbnail'];

        return $this->connected($user, $selection);
    }

    // GET /api/platforms/bandcamp/recent?account={id} — up to 15 releases for
    // the picker. Defaults to the first account when no account id is given.
    public function recent(Request $request): JsonResponse
    {
        $row = $this->requestedAccountRow($this->currentUser($request), $request->query('account'));
        $url = FeedPayload::fromArray($row?->payload ?? [])->url;
        if (! $url) {
            return $this->error('Connect a Bandcamp page first.', 404);
        }

        $profile = $this->scraper->fetchProfile($url);
        if ($profile === null) {
            return $this->error('Could not load recent releases.', 422);
        }

        return $this->success(['items' => array_slice($profile['items'], 0, 15)]);
    }

    // POST /api/platforms/bandcamp/highlights?account={id} — snapshot up to 5
    // chosen releases onto that account.
    public function highlights(SaveBandcampHighlightsRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();
        $accountId = $request->query('account');

        return $this->withConnectionLock($user, function () use ($user, $validated, $accountId): JsonResponse {
            $row = $this->requestedAccountRow($user, $accountId);
            $selection = $row?->payload;
            if (! $row || ! $selection) {
                return $this->error('Connect a Bandcamp page first.', 404);
            }

            $profile = $this->scraper->fetchProfile($selection['url']);
            if ($profile === null) {
                return $this->error('Could not load recent releases.', 422);
            }
            $items = $profile['items'];

            // Refresh the "Most recent" tile too — a release published since
            // connect would otherwise leave `latest` stale while only the
            // highlights updated.
            if (isset($items[0])) {
                $selection = $this->refreshLatestTile($selection, $this->scraper->enrichPrices([$items[0]])[0], self::FLAT_FIELDS);
            }

            $byId = collect($items)->keyBy('itemId');
            $chosen = collect($validated['itemIds'])
                ->map(fn (string $id) => $byId->get($id))
                ->filter()
                ->take(self::MAX_HIGHLIGHTS)
                ->values()
                ->all();
            // Buy price for each curated highlight (bounded concurrent fetch).
            $selection['highlights'] = $this->scraper->enrichPrices($chosen, self::MAX_HIGHLIGHTS);
            $this->writeConnection($user, $selection, $row->resource_id);

            return $this->success(['id' => $row->resource_id, ...(new BandcampConnectionResource($selection))->resolve()]);
        });
    }
}
