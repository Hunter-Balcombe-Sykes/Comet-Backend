<?php

namespace App\Ingest\Projection;

/**
 * Deezer top track → `track` (wave 2, 2026-08-28). Shared music shape; the
 * Deezer widget is keyed by the numeric track id off the permalink.
 */
class DeezerTrackProjector extends MusicTrackProjector
{
    protected function provider(): string
    {
        return 'deezer';
    }

    protected function embedKey(string $url): string
    {
        return preg_match('~/track/(\d+)~', $url, $m) === 1 ? $m[1] : $url;
    }
}
