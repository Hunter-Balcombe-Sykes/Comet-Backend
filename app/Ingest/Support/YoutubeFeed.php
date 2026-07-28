<?php

namespace App\Ingest\Support;

use SimpleXMLElement;

/**
 * YouTube channel Atom feed (youtube.com/feeds/videos.xml) → entry list.
 * Shared by YoutubeRssConnector and YoutubeMusicConnector — the YT Music
 * "releases" strategy reads the SAME uploads feed off the artist's
 * auto-generated "- Topic" channel, so the parse must not fork.
 *
 * XXE guard: LIBXML_NONET stops libxml resolving external entities/DTDs over
 * the network; LIBXML_NOENT is deliberately NOT passed (that flag is what
 * would substitute internal-DTD entities and open an expansion attack).
 */
final class YoutubeFeed
{
    /**
     * @return list<array{id: string, title: string, url: string, published: ?string, thumbnail: ?string, channel_title: ?string}>|null
     *                                                                                                                                  null when the body does not parse as XML
     */
    public static function parse(string $xml): ?array
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
            $item = self::mapEntry($entry, $ytNs, $mediaNs);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    private static function mapEntry(SimpleXMLElement $entry, string $ytNs, string $mediaNs): ?array
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
