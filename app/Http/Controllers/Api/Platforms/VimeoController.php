<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectVimeoRequest;
use App\Http\Resources\Platforms\VimeoConnectionResource;
use App\Services\Platforms\VimeoApi;
use Illuminate\Http\JsonResponse;

// Vimeo — connect by profile/channel URL; the keyless Simple API provides
// the display name, avatar, and latest uploads (each with a public
// player.vimeo.com embed). No picker: the sitepage shows the latest videos.
class VimeoController extends SingleSelectionPlatformController
{
    private const MAX_ITEMS = 12;

    public function __construct(private readonly VimeoApi $vimeo) {}

    protected function platform(): string
    {
        return 'vimeo';
    }

    protected function resourceClass(): string
    {
        return VimeoConnectionResource::class;
    }

    // POST /api/platforms/vimeo/connect
    public function connect(ConnectVimeoRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $source = $this->vimeo->parseSource($request->validated()['url']);
        if (! $source) {
            return $this->error('Enter your Vimeo profile or channel URL (vimeo.com/yourname).', 422);
        }

        $profile = $this->vimeo->fetchProfile($source['apiPath']);
        $videos = $this->vimeo->fetchVideos($source['apiPath']);
        if ($profile === null && $videos === []) {
            return $this->error('Could not find that Vimeo profile.', 404);
        }

        return $this->connected($user, [
            'url' => $source['link'],
            'apiPath' => $source['apiPath'],
            'name' => $profile['name'] ?? null,
            'thumbnail' => $profile['thumbnail'] ?? ($videos[0]['thumbnail'] ?? null),
            'link' => $profile['link'] ?? $source['link'],
            'latest' => $videos[0] ?? null,
            'items' => array_slice($videos, 0, self::MAX_ITEMS),
        ]);
    }
}
