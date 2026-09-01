<?php

use App\Ingest\Connectors\SpotifyPodcastsConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\ApplePodcastsEpisodeProjector;
use App\Ingest\Projection\RecordView;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\SpotifyPodcastsVendorDriver;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Connect\SpotifyPodcastsConnect;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\SpotifyPodcastsFetch;
use Illuminate\Support\Facades\Http;

// Item 11f (2026-09-01) wiring pass: the spotify_podcasts LANE — scraper,
// connect + fetch strategies, vendor driver, connector — end-to-end on the
// same recorded payloads the adapter contract test
// (ScrapeCreatorsSpotifyPodcastsTest) pins the normalizers against. The
// Pinterest frame:
//
//  1. When the vendor answers usably, the lane lands episodes EXACTLY as the
//     normalizer describes them, and the pool reads them through the REAL
//     ApplePodcastsEpisodeProjector — one projector, two sources.
//  2. When the vendor answers any other way, the run refuses or noAnswers —
//     spotify_podcasts has NO fallback lane, so a miss must stay a miss,
//     never an empty show. Budget: claim before the call, release on
//     transport-null, keep the slot spent on billed husks (NotFound AND an
//     all-RestrictedContent page both bill).

function scSpotPodLaneShowFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-spotify-podcast.json')),
        true
    );
}

function scSpotPodLaneNotFoundFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-spotify-podcast-notfound.json')),
        true
    );
}

function scSpotPodLaneEpisodesFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-spotify-podcast-episodes.json')),
        true
    );
}

function scSpotPodLaneMixedFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-spotify-podcast-episodes-mixed.json')),
        true
    );
}

function scSpotPodLaneCtx(array $input): BilledEffectContext
{
    return new BilledEffectContext('vendor', 'spotify_podcasts', $input, 'run-1', 'source-1', 'user-1');
}

const SC_SPOT_POD_LANE_SHOW = 'https://open.spotify.com/show/79CkJF3UJTHFV8Dse3Oy0P';

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.spotify_podcasts', 50);
});

// ── (a) Driver: one billed episodes call, the budget mechanics ──────────────

it('answers normalized episode rows off the recorded page in one billed call', function () {
    Http::fake(['api.scrapecreators.com/v1/spotify/podcast/episodes*' => Http::response(scSpotPodLaneEpisodesFixture())]);

    $result = app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => '79CkJF3UJTHFV8Dse3Oy0P']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toHaveCount(3)
        ->and($result->data[0]['trackId'])->toBe('304elIxDVbXQ7dBO4G2N4e')
        ->and($result->data[0]['collectionName'])->toBe('Huberman Lab');
    Http::assertSentCount(1);
});

it('answers the real rows around a mid-list RestrictedContent husk', function () {
    // JRE is a Spotify exclusive: its recorded page carries one restricted
    // husk among real episodes — the list survives, the husk does not.
    Http::fake(['api.scrapecreators.com/*' => Http::response(scSpotPodLaneMixedFixture())]);

    $result = app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => '4rOoJ6Egrf8K2IrywzwOMk']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toHaveCount(1)
        ->and($result->data[0]['trackId'])->toBe('71Q6UzLG4QoN7lRw5SmUxf');
});

it('noAnswers a show-less input before any claim or wire call', function () {
    Http::fake();

    $result = app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => 'not a show id']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    Http::assertNothingSent();
});

it('refuses to run without a key — there is no fallback lane to fall through to', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(fn () => app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => '79CkJF3UJTHFV8Dse3Oy0P'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('refuses before spending when the daily cap is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.spotify_podcasts', 0);
    Http::fake();

    expect(fn () => app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => '79CkJF3UJTHFV8Dse3Oy0P'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('releases the claimed slot on a transport failure so a later run may retry', function () {
    config()->set('partna.limits.scrapecreators.sources.spotify_podcasts', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    $first = app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => '79CkJF3UJTHFV8Dse3Oy0P']));
    expect($first->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    // The slot came back: with cap=1 an unreleased claim would refuse the
    // second run outright (EffectNotAttempted) — instead it claims again,
    // reaches the wire again, and folds the same transport miss.
    $second = app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => '79CkJF3UJTHFV8Dse3Oy0P']));

    expect($second->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and($second->reason)->toContain('did not answer');
    Http::assertSentCount(2);
});

