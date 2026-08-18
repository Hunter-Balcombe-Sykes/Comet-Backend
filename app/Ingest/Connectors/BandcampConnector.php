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
    /** Release pages fetched per run for dates/credits; the rest land undated. */
    public const DETAIL_FETCH_CAP = 60;

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

        $items = $this->enrichFromReleasePages($items, $io);

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
     * The music page renders the newest releases as grid markup and hands the
     * REST to the client as a JSON blob (`data-client-items`) — a discography
     * of 26 shows 16 <li> and 10 blob entries. Reading only the blob (F24,
     * 2026-08-18) landed the ten OLDEST releases and missed every recent one,
     * so the "newest release" arm published a 2016 single. Both halves are
     * read and unioned by page path; grid first, so a scope-limited prefix is
     * the newest releases in page order.
     *
     * Neither half carries an artist or a release date; the page's
     * `data-band` gives the artist for the whole catalogue, and the dates come
     * from the release pages (enrichFromReleasePages).
     *
     * @return list<array<string, mixed>>
     */
    private function parseDiscography(string $html, string $origin): array
    {
        $bandName = null;
        if (preg_match('/data-band="([^"]+)"/', $html, $b)) {
            $band = json_decode(html_entity_decode($b[1], ENT_QUOTES), true);
            $bandName = is_array($band) && is_string($band['name'] ?? null) && trim($band['name']) !== '' ? trim($band['name']) : null;
        }

        $items = [];

        // Server-rendered grid: <li class="music-grid-item"><a href="/album/x"><img src="…"><p class="title">…</p></a></li>
        preg_match_all('~<a href="(/(?:album|track)/[^"?#]+)[^"]*"[^>]*>(.*?)</a>~s', $html, $anchors, PREG_SET_ORDER);
        foreach ($anchors as $anchor) {
            if (! preg_match('~<p class="title">\s*(.*?)\s*</p>~s', $anchor[2], $t)) {
                continue;
            }
            $title = trim(html_entity_decode(strip_tags($t[1]), ENT_QUOTES));
            if ($title === '') {
                continue;
            }
            $key = trim($anchor[1], '/');
            if (isset($items[$key])) {
                continue;
            }
            $art = preg_match('~<img[^>]+src="(https://f\d\.bcbits\.com/img/a\d+_\d+\.jpg)"~', $anchor[2], $i) ? $i[1] : null;
            $items[$key] = [
                'key' => $key,
                'title' => $title,
                'url' => $origin.'/'.$key,
                'artist' => $bandName,
                'release_date' => null,
                'art_url' => $art === null ? null : preg_replace('/_\d+\.jpg$/', '_10.jpg', $art),
                'type' => str_starts_with($key, 'track/') ? 'track' : 'album',
            ];
        }

        // Client-side remainder: the JSON blob is far more stable than the
        // markup, so it stays the primary read for anything it lists.
        if (preg_match('/data-client-items="([^"]+)"/', $html, $m)) {
            $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    $item = is_array($entry) ? $this->mapGridEntry($entry, $origin, $bandName) : null;
                    if ($item !== null && ! isset($items[$item['key']])) {
                        $items[$item['key']] = $item;
                    }
                }
            }
        }

        return array_values($items);
    }

    /**
     * Release pages carry what the music page does not: JSON-LD datePublished,
     * byArtist (a split or compilation credits someone else), numTracks
     * (album vs EP vs single) and the full-size art. One GET per release that
     * arrived undated, pooled, capped so a 300-release label page cannot turn
     * a free run into a crawl; whatever sits past the cap lands undated and
     * still shows — it just cannot win the "newest" arm.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function enrichFromReleasePages(array $items, Io $io): array
    {
        $urls = [];
        foreach ($items as $i => $item) {
            if ($item['release_date'] === null && count($urls) < self::DETAIL_FETCH_CAP) {
                $urls[$item['url']] = $i;
            }
        }
        if ($urls === []) {
            return $items;
        }

        foreach (array_chunk(array_keys($urls), 8) as $chunk) {
            foreach ($io->getMany($chunk) as $url => $response) {
                $i = $urls[$url] ?? null;
                if ($i === null || $response === null || ($response['status'] ?? 0) !== 200 || ! is_string($response['body'] ?? null)) {
                    continue;
                }
                $items[$i] = $this->applyReleasePage($items[$i], $response['body']);
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function applyReleasePage(array $item, string $html): array
    {
        if (! preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $blocks)) {
            return $item;
        }
        foreach ($blocks[1] as $block) {
            $ld = json_decode(trim($block), true);
            if (! is_array($ld) || ! in_array($ld['@type'] ?? null, ['MusicAlbum', 'MusicRecording'], true)) {
                continue;
            }
            $item['release_date'] = $this->normalizeDate($ld['datePublished'] ?? null) ?? $item['release_date'];
            $artist = is_array($ld['byArtist'] ?? null) ? ($ld['byArtist']['name'] ?? null) : null;
            if (is_string($artist) && trim($artist) !== '') {
                $item['artist'] = trim($artist);
            }
            if (is_numeric($ld['numTracks'] ?? null)) {
                $item['track_count'] = (int) $ld['numTracks'];
            }
            if (is_string($ld['image'] ?? null) && preg_match('~^https://f\d\.bcbits\.com/img/a\d+_\d+\.jpg$~', $ld['image'])) {
                $item['art_url'] = preg_replace('/_\d+\.jpg$/', '_10.jpg', $ld['image']);
            }
            break;
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function mapGridEntry(array $entry, string $origin, ?string $bandName = null): ?array
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
            'artist' => is_string($entry['artist'] ?? null) && $entry['artist'] !== '' ? $entry['artist'] : $bandName,
            'release_date' => $this->normalizeDate($entry['release_date'] ?? $entry['publish_date'] ?? null),
            'art_url' => is_numeric($entry['art_id'] ?? null)
                ? "https://f4.bcbits.com/img/a{$entry['art_id']}_10.jpg"
                : null,
            'type' => $entry['type'] ?? (str_starts_with(ltrim($path, '/'), 'track/') ? 'track' : 'album'),
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
