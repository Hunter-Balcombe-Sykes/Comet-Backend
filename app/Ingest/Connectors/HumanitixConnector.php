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
use App\Ingest\Support\Html;
use App\Ingest\Support\SchemaOrgEvent;

/**
 * Humanitix host pages (events.humanitix.com/host/<slug>), keyless — their
 * official API is own-account-only, so the JSON-LD scrape stays (plan §11).
 * Event pages carry a clean schema.org Event graph; the host page sometimes
 * embeds the events' JSON-LD itself, in which case the per-event fetches are
 * skipped entirely.
 *
 * Same coverage honesty as Eventbrite: exhaustive only when every candidate
 * event link was fetched and parsed; anything partial claims nothing (C5).
 * Lands the SAME doc shape as Eventbrite (App\Ingest\Support\SchemaOrgEvent)
 * so one projector serves both.
 */
class HumanitixConnector implements Connector
{
    /** Host-page path segments that are product chrome, not event slugs. */
    private const NON_EVENT_SLUGS = [
        'host', 'search', 'tours', 'sell', 'about', 'signin', 'login', 'signup',
        'contact', 'contact-us', 'help', 'privacy', 'terms', 'blog', 'faqs',
        'careers', 'pricing', 'features', 'refunds', 'cookies', 'us', 'au', 'nz', 'uk',
    ];

    /** Detail pages fetched per run — bounds one run's fan-out. */
    private const MAX_EVENT_PAGES = 20;

    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('humanitix'),
            identifierKind: 'url',
            hosts: ['events.humanitix.com', 'humanitix.com', 'www.humanitix.com'],
            streams: [
                'events' => new StreamSpec(
                    name: 'events',
                    target: 'event',
                    profile: SourceProfile::Calendar,
                    requires: ['name', 'url'],
                    volatile: [],
                    orderField: 'start_date',
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 43200,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $hostUrl = rtrim(trim($pull->identifier), '/');
        $page = $io->get($hostUrl);

        if ($page['status'] !== 200 || $page['body'] === '') {
            // A failed fetch is UNAVAILABLE, never "the host has no events"
            // (C5).
            yield new Unavailable("host page returned {$page['status']}", $page['status']);

            return;
        }

        // Fast path: the host page sometimes embeds the events' JSON-LD.
        $items = [];
        $complete = true;
        foreach (Html::jsonLdNodes($page['body']) as $node) {
            if (isset($node['startDate'])) {
                $item = $this->mapNode($node, $hostUrl);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        // Fallback: harvest candidate event links and read each page's
        // JSON-LD. A non-event link self-filters (no startDate node).
        if ($items === []) {
            $candidates = $this->candidateEventUrls($page['body']);
            if ($candidates === []) {
                yield new Note('no_events', 'No embedded event data or event links on the host page');

                return;
            }

            $limit = $pull->scopeLimit() ?? self::MAX_EVENT_PAGES;
            $complete = count($candidates) <= $limit;
            $batch = array_slice($candidates, 0, $limit);
            $responses = $io->getMany($batch);

            foreach ($batch as $url) {
                $response = $responses[$url] ?? null;
                if ($response === null || $response['status'] !== 200) {
                    $complete = false;

                    continue;
                }
                foreach (Html::jsonLdNodes($response['body']) as $node) {
                    if (isset($node['startDate'])) {
                        $item = $this->mapNode($node, $url);
                        if ($item !== null) {
                            $items[] = $item;
                        }
                        break;
                    }
                }
            }
        }

        if ($items === []) {
            yield new Note('no_events_parsed', 'No pages yielded a schema.org Event node');

            return;
        }

        foreach ($items as $item) {
            yield new Record('events', $item['key'], $item['doc']);
        }

        yield new Covered('events', $complete ? Coverage::exhaustive() : Coverage::unknown());
    }

    /** @return array{key: string, doc: array<string, mixed>}|null */
    private function mapNode(array $node, string $fallbackUrl): ?array
    {
        $doc = SchemaOrgEvent::map($node, $fallbackUrl);
        if ($doc === null) {
            return null;
        }

        // The event-page slug is Humanitix's stable identifier.
        $path = trim((string) parse_url((string) $doc['url'], PHP_URL_PATH), '/');

        return ['key' => $path !== '' ? $path : (string) $doc['url'], 'doc' => $doc];
    }

    /**
     * Candidate event-page URLs linked from the host page, deduped, chrome
     * slugs filtered.
     *
     * @return list<string>
     */
    private function candidateEventUrls(string $html): array
    {
        $urls = [];
        if (preg_match_all('~href="(?:https://events\.humanitix\.com)?/([a-z0-9][a-z0-9-]{2,})(?:\?[^"]*)?"~i', $html, $m)) {
            foreach ($m[1] as $slug) {
                $slug = strtolower($slug);
                if (in_array($slug, self::NON_EVENT_SLUGS, true)) {
                    continue;
                }
                $urls['https://events.humanitix.com/'.$slug] = true;
            }
        }

        return array_keys($urls);
    }
}
