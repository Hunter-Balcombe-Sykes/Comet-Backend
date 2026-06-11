<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectSpotifyRequest;
use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Services\Platforms\OEmbedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoints for Spotify. Connect by entity link only (artist /
// album / playlist / track / show / episode / user) — the public oEmbed
// endpoint resolves the display name + artwork with no auth, and the official
// embed player URL is derived from the entity type + id. The sitepage renders
// the embed (which streams the artist's top tracks natively), so there is no
// highlights picker.
class SpotifyController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(private readonly OEmbedService $oembed) {}

    protected function platform(): string
    {
        return 'spotify';
    }

    // POST /api/platforms/spotify/connect
    public function connect(ConnectSpotifyRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        $entity = $this->parseEntity($validated['url']);
        if (! $entity) {
            return $this->error('Enter a Spotify link (open.spotify.com/artist/...).', 422);
        }
        [$type, $id] = $entity;
        $link = "https://open.spotify.com/{$type}/{$id}";

        $resolved = $this->oembed->resolve('https://open.spotify.com/oembed?url='.rawurlencode($link));
        if ($resolved === null) {
            return $this->error('Could not load that Spotify link.', 422);
        }

        $selection = [
            'url' => $link,
            'name' => $resolved['name'],
            'thumbnail' => $resolved['thumbnail'],
            // The embed URL is deterministic; oEmbed's iframe_url is preferred
            // but the constructed form covers a missing field.
            'embedUrl' => $resolved['embedUrl'] ?? "https://open.spotify.com/embed/{$type}/{$id}",
            'link' => $link,
        ];
        $this->writeConnection($user, $selection);

        return $this->success((new MusicEmbedConnectionResource($selection))->resolve());
    }

    // GET /api/platforms/spotify/selection
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload ? (new MusicEmbedConnectionResource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/spotify
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    /** @return array{0:string, 1:string}|null [type, id] from any entity link. */
    private function parseEntity(string $url): ?array
    {
        if (preg_match('~^https?://open\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?(artist|album|playlist|track|show|episode|user)/([A-Za-z0-9]+)~i', trim($url), $m)) {
            return [strtolower($m[1]), $m[2]];
        }

        return null;
    }
}
