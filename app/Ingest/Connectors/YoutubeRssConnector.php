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
use SimpleXMLElement;

/**
 * YouTube's keyless channel RSS (GET youtube.com/feeds/videos.xml?channel_id=)
 * — an Atom feed capped at the latest 15 uploads, newest first. Same honesty
 * requirement as Vimeo: only ever a prefix down to the oldest `published`
 * actually seen, never exhaustive (C5).
 *
 * XXE guard: LIBXML_NONET stops libxml resolving any external entity/DTD over
 * the network; LIBXML_NOENT is deliberately NOT passed (that flag is what
 * would substitute internal-DTD entities and open an expansion attack), so no
 * extra `libxml_set_external_entity_loader` call is needed on top.
 */
class YoutubeRssConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('youtube'),
            identifierKind: 'channel_id',
            hosts: ['youtube.com', '*.youtube.com', '*.ytimg.com'],
            streams: [
                'watch' => new StreamSpec(
                    name: 'watch',
                    target: 'video',
                    profile: SourceProfile::Feed,
                    requires: ['id', 'title', 'url'],
                    volatile: [],
                    orderField: 'published',
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 43200,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $channelId = trim($pull->identifier);
        $response = $io->get('https://www.youtube.com/feeds/videos.xml?'.http_build_query(['channel_id' => $channelId]));

        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("videos.xml returned {$response['status']}", $response['status']);

            return;
        }

        $items = $this->parseFeed($response['body']);

        if ($items === null) {
            // Unparseable XML is a shape break (a layout/schema change, or a
            // non-XML error body under a 200) — never "no uploads". An empty
            // but well-formed <feed> is handled separately below.
            yield new Unavailable('videos.xml did not parse as XML', $response['status']);

            return;
        }

        if ($items === []) {
            yield new Note('empty_feed', 'No entries parsed from the channel RSS feed');

            return;
        }

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            yield new Record('watch', $item['id'], $item);
        }

        // The feed gives the latest 15 (YouTube's own server-side cap) —
        // only a prefix down to the oldest entry actually seen.
        $dates = array_filter(array_column($items, 'published'));
        yield new Covered('watch', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /** @return list<array<string, mixed>>|null null when the body does not parse as XML */
    private function parseFeed(string $xml): ?array
    {
        $previousState = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousState);

        if ($doc === false) {
            return null;
        }

        $namespaces = $doc->getNamespaces(true);
        $ytNs = $namespaces['yt'] ?? 'http://www.youtube.com/xml/schemas/2015';
        $mediaNs = $namespaces['media'] ?? 'http://search.yahoo.com/mrss/';

        $items = [];
        foreach ($doc->entry as $entry) {
            $item = $this->mapEntry($entry, $ytNs, $mediaNs);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    private function mapEntry(SimpleXMLElement $entry, string $ytNs, string $mediaNs): ?array
    {
        $ytChildren = $entry->children($ytNs);
        $videoId = isset($ytChildren->videoId) ? trim((string) $ytChildren->videoId) : '';
        $title = isset($entry->title) ? trim((string) $entry->title) : '';

        if ($videoId === '' || $title === '') {
            return null;
        }

        $link = null;
        foreach ($entry->link as $linkEl) {
            $href = (string) $linkEl->attributes()->href;
            if ($href !== '') {
                $link = $href;
                break;
            }
        }

        $thumbnail = null;
        $mediaGroup = $entry->children($mediaNs)->group;
        if ($mediaGroup !== null) {
            foreach ($mediaGroup->children($mediaNs)->thumbnail as $thumb) {
                $url = (string) $thumb->attributes()->url;
                if ($url !== '') {
                    $thumbnail = $url;
                    break;
                }
            }
        }

        return [
            'id' => $videoId,
            'title' => $title,
            'url' => $link ?? "https://www.youtube.com/watch?v={$videoId}",
            'published' => isset($entry->published) ? (string) $entry->published : null,
            'thumbnail' => $thumbnail,
            'channel_title' => isset($entry->author->name) ? trim((string) $entry->author->name) : null,
        ];
    }
}
