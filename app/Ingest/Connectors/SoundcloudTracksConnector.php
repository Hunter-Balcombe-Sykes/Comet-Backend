<?php

namespace App\Ingest\Connectors;

use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Message;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Ingest\Support\MusicTrackPull;

/**
 * An artist's SoundCloud uploads as `track` items, via a paid Apify actor
 * (convergence Phase 4) — the twin of SpotifyTracksConnector.
 *
 * This REPLACED SoundcloudConnector's keyless oEmbed `listen` stream, which
 * resolved one entity to the `channel` kind and could not list anything.
 *
 * SoundCloud is the more valuable of the two here: its actor returns `isrc`,
 * which gives KeyClass::Isrc its first producer (convergence-log F10) and turns
 * cross-platform track dedup from title-matching into a vendor-identifier match.
 */
class SoundcloudTracksConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('soundcloud'),
            identifierKind: 'url',
            hosts: [],
            streams: [
                'tracks' => new StreamSpec(
                    name: 'tracks',
                    target: 'track',
                    profile: SourceProfile::Catalogue,
                    requires: ['title', 'url'],
                    volatile: [],
                    orderField: 'published',
                ),
            ],
            cost: CostClass::Actor,
            defaultIntervalSeconds: 604800,
            // Owner ruling R8 (overnight 2026-08-18): paid sources get ONE eager
            // run at connect so the library fills on day one, then the
            // scheduler cadence under the platform's budget cap.
            eagerOnConnect: true,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        yield from MusicTrackPull::run($pull, $io, 'soundcloud');
    }
}
