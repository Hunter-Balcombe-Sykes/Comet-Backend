<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectPodcastRequest;
use App\Http\Resources\Platforms\PodcastConnectionResource;
use App\Services\Platforms\PodcastFeedService;
use Illuminate\Http\JsonResponse;

// Podcast (RSS) — the universal show connector. Accepts the feed URL itself
// or any host page that autodiscovers one (Buzzsprout, Podbean, Transistor,
// Captivate, a WordPress site…). Stores the show identity + latest episodes,
// each with its public audio enclosure for native playback on the sitepage.
class PodcastController extends SingleSelectionPlatformController
{
    public function __construct(private readonly PodcastFeedService $feed) {}

    protected function platform(): string
    {
        return 'podcast';
    }

    protected function resourceClass(): string
    {
        return PodcastConnectionResource::class;
    }

    // POST /api/platforms/podcast/connect
    public function connect(ConnectPodcastRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $input = $request->validated()['url'];

        $result = $this->feed->fetchFromInput($input);
        if ($result === null) {
            return $this->error('Could not find a podcast RSS feed at that URL. Paste your feed URL, or your show page on Buzzsprout / Podbean / Transistor.', 422);
        }

        return $this->connected($user, [
            'url' => $result['feedUrl'],
            'pageUrl' => $result['feedUrl'] === $input ? null : $input,
            'name' => $result['show']['name'],
            'thumbnail' => $result['show']['thumbnail'],
            'description' => $result['show']['description'],
            'link' => $result['show']['link'],
            'latest' => $result['episodes'][0] ?? null,
            'episodes' => $result['episodes'],
        ]);
    }
}
