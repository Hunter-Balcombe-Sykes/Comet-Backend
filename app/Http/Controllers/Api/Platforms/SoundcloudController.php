<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectSoundcloudRequest;
use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\PlatformInput;
use Illuminate\Http\JsonResponse;

// Test-mode endpoints for SoundCloud. Connect by profile / track / set link —
// the public oEmbed endpoint resolves the display name + artwork with no
// auth; the official widget player URL is parsed from the oEmbed html (with a
// deterministic w.soundcloud.com fallback that accepts permalink URLs). The
// sitepage renders the widget, so there is no highlights picker.
class SoundcloudController extends SingleSelectionPlatformController
{
    public function __construct(private readonly OEmbedService $oembed) {}

    protected function platform(): string
    {
        return 'soundcloud';
    }

    protected function resourceClass(): string
    {
        return MusicEmbedConnectionResource::class;
    }

    // Listen platform — multiple profile accounts (shop-style list).
    protected function supportsMultipleAccounts(): bool
    {
        return true;
    }

    // POST /api/platforms/soundcloud/connect
    public function connect(ConnectSoundcloudRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        $link = $this->canonicalUrl($validated['url']);
        if (! $link) {
            return $this->error('Enter your SoundCloud link (soundcloud.com/yourname).', 422);
        }

        $resolved = $this->oembed->resolve('https://soundcloud.com/oembed?format=json&url='.rawurlencode($link));
        if ($resolved === null) {
            return $this->error('Could not load that SoundCloud link.', 422);
        }

        return $this->connected($user, [
            'url' => $link,
            'name' => $resolved['name'],
            'thumbnail' => $resolved['thumbnail'],
            // The widget accepts permalink URLs directly, so a missing oEmbed
            // iframe still yields a working player.
            'embedUrl' => $resolved['embedUrl'] ?? 'https://w.soundcloud.com/player/?url='.rawurlencode($link).'&visual=true',
            'link' => $link,
        ]);
    }

    /** soundcloud.com path (≤3 segments) → canonical https link, else null. */
    private function canonicalUrl(string $url): ?string
    {
        $url = PlatformInput::urlish($url);

        if (preg_match('~^https?://(?:www\.|m\.)?soundcloud\.com(/[a-z0-9_-]+(?:/[a-z0-9_-]+){0,2})~i', $url, $m)) {
            return 'https://soundcloud.com'.strtolower(rtrim($m[1], '/'));
        }

        // A bare profile name maps straight onto soundcloud.com/{name}.
        if (PlatformInput::isBareToken($url, '~^[a-z0-9_-]{3,40}$~i')) {
            return 'https://soundcloud.com/'.strtolower(PlatformInput::token($url));
        }

        return null;
    }
}
