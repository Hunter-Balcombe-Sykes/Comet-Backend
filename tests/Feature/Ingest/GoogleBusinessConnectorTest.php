<?php

use App\Ingest\Connectors\GoogleBusinessConnector;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C: the connector's real pull() against a recorded billed-effect
// result. No network, no DB — a connector is a pure function from
// (Pull, Io) to Messages.

/** A minimal Io: get/post enforce the manifest host guard (unused by this
 * connector's own pull(), which only ever calls effect() — proven separately
 * below); effect() answers with a fixed, controllable result. */
function gbIo(array $effectResult): Io
{
    return new class($effectResult) implements Io
    {
        public function __construct(private array $effectResult) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! GoogleBusinessConnector::manifest()->mayContact($host)) {
                throw new EffectRefused("off-manifest host {$host}");
            }

            return ['status' => 404, 'body' => '', 'headers' => []];
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! GoogleBusinessConnector::manifest()->mayContact($host)) {
                throw new EffectRefused("off-manifest host {$host}");
            }

            return ['status' => 404, 'body' => '', 'headers' => []];
        }

        public function getMany(array $urls, array $headers = []): array
        {
            return array_map(fn ($u) => $this->get($u), array_combine($urls, $urls));
        }

        public function effect(string $kind, string $name, array $input): array
        {
            return $this->effectResult;
        }
    };
}

function gbPull(string $streamName, string $placeId = 'INVENTEDPLACEID000'): Pull
{
    return new Pull(
        identifier: $placeId,
        stream: GoogleBusinessConnector::manifest()->stream($streamName),
    );
}

it('declares no fetchable hosts at all, because its one call is a billed effect', function () {
    $manifest = GoogleBusinessConnector::manifest();

    // Places is reached ONLY through $io->effect(), whose driver is
    // GoogleBusinessService — the single place allowed to issue a keyed
    // request, because that is where PlacesBudget is claimed. Declaring the
    // Places hosts here would advertise a direct HTTP path that must never
    // exist; PlacesBudgetGuardTest fails the build if one appears.
    expect($manifest->hosts)->toBe([])
        ->and($manifest->mayContact('places.googleapis.com'))->toBeFalse()
        ->and($manifest->mayContact('evil.com'))->toBeFalse();

    // This connector's own pull() never calls get()/post() (the Places call
    // is a billed effect, not a plain fetch) — the guard itself is proven
    // directly against the Io, the same mechanism every connector shares.
    $io = gbIo(['status' => 'ok', 'cached' => false, 'data' => []]);
    expect(fn () => $io->get('https://evil.com/places/details'))->toThrow(EffectRefused::class);
});

