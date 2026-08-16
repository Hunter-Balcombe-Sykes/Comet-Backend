<?php

namespace App\Ingest\Projection;

/**
 * A normalized actor track → the `track` item kind.
 *
 * Both music adapters emit the SAME doc shape, so the projection is shared and
 * only the embed differs per platform. That is the same call MenuItemProjector
 * makes for its three platforms, for the same reason: one shape, one projector.
 *
 * f_catalog.isrc is as much the point of this projector as the display fields
 * are. IdentityKeyDeriver already derives KeyClass::Isrc from that column and
 * has simply never had a producer (convergence-log F10); emitting it here is
 * what turns cross-platform track dedup from title-matching into a
 * vendor-identifier match. f_authored.creator matters for the same reason — it
 * is half of the TitleRelease key, which is the fallback when a platform
 * returns no ISRC at all.
 */
abstract class MusicTrackProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'track';
    }

    /** The f_embed provider slug for this platform. */
    abstract protected function provider(): string;

    /** What the player is keyed by — a path for Spotify, the permalink for SoundCloud. */
    abstract protected function embedKey(string $url): string;

    public function project(RecordView $view): ?array
    {
        $title = $view->string('title');
        $url = $view->string('url');

        // requires: ['title', 'url'] — the runtime rejects a record missing
        // either, so returning null here is belt to that braces rather than a
        // second opinion.
        if ($title === null || $url === null) {
            return null;
        }

        $seconds = $view->int('duration_seconds');
        $isrc = $view->string('isrc');
        $artist = $view->string('artist');
        $published = $view->string('published');
        $artwork = $view->string('artwork');

        return [
            'kind' => self::kind(),
            'headline' => $title,
            // array_filter drops the nulls, so a track that carries no ISRC
            // emits no f_catalog row at all rather than an empty one.
            'facets' => array_filter([
                'f_link' => ['url' => $url],
                'f_catalog' => $isrc === null ? null : ['isrc' => $isrc],
                'f_authored' => $artist === null ? null : ['creator' => $artist],
                'f_duration' => $seconds === null || $seconds <= 0 ? null : ['seconds' => $seconds],
                'f_published' => $published === null ? null : ['published_from' => $published],
                'f_embed' => ['provider' => $this->provider(), 'embed_key' => $this->embedKey($url)],
            ]),
            'media' => $artwork === null ? [] : [
                ['role' => 'cover', 'url' => $artwork],
            ],
        ];
    }
}
