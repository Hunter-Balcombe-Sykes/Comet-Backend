<?php

use App\Ingest\Connectors\BlueskyConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\BlueskyMediaProjector;
use App\Ingest\Projection\RecordView;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\BlueskyVendorDriver;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use Illuminate\Support\Facades\Http;

// Item 10b (2026-09-01): bluesky profile + own posts → media-pool candidates,
// end-to-end on the RECORDED live payloads (the same captures the contract
// tests in ScrapeCreatorsBlueskyTest pin). The Pinterest frame:
//
//  1. When the vendor answers usably AND the answered account is the one that
//     was asked for, the driver lands the normalizer's own-post rows and the
//     connector shapes the imagery-bearing subset into the media stream.
//  2. When the vendor answers any other way — a husk, a transport miss, or a
//     DIFFERENT account (squatter handles answer successfully; the exact-
//     account check is load-bearing) — the run refuses or noAnswers. Bluesky
//     has NO fallback lane, so a miss must stay a miss, never an empty account.

const BSKY_LANE_DID = 'did:plc:z72i7hdynmk6r22z27h6tvur';

function bskyLaneProfileFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-bluesky-profile.json')),
        true
    );
}

function bskyLanePostsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-bluesky-user-posts.json')),
        true
    );
}

function bskyLaneCtx(array $input): BilledEffectContext
{
    return new BilledEffectContext('vendor', 'bluesky', $input, 'run-1', 'source-1', 'user-1');
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.bluesky', 50);
});

// ── (a) Driver: profile-then-posts, exact-account gate, budget mechanics ────

it('fetches the profile, validates it, and answers the own-post rows keyed by the answered did', function () {
    Http::fake([
        'api.scrapecreators.com/v1/bluesky/profile*' => Http::response(bskyLaneProfileFixture()),
        'api.scrapecreators.com/v1/bluesky/user/posts*' => Http::response(bskyLanePostsFixture()),
    ]);

    $result = app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => '@Bsky.app']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        // The recorded feed's 3 own top-level posts — the text-only pinned
        // one INCLUDED: the driver answers vendor truth, the connector is
        // where media candidacy is decided.
        ->and(array_column($result->data, 'id'))->toBe(['3l6oveex3ii2l', '3mu3jzayuys2k', '3msqpuobiwk2t']);

    // Posts are fetched by the ANSWERED did, not the asked handle — that is
    // what makes the own-author filter exact.
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'user/posts')
        || $request['user_id'] === BSKY_LANE_DID);
});

it('accepts a did identifier and validates it against the answered did', function () {
    Http::fake([
        'api.scrapecreators.com/v1/bluesky/profile*' => Http::response(bskyLaneProfileFixture()),
        'api.scrapecreators.com/v1/bluesky/user/posts*' => Http::response(bskyLanePostsFixture()),
    ]);

    $result = app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => BSKY_LANE_DID]));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered);
});

it('refuses a profile that is not the account it asked for — squatters answer successfully', function () {
    config()->set('partna.limits.scrapecreators.sources.bluesky', 1);
    // The recorded profile answers 'bsky.app'; the run asked for someone else.
    Http::fake(['api.scrapecreators.com/*' => Http::response(bskyLaneProfileFixture())]);

    $result = app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => 'prospect.bsky.social']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and($result->reason)->toContain('mismatch');
    // The posts call never happened — nothing of another account's may land.
    Http::assertSentCount(1);

    // The mismatch was a BILLED answer — the slot stays spent (cap=1, so a
    // second run refuses before the wire).
    expect(fn () => app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => 'prospect.bsky.social'])))
        ->toThrow(EffectNotAttempted::class);
});

it('refuses to run without a key — there is no fallback lane to fall through to', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(fn () => app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => 'bsky.app'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('refuses before spending when the daily cap is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.bluesky', 0);
    Http::fake();

    expect(fn () => app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => 'bsky.app'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('releases the claimed slot on a profile transport failure so a later run may retry', function () {
    config()->set('partna.limits.scrapecreators.sources.bluesky', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    $first = app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => 'bsky.app']));
    expect($first->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    // The slot came back: with cap=1 an unreleased claim would refuse the
    // second run outright (EffectNotAttempted) — instead it claims again,
    // reaches the wire again, and folds the same transport miss.
    $second = app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => 'bsky.app']));

    expect($second->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and($second->reason)->toContain('did not answer');
    Http::assertSentCount(2);
});

