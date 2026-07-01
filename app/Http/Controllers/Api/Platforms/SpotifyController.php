<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\PlatformInput;
use Illuminate\Http\JsonResponse;

// Test-mode endpoints for Spotify. Connect by entity link only (artist /
// album / playlist / track / show / episode / user) — the public oEmbed
// endpoint resolves the display name + artwork with no auth, and the official
// embed player URL is derived from the entity type + id. The sitepage renders
// the embed (which streams the artist's top tracks natively), so there is no
// highlights picker.
class SpotifyController extends SingleSelectionPlatformController
{
    public function __construct(private readonly OEmbedService $oembed) {}

    protected function platform(): string
    {
        return 'spotify';
    }

    protected function resourceClass(): string
    {
        return MusicEmbedConnectionResource::class;
    }

    // Listen platform — multiple entity accounts (shop-style list).
    protected function supportsMultipleAccounts(): bool
    {
        return true;
    }

    // POST /api/platforms/spotify/connect
    public function connect(PlatformConnectRequest $request): JsonResponse
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

        return $this->connected($user, [
            'url' => $link,
            'name' => $resolved['name'],
            'thumbnail' => $resolved['thumbnail'],
            // The embed URL is deterministic; oEmbed's iframe_url is preferred
            // but the constructed form covers a missing field.
            'embedUrl' => $resolved['embedUrl'] ?? "https://open.spotify.com/embed/{$type}/{$id}",
            'link' => $link,
        ]);
    }

    /** @return array{0:string, 1:string}|null [type, id] from any entity link. */
    private function parseEntity(string $url): ?array
    {
        if (preg_match('~^https?://open\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?(artist|album|playlist|track|show|episode|user)/([A-Za-z0-9]+)~i', PlatformInput::urlish($url), $m)) {
            return [strtolower($m[1]), $m[2]];
        }

        return null;
    }
}
