<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectMixcloudRequest;
use App\Http\Resources\Platforms\MixcloudConnectionResource;
use App\Services\Platforms\MixcloudApi;
use Illuminate\Http\JsonResponse;

// Mixcloud — connect by profile URL or handle; the open JSON API provides
// the profile + latest shows, each with an official keyless widget embed.
class MixcloudController extends SingleSelectionPlatformController
{
    private const MAX_ITEMS = 12;

    public function __construct(private readonly MixcloudApi $mixcloud) {}

    protected function platform(): string
    {
        return 'mixcloud';
    }

    protected function resourceClass(): string
    {
        return MixcloudConnectionResource::class;
    }

    // POST /api/platforms/mixcloud/connect
    public function connect(ConnectMixcloudRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $username = $this->mixcloud->parseUsername($request->validated()['url']);
        if (! $username) {
            return $this->error('Enter your Mixcloud page (mixcloud.com/yourname).', 422);
        }

        $profile = $this->mixcloud->fetchProfile($username);
        if ($profile === null) {
            return $this->error('Could not find that Mixcloud profile.', 404);
        }
        $shows = $this->mixcloud->fetchCloudcasts($profile['username'], self::MAX_ITEMS);

        return $this->connected($user, [
            'url' => $profile['link'] ?? "https://www.mixcloud.com/{$profile['username']}/",
            'username' => $profile['username'],
            'name' => $profile['name'],
            'thumbnail' => $profile['thumbnail'],
            'followers' => $profile['followers'],
            // The profile feed itself streams the whole catalogue in one player.
            'embedUrl' => MixcloudApi::embedUrlForFeed('/'.$profile['username'].'/'),
            'latest' => $shows[0] ?? null,
            'items' => $shows,
        ]);
    }
}
