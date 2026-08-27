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
 * T27b (owner, 2026-08-28): Booksy venue pages carry a complete schema.org
 * block — verified live against booksy.com/en-au/11338_finn-barber_…
 * 2026-08-28: @type HairSalon with makesOffer[] (name, price, priceCurrency,
 * image), review[] (author.name, datePublished, reviewBody, reviewRating),
 * aggregateRating {ratingValue, reviewCount}. Two streams:
 *
 *  - services → the `service` kind (Catalogue; the block IS the whole menu,
 *    so exhaustive-with-deletes, Fresha's semantics). Booksy offers carry NO
 *    stable vendor id — the key is the offer name, so a rename retires the
 *    old row and lands the new one, which deletesOnExhaustive handles.
 *  - reviews → the `review` kind (Sample, never deletes), same doc shape as
 *    the Fresha reviews stream so the projector is shared.
 */
class BooksyConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('booksy'),
            identifierKind: 'path',
            hosts: ['booksy.com', '*.booksy.com'],
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
                'reviews' => new StreamSpec(
                    name: 'reviews',
                    target: 'review',
                    profile: SourceProfile::Sample,
                    requires: ['rating'],
                    volatile: [],
                    orderField: null,
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 172800,
            // Booksy reviewer PII — same posture as fresha/google_business.
            redactions: ['author', 'author_photo'],
            redactionScopes: [
                'author' => 'when_unclaimed',
                'author_photo' => 'when_unclaimed',
            ],
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $path = trim($pull->identifier, "/ \t");
        if ($path === '') {
            yield new Unavailable('empty booksy venue path');

            return;
        }

        $url = 'https://booksy.com/'.str_replace('%2F', '/', rawurlencode($path));
        $response = $io->get($url);
        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("booksy page returned {$response['status']}", $response['status']);

            return;
        }

        $venue = $this->venueJsonLd($response['body']);
        if ($venue === null) {
            yield new Unavailable('booksy page carried no schema.org venue block — structure may have changed', $response['status']);

            return;
        }

        if ($pull->stream->name === 'services') {
            yield from $this->serviceMessages($venue, $url);

            return;
        }

        yield from $this->reviewMessages($venue);
    }

    /** @return iterable<Message> */
    private function serviceMessages(array $venue, string $venueUrl): iterable
    {
        $offers = is_array($venue['makesOffer'] ?? null) ? $venue['makesOffer'] : [];
        $items = [];
        foreach ($offers as $offer) {
            if (! is_array($offer)) {
                continue;
            }
            $name = is_string($offer['name'] ?? null) ? trim($offer['name']) : '';
            if ($name === '') {
                continue;
            }
            $items[] = array_filter([
                'service_id' => 'offer-'.substr(sha1(mb_strtolower($name)), 0, 16),
                'name' => $name,
                'price' => is_numeric($offer['price'] ?? null) ? (float) $offer['price'] : null,
                'currency' => is_string($offer['priceCurrency'] ?? null) ? $offer['priceCurrency'] : null,
                'url' => $venueUrl,
                'image' => is_string($offer['image'] ?? null) ? $offer['image'] : null,
            ], static fn ($v) => $v !== null);
        }

        if ($items === []) {
            yield new Note('empty_feed', 'No offers in the venue schema.org block');

            return;
        }

        foreach ($items as $item) {
            yield new Record('services', $item['service_id'], $item);
        }

        // The block is the venue's whole published menu — exhaustive, which
        // is what licences deletesOnExhaustive to retire renamed offers.
        yield new Covered('services', Coverage::exhaustive());
    }

    /** @return iterable<Message> */
    private function reviewMessages(array $venue): iterable
    {
        $agg = is_array($venue['aggregateRating'] ?? null) ? $venue['aggregateRating'] : [];
        $venueRating = is_numeric($agg['ratingValue'] ?? null) ? (float) $agg['ratingValue'] : null;
        $venueCount = is_numeric($agg['reviewCount'] ?? null) ? (int) $agg['reviewCount'] : null;

        $reviews = is_array($venue['review'] ?? null) ? $venue['review'] : [];
        $landed = 0;
        foreach ($reviews as $review) {
            if (! is_array($review)) {
                continue;
            }
            $rating = data_get($review, 'reviewRating.ratingValue');
            if (! is_numeric($rating)) {
                continue;
            }
            $body = is_string($review['reviewBody'] ?? null) ? trim($review['reviewBody']) : null;
            $date = is_string($review['datePublished'] ?? null) ? $review['datePublished'] : null;
            $doc = array_filter([
                'review_id' => 'rev-'.substr(sha1(json_encode([$body, $date, data_get($review, 'author.name')])), 0, 16),
                'rating' => (float) $rating,
                'text' => $body !== null && $body !== '' ? mb_substr($body, 0, FreshaConnector::MAX_TEXT_LENGTH) : null,
                'author' => is_string(data_get($review, 'author.name')) ? data_get($review, 'author.name') : null,
                'publish_time' => $date,
                'venue_rating' => $venueRating,
                'venue_rating_count' => $venueCount,
            ], static fn ($v) => $v !== null);
            yield new Record('reviews', $doc['review_id'], $doc);
            $landed++;
        }

        if ($landed === 0) {
            yield new Note('empty_feed', 'No reviews in the venue schema.org block');
        }
    }

    /**
     * The first JSON-LD block that looks like the venue (carries makesOffer
     * or aggregateRating), decoded.
     *
     * @return array<string, mixed>|null
     */
    private function venueJsonLd(string $body): ?array
    {
        if (! preg_match_all('~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', $body, $m)) {
            return null;
        }
        foreach ($m[1] as $json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded) && (isset($decoded['makesOffer']) || isset($decoded['aggregateRating']))) {
                return $decoded;
            }
        }

        return null;
    }
}
