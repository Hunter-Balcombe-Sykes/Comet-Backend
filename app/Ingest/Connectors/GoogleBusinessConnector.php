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
 * Google Business via Places Details — a METERED cost class (this is a
 * billed call, unlike every other P3 pilot). The fetch itself goes through
 * `$io->effect('api', 'places.details', …)` rather than `$io->get()`,
 * because it must be admitted, budgeted AND ledgered (charge-once) before it
 * fires; a non-'ok' ledger verdict (refused/abandoned/cached-miss) is the
 * ledger doing its job, not a crash, and folds into the same Unavailable
 * outcome an unreachable vendor would.
 *
 * Three streams, three different absence stories:
 *   - `profile` (Identity): the account's own facts. One authoritative
 *     record, Coverage::exhaustive().
 *   - `reviews` (Sample): a vendor-curated subset of a much larger public
 *     set we can never claim to enumerate. `orderField` is null and NO
 *     Covered message is ever emitted for it — not even exhaustive — because
 *     a Sample stream never dominates and never deletes (mayDelete() is
 *     false regardless; the display set is simply whatever the latest ok run
 *     returned, per plan §4's corrected worked example).
 *   - `media` (Feed, `orderField` null): Places photos are also a
 *     vendor-curated subset (not a paginated "everything"), and carry no
 *     per-photo timestamp to order by — so unlike Vimeo/YouTube's Feed
 *     streams this can never even claim a prefix. It emits
 *     Coverage::unknown() when photos are present: informational only
 *     (mayDelete() is already false via the null orderField), but honest.
 *
 * Redactions (PRIV-1, mirroring GoogleBusinessPayload::stripThirdPartyPii):
 * reviewer-identifying fields (`author`, `author_uri`, `author_photo`) are
 * declared `when_unclaimed` via Manifest::$redactionScopes — an unclaimed
 * owner never held this data by consent, so it is stripped before landing;
 * a claimed account keeps the full reviewer attribution. This is the
 * live-regression guard: getting the scope wrong either leaks PII for
 * unclaimed accounts or silently drops attribution the moment someone
 * claims their listing.
 */
class GoogleBusinessConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('google_business'),
            identifierKind: 'place_id',
            // EMPTY on purpose. `hosts` governs what $io->get()/getMany() may
            // contact, and this connector never fetches over HTTP: its one
            // billed call goes through $io->effect('api', 'places.details'),
            // whose driver is GoogleBusinessService — the only place permitted
            // to issue a keyed Places request, because that is where
            // PlacesBudget is claimed. Naming the Places hosts here would
            // imply a direct path that must never exist (and
            // PlacesBudgetGuardTest fails the build if one appears).
            hosts: [],
            streams: [
                'profile' => new StreamSpec(
                    name: 'profile',
                    target: 'profile_fields',
                    profile: SourceProfile::Identity,
                    requires: ['display_name'],
                    volatile: [],
                    orderField: null,
                    authoritativeFields: ['display_name', 'address', 'phone', 'website'],
                ),
                'reviews' => new StreamSpec(
                    name: 'reviews',
                    target: 'review',
                    profile: SourceProfile::Sample,
                    requires: ['rating'],
                    volatile: [],
                    // Sample + null orderField together are what make
                    // mayDelete() false — a review stream must NEVER delete.
                    orderField: null,
                ),
                'media' => new StreamSpec(
                    name: 'media',
                    target: 'media',
                    profile: SourceProfile::Feed,
                    requires: ['ref'],
                    volatile: [],
                    // Null on purpose (see class docblock): no per-photo
                    // timestamp exists to claim even a prefix by.
                    orderField: null,
                ),
            ],
            cost: CostClass::Metered,
            defaultIntervalSeconds: 172800,
            redactions: ['author', 'author_uri', 'author_photo'],
            redactionScopes: [
                'author' => 'when_unclaimed',
                'author_uri' => 'when_unclaimed',
                'author_photo' => 'when_unclaimed',
            ],
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $placeId = trim($pull->identifier);

        $effect = $io->effect('api', 'places.details', ['place_id' => $placeId]);

        if (($effect['status'] ?? null) !== 'ok') {
            yield new Unavailable("places details effect returned status '{$effect['status']}'");

            return;
        }

        $place = $effect['data'];
        if (! is_array($place)) {
            yield new Unavailable('places details effect returned no usable data');

            return;
        }

        yield from match ($pull->stream->name) {
            'profile' => $this->profileMessages($place, $placeId),
            'reviews' => $this->reviewsMessages($place),
            'media' => $this->mediaMessages($place),
            default => [],
        };
    }

    /** @return iterable<Message> */
    private function profileMessages(array $place, string $placeId): iterable
    {
        $doc = array_filter([
            'display_name' => is_string(data_get($place, 'displayName.text')) ? data_get($place, 'displayName.text') : null,
            'address' => is_string($place['formattedAddress'] ?? null) ? $place['formattedAddress'] : null,
            'phone' => is_string($place['nationalPhoneNumber'] ?? null) ? $place['nationalPhoneNumber'] : null,
            'website' => is_string($place['websiteUri'] ?? null) ? $place['websiteUri'] : null,
        ], static fn ($v) => $v !== null);

        if (! isset($doc['display_name'])) {
            // No name means nothing renderable — degrade quietly rather than
            // land a profile record the shape check would reject anyway.
            yield new Note('no_profile_fields', 'Places details carried no display name');

            return;
        }

        yield new Record('profile', $placeId, $doc);
        // One authoritative fact-set, fully returned — never a partial list.
        yield new Covered('profile', Coverage::exhaustive());
    }

    /** @return iterable<Message> */
    private function reviewsMessages(array $place): iterable
    {
        $reviews = is_array($place['reviews'] ?? null) ? $place['reviews'] : [];

        $items = [];
        foreach ($reviews as $review) {
            $item = $this->mapReview($review);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('no_reviews', 'Places details carried no reviews this run');

            return;
        }

        foreach ($items as $item) {
            yield new Record('reviews', $item['review_id'], $item);
        }

        // Deliberately NO Covered message: a Sample is a vendor-curated
        // subset we can never claim to have exhaustively seen, and emitting
        // even Coverage::exhaustive() here would misstate what this endpoint
        // returns. mayDelete() is already false (Sample + null orderField);
        // this is about not making a false claim, not about the folding
        // mechanics.
    }

    /** @return iterable<Message> */
    private function mediaMessages(array $place): iterable
    {
        $photos = is_array($place['photos'] ?? null) ? $place['photos'] : [];

        $items = [];
        foreach ($photos as $photo) {
            $item = $this->mapPhoto($photo);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('no_media', 'Places details carried no photos this run');

            return;
        }

        foreach ($items as $item) {
            yield new Record('media', $item['ref'], $item);
        }

        // Unknown, not exhaustive: Places photos are themselves a
        // vendor-curated subset, and there is no per-photo order field to
        // reason about a prefix with — informational only, since the null
        // orderField already forbids deletion regardless of this claim.
        yield new Covered('media', Coverage::unknown());
    }

    /** @return array<string, mixed>|null */
    private function mapReview(mixed $review): ?array
    {
        if (! is_array($review)) {
            return null;
        }
        $rating = $review['rating'] ?? null;
        if ($rating === null) {
            return null;
        }

        $name = is_string($review['name'] ?? null) ? $review['name'] : null;
        $author = is_array($review['authorAttribution'] ?? null) ? $review['authorAttribution'] : [];

        // The Places API (New) review resource name (places/…/reviews/…) is
        // the stable id; falling back to a content hash only guards against
        // a shape that somehow omits it, never expected in practice.
        $key = $name ?? hash('sha256', json_encode([$author, $review['publishTime'] ?? null]));

        return array_filter([
            'review_id' => $key,
            'rating' => $rating,
            'text' => is_string(data_get($review, 'text.text')) ? data_get($review, 'text.text') : null,
            'author' => is_string($author['displayName'] ?? null) ? $author['displayName'] : null,
            'author_uri' => is_string($author['uri'] ?? null) ? $author['uri'] : null,
            'author_photo' => is_string($author['photoUri'] ?? null) ? $author['photoUri'] : null,
            'publish_time' => is_string($review['publishTime'] ?? null) ? $review['publishTime'] : null,
            'published_ago' => is_string($review['relativePublishTimeDescription'] ?? null) ? $review['relativePublishTimeDescription'] : null,
        ], static fn ($v) => $v !== null);
    }

    /** @return array<string, mixed>|null */
    private function mapPhoto(mixed $photo): ?array
    {
        if (! is_array($photo)) {
            return null;
        }
        $ref = is_string($photo['name'] ?? null) ? $photo['name'] : '';
        if ($ref === '') {
            return null;
        }

        $authors = array_values(array_filter(array_map(
            static fn ($a) => is_array($a) && is_string($a['displayName'] ?? null)
                ? ['name' => $a['displayName'], 'uri' => is_string($a['uri'] ?? null) ? $a['uri'] : null]
                : null,
            (array) ($photo['authorAttributions'] ?? []),
        )));

        // Slice 1b D6: Places terms require crediting the author and linking
        // back to the photo on Maps wherever the photo is DISPLAYED. Until this
        // slice nothing resolved a ref to an image, so nothing was displayed and
        // the credit was dropped (see ThirdPartyPii). Task 5 resolves the refs,
        // so the obligation now attaches and the credit has to travel with the
        // photo. Absent stays absent — Google supplies attribution for only
        // about half the photos it returns, and an empty credit block reads as
        // a bug rather than as missing vendor data.
        $attribution = array_filter([
            'authors' => $authors !== [] ? $authors : null,
            'maps_uri' => is_string($photo['googleMapsUri'] ?? null) ? $photo['googleMapsUri'] : null,
            'flag_uri' => is_string($photo['flagContentUri'] ?? null) ? $photo['flagContentUri'] : null,
        ], static fn ($v) => $v !== null);

        return array_filter([
            'ref' => $ref,
            // Populated by PlacesDetailsDriver inside the SAME billed fetch: a
            // ref and a url are only consistent within one Details call, since
            // the ref is reissued every time.
            'url' => is_string($photo['url'] ?? null) && $photo['url'] !== '' ? $photo['url'] : null,
            'width_px' => $photo['widthPx'] ?? null,
            'height_px' => $photo['heightPx'] ?? null,
            'attribution' => $attribution !== [] ? $attribution : null,
        ], static fn ($v) => $v !== null && $v !== []);
    }
}
