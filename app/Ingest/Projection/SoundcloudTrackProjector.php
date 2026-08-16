<?php

namespace App\Ingest\Projection;

/**
 * SoundCloud actor track → `track`. Replaced SoundcloudChannelProjector, which
 * projected the profile's oEmbed to the now-retired `channel` kind.
 */
class SoundcloudTrackProjector extends MusicTrackProjector
{
    protected function provider(): string
    {
        return 'soundcloud';
    }

    /**
     * SoundCloud's widget takes the permalink URL itself, not an id — the same
     * shape the old oEmbed connector fell back to when the returned html
     * carried no iframe src.
     */
    protected function embedKey(string $url): string
    {
        return $url;
    }
}
