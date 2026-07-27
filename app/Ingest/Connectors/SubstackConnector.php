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
 * Substack's officially-served, keyless publication RSS (GET
 * {publication}.substack.com/feed) — an RSS 2.0 feed of the latest ~20 posts,
 * newest first. Same honesty requirement as Vimeo/YoutubeRss: only ever a
 * prefix down to the oldest `published` actually seen, never exhaustive
 * (C5) — a newsletter with 21+ posts looks identical to one with exactly 20
 * from this feed alone.
 *
 * XXE guard: LIBXML_NONET stops libxml resolving any external entity/DTD over
 * the network; LIBXML_NOENT is deliberately NOT passed (that flag is what
 * would substitute internal-DTD entities and open an expansion attack), so no
 * extra `libxml_set_external_entity_loader` call is needed on top — mirrors
 * YoutubeRssConnector's guard exactly.
 */
class SubstackConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('substack'),
            identifierKind: 'publication',
            hosts: ['*.substack.com', 'substack.com'],
            streams: [
                'posts' => new StreamSpec(
                    name: 'posts',
                    target: 'article',
                    profile: SourceProfile::Feed,
                    // A post with no id/title/url cannot be rendered or even
                    // linked to; landing it would poison every projection
                    // downstream.
                    requires: ['id', 'title', 'url'],
                    volatile: [],
                    // pubDate is what makes "I saw everything from here
                    // forward" a claim we can actually check.
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
        $publication = trim($pull->identifier, '/');
        $response = $io->get("https://{$publication}.substack.com/feed");

        if ($response['status'] !== 200 || $response['body'] === '') {
            // A failed fetch is UNAVAILABLE, never "this publication has no
            // posts" — emitting nothing here would let absence-folding
            // conclude the whole archive was deleted (C5).
            yield new Unavailable("feed returned {$response['status']}", $response['status']);

            return;
        }

        $items = $this->parseFeed($response['body']);

        if ($items === null) {
            // Unparseable XML is a shape break (a schema change, or a
            // non-XML error body under a 200) — never "no posts". A
            // well-formed but empty <channel> is handled separately below.
            yield new Unavailable('feed did not parse as XML', $response['status']);

            return;
        }

        if ($items === []) {
            yield new Note('empty_feed', 'No posts parsed from the publication RSS feed');

            return;
        }

        foreach ($items as $item) {
            yield new Record('posts', $item['id'], $item);
        }

        // Substack serves only the latest ~20 posts on this feed — only ever
        // a prefix down to the oldest entry actually seen, never exhaustive
        // (see class docblock).
        $dates = array_filter(array_column($items, 'published'));
        yield new Covered('posts', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /** @return list<array<string, mixed>>|null null when the body does not parse as XML */
    private function parseFeed(string $xml): ?array
    {
        $previousState = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousState);

        if ($doc === false || ! isset($doc->channel)) {
            return null;
        }

        $items = [];
        foreach ($doc->channel->item as $item) {
            $mapped = $this->mapItem($item);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    private function mapItem(SimpleXMLElement $item): ?array
    {
        $title = isset($item->title) ? trim((string) $item->title) : '';
        $link = isset($item->link) ? trim((string) $item->link) : '';
        $guid = isset($item->guid) ? trim((string) $item->guid) : '';
        // guid is the stable id (Substack's is a permalink-shaped string,
        // just not marked isPermaLink) — link is the fallback for a feed that
        // somehow omits it.
        $id = $guid !== '' ? $guid : $link;

        if ($id === '' || $title === '' || $link === '') {
            return null;
        }

        return [
            'id' => $id,
            'title' => $title,
            'url' => $link,
            'published' => $this->normalizeDate(isset($item->pubDate) ? (string) $item->pubDate : null),
        ];
    }

    /** pubDate is RFC 2822 ("Wed, 01 Jan 2025 00:00:00 GMT") — not lexically sortable, so it must be normalised before it can serve as an orderField. */
    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
