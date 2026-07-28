<?php

namespace App\Ingest\Projection;

/**
 * (source_key, stream) → projector. The projection twin of ConnectorRegistry,
 * with one deliberate difference: an unmapped stream returns null rather than
 * throwing, because "this stream projects to no item" is a legitimate state —
 * profile_fields streams resolve through field bindings (plan §14), not here.
 *
 * A stream whose spec targets an ITEM kind but has no projector line is the
 * bug case; `ingest:project` reports those loudly instead of skipping them
 * silently.
 */
final class ProjectorRegistry
{
    /** @var array<string, array<string, class-string<Projector>>> */
    private const MAP = [
        'apple_music' => ['listen' => AppleMusicReleaseProjector::class],
        'apple_podcasts' => ['listen' => ApplePodcastsEpisodeProjector::class],
        'bandcamp' => ['releases' => BandcampReleaseProjector::class],
        // Eventbrite and Humanitix land the SAME doc shape
        // (App\Ingest\Support\SchemaOrgEvent) — one projector, on purpose.
        'eventbrite' => ['events' => SchemaOrgEventProjector::class],
        'fresha' => ['services' => FreshaServiceProjector::class],
        'gumroad' => ['products' => GumroadProductProjector::class],
        'humanitix' => ['events' => SchemaOrgEventProjector::class],
        'instagram' => ['media' => InstagramMediaProjector::class],
        'google_business' => [
            'reviews' => GoogleBusinessReviewProjector::class,
            'media' => GoogleBusinessMediaProjector::class,
        ],
        'skool' => ['community' => ChannelCardProjector::class],
        'soundcloud' => ['listen' => SoundcloudChannelProjector::class],
        'spotify' => ['listen' => SpotifyChannelProjector::class],
        'strava' => ['club' => ChannelCardProjector::class],
        'substack' => ['posts' => SubstackArticleProjector::class],
        'twitch' => [
            'channel' => ChannelCardProjector::class,
            'vods' => TwitchVodProjector::class,
        ],
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
