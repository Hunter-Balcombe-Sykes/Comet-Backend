<?php

use App\Ingest\Connectors\PinterestConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\PinterestMediaProjector;
use App\Ingest\Projection\RecordView;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\PinterestVendorDriver;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Services\Platforms\ScrapeCreators\PinterestBoardsNormalizer;
use App\Services\Platforms\ScrapeCreators\PinterestPinsNormalizer;
use Illuminate\Support\Facades\Http;

// Item 10a (2026-09-01): pinterest boards + pins → media-pool candidates,
// pinned against RECORDED live payloads (slimmed /v1/pinterest/user/boards +
// /v1/pinterest/board answers for food52, 2026-09-01). The Instagram frame:
//
//  1. When the vendor answers usably, the connector lands pins EXACTLY as the
//     driver's rows describe them — same keys, same mapPin outcomes.
//  2. When the vendor answers any other way, the run refuses or noAnswers —
//     pinterest has NO fallback lane, so a miss must stay a miss, never an
//     empty account.

function scPinBoardsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-pinterest-user-boards.json')),
        true
    );
}

function scPinPinsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-pinterest-board.json')),
        true
    );
}

function scPinCtx(array $input): BilledEffectContext
{
    return new BilledEffectContext('vendor', 'pinterest', $input, 'run-1', 'source-1', 'user-1');
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.pinterest', 50);
});

// ── (a) Normalizer contracts against the recorded fixtures ──────────────────

it('normalizes recorded boards into discovery rows with the privacy gate applied positively', function () {
    $rows = app(PinterestBoardsNormalizer::class)->rows(scPinBoardsFixture());

    expect($rows)->toHaveCount(3);

    $colorful = collect($rows)->firstWhere('id', '227361549877447441');
    expect($colorful['name'])->toBe('Colorful Tables')
        ->and($colorful['url'])->toBe('https://www.pinterest.com/food52/colorful-tables/')
        ->and($colorful['description'])->toBe('Whimsy Table Decor')
        ->and($colorful['pin_count'])->toBe(45)
        ->and($colorful['cover'])->toStartWith('https://i.pinimg.com/');

    // Empty-string description collapses to null, never lands as ''.
    expect(collect($rows)->firstWhere('id', '227361549877451708')['description'])->toBeNull();
});

it('drops any board it cannot positively prove public', function () {
    $body = scPinBoardsFixture();
    $body['boards'][0]['privacy'] = 'secret';
    unset($body['boards'][1]['privacy']);

    $rows = app(PinterestBoardsNormalizer::class)->rows($body);

    expect(array_column($rows, 'id'))->toBe(['227361549877447441']);
});

it('reads a boards husk as a vendor miss, never as a board-less account', function () {
    expect(app(PinterestBoardsNormalizer::class)->rows(['success' => true, 'credits_charged' => 1, 'boards' => []]))->toBeNull()
        ->and(app(PinterestBoardsNormalizer::class)->rows(['success' => false, 'message' => 'nope']))->toBeNull();
});

it('normalizes recorded pins and drops the story annotation row on shape', function () {
    $rows = app(PinterestPinsNormalizer::class)->rows(scPinPinsFixture());

    // 5 vendor rows, but the type:"story" related-interests module is not a pin.
    expect($rows)->toHaveCount(4);

    // The upload-only pin: no link, whitespace description → null caption.
    $upload = collect($rows)->firstWhere('id', '227361481183370480');
    expect($upload['title'])->toBe('Playful Glassware')
        ->and($upload['caption'])->toBeNull()
        ->and($upload['url'])->toBe('https://www.pinterest.com/pin/227361481183370480/')
        ->and($upload['image'])->toBe('https://i.pinimg.com/originals/6a/f2/3c/6af23c64881daa704bd7cc21c2f39165.jpg')
        ->and($upload['video_url'])->toBeNull()
        ->and($upload['board_id'])->toBe('227361549877447441');

    // The video pin: is_video is FALSE on the wire — video is read from
    // video_list shape, and the trial-verified ms quirk lands 92933 as 93s.
    $video = collect($rows)->firstWhere('id', '227361481185997356');
    expect($video['video_url'])->toEndWith('_720w.mp4')
        ->and($video['duration'])->toBe(93);
});