it('keeps the slot spent on the recorded NotFound husk even though bluesky bills it at zero', function () {
    config()->set('partna.limits.scrapecreators.sources.bluesky', 1);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(json_decode(
            file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-bluesky-profile-notfound.json')),
            true
        )),
    ]);

    $first = app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => 'ghost.bsky.social']));
    expect($first->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    expect(fn () => app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => 'ghost.bsky.social'])))
        ->toThrow(EffectNotAttempted::class);
});

it('folds mid-run cap exhaustion into noAnswer, never a throw — the profile call already billed', function () {
    // One slot: the profile claims it, the posts claim must then be refused
    // WITHOUT EffectNotAttempted (rule 1: only before the first vendor call).
    config()->set('partna.limits.scrapecreators.sources.bluesky', 1);
    Http::fake(['api.scrapecreators.com/v1/bluesky/profile*' => Http::response(bskyLaneProfileFixture())]);

    $result = app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => 'bsky.app']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and($result->reason)->toContain('cap');
    Http::assertSentCount(1);
});

it('answers noAnswer when the posts call does not answer, releasing only the posts slot', function () {
    Http::fake([
        'api.scrapecreators.com/v1/bluesky/profile*' => Http::response(bskyLaneProfileFixture()),
        'api.scrapecreators.com/v1/bluesky/user/posts*' => Http::response('upstream sad', 502),
    ]);

    $result = app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => 'bsky.app']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and($result->reason)->toContain('did not answer');
    Http::assertSentCount(2);
});