it('yields one profile record from the places details effect, exhaustive', function () {
    $io = gbIo(['status' => 'ok', 'cached' => false, 'data' => [
        'displayName' => ['text' => 'Invented Cafe'],
        'formattedAddress' => '1 Invented St, Sydney NSW 2000',
        'nationalPhoneNumber' => '(02) 0000 0000',
        'websiteUri' => 'https://invented-cafe.example',
    ]]);

    $messages = iterator_to_array((new GoogleBusinessConnector)->pull(gbPull('profile', 'INVENTEDPLACEID000'), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('INVENTEDPLACEID000')
        ->and($records[0]->doc['display_name'])->toBe('Invented Cafe')
        ->and($records[0]->doc['address'])->toBe('1 Invented St, Sydney NSW 2000')
        ->and($records[0]->doc['phone'])->toBe('(02) 0000 0000')
        ->and($records[0]->doc['website'])->toBe('https://invented-cafe.example')
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('yields a record per review but NEVER a coverage claim, since a sample can never be exhaustively seen', function () {
    $io = gbIo(['status' => 'ok', 'cached' => false, 'data' => [
        'reviews' => [
            [
                'name' => 'places/INVENTEDPLACEID000/reviews/invented-review-1',
                'rating' => 5,
                'text' => ['text' => 'Lovely spot.'],
                'authorAttribution' => ['displayName' => 'Invented Reviewer One', 'uri' => 'https://example/u1', 'photoUri' => 'https://example/p1'],
                'publishTime' => '2025-01-01T00:00:00Z',
            ],
            [
                'name' => 'places/INVENTEDPLACEID000/reviews/invented-review-2',
                'rating' => 4,
                'text' => ['text' => 'Pretty good.'],
                'authorAttribution' => ['displayName' => 'Invented Reviewer Two'],
                'publishTime' => '2025-02-02T00:00:00Z',
            ],
        ],
    ]]);

    $messages = iterator_to_array((new GoogleBusinessConnector)->pull(gbPull('reviews'), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('places/INVENTEDPLACEID000/reviews/invented-review-1')
        ->and($records[0]->doc['rating'])->toBe(5)
        ->and($records[0]->doc['author'])->toBe('Invented Reviewer One')
        // The property this whole stream design rests on: not even on a full
        // happy path does a Sample stream get to claim coverage.
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('yields a record per photo with an unknown coverage claim, never exhaustive or a prefix', function () {
    $io = gbIo(['status' => 'ok', 'cached' => false, 'data' => [
        'photos' => [
            ['name' => 'places/INVENTEDPLACEID000/photos/invented-photo-1', 'widthPx' => 800, 'heightPx' => 600, 'authorAttributions' => [['displayName' => 'Invented Contributor']]],
        ],
    ]]);

    $messages = iterator_to_array((new GoogleBusinessConnector)->pull(gbPull('media'), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('places/INVENTEDPLACEID000/photos/invented-photo-1')
        // Slice 1b D6: the flat `authors` list of display names became a
        // structured `attribution` block, because the Places terms want the
        // author's Maps profile link and the photo's own Maps/flag URIs shown
        // alongside the name — a bare string cannot carry those.
        ->and($records[0]->doc['attribution']['authors'])->toBe([['name' => 'Invented Contributor', 'uri' => null]])
        ->and($records[0]->doc)->not->toHaveKey('authors')
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('unknown');
});

it('reports a non-ok ledger verdict as unavailable, not as an empty listing', function () {
    $io = gbIo(['status' => 'refused', 'cached' => true, 'data' => null]);

    $messages = iterator_to_array((new GoogleBusinessConnector)->pull(gbPull('profile'), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('emits a note with no coverage when the place has no reviews', function () {
    $io = gbIo(['status' => 'ok', 'cached' => false, 'data' => ['reviews' => []]]);

    $messages = iterator_to_array((new GoogleBusinessConnector)->pull(gbPull('reviews'), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('emits a note with no coverage when the place has no photos', function () {
    $io = gbIo(['status' => 'ok', 'cached' => false, 'data' => ['photos' => []]]);

    $messages = iterator_to_array((new GoogleBusinessConnector)->pull(gbPull('media'), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('marks reviews a Sample stream that explicitly must never delete', function () {
    $spec = GoogleBusinessConnector::manifest()->stream('reviews');

    expect($spec->profile)->toBe(SourceProfile::Sample)
        ->and($spec->mayDelete())->toBeFalse();
});

it('marks profile an Identity stream with no order field, so it cannot delete either', function () {
    $spec = GoogleBusinessConnector::manifest()->stream('profile');

    expect($spec->profile)->toBe(SourceProfile::Identity)
        ->and($spec->orderField)->toBeNull()
        ->and($spec->mayDelete())->toBeFalse();
});

it('marks media a Feed stream whose null order field still forbids deletion', function () {
    $spec = GoogleBusinessConnector::manifest()->stream('media');

    expect($spec->profile)->toBe(SourceProfile::Feed)
        ->and($spec->orderField)->toBeNull()
        ->and($spec->mayDelete())->toBeFalse();
});

it('redacts reviewer-identifying fields only for unclaimed accounts — claimed accounts keep full attribution', function () {
    $manifest = GoogleBusinessConnector::manifest();

    $unclaimed = $manifest->redactionsFor(false);
    $claimed = $manifest->redactionsFor(true);

    expect(in_array('author', $unclaimed, true))->toBeTrue()
        ->and(in_array('author_uri', $unclaimed, true))->toBeTrue()
        ->and(in_array('author_photo', $unclaimed, true))->toBeTrue()
        ->and(in_array('author', $claimed, true))->toBeFalse()
        ->and(in_array('author_uri', $claimed, true))->toBeFalse()
        ->and(in_array('author_photo', $claimed, true))->toBeFalse();
});
