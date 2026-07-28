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
 * SoundCloud's keyless oEmbed (GET soundcloud.com/oembed?url=…) — the exact
 * twin of SpotifyOembedConnector: ONE entity (profile, track or set)
 * resolved to a small JSON blob, so exhaustive-or-unavailable with a null
 * orderField (this stream can never delete). The widget/internal APIs stay
 * deferred as ToS-high (plan §11) — oEmbed is the officially served surface.
 *
 * Unlike Spotify, SoundCloud's oEmbed carries no `iframe_url`; the player
 * URL hides in the returned `html` snippet. The embed src is parsed out here
 * so the landed doc is self-contained, with the deterministic
 * w.soundcloud.com fallback the legacy connect flow shipped — a missing
 * oEmbed iframe still yields a working player.
 */
class SoundcloudConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('soundcloud'),
            identifierKind: 'url',
            hosts: ['soundcloud.com', 'www.soundcloud.com', 'w.soundcloud.com', '*.sndcdn.com'],
            streams: [
                'listen' => new StreamSpec(
                    name: 'listen',
                    target: 'channel',
                    profile: SourceProfile::Identity,
                    requires: ['title'],
                    volatile: [],
                    // Null on purpose: one entity is not a list, and this
                    // stream must NEVER be able to delete.
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
        $entityUrl = trim($pull->identifier);
        $response = $io->get('https://soundcloud.com/oembed?'.http_build_query(['format' => 'json', 'url' => $entityUrl]));

        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("oembed returned {$response['status']}", $response['status']);

            return;
        }

        $data = json_decode($response['body'], true);
        $title = is_array($data) && is_string($data['title'] ?? null) ? trim($data['title']) : '';

        if ($title === '') {
            // A 200 without a usable title means the endpoint's shape moved,
            // not that the profile vanished — degrade, never land titleless.
            yield new Unavailable('oembed response missing a usable title', $response['status']);

            return;
        }

        $doc = [
            'title' => $title,
            'url' => $entityUrl,
            'thumbnail_url' => is_string($data['thumbnail_url'] ?? null) ? $data['thumbnail_url'] : null,
            'embed_url' => $this->embedUrl($data, $entityUrl),
            'author_name' => is_string($data['author_name'] ?? null) ? $data['author_name'] : null,
        ];

        yield new Record('listen', $this->keyFromUrl($entityUrl), $doc);
        // One known entity, fully seen: always exhaustive when it resolves.
        yield new Covered('listen', Coverage::exhaustive());
    }

    /** @param array<string, mixed> $data */
    private function embedUrl(array $data, string $entityUrl): string
    {
        if (is_string($data['html'] ?? null) && preg_match('~src="([^"]+)"~i', $data['html'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }

        // The widget accepts permalink URLs directly.
        return 'https://w.soundcloud.com/player/?url='.rawurlencode($entityUrl).'&visual=true';
    }

    /** The entity path (e.g. "some-artist" or "some-artist/sets/x") is the stable key. */
    private function keyFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path !== '' ? $path : $url;
    }
}
