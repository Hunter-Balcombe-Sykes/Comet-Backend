<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectTidalRequest;
use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\TidalScraper;
use Illuminate\Http\JsonResponse;

// TIDAL — connect by entity link (artist / album / track / playlist / video
// / mix). The public oEmbed endpoint resolves the official embed player; the
// entity page's og tags fill in name + artwork (TIDAL's oEmbed omits them).
// Stored in the shared music-embed shape.
class TidalController extends SingleSelectionPlatformController
{
    public function __construct(
        private readonly TidalScraper $scraper,
        private readonly OEmbedService $oembed,
    ) {}

    protected function platform(): string
    {
        return 'tidal';
    }

    protected function resourceClass(): string
    {
        return MusicEmbedConnectionResource::class;
    }

    // POST /api/platforms/tidal/connect
    public function connect(ConnectTidalRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $entity = $this->scraper->parseEntity($request->validated()['url']);
        if (! $entity) {
            return $this->error('Enter a TIDAL link (tidal.com/browse/album/...).', 422);
        }

        // TIDAL's oEmbed covers albums / tracks / playlists / videos / mixes but
        // NOT artist pages (it answers 400; embed.tidal.com/artists/* is a 404).
        // Say so up front instead of failing opaquely downstream.
        if ($entity['type'] === 'artist') {
            return $this->error("TIDAL doesn't offer an embeddable player for artist pages — paste an album, track or playlist link from your profile instead.", 422);
        }

        $resolved = $this->oembed->resolve('https://oembed.tidal.com/?url='.rawurlencode($entity['link']));
        if ($resolved === null) {
            // 422, never 502: Cloudflare replaces origin 502s with its own
            // CORS-less error page, which the dashboard sees as "Failed to fetch".
            return $this->error('Could not load that TIDAL link.', 422);
        }
        $meta = $this->scraper->fetchMeta($entity['link']);

        return $this->connected($user, [
            'url' => $entity['link'],
            'name' => $meta['name'] ?? $resolved['name'],
            'thumbnail' => $meta['thumbnail'] ?? $resolved['thumbnail'],
            'embedUrl' => $resolved['embedUrl'] ?? TidalScraper::embedUrlFor($entity['type'], $entity['id']),
            'link' => $entity['link'],
        ]);
    }
}
