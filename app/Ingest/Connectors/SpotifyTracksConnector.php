<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Bookmark;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
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
            // open.spotify.com for the keyless oEmbed cover lookup only.
            hosts: ['open.spotify.com'],
            streams: [
                'tracks' => new StreamSpec(
                    name: 'tracks',
                    target: 'track',
                    profile: SourceProfile::Catalogue,
                    requires: ['title', 'url'],
                    volatile: [],
                    orderField: 'published',
                ),
                // Listen restructure (2026-08-18): the artist's RELEASES —
                // album / single / compilation with cover art — off the
                // discography actor (`partna.music.platforms.spotify_releases`),
                // so a Spotify album is one item with its Apple/Bandcamp twin.
                'releases' => new StreamSpec(
                    name: 'releases',
                    target: 'release',
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
            // Owner ruling R8 (overnight 2026-08-18): paid sources get ONE eager
            // run at connect so the library fills on day one, then the
            // scheduler cadence under the platform's budget cap.
            eagerOnConnect: true,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        if ($pull->stream->name === 'releases') {
            yield from $this->pullReleases($pull, $io);

            return;
        }

        // Tracks: the actor gives no per-track artwork (topTracks: id, title,
        // duration only). Spotify's keyless oEmbed does — one GET per track,
        // cached in the stream cursor by track id, so a weekly run only pays
        // for tracks it has not seen (owner, 2026-08-18: "get the track
        // covers"). The 300px thumbnail is upsized to the CDN's 640 variant.
        $art = is_array($pull->cursor['art'] ?? null) ? $pull->cursor['art'] : [];
        $fresh = false;
        foreach (MusicTrackPull::run($pull, $io, 'spotify') as $message) {
            if ($message instanceof Record && $message->stream === 'tracks' && empty($message->doc['artwork'])) {
                $key = (string) $message->key;
                if (! array_key_exists($key, $art)) {
                    $thumb = $this->oembedThumbnail((string) ($message->doc['url'] ?? ''), $io);
                    // Only a resolved cover is remembered — a transient oEmbed
                    // miss is retried next run, not cached forever (review).
                    if ($thumb !== null) {
                        $art[$key] = $thumb;
                        $fresh = true;
                    }
                }
                if (isset($art[$key]) && is_string($art[$key]) && $art[$key] !== '') {
                    $doc = $message->doc;
                    $doc['artwork'] = $art[$key];
                    $message = new Record($message->stream, $message->key, $doc);
                }
            }
            yield $message;
        }
        if ($fresh) {
            // Cap the cache so a long-lived source never grows without bound.
            yield new Bookmark('tracks', ['art' => array_slice($art, -500, null, true)]);
        }
    }

    /** @return iterable<Message> */
    private function pullReleases(Pull $pull, Io $io): iterable
    {
        // The discography actor takes an ARTIST; a connection made from a
        // track/album/playlist url has no discography — say so for free
        // instead of paying an actor start for an empty answer (review).
        if (! preg_match('~open\.spotify\.com/(?:intl-[a-z]{2}/)?artist/[A-Za-z0-9]+~', trim($pull->identifier))) {
            yield new Note('not_an_artist', 'Releases are only listed for an artist connection');

            return;
        }
        $effect = $io->effect('actor', 'music', [
            'platform' => 'spotify_releases',
            'identifier' => trim($pull->identifier),
        ]);
        if (($effect['status'] ?? null) !== 'ok') {
            yield new Unavailable("spotify releases actor effect returned status '".($effect['status'] ?? 'null')."'");

            return;
        }
        $releases = array_values(array_filter(is_array($effect['data'] ?? null) ? $effect['data'] : [], 'is_array'));
        if ($releases === []) {
            yield new Note('empty_catalogue', 'The Spotify discography actor returned no releases for this artist');

            return;
        }
        // The artist credit is the connection's, not on the row (the
        // discography page IS the artist's). Spotify's keyless oEmbed on the
        // artist url answers with the artist's name as `title`; resolved once
        // and kept in this stream's cursor.
        $artist = is_string($pull->cursor['artist'] ?? null) ? $pull->cursor['artist'] : null;
        $resolvedFresh = false;
        if ($artist === null) {
            $response = $io->get('https://open.spotify.com/oembed?'.http_build_query(['url' => trim($pull->identifier)]));
            $json = $response['status'] === 200 ? json_decode($response['body'], true) : null;
            $artist = is_array($json) && is_string($json['title'] ?? null) && trim($json['title']) !== '' ? trim($json['title']) : null;
            $resolvedFresh = $artist !== null;
        }
        foreach ($releases as $release) {
            if ($artist !== null && empty($release['artist'])) {
                $release['artist'] = $artist;
            }
            yield new Record('releases', (string) $release['external_id'], $release);
        }
        $dates = array_filter(array_column($releases, 'published'));
        // maxItems caps the actor: a prefix, like the tracks stream.
        yield new Covered('releases', Coverage::prefix($dates === [] ? null : min($dates), count($releases)));
        if ($resolvedFresh) {
            yield new Bookmark('releases', ['artist' => $artist]);
        }
    }

    /** Spotify oEmbed → cover art url (640px CDN variant), or null. */
    private function oembedThumbnail(string $trackUrl, Io $io): ?string
    {
        if ($trackUrl === '') {
            return null;
        }
        $response = $io->get('https://open.spotify.com/oembed?'.http_build_query(['url' => $trackUrl]));
        if ($response['status'] !== 200 || $response['body'] === '') {
            return null;
        }
        $json = json_decode($response['body'], true);
        $thumb = is_array($json) && is_string($json['thumbnail_url'] ?? null) ? $json['thumbnail_url'] : null;
        if ($thumb === null) {
            return null;
        }

        return str_replace(['ab67616d00001e02', 'ab67616d00004851'], 'ab67616d0000b273', $thumb);
    }
}