it('reads a husk feed as a vendor miss, never as an empty account', function () {
    Http::fake([
        'api.scrapecreators.com/v1/bluesky/profile*' => Http::response(bskyLaneProfileFixture()),
        'api.scrapecreators.com/v1/bluesky/user/posts*' => Http::response(['success' => true, 'error' => 'not_found']),
    ]);

    $result = app(BlueskyVendorDriver::class)->run(bskyLaneCtx(['handle' => 'bsky.app']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and($result->reason)->toContain('no own authored post');
});

// ── (b) Connector: imagery-bearing posts enter the media stream, newest first ─

function bskyLaneIo(array $effect): Io
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

function bskyLanePull(): Pull
{
    return new Pull(identifier: '@Bsky.app', stream: BlueskyConnector::manifest()->stream('posts'), config: []);
}

function bskyLaneRow(string $id, string $createdAt, array $extra = []): array
{
    return $extra + [
        'id' => $id,
        'uri' => 'at://'.BSKY_LANE_DID.'/app.bsky.feed.post/'.$id,
        'url' => 'https://bsky.app/profile/bsky.app/post/'.$id,
        'text' => 'Post '.$id,
        'createdAt' => $createdAt,
        'isVideo' => false,
        'images' => [[
            'url' => 'https://cdn.bsky.app/img/feed_fullsize/plain/'.$id.'@jpeg',
            'thumb' => 'https://cdn.bsky.app/img/feed_thumbnail/plain/'.$id.'@jpeg',
            'alt' => 'Alt '.$id,
            'width' => 3300,
            'height' => 1968,
        ]],
        'video' => null,
    ];
}

it('lands imagery posts newest-first with prefix coverage, dropping the text-only pinned post', function () {
    // Pinned-first vendor order: the 2024 text post leads, then out-of-order
    // 2026 posts — the exact recorded shape.
    $io = bskyLaneIo(['status' => 'ok', 'cached' => false, 'data' => [
        bskyLaneRow('3l6oveex3ii2l', '2024-10-17T07:06:51.491Z', ['images' => null]),
        bskyLaneRow('3msqpuobiwk2t', '2026-08-10T18:23:59.962Z'),
        bskyLaneRow('3mu3jzayuys2k', '2026-08-27T19:03:40.083Z'),
    ]]);

    $messages = iterator_to_array((new BlueskyConnector)->pull(bskyLanePull(), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = collect($messages)->first(fn ($m) => $m instanceof Covered);

    expect($io->calls)->toBe([['vendor', 'bluesky', ['handle' => 'bsky.app']]])
        ->and($records)->toHaveCount(2)
        ->and($records[0]->stream)->toBe('posts')
        // Recency order, not vendor order — the pinned rule.
        ->and(array_map(fn ($r) => $r->key, $records))->toBe(['3mu3jzayuys2k', '3msqpuobiwk2t'])
        ->and($covered)->not->toBeNull()
        // Coverage reaches only the oldest LANDED post — never the account.
        ->and($covered->coverage->toArray())->toBe([
            'type' => 'prefix',
            'from' => '2026-08-10T18:23:59.962Z',
            'count' => 2,
        ]);
});

it('keeps a video post as a candidate through its poster frame', function () {
    $io = bskyLaneIo(['status' => 'ok', 'cached' => false, 'data' => [
        bskyLaneRow('3m2d3syyfrt2v', '2025-10-03T22:15:51.915Z', [
            'isVideo' => true,
            'images' => null,
            'video' => [
                'playlist' => 'https://video.bsky.app/watch/x/playlist.m3u8',
                'thumbnail' => 'https://video.bsky.app/watch/x/thumbnail.jpg',
            ],
        ]),
    ]]);

    $records = array_values(array_filter(
        iterator_to_array((new BlueskyConnector)->pull(bskyLanePull(), $io), false),
        fn ($m) => $m instanceof Record
    ));

    expect($records)->toHaveCount(1)
        ->and($records[0]->doc['isVideo'])->toBeTrue()
        ->and($records[0]->doc['video']['thumbnail'])->toEndWith('/thumbnail.jpg');
});

it('folds a refused effect into Unavailable and an imagery-less answer into a Note', function () {
    $refused = iterator_to_array((new BlueskyConnector)->pull(bskyLanePull(), bskyLaneIo(['status' => 'refused'])), false);
    expect($refused)->toHaveCount(1)->and($refused[0])->toBeInstanceOf(Unavailable::class);

    // An all-text account answered fine — but holds nothing the media pool
    // could frame.
    $textOnly = iterator_to_array((new BlueskyConnector)->pull(bskyLanePull(), bskyLaneIo([
        'status' => 'ok', 'cached' => false,
        'data' => [bskyLaneRow('3l6oveex3ii2l', '2024-10-17T07:06:51.491Z', ['images' => null])],
    ])), false);
    expect($textOnly)->toHaveCount(1)->and($textOnly[0])->toBeInstanceOf(Note::class);
});

// ── (c) Projector: posts enter the media pool with owned refs ───────────────

it('projects an images post to the media kind with owned refs, alt text, and its date', function () {
    $doc = bskyLaneRow('3mu3jzayuys2k', '2026-08-27T19:03:40.083Z');
    $doc['images'][] = [
        'url' => 'https://cdn.bsky.app/img/feed_fullsize/plain/second@jpeg',
        'thumb' => 'https://cdn.bsky.app/img/feed_thumbnail/plain/second@jpeg',
    ];

    $item = (new BlueskyMediaProjector)->project(new RecordView($doc));

    expect($item['kind'])->toBe('media')
        ->and($item['headline'])->toBeNull()
        ->and($item['media'][0])->toBe([
            'role' => 'cover',
            'url' => 'https://cdn.bsky.app/img/feed_fullsize/plain/3mu3jzayuys2k@jpeg',
            'ref' => 'bluesky:3mu3jzayuys2k:0',
            'alt' => 'Alt 3mu3jzayuys2k',
        ])
        ->and($item['media'][1]['role'])->toBe('gallery')
        ->and($item['media'][1]['ref'])->toBe('bluesky:3mu3jzayuys2k:1')
        ->and($item['media'][1])->not->toHaveKey('alt')
        ->and($item['facets']['f_link']['url'])->toBe('https://bsky.app/profile/bsky.app/post/3mu3jzayuys2k')
        ->and($item['facets']['f_text']['body'])->toBe('Post 3mu3jzayuys2k')
        ->and($item['facets']['f_published']['published_from'])->toBe('2026-08-27T19:03:40.083Z');
});

it('projects a video post as its poster frame only — HLS ships no mirrorable video bytes', function () {
    $item = (new BlueskyMediaProjector)->project(new RecordView(bskyLaneRow('3m2d3syyfrt2v', '2025-10-03T22:15:51.915Z', [
        'isVideo' => true,
        'images' => null,
        'video' => [
            'playlist' => 'https://video.bsky.app/watch/x/playlist.m3u8',
            'thumbnail' => 'https://video.bsky.app/watch/x/thumbnail.jpg',
            'alt' => 'A highlight reel',
        ],
    ])));

    expect($item['media'])->toBe([[
        'role' => 'cover',
        'url' => 'https://video.bsky.app/watch/x/thumbnail.jpg',
        'ref' => 'bluesky:3m2d3syyfrt2v:cover',
        'alt' => 'A highlight reel',
    ]])
        ->and($item['facets'])->not->toHaveKey('f_playable')
        ->and($item['facets']['f_published']['published_from'])->toBe('2025-10-03T22:15:51.915Z');
});

it('refuses to project a frameless post', function () {
    expect((new BlueskyMediaProjector)->project(new RecordView([
        'id' => '3l6oveex3ii2l',
        'text' => 'Text only',
        'createdAt' => '2024-10-17T07:06:51.491Z',
    ])))->toBeNull();
});
