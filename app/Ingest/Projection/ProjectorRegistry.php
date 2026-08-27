<?php

namespace App\Ingest\Projection;

/**
 * (source_key, stream) → projector. The projection twin of ConnectorRegistry,
 * with one deliberate difference: an unmapped stream returns null rather than
 * throwing, because "this stream projects to no item" is a legitimate state —
 * a `target: 'none'` stream fetches for a side effect and lands no item.
 *
 * It is NOT a place to park an unbuilt lane. This docblock used to promise
 * that `profile_fields` streams "resolve through field bindings (plan §14)";
 * field bindings were built in 20260728150000 and deliberately dropped in
 * 20260805110000, so that sentence outlived its subject by months and three
 * connectors kept declaring a stream whose output was discarded. Phase 1
 * deleted the seam. Identity is App\Services\Platforms\IdentitySync's job,
 * off the connection payload, writing site.workplaces.
 *
 * A stream whose spec targets an ITEM kind but has no projector line is the
 * bug case; `ingest:project` reports those loudly instead of skipping them
 * silently.
 */
final class ProjectorRegistry
{
    /** @var array<string, array<string, class-string<Projector>>> */
    private const MAP = [
        'apple_music' => ['listen' => AppleMusicReleaseProjector::class, 'songs' => AppleMusicTrackProjector::class],
        'apple_podcasts' => ['listen' => ApplePodcastsEpisodeProjector::class],
        'bandcamp' => ['releases' => BandcampReleaseProjector::class],
        // Booksy reviews land the same doc shape as Fresha's — shared
        // projector; the class name is historical (first producer).
        'booksy' => ['services' => BooksyServiceProjector::class, 'reviews' => FreshaReviewProjector::class],
        // The three menu platforms land the SAME doc shape (MenuRecords) —
        // one projector, on purpose.
        'doordash' => ['menu' => MenuItemProjector::class],
        // Eventbrite and Humanitix land the SAME doc shape
        // (App\Ingest\Support\SchemaOrgEvent) — one projector, on purpose.
        'eventbrite' => ['events' => SchemaOrgEventProjector::class],
        'fresha' => ['services' => FreshaServiceProjector::class, 'reviews' => FreshaReviewProjector::class],
        'humanitix' => ['events' => SchemaOrgEventProjector::class],
        // Luma lands the same schema.org doc shape — same projector (T27b).
        'luma' => ['events' => SchemaOrgEventProjector::class],
        'resident_advisor' => ['events' => SchemaOrgEventProjector::class],
        'instagram' => ['media' => InstagramMediaProjector::class],
        'mixcloud' => ['tracks' => MixcloudTrackProjector::class],
        'google_business' => [
            'reviews' => GoogleBusinessReviewProjector::class,
            'media' => GoogleBusinessMediaProjector::class,
        ],
        // Phase 4 converted both off the retired `channel` kind: the oEmbed
        // `listen` streams (one entity, no list) became actor-backed `tracks`
        // streams. Dev may still hold `listen` stream rows from before that —
        // they have no projector line by design and are cleaned up with the
        // channel items themselves.
        'soundcloud' => ['tracks' => SoundcloudTrackProjector::class],
        'spotify' => ['tracks' => SpotifyTrackProjector::class, 'releases' => SpotifyReleaseProjector::class],
        'square' => ['menu' => MenuItemProjector::class],
        'uber_eats' => ['menu' => MenuItemProjector::class],
        'vimeo' => ['watch' => VimeoVideoProjector::class],
        'youtube' => ['watch' => YoutubeVideoProjector::class],
        'youtube_music' => ['releases' => YoutubeMusicTrackProjector::class],
    ];

    public static function has(string $sourceKey, string $stream): bool
    {
        return isset(self::MAP[$sourceKey][$stream]);
    }

    public static function for(string $sourceKey, string $stream): ?Projector
    {
        $class = self::MAP[$sourceKey][$stream] ?? null;

        return $class === null ? null : app($class);
    }

    /** @return array<string, array<string, class-string<Projector>>> */
    public static function all(): array
    {
        return self::MAP;
    }
}
