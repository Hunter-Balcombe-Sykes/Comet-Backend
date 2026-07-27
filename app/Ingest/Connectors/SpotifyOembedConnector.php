<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/**
 * Spotify's keyless oEmbed endpoint (GET open.spotify.com/oembed?url=…)
 * resolves ONE entity — the embed itself — to a small JSON blob (title,
 * thumbnail_url, html, provider_name). There is no list to page through:
 * `orderField` is deliberately null, which makes StreamSpec::mayDelete()
 * false unconditionally — a single known entity fully seen every run is
 * Coverage::exhaustive() by construction, never something absence-folding
 * needs to reason about.
 *
 * Feb-2026 note: Spotify's client-credentials/catalog endpoints changed
 * around that date; oEmbed is a separate, older, unauthenticated surface and
 * was RE-VERIFIED live at P3 pilot kickoff (2026-07) to still answer this
 * exact shape — this is not an untested assumption carried over from the
 * pre-Feb API changes. Because that verification can silently go stale again
 * on a future Spotify change, this connector never trusts response shape: a
 * 200 with an unexpected/missing `title` degrades to Unavailable exactly
 * like a non-200 would, and never throws.
 */
class SpotifyOembedConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('spotify'),
            identifierKind: 'url',
            hosts: ['open.spotify.com', 'spotify.com', '*.spotifycdn.com', '*.scdn.co'],
            streams: [
                'listen' => new StreamSpec(
                    name: 'listen',
                    target: 'channel',
                    profile: SourceProfile::Identity,
                    requires: ['title'],
                    volatile: [],
                    // Null on purpose: one entity is not a list, and this
                    // stream must NEVER be able to delete (see class docblock).
                    orderField: null,
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 43200,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $entityUrl = $pull->identifier;
        $response = $io->get('https://open.spotify.com/oembed?'.http_build_query(['url' => $entityUrl]));

        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("oembed returned {$response['status']}", $response['status']);

            return;
        }

        $data = json_decode($response['body'], true);
        $title = is_array($data) && is_string($data['title'] ?? null) ? trim($data['title']) : '';

        if ($title === '') {
            // A single-entity stream has no concept of "empty" the way a list
            // does — a 200 that doesn't carry a usable title means the
            // endpoint's shape moved, not that the track/artist vanished.
            // Degrade to Unavailable, per the class docblock's Feb-2026 note,
            // rather than emit a titleless record for the runtime's own shape
            // check to reject.
            yield new Unavailable('oembed response missing a usable title', $response['status']);

            return;
        }

        $doc = [
            'title' => $title,
            'thumbnail_url' => is_string($data['thumbnail_url'] ?? null) ? $data['thumbnail_url'] : null,
            'html' => is_string($data['html'] ?? null) ? $data['html'] : null,
            'provider_name' => is_string($data['provider_name'] ?? null) ? $data['provider_name'] : null,
        ];

        yield new Record('listen', $this->keyFromUrl($entityUrl), $doc);
        // One known entity, fully seen: never a partial list, so always
        // exhaustive when it resolves at all.
        yield new Covered('listen', Coverage::exhaustive());
    }

    /** The entity path (e.g. "artist/abc123") is the stable key — titles rotate. */
    private function keyFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path !== '' ? $path : $url;
    }
}
