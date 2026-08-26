<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\PlatformInput;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// SoundCloud connect: PROFILE link (or bare name) → public oEmbed resolves
// the display name + artwork; widget URL parsed from the oEmbed html with a
// deterministic w.soundcloud.com fallback. Moved verbatim from
// SoundcloudController. (Header corrected 2026-08-27: it claimed
// track/set links connect too — canonicalUrl() has rejected multi-segment
// paths since T6b, deliberately: a /user/track path is an ITEM.)
class SoundcloudConnect implements ConnectStrategy
{
    public function __construct(private readonly OEmbedService $oembed) {}

    public function resolve(string $input): ConnectResult
    {
        $link = $this->canonicalUrl($input);
        if (! $link) {
            return ConnectResult::fail();
        }

        $resolved = $this->oembed->resolve('https://soundcloud.com/oembed?format=json&url='.rawurlencode($link));
        if ($resolved === null) {
            return ConnectResult::fail('Could not load that SoundCloud link.');
        }

        return ConnectResult::ok([
            'url' => $link,
            'name' => $resolved['name'],
            'thumbnail' => $resolved['thumbnail'],
            // The widget accepts permalink URLs directly, so a missing oEmbed
            // iframe still yields a working player.
            'embedUrl' => $resolved['embedUrl'] ?? 'https://w.soundcloud.com/player/?url='.rawurlencode($link).'&visual=true',
            'link' => $link,
        ]);
    }

    /** soundcloud.com PROFILE path (one segment) → canonical https link, else
     * null. T6b (2026-08-20): a /user/track path is an ITEM — it belongs on
     * Listen, and connecting the profile off a track link through the manual
     * door is the same bug class the detector narrowing closed. */
    private function canonicalUrl(string $url): ?string
    {
        $url = PlatformInput::urlish($url);

        if (preg_match('~^https?://(?:www\.|m\.)?soundcloud\.com(/[a-z0-9_-]+)/?(?:[?#]|$)~i', $url, $m)) {
            return 'https://soundcloud.com'.strtolower(rtrim($m[1], '/'));
        }

        // A bare profile name maps straight onto soundcloud.com/{name}.
        if (PlatformInput::isBareToken($url, '~^[a-z0-9_-]{3,40}$~i')) {
            return 'https://soundcloud.com/'.strtolower(PlatformInput::token($url));
        }

        return null;
    }
}