it('reads a pins husk as a vendor miss, never as an empty board', function () {
    expect(app(PinterestPinsNormalizer::class)->rows(['success' => true, 'credits_charged' => 1, 'pins' => []]))->toBeNull()
        ->and(app(PinterestPinsNormalizer::class)->rows(['success' => false]))->toBeNull();
});

// ── (b) Driver: boards walk, budget mechanics, no-fallback refusals ─────────

it('walks the public boards in vendor order and answers deduped pin rows', function () {
    Http::fake([
        'api.scrapecreators.com/v1/pinterest/user/boards*' => Http::response(scPinBoardsFixture()),
        'api.scrapecreators.com/v1/pinterest/board*' => Http::response(scPinPinsFixture()),
    ]);

    $result = app(PinterestVendorDriver::class)->run(scPinCtx(['username' => '@Food52']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        // Every board call returns the same recorded page — the id-keyed
        // dedupe folds 3×4 vendor rows into 4.
        ->and($result->data)->toHaveCount(4)
        // The vetted board-list row overrides the pin's embedded board stub.
        ->and($result->data[0]['board_name'])->toBe('Summer Inspo');
    // 1 boards call + boards_per_run (default 3) pin calls.
    Http::assertSentCount(4);
});

it('bounds spend at boards_per_run', function () {
    config()->set('partna.limits.scrapecreators.pinterest.boards_per_run', 1);
    Http::fake([
        'api.scrapecreators.com/v1/pinterest/user/boards*' => Http::response(scPinBoardsFixture()),
        'api.scrapecreators.com/v1/pinterest/board*' => Http::response(scPinPinsFixture()),
    ]);

    $result = app(PinterestVendorDriver::class)->run(scPinCtx(['username' => 'food52']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered);
    Http::assertSentCount(2);
});

it('refuses to run without a key — there is no fallback lane to fall through to', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(fn () => app(PinterestVendorDriver::class)->run(scPinCtx(['username' => 'food52'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('refuses before spending when the daily cap is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.pinterest', 0);
    Http::fake();

    expect(fn () => app(PinterestVendorDriver::class)->run(scPinCtx(['username' => 'food52'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('releases the claimed slot on a transport failure so a later run may retry', function () {
    config()->set('partna.limits.scrapecreators.sources.pinterest', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    $first = app(PinterestVendorDriver::class)->run(scPinCtx(['username' => 'food52']));
    expect($first->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    // The slot came back: with cap=1 an unreleased claim would refuse the
    // second run outright (EffectNotAttempted) — instead it claims again,
    // reaches the wire again, and folds the same transport miss.
    $second = app(PinterestVendorDriver::class)->run(scPinCtx(['username' => 'food52']));

    expect($second->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and($second->reason)->toContain('did not answer');
    Http::assertSentCount(2);
});

it('keeps the slot spent on a billed husk (the NotFound quirk bills with success:true)', function () {
    config()->set('partna.limits.scrapecreators.sources.pinterest', 1);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 1, 'boards' => []]),
    ]);

    $first = app(PinterestVendorDriver::class)->run(scPinCtx(['username' => 'ghost-handle']));
    expect($first->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    expect(fn () => app(PinterestVendorDriver::class)->run(scPinCtx(['username' => 'ghost-handle'])))
        ->toThrow(EffectNotAttempted::class);
});

it('answers noAnswer, never answered-empty, when no board yields an image-bearing pin', function () {
    Http::fake([
        'api.scrapecreators.com/v1/pinterest/user/boards*' => Http::response(scPinBoardsFixture()),
        'api.scrapecreators.com/v1/pinterest/board*' => Http::response(['success' => true, 'credits_charged' => 1, 'pins' => []]),
    ]);

    $result = app(PinterestVendorDriver::class)->run(scPinCtx(['username' => 'food52']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

// ── (c) Connector + projector: pins enter the media pool like instagram ─────

function scPinIo(array $effect): Io
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

function scPinPull(): Pull
{
    return new Pull(identifier: '@Food52', stream: PinterestConnector::manifest()->stream('pins'), config: []);
}

function scPinRow(string $id, array $extra = []): array
{
    return $extra + [
        'id' => $id,
        'title' => 'Playful Glassware',
        'caption' => null,
        'url' => "https://www.pinterest.com/pin/{$id}/",
        'image' => 'https://i.pinimg.com/originals/6a/f2/3c/6af23c64881daa704bd7cc21c2f39165.jpg',
        'video_url' => null,
        'duration' => null,
        'board_id' => '227361549877447441',
        'board_name' => 'Colorful Tables',
    ];
}

it('lands pins in curation order with unknown coverage — absence never deletes', function () {
    $io = scPinIo(['status' => 'ok', 'cached' => false, 'data' => [
        scPinRow('227361481183370480'),
        scPinRow('227361481185997356', ['video_url' => 'https://v1.pinimg.com/videos/iht/expMp4/x_720w.mp4', 'duration' => 93]),
        ['id' => 'not-numeric', 'image' => 'https://i.pinimg.com/x.jpg'],
    ]]);

    $messages = iterator_to_array((new PinterestConnector)->pull(scPinPull(), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = collect($messages)->first(fn ($m) => $m instanceof Covered);

    expect($io->calls)->toBe([['vendor', 'pinterest', ['username' => 'food52']]])
        ->and($records)->toHaveCount(2)
        ->and($records[0]->stream)->toBe('pins')
        ->and($records[0]->key)->toBe('227361481183370480')
        ->and($records[1]->doc['video_url'])->toEndWith('_720w.mp4')
        ->and($covered)->not->toBeNull()
        ->and($covered->coverage->toArray())->toBe(['type' => 'unknown']);
});

it('folds a refused effect into Unavailable and an empty answer into a Note', function () {
    $refused = iterator_to_array((new PinterestConnector)->pull(scPinPull(), scPinIo(['status' => 'refused'])), false);
    expect($refused)->toHaveCount(1)->and($refused[0])->toBeInstanceOf(Unavailable::class);

    $empty = iterator_to_array((new PinterestConnector)->pull(scPinPull(), scPinIo(['status' => 'ok', 'cached' => false, 'data' => []])), false);
    expect($empty)->toHaveCount(1)->and($empty[0])->toBeInstanceOf(Note::class);
});

it('projects a pin to the media kind with owned refs and no synthesized date', function () {
    $doc = scPinRow('227361481185997356', [
        'caption' => 'A colorful table story.',
        'video_url' => 'https://v1.pinimg.com/videos/iht/expMp4/x_720w.mp4',
        'duration' => 93,
    ]);

    $item = (new PinterestMediaProjector)->project(new RecordView($doc));

    expect($item['kind'])->toBe('media')
        ->and($item['headline'])->toBeNull()
        ->and($item['media'][0])->toBe([
            'role' => 'cover',
            'url' => 'https://i.pinimg.com/originals/6a/f2/3c/6af23c64881daa704bd7cc21c2f39165.jpg',
            'ref' => 'pinterest:227361481185997356:0',
        ])
        ->and($item['media'][1]['role'])->toBe('video')
        ->and($item['media'][1]['ref'])->toBe('pinterest:227361481185997356:video')
        ->and($item['facets']['f_text']['body'])->toBe('A colorful table story.')
        ->and($item['facets']['f_playable']['stream_url'])->toEndWith('_720w.mp4')
        ->and($item['facets'])->not->toHaveKey('f_published');
});

it('refuses to project an imageless pin', function () {
    expect((new PinterestMediaProjector)->project(new RecordView(['id' => '227361481183370480'])))->toBeNull();
});
