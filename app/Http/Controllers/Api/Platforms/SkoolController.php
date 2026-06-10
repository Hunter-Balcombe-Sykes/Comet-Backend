<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectSkoolRequest;
use App\Http\Resources\Platforms\SkoolConnectionResource;
use App\Services\Platforms\SkoolScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoints for Skool. Connect by community URL — the community's
// public about page carries og: tags (name, avatar, description) even for
// private communities, so the sitepage can show a rich community card with
// no auth. Scraping lives in App\Services\Platforms\SkoolScraper.
class SkoolController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(private readonly SkoolScraper $scraper) {}

    protected function platform(): string
    {
        return 'skool';
    }

    // POST /api/platforms/skool/connect
    public function connect(ConnectSkoolRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        $canonical = $this->scraper->normalizeUrl($validated['url']);
        if (! $canonical) {
            return $this->error('Enter your Skool community URL (skool.com/your-community).', 422);
        }

        $community = $this->scraper->fetchCommunity($canonical);
        if ($community === null) {
            return $this->error('Could not read that Skool community — check the URL.', 404);
        }

        $this->writeConnection($user, $community);

        return $this->success((new SkoolConnectionResource($community))->resolve());
    }

    // GET /api/platforms/skool/selection
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload ? (new SkoolConnectionResource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/skool
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }
}