it('keeps the slot spent on the recorded NotFound husk (bills as success:true)', function () {
    config()->set('partna.limits.scrapecreators.sources.spotify_podcasts', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response(scSpotPodLaneNotFoundFixture())]);

    $first = app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => '79CkJF3UJTHFV8Dse3Oy0Q']));
    expect($first->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    expect(fn () => app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => '79CkJF3UJTHFV8Dse3Oy0Q'])))
        ->toThrow(EffectNotAttempted::class);
});

it('keeps the slot spent on an all-restricted page — billed, and never an empty catalogue', function () {
    config()->set('partna.limits.scrapecreators.sources.spotify_podcasts', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response([
        'success' => true,
        'credits_charged' => 1,
        'episodes' => [['__typename' => 'RestrictedContent'], ['__typename' => 'RestrictedContent']],
    ])]);

    $first = app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => '4rOoJ6Egrf8K2IrywzwOMk']));
    expect($first->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    expect(fn () => app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => '4rOoJ6Egrf8K2IrywzwOMk'])))
        ->toThrow(EffectNotAttempted::class);
});

// ── (b) Connector: episodes enter the listen pool beside apple_podcasts ─────

function scSpotPodLaneIo(array $effect): Io
{
    return new class($effect) implements Io
    {
        public array $calls = [];

        public function __construct(private array $effect) {}

        public function get(string $url, array $headers = []): array
        {
            throw new RuntimeException('unexpected GET');
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new RuntimeException('unexpected POST');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            throw new RuntimeException('unexpected getMany');
        }

        public function effect(string $kind, string $name, array $input): array
        {
            $this->calls[] = [$kind, $name, $input];

            return $this->effect;
        }
    };
}

function scSpotPodLanePull(string $identifier = SC_SPOT_POD_LANE_SHOW): Pull
{
    return new Pull(identifier: $identifier, stream: SpotifyPodcastsConnector::manifest()->stream('listen'), config: []);
}

it('lands the recorded episodes newest-first with prefix coverage', function () {
    // The driver's own answer for the recorded page, via a real run.
    Http::fake(['api.scrapecreators.com/*' => Http::response(scSpotPodLaneEpisodesFixture())]);
    $driven = app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => '79CkJF3UJTHFV8Dse3Oy0P']));

    $io = scSpotPodLaneIo(['status' => 'ok', 'cached' => false, 'data' => $driven->data]);
    $messages = iterator_to_array((new SpotifyPodcastsConnector)->pull(scSpotPodLanePull(), $io), false);

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = collect($messages)->first(fn ($m) => $m instanceof Covered);

    expect($io->calls)->toBe([['vendor', 'spotify_podcasts', ['show_id' => '79CkJF3UJTHFV8Dse3Oy0P']]])
        ->and($records)->toHaveCount(3)
        ->and($records[0]->stream)->toBe('listen')
        ->and($records[0]->key)->toBe('304elIxDVbXQ7dBO4G2N4e')
        ->and($records[0]->doc['releaseDate'])->toBe('2026-08-31T08:00:00Z')
        ->and($covered)->not->toBeNull()
        ->and($covered->coverage->toArray()['type'])->toBe('prefix');
});

it('parses the show id off an intl-prefixed identifier URL', function () {
    $io = scSpotPodLaneIo(['status' => 'ok', 'cached' => false, 'data' => [[
        'trackId' => 'ep1', 'trackName' => 'Episode One',
    ]]]);

    iterator_to_array((new SpotifyPodcastsConnector)->pull(
        scSpotPodLanePull('https://open.spotify.com/intl-de/show/79CkJF3UJTHFV8Dse3Oy0P?si=abc'),
        $io,
    ), false);

    expect($io->calls)->toBe([['vendor', 'spotify_podcasts', ['show_id' => '79CkJF3UJTHFV8Dse3Oy0P']]]);
});

