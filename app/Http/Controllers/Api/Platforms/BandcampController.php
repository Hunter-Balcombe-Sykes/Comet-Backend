<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Api\Platforms\Concerns\RefreshesLatestTile;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectBandcampRequest;
use App\Http\Requests\Platforms\SaveBandcampHighlightsRequest;
use App\Http\Resources\Platforms\BandcampConnectionResource;
use App\Services\Platforms\BandcampScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoints for Bandcamp — same shape as Apple Music: connect by
// URL (no auth — the /music page grid is scraped), an auto-latest release
// tile, and up to 5 user-curated highlight releases via the recent picker.
// Scraping lives in App\Services\Platforms\BandcampScraper.
class BandcampController extends ApiController
{
    use ManagesIntegrationConnection;
    use RefreshesLatestTile;
    use ResolveCurrentUser;

    private const MAX_HIGHLIGHTS = 5;

    // Flat back-compat tile fields copied verbatim from the latest release
    // (mirrors the Apple Music selection so sitepages render both identically).
    private const FLAT_FIELDS = ['name', 'thumbnail', 'link'];

    public function __construct(private readonly BandcampScraper $scraper) {}

    protected function platform(): string
    {
        return 'bandcamp';
    }

    // POST /api/platforms/bandcamp/connect — resolve the artist page, store the
    // latest release + artist profile. Highlights survive a same-page reconnect.
    public function connect(ConnectBandcampRequest $request): JsonResponse
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
        $latest = $profile['items'][0];

        $existing = $this->readConnection($user);
        $kept = data_get($existing, 'url') === $origin ? data_get($existing, 'highlights', []) : [];

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

        $this->writeConnection($user, $selection);

        return $this->success((new BandcampConnectionResource($selection))->resolve());
    }

    // GET /api/platforms/bandcamp/recent — up to 15 releases for the picker.
    public function recent(Request $request): JsonResponse
    {
        $url = data_get($this->readConnection($this->currentUser($request)), 'url');
        if (! $url) {
            return $this->error('Connect a Bandcamp page first.', 404);
        }

        $profile = $this->scraper->fetchProfile($url);
        if ($profile === null) {
            return $this->error('Could not load recent releases.', 422);
        }

        return $this->success(['items' => array_slice($profile['items'], 0, 15)]);
    }

    // POST /api/platforms/bandcamp/highlights — snapshot up to 5 chosen releases.
    public function highlights(SaveBandcampHighlightsRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        return $this->withConnectionLock($user, function () use ($user, $validated): JsonResponse {
            $selection = $this->readConnection($user);
            if (! $selection) {
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
                $selection = $this->refreshLatestTile($selection, $items[0], self::FLAT_FIELDS);
            }

            $byId = collect($items)->keyBy('itemId');
            $selection['highlights'] = collect($validated['itemIds'])
                ->map(fn (string $id) => $byId->get($id))
                ->filter()
                ->take(self::MAX_HIGHLIGHTS)
                ->values()
                ->all();
            $this->writeConnection($user, $selection);

            return $this->success((new BandcampConnectionResource($selection))->resolve());
        });
    }

    // GET /api/platforms/bandcamp/selection
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload ? (new BandcampConnectionResource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/bandcamp
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }
}
