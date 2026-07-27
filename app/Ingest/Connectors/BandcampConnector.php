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
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/**
 * Bandcamp artist discography. The first pilot connector, and deliberately
 * the simplest interesting one: keyless HTML + JSON-LD, a genuinely
 * exhaustive listing (an artist's music page shows every release), and a
 * usable order field — so it exercises records, Coverage domination, and
 * deletion without any billed effect in the way.
 *
 * Straight-line code, no base class, no lifecycle hooks: everything it does
 * is visible in pull().
 */
class BandcampConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('bandcamp'),
            identifierKind: 'url',
            // Artist pages live on <artist>.bandcamp.com; album art and audio
            // are served from bcbits.com. Nothing else is reachable.
            hosts: ['*.bandcamp.com', 'bandcamp.com', '*.bcbits.com'],
            streams: [
                'releases' => new StreamSpec(
                    name: 'releases',
                    target: 'release',
                    profile: SourceProfile::Catalogue,
                    // A release with no title or URL is not a release; landing
                    // it would poison every projection downstream.
                    requires: ['title', 'url'],
                    // Bandcamp rotates a cache-busting query on art URLs; without
                    // this every single run would look like a change.
                    volatile: ['art_url?query'],
                    // Release date is what makes "I saw everything from here
                    // forward" a claim we can actually check.
                    orderField: 'release_date',
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 43200,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $origin = rtrim($pull->identifier, '/');
        $response = $io->get($origin.'/music');

        if ($response['status'] !== 200 || $response['body'] === '') {
            // A failed fetch is UNAVAILABLE, never "the artist has no music".
            // Emitting nothing here would let the absence fold conclude the
            // discography was deleted (C5).
            yield new Unavailable("music page returned {$response['status']}", $response['status']);

            return;
        }

        $items = $this->parseDiscography($response['body'], $origin);

        if ($items === []) {
            // Parsed cleanly but found nothing: either a genuinely empty page
            // or a layout change. Either way it is not evidence of deletion,
            // so no Coverage is emitted and nothing can be tombstoned.
            yield new Note('empty_discography', 'No releases parsed from the music page');

            return;
        }

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            yield new Record('releases', $item['key'], $item);
        }

        // Scope-limited runs saw only a prefix and must say so, or absence
        // folding would delete everything past the limit.
        $dates = array_filter(array_column($items, 'release_date'));
        yield new Covered('releases', $limit === null
            ? Coverage::exhaustive()
            : Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseDiscography(string $html, string $origin): array
    {
        $items = [];

        // The music page embeds its grid as a JSON blob; parsing that is far
        // more stable than walking the markup, which Bandcamp restyles often.
        if (preg_match('/data-client-items="([^"]+)"/', $html, $m)) {
            $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    $item = $this->mapGridEntry($entry, $origin);
                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
            }
        }

        if ($items === []) {
            // Fallback: the album links themselves. Less rich, but a layout
            // change that breaks the blob should degrade rather than blank.
            preg_match_all('~<a href="(/(?:album|track)/[^"]+)"[^>]*>.*?<p class="title">\s*([^<]+?)\s*</p>~s', $html, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $items[] = [
                    'key' => trim($match[1], '/'),
                    'title' => html_entity_decode(trim($match[2])),
                    'url' => $origin.$match[1],
                    'release_date' => null,
                    'art_url' => null,
                ];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function mapGridEntry(array $entry, string $origin): ?array
    {
        $path = $entry['page_url'] ?? null;
        $title = $entry['title'] ?? null;
        if (! is_string($path) || ! is_string($title) || $title === '') {
            return null;
        }

        return [
            // The page path is Bandcamp's own stable identifier — titles and
            // art change, this does not.
            'key' => trim(strtok($path, '?'), '/'),
            'title' => $title,
            'url' => $origin.'/'.ltrim(strtok($path, '?'), '/'),
            'artist' => $entry['artist'] ?? null,
            'release_date' => $this->normalizeDate($entry['release_date'] ?? $entry['publish_date'] ?? null),
            'art_url' => is_numeric($entry['art_id'] ?? null)
                ? "https://f4.bcbits.com/img/a{$entry['art_id']}_10.jpg"
                : null,
            'type' => $entry['type'] ?? null,
        ];
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
