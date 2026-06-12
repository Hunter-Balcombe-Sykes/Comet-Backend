<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectTwitchRequest;
use App\Http\Resources\Platforms\TwitchConnectionResource;
use App\Services\Platforms\TwitchScraper;
use Illuminate\Http\JsonResponse;

// Twitch — connect by channel URL or handle; the channel page's og tags
// provide the display name, avatar, and bio. The sitepage embeds the live
// player keylessly (player.twitch.tv, parent filled at render time).
class TwitchController extends SingleSelectionPlatformController
{
    public function __construct(private readonly TwitchScraper $scraper) {}

    protected function platform(): string
    {
        return 'twitch';
    }

    protected function resourceClass(): string
    {
        return TwitchConnectionResource::class;
    }

    // Watch platform — multiple channel accounts (shop-style list).
    protected function supportsMultipleAccounts(): bool
    {
        return true;
    }

    // POST /api/platforms/twitch/connect
    public function connect(ConnectTwitchRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $login = $this->scraper->parseLogin($request->validated()['url']);
        if (! $login) {
            return $this->error('Enter your Twitch channel (twitch.tv/yourname).', 422);
        }

        $channel = $this->scraper->fetchChannel($login);
        if ($channel === null) {
            return $this->error('Could not find that Twitch channel.', 404);
        }

        return $this->connected($user, [
            'url' => "https://www.twitch.tv/{$login}",
            'login' => $login,
            'name' => $channel['name'],
            'image' => $channel['image'],
            'description' => $channel['description'],
        ]);
    }
}
