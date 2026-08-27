<?php

namespace App\Ingest\Projection;

/**
 * Mixcloud cloudcast → `track` (T27b, 2026-08-28). Same shared shape as the
 * other music adapters; Mixcloud's widget is keyed by the show's permalink
 * URL, the SoundCloud pattern.
 */
class MixcloudTrackProjector extends MusicTrackProjector
{
    protected function provider(): string
    {
        return 'mixcloud';
    }

    protected function embedKey(string $url): string
    {
        return $url;
    }
}
