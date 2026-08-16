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
 * An artist's Spotify catalogue as `track` items, via a paid Apify actor
 * (convergence Phase 4).
 *
 * This REPLACED SpotifyOembedConnector, which resolved one entity — the embed
 * itself — to the `channel` kind. oEmbed has no list to page through, so it
 * could never produce tracks; that limitation is the whole reason the `channel`
 * kind existed, and replacing it here is what lets the kind retire.
 *
 * `hosts` is empty on purpose. The only outbound call is the actor run, which
 * happens inside MusicActorDriver behind the effect seam, so this connector
 * never opens a socket and needs no admitted host.
 *
 * The identifier is the connection's own artist URL and the adapter runs the
 * actor in URL mode, so a run can never resolve to a different artist who
 * happens to share a name.
 */
class SpotifyTracksConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('spotify'),
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
            // Weekly: a catalogue changes on release cadence, and every run is
            // a billed actor invocation.
            defaultIntervalSeconds: 604800,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        yield from MusicTrackPull::run($pull, $io, 'spotify');
    }
}