it('says not_a_show for free instead of paying for a husk on a non-show identifier', function () {
    $io = scSpotPodLaneIo(['status' => 'ok', 'cached' => false, 'data' => []]);

    $messages = iterator_to_array((new SpotifyPodcastsConnector)->pull(
        scSpotPodLanePull('https://open.spotify.com/artist/5tDjiBYUsTqzd0RkTZxK7u'),
        $io,
    ), false);

    expect($io->calls)->toBe([])
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class);
});

it('folds a refused effect into Unavailable and an empty answer into a Note', function () {
    $refused = iterator_to_array((new SpotifyPodcastsConnector)->pull(scSpotPodLanePull(), scSpotPodLaneIo(['status' => 'refused'])), false);
    expect($refused)->toHaveCount(1)->and($refused[0])->toBeInstanceOf(Unavailable::class);

    $empty = iterator_to_array((new SpotifyPodcastsConnector)->pull(scSpotPodLanePull(), scSpotPodLaneIo(['status' => 'ok', 'cached' => false, 'data' => []])), false);
    expect($empty)->toHaveCount(1)->and($empty[0])->toBeInstanceOf(Note::class);
});

it('re-asserts the listen vocabulary and drops id-less rows the driver never made', function () {
    $io = scSpotPodLaneIo(['status' => 'ok', 'cached' => false, 'data' => [
        ['trackId' => 'keeper', 'trackName' => 'Keeper'],
        ['trackName' => 'No id'],
        'not-an-array',
    ]]);

    $messages = iterator_to_array((new SpotifyPodcastsConnector)->pull(scSpotPodLanePull(), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($records)->toHaveCount(1)
        ->and($records[0]->doc['trackId'])->toBe('keeper')
        // A minimal row still lands the full vocabulary, absent keys null —
        // and the link is derived from the id, never absent.
        ->and($records[0]->doc['collectionName'])->toBeNull()
        ->and($records[0]->doc['trackViewUrl'])->toBe('https://open.spotify.com/episode/keeper');
});

it('projects a landed episode through the real apple-podcasts projector — one projector, two sources', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scSpotPodLaneEpisodesFixture())]);
    $driven = app(SpotifyPodcastsVendorDriver::class)->run(scSpotPodLaneCtx(['show_id' => '79CkJF3UJTHFV8Dse3Oy0P']));

    $io = scSpotPodLaneIo(['status' => 'ok', 'cached' => false, 'data' => $driven->data]);
    $messages = iterator_to_array((new SpotifyPodcastsConnector)->pull(scSpotPodLanePull(), $io), false);
    $record = collect($messages)->first(fn ($m) => $m instanceof Record);

    $item = (new ApplePodcastsEpisodeProjector)->project(new RecordView($record->doc, (string) $record->key));

    expect($item['kind'])->toBe('episode')
        ->and($item['headline'])->toBe('How to Accelerate Learning & Improve Education | Joe Liemandt')
        ->and($item['facets']['f_link']['url'])->toBe('https://open.spotify.com/episode/304elIxDVbXQ7dBO4G2N4e')
        ->and($item['facets']['f_authored']['creator'])->toBe('Huberman Lab')
        ->and($item['media'][0]['role'])->toBe('cover');
});

// ── (c) Connect strategy: the show card, budget-claimed ─────────────────────

it('resolves a show link into the identity card in one billed call', function () {
    Http::fake(['api.scrapecreators.com/v1/spotify/podcast*' => Http::response(scSpotPodLaneShowFixture())]);

    $result = app(SpotifyPodcastsConnect::class)->resolve(SC_SPOT_POD_LANE_SHOW.'?si=share-token');

    expect($result->failed())->toBeFalse()
        ->and($result->selection['url'])->toBe(SC_SPOT_POD_LANE_SHOW)
        ->and($result->selection['link'])->toBe(SC_SPOT_POD_LANE_SHOW)
        ->and($result->selection['name'])->toBe('Huberman Lab')
        ->and($result->selection['publisher'])->toBe('Scicomm Media')
        ->and($result->selection['thumbnail'])->toBe('https://i.scdn.co/image/ab6765630000ba8a66aed32f8066a72781b3b12a');
    Http::assertSentCount(1);
});

