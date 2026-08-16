<?php

namespace App\Ingest\Support;

use App\Ingest\Landing\Coverage;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/**
 * The pull body shared by the Spotify and SoundCloud track connectors, in the
 * same spirit as MenuRecords: the two are genuine twins — one billed effect,
 * one normalized track list, one stream — and the only thing that differs is
 * which platform they name. Duplicating the body would let their empty-catalogue
 * and coverage semantics drift apart, which are exactly the two things that
 * decide whether absence-folding tombstones a real catalogue.
 */
final class MusicTrackPull
{
    /** @return iterable<Message> */
    public static function run(Pull $pull, Io $io, string $platform): iterable
    {
        $effect = $io->effect('actor', 'music', [
            'platform' => $platform,
            'identifier' => trim($pull->identifier),
        ]);

        if (($effect['status'] ?? null) !== 'ok') {
            // Refused, budget-skipped or failed: the caller decides what an
            // absent result means, and for a catalogue it means "unknown",
            // never "empty".
            yield new Unavailable("music actor effect returned status '".($effect['status'] ?? 'null')."'");

            return;
        }

        $tracks = array_values(array_filter(
            is_array($effect['data'] ?? null) ? $effect['data'] : [],
            static fn ($track) => is_array($track),
        ));

        if ($tracks === []) {
            // No coverage claim: an artist the actor could not see must never
            // read as "they deleted their catalogue" (the same guard
            // YoutubeMusicConnector carries for an empty feed).
            yield new Note('empty_catalogue', "The {$platform} actor returned no tracks for this artist");

            return;
        }

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $tracks = array_slice($tracks, 0, $limit);
        }

        foreach ($tracks as $track) {
            yield new Record('tracks', (string) $track['external_id'], $track);
        }

        // PREFIX, never exhaustive: max_tracks caps the actor, so what came
        // back is the head of a catalogue. Claiming exhaustive would let
        // absence-folding tombstone every older release the cap excluded.
        $dates = array_filter(array_column($tracks, 'published'));

        yield new Covered('tracks', Coverage::prefix($dates === [] ? null : min($dates), count($tracks)));
    }
}
