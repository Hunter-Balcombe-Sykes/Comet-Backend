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
            return $this->error('Enter a TIDAL link (tidal.com/browse/artist/...).', 422);
        }

        $resolved = $this->oembed->resolve('https://oembed.tidal.com/?url='.rawurlencode($entity['link']));
        if ($resolved === null) {
            return $this->error('Could not load that TIDAL link.', 502);
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