it('identify() derives the same identity key as resolve() with zero network', function () {
    Http::fake(['api.scrapecreators.com/v1/spotify/podcast*' => Http::response(scSpotPodLaneShowFixture())]);

    $strategy = app(SpotifyPodcastsConnect::class);
    $identify = $strategy->identify('open.spotify.com/show/79CkJF3UJTHFV8Dse3Oy0P');
    Http::assertNothingSent();

    $resolve = $strategy->resolve('open.spotify.com/show/79CkJF3UJTHFV8Dse3Oy0P');

    expect($identify->failed())->toBeFalse()
        ->and($identify->selection['link'])->toBe($resolve->selection['link'])
        ->and($identify->selection['url'])->toBe($resolve->selection['url']);
});

it('shares the parse-fail shape across both paths, and refuses item-kind links', function () {
    Http::fake();
    $strategy = app(SpotifyPodcastsConnect::class);

    // An EPISODE link is an item, not a show (T6b) — and non-spotify input
    // fails identically. Null error → the descriptor's message; 422.
    foreach (['https://open.spotify.com/episode/304elIxDVbXQ7dBO4G2N4e', 'https://example.com/not-spotify'] as $bad) {
        $identify = $strategy->identify($bad);
        $resolve = $strategy->resolve($bad);

        expect($identify->failed())->toBeTrue()
            ->and($resolve->failed())->toBeTrue()
            ->and([$identify->error, $identify->status])->toBe([$resolve->error, $resolve->status])
            ->and($resolve->error)->toBeNull()
            ->and($resolve->status)->toBe(422);
    }
    Http::assertNothingSent();
});

it('fails the connect with its own message on the recorded NotFound husk', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scSpotPodLaneNotFoundFixture())]);

    $result = app(SpotifyPodcastsConnect::class)->resolve('https://open.spotify.com/show/79CkJF3UJTHFV8Dse3Oy0Q');

    expect($result->failed())->toBeTrue()
        ->and($result->error)->toBe('Could not load that Spotify show.');
});

it('fails the connect without reaching the wire when the cap is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.spotify_podcasts', 0);
    Http::fake();

    $result = app(SpotifyPodcastsConnect::class)->resolve(SC_SPOT_POD_LANE_SHOW);

    expect($result->failed())->toBeTrue()
        ->and($result->error)->toBe('Could not load that Spotify show.');
    Http::assertNothingSent();
});

// ── (d) Fetch strategy: the refresher/deferred-fill leg ─────────────────────

it('re-pulls the show card by the stored link and merges over the payload', function () {
    Http::fake(['api.scrapecreators.com/v1/spotify/podcast*' => Http::response(scSpotPodLaneShowFixture())]);

    $connection = new IntegrationConnection(['payload' => [
        // The pending shape identify() writes — an intl paste, deliberately,
        // to prove the refetch re-canonicalises it.
        'link' => 'https://open.spotify.com/intl-de/show/79CkJF3UJTHFV8Dse3Oy0P',
        'url' => 'https://open.spotify.com/intl-de/show/79CkJF3UJTHFV8Dse3Oy0P',
    ]]);

    $payload = app(SpotifyPodcastsFetch::class)->fetch($connection);

    expect($payload['name'])->toBe('Huberman Lab')
        ->and($payload['publisher'])->toBe('Scicomm Media')
        ->and($payload['link'])->toBe(SC_SPOT_POD_LANE_SHOW)
        ->and($payload['url'])->toBe(SC_SPOT_POD_LANE_SHOW)
        ->and($payload['thumbnail'])->toStartWith('https://i.scdn.co/image/');
});

it('throws the shape exception on a link-less payload before any spend', function () {
    Http::fake();

    expect(fn () => app(SpotifyPodcastsFetch::class)->fetch(new IntegrationConnection(['payload' => ['name' => 'Old']])))
        ->toThrow(FetchShapeException::class);
    Http::assertNothingSent();
});

it('throws unavailable — never an emptied card — when the vendor misses', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    $connection = new IntegrationConnection(['payload' => ['link' => SC_SPOT_POD_LANE_SHOW, 'name' => 'Kept']]);

    expect(fn () => app(SpotifyPodcastsFetch::class)->fetch($connection))
        ->toThrow(FetchUnavailableException::class);
});
