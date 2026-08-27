<?php

// T23b (owner, 2026-08-28): the Fresha reviews stream — the venue page's
// __NEXT_DATA__ review list lands in the `review` kind through the existing
// reviews pool. Employee-mode connections keep ONLY reviews Fresha itself
// attributes to that staff member (footer interpolation OPEN_PROFILE —
// structured attribution, no name matching). Node shapes below mirror the
// live capture from anseo-studio (2026-08-28).

use App\Ingest\Connectors\FreshaConnector;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\FreshaReviewProjector;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Projection\RecordView;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/** An Io whose GET answers with the venue page; POST never expected. */
function freshaReviewsIo(array $getResponse): Io
{
    return new class($getResponse) implements Io
    {
        public function __construct(private array $getResponse) {}

        public function get(string $url, array $headers = []): array
        {
            return $this->getResponse;
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new RuntimeException('reviews stream must never hit the booking GraphQL');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            return array_map(fn ($u) => $this->get($u), array_combine($urls, $urls));
        }

        public function effect(string $kind, string $name, array $input): array
        {
            return ['status' => 'ok', 'cached' => false, 'data' => null];
        }
    };
}

function freshaVenuePage(array $location): array
{
    $next = json_encode(['props' => ['pageProps' => ['data' => ['location' => $location]]]]);

    return ['status' => 200, 'body' => '<html><script id="__NEXT_DATA__" type="application/json">'.$next.'</script></html>', 'headers' => []];
}

function freshaReviewNode(string $id, string $text, ?string $employeeId, ?string $employeeName, float $rating = 5.0): array
{
    $footer = ['fallbackText' => '1 month ago • Haircut'.($employeeName ? " • with {$employeeName}" : '')];
    if ($employeeId !== null) {
        $footer['interpolations'] = [[
            'id' => 'member',
            'text' => $employeeName,
            'action' => ['employeeId' => $employeeId, 'type' => 'OPEN_PROFILE'],
        ]];
    }

    return ['node' => [
        'id' => $id,
        'rating' => $rating,
        'text' => $text,
        'date' => ['iso' => '2026-07-12T10:21:52.985Z'],
        'footer' => $footer,
        'author' => ['name' => 'Nick M', 'avatar' => ['url' => 'https://images.fresha.com/x.png']],
    ]];
}

function freshaReviewsPull(?string $selectionRef): Pull
{
    return new Pull(
        identifier: 'anseo-studio-melbourne-w8ajp04r',
        stream: FreshaConnector::manifest()->stream('reviews'),
        config: $selectionRef === null ? [] : ['selection_ref' => $selectionRef],
    );
}

it('lands only the selected employee\'s reviews in employee mode, with venue stats', function () {
    $io = freshaReviewsIo(freshaVenuePage([
        'rating' => 4.9,
        'reviewsCount' => 37,
        'reviews' => ['edges' => [
            freshaReviewNode('r1', 'Simon does an unreal, best haircut I\'ve had.', '5182247', 'Simon'),
            freshaReviewNode('r2', 'Holley was fantastic.', '999', 'Holley'),
            freshaReviewNode('r3', 'Great vibe at the studio.', null, null),
        ]],
    ]));

    $messages = iterator_to_array((new FreshaConnector)->pull(freshaReviewsPull('5182247'), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('r1')
        ->and($records[0]->doc['text'])->toContain('Simon does an unreal')
        ->and($records[0]->doc['employee_id'])->toBe('5182247')
        ->and($records[0]->doc['employee_name'])->toBe('Simon')
        ->and($records[0]->doc['venue_rating'])->toBe(4.9)
        ->and($records[0]->doc['venue_rating_count'])->toBe(37)
        ->and($records[0]->doc['author'])->toBe('Nick M');
});

it('lands the whole venue\'s reviews in storewide mode', function () {
    $io = freshaReviewsIo(freshaVenuePage([
        'rating' => 4.9, 'reviewsCount' => 37,
        'reviews' => ['edges' => [
            freshaReviewNode('r1', 'A', '5182247', 'Simon'),
            freshaReviewNode('r2', 'B', '999', 'Holley'),
            freshaReviewNode('r3', 'C', null, null),
        ]],
    ]));

    $records = array_values(array_filter(
        iterator_to_array((new FreshaConnector)->pull(freshaReviewsPull('storewide'), $io), false),
        fn ($m) => $m instanceof Record,
    ));

    expect($records)->toHaveCount(3);
});

it('notes no_selection instead of publishing a whole venue\'s reviews on an unchosen page', function () {
    $io = freshaReviewsIo(freshaVenuePage(['rating' => 5, 'reviews' => ['edges' => []]]));

    $messages = iterator_to_array((new FreshaConnector)->pull(freshaReviewsPull(null), $io), false);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class);
});

it('reports Unavailable when the venue page yields no location blob', function () {
    $io = freshaReviewsIo(['status' => 410, 'body' => '', 'headers' => []]);

    $messages = iterator_to_array((new FreshaConnector)->pull(freshaReviewsPull('storewide'), $io), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Unavailable))->not->toBeNull();
});

it('registers the fresha reviews projector and emits the review facets', function () {
    expect(ProjectorRegistry::has('fresha', 'reviews'))->toBeTrue();

    $view = new RecordView([
        'rating' => 5.0,
        'text' => 'Best haircut in town, Emma did a great job',
        'author' => 'A Customer',
        'publish_time' => '2026-07-12T10:21:52.985Z',
        'published_ago' => '1 month ago • Haircut • with Emma',
        'employee_name' => 'Emma',
        'venue_rating' => 4.9,
        'venue_rating_count' => 152,
    ]);
    $projection = (new FreshaReviewProjector)->project($view);

    expect($projection['kind'])->toBe('review')
        ->and($projection['headline'])->toBeNull()
        ->and($projection['facets']['f_review']['text'])->toContain('Emma did a great job')
        ->and($projection['facets']['f_review']['staff_name'])->toBe('Emma')
        ->and($projection['facets']['f_rated']['rating'])->toBe(5.0)
        ->and($projection['source_stats'])->toBe(['rating_avg' => 4.9, 'rating_count' => 152]);
});
