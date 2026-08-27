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
 * T27b (owner, 2026-08-28): Treatwell venue pages carry a schema.org @graph
 * whose HealthAndBeautyBusiness node holds hasOfferCatalog — nested
 * OfferCatalogs (the CATEGORIES) of Offers with itemOffered.Service {name,
 * description, additionalProperty Duration "PT30M"}, price "10.00",
 * priceCurrency. Shape verified live against
 * treatwell.co.uk/place/the-barber-shop-mayfair 2026-08-28. Services only —
 * the block carries no reviews. Same Catalogue-with-deletes semantics as
 * Booksy (names are the only key).
 */
class TreatwellConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('treatwell'),
            identifierKind: 'url',
            hosts: ['*.treatwell.com', 'treatwell.com', '*.treatwell.co.uk', 'treatwell.co.uk', '*.treatwell.de', 'treatwell.de', '*.treatwell.fr', 'treatwell.fr', '*.treatwell.nl', 'treatwell.nl', '*.treatwell.es', 'treatwell.es', '*.treatwell.it', 'treatwell.it'],
            streams: [
                'services' => new StreamSpec(
                    name: 'services',
                    target: 'service',
                    profile: SourceProfile::Catalogue,
                    requires: ['name'],
                    volatile: [],
                    orderField: null,
                    deletesOnExhaustive: true,
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 172800,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $url = trim($pull->identifier);
        if (preg_match('~^https?://~i', $url) !== 1) {
            yield new Unavailable('treatwell identifier is not a URL');

            return;
        }

        $response = $io->get($url);
        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("treatwell page returned {$response['status']}", $response['status']);

            return;
        }

        $catalog = $this->offerCatalog($response['body']);
        if ($catalog === null) {
            yield new Unavailable('treatwell page carried no hasOfferCatalog — structure may have changed', $response['status']);

            return;
        }

        $items = [];
        $categoryPosition = 0;
        foreach ($catalog as $category) {
            $categoryName = is_string($category['name'] ?? null) ? trim($category['name']) : null;
            $position = 0;
            foreach ((array) ($category['itemListElement'] ?? []) as $offer) {
                $item = is_array($offer) ? $this->mapOffer($offer, $url, $categoryName, $categoryPosition, $position) : null;
                if ($item !== null) {
                    $items[] = $item;
                    $position++;
                }
            }
            $categoryPosition++;
        }

        if ($items === []) {
            yield new Note('empty_feed', 'No offers in the venue catalog');

            return;
        }

        foreach ($items as $item) {
            yield new Record('services', $item['service_id'], $item);
        }

        yield new Covered('services', Coverage::exhaustive());
    }

    /**
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>|null
     */
    private function mapOffer(array $offer, string $venueUrl, ?string $category, int $categoryPosition, int $position): ?array
    {
        $service = is_array($offer['itemOffered'] ?? null) ? $offer['itemOffered'] : [];
        $name = is_string($service['name'] ?? null) ? trim($service['name']) : '';
        if ($name === '') {
            return null;
        }

        $durationIso = data_get($service, 'additionalProperty.value');
        $description = is_string($service['description'] ?? null) ? trim($service['description']) : null;

        return array_filter([
            'service_id' => 'offer-'.substr(sha1(mb_strtolower(($category ?? '').'|'.$name)), 0, 16),
            'name' => $name,
            'description' => $description !== null && $description !== '' ? mb_substr($description, 0, FreshaConnector::MAX_TEXT_LENGTH) : null,
            'price' => is_numeric($offer['price'] ?? null) ? (float) $offer['price'] : null,
            'currency' => is_string($offer['priceCurrency'] ?? null) ? $offer['priceCurrency'] : null,
            'duration_seconds' => $this->isoDurationSeconds(is_string($durationIso) ? $durationIso : null),
            'category' => $category,
            'category_position' => $category === null ? null : $categoryPosition,
            'position' => $position,
            'url' => $venueUrl,
        ], static fn ($v) => $v !== null);
    }

    /** "PT30M" / "PT1H30M" → seconds; null when unparseable. */
    private function isoDurationSeconds(?string $iso): ?int
    {
        if ($iso === null || preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?$/i', $iso, $m) !== 1) {
            return null;
        }
        $seconds = ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60;

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * The business node's nested category catalogs, or null.
     *
     * @return list<array<string, mixed>>|null
     */
    private function offerCatalog(string $body): ?array
    {
        if (! preg_match_all('~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', $body, $m)) {
            return null;
        }
        foreach ($m[1] as $json) {
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                continue;
            }
            $nodes = is_array($decoded['@graph'] ?? null) ? $decoded['@graph'] : [$decoded];
            foreach ($nodes as $node) {
                $list = is_array($node) ? data_get($node, 'hasOfferCatalog.itemListElement') : null;
                if (is_array($list) && $list !== []) {
                    return array_values(array_filter($list, 'is_array'));
                }
            }
        }

        return null;
    }
}
