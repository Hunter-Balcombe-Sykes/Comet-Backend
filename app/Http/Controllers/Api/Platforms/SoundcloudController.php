<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectSoundcloudRequest;
use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Services\Platforms\OEmbedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoints for SoundCloud. Connect by profile / track / set link —
// the public oEmbed endpoint resolves the display name + artwork with no
// auth; the official widget player URL is parsed from the oEmbed html (with a
// deterministic w.soundcloud.com fallback that accepts permalink URLs). The
// sitepage renders the widget, so there is no highlights picker.
class SoundcloudController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(private readonly OEmbedService $oembed) {}

    protected function platform(): string
    {
        return 'soundcloud';
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
            return $this->error('Could not load that SoundCloud link.', 502);
        }

        $selection = [
            'url' => $link,
            'name' => $resolved['name'],
            'thumbnail' => $resolved['thumbnail'],
            // The widget accepts permalink URLs directly, so a missing oEmbed
            // iframe still yields a working player.
            'embedUrl' => $resolved['embedUrl'] ?? 'https://w.soundcloud.com/player/?url='.rawurlencode($link).'&visual=true',
            'link' => $link,
        ];
        $this->writeConnection($user, $selection);

        return $this->success((new MusicEmbedConnectionResource($selection))->resolve());
    }

    // GET /api/platforms/soundcloud/selection
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload ? (new MusicEmbedConnectionResource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/soundcloud
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    /** soundcloud.com path (≤3 segments) → canonical https link, else null. */
    private function canonicalUrl(string $url): ?string
    {
        if (preg_match('~^https?://(?:www\.|m\.)?soundcloud\.com(/[a-z0-9_-]+(?:/[a-z0-9_-]+){0,2})~i', trim($url), $m)) {
            return 'https://soundcloud.com'.strtolower(rtrim($m[1], '/'));
        }

        return null;
    }
}
