<?php

use App\Ingest\Connectors\ThreadsConnector;
use App\Ingest\Landing\DocHasher;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\RecordView;
use App\Ingest\Projection\ThreadsMediaProjector;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\ThreadsVendorDriver;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Services\Media\MediaMirror;
use Illuminate\Support\Facades\Http;

// Item 10a (2026-09-01): the Threads ingest lane end-to-end — driver →
// connector → projector — on the RECORDED /v1/threads/user/posts capture
// (mosseri, slimmed; see ScrapeCreatorsThreadsTest for the normalizer-level
// contracts this lane rides on). The Instagram frame with the Pinterest
// substitution:
//
//  1. When the vendor answers usably, the connector lands the feed EXACTLY as
//     the driver's rows describe it, newest first — a pinned-but-old post on
//     the wire cannot claim the top.
//  2. When the vendor answers any other way, the run refuses or noAnswers —
//     threads has NO fallback lane, so a miss must stay a miss, never an
//     empty account.
//  3. Every frame that reaches projection carries its owned `threads:` ref,
//     and MediaMirror recognises the namespace — the never-hot-link half of
//     Item 10a (all URLs in this lane are IG-signed and expiring).

function scThreadsLaneFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-threads-user-posts.json')),
        true
    );
}

function scThreadsLaneCtx(array $input): BilledEffectContext
{
    return new BilledEffectContext('vendor', 'threads', $input, 'run-1', 'source-1', 'user-1');
}

function scThreadsLaneRow(string $id, array $extra = []): array
{
    return $extra + [
        'id' => $id,
        'code' => 'Db_AU3kFko7',
        'caption' => 'A caption.',
        'taken_at' => '2026-08-13T14:57:20Z',
        'url' => 'https://www.threads.com/@mosseri/post/Db_AU3kFko7',
        'is_video' => false,
        'like_count' => 5367,
        'reply_count' => 3764,
        'media' => [
            [
                'role' => 'cover',
                'url' => 'https://scontent-mia3-1.cdninstagram.com/v/t51.82787-15/frame.jpg?_nc_sid=a&oe=6A9C9D21',
                'ref' => "threads:{$id}:0",
            ],
        ],
    ];
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.threads', 50);
});

// ── (a) Driver: one billed call, budget mechanics, no-fallback refusals ──────

it('answers the recorded page as normalized feed rows with owned refs on every asset', function () {
    Http::fake([
        'api.scrapecreators.com/v1/threads/user/posts*' => Http::response(scThreadsLaneFixture()),
    ]);

    $result = app(ThreadsVendorDriver::class)->run(scThreadsLaneCtx(['username' => '@Mosseri']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toHaveCount(4);
    foreach ($result->data as $row) {
        foreach ($row['media'] as $entry) {
            expect($entry['ref'])->toStartWith('threads:');
        }
    }
    // One run = exactly one billed call, with the handle lowercased and
    // @-stripped before it reaches the wire.
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'handle=mosseri'));
});

it('refuses to run without a key — there is no fallback lane to fall through to', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(fn () => app(ThreadsVendorDriver::class)->run(scThreadsLaneCtx(['username' => 'mosseri'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('refuses before spending when the daily cap is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.threads', 0);
    Http::fake();

    expect(fn () => app(ThreadsVendorDriver::class)->run(scThreadsLaneCtx(['username' => 'mosseri'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('releases the claimed slot on a transport failure so a later run may retry', function () {
    config()->set('partna.limits.scrapecreators.sources.threads', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    $first = app(ThreadsVendorDriver::class)->run(scThreadsLaneCtx(['username' => 'mosseri']));
    expect($first->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    // The slot came back: with cap=1 an unreleased claim would refuse the
    // second run outright (EffectNotAttempted) — instead it claims again,
    // reaches the wire again, and folds the same transport miss.
    $second = app(ThreadsVendorDriver::class)->run(scThreadsLaneCtx(['username' => 'mosseri']));

    expect($second->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and($second->reason)->toContain('did not answer');
    Http::assertSentCount(2);
});

it('keeps the slot spent on a billed husk (the NotFound quirk bills with success:true)', function () {
    config()->set('partna.limits.scrapecreators.sources.threads', 1);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 1, 'posts' => []]),
    ]);

    $first = app(ThreadsVendorDriver::class)->run(scThreadsLaneCtx(['username' => 'ghost-handle']));
    expect($first->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    expect(fn () => app(ThreadsVendorDriver::class)->run(scThreadsLaneCtx(['username' => 'ghost-handle'])))
        ->toThrow(EffectNotAttempted::class);
});

it('answers noAnswer, never answered-empty, when the page is nothing but replies', function () {
    Http::fake([
        'api.scrapecreators.com/v1/threads/user/posts*' => Http::response(['success' => true, 'credits_charged' => 1, 'posts' => [
            ['pk' => '999900001', 'code' => 'Xreply', 'taken_at' => 1786000000, 'text_post_app_info' => ['is_reply' => true]],
        ]]),
    ]);

    $result = app(ThreadsVendorDriver::class)->run(scThreadsLaneCtx(['username' => 'replies-only']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

// ── (b) Connector: the feed lands like instagram's ──────────────────────────

function scThreadsLaneIo(array $effect): Io
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

function scThreadsLanePull(): Pull
{
    return new Pull(identifier: '@Mosseri', stream: ThreadsConnector::manifest()->stream('media'), config: []);
}

it('lands the feed newest-first with prefix coverage — a pinned-old post cannot claim the top', function () {
    // Wire order deliberately oldest-first (the pinned-post shape).
    $io = scThreadsLaneIo(['status' => 'ok', 'cached' => false, 'data' => [
        scThreadsLaneRow('3907102092363893630', ['taken_at' => '2026-05-28T15:41:26Z']),
        scThreadsLaneRow('3962887631160101435'),
        ['id' => 'not-digits', 'media' => []],
    ]]);

    $messages = iterator_to_array((new ThreadsConnector)->pull(scThreadsLanePull(), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = collect($messages)->first(fn ($m) => $m instanceof Covered);

    expect($io->calls)->toBe([['vendor', 'threads', ['username' => 'mosseri']]])
        ->and($records)->toHaveCount(2)
        ->and($records[0]->stream)->toBe('media')
        ->and($records[0]->key)->toBe('3962887631160101435')
        ->and($records[1]->key)->toBe('3907102092363893630')
        ->and($covered)->not->toBeNull()
        ->and($covered->coverage->toArray()['type'])->toBe('prefix')
        ->and($covered->coverage->toArray()['from'])->toBe('2026-05-28T15:41:26Z')
        ->and($covered->coverage->toArray()['count'])->toBe(2);
});

it('folds a refused effect into Unavailable and an empty answer into a Note', function () {
    $refused = iterator_to_array((new ThreadsConnector)->pull(scThreadsLanePull(), scThreadsLaneIo(['status' => 'refused'])), false);
    expect($refused)->toHaveCount(1)->and($refused[0])->toBeInstanceOf(Unavailable::class);

    $empty = iterator_to_array((new ThreadsConnector)->pull(scThreadsLanePull(), scThreadsLaneIo(['status' => 'ok', 'cached' => false, 'data' => []])), false);
    expect($empty)->toHaveCount(1)->and($empty[0])->toBeInstanceOf(Note::class);
});

it('rebuilds cached rows on its own contract — foreign refs drop, text-only rows survive with media:[]', function () {
    $io = scThreadsLaneIo(['status' => 'ok', 'cached' => true, 'data' => [
        // A stale cached blob smuggling a non-owned ref: the entry drops,
        // the row stays.
        scThreadsLaneRow('3962887631160101435', ['media' => [
            ['role' => 'cover', 'url' => 'https://i.pinimg.com/originals/x.jpg', 'ref' => 'pinterest:1:0'],
            ['role' => 'video', 'url' => 'https://scontent.cdninstagram.com/v/clip.mp4?oe=1', 'ref' => 'threads:3962887631160101435:video'],
        ]]),
        scThreadsLaneRow('3937557373968119426', ['media' => [], 'is_video' => false]),
    ]]);

    $records = array_values(array_filter(
        iterator_to_array((new ThreadsConnector)->pull(scThreadsLanePull(), $io), false),
        fn ($m) => $m instanceof Record,
    ));

    expect($records)->toHaveCount(2)
        ->and($records[0]->doc['media'])->toBe([
            ['role' => 'video', 'url' => 'https://scontent.cdninstagram.com/v/clip.mp4?oe=1', 'ref' => 'threads:3962887631160101435:video'],
        ])
        ->and($records[1]->doc['media'])->toBe([]);
});

// ── (c) Projector: posts enter the media pool like instagram's ──────────────

it('projects an image post to the media kind, passing the normalizer-minted frames through', function () {
    $item = (new ThreadsMediaProjector)->project(new RecordView(scThreadsLaneRow('3962887631160101435')));

    expect($item['kind'])->toBe('media')
        ->and($item['headline'])->toBeNull()
        ->and($item['media'])->toBe([[
            'role' => 'cover',
            'url' => 'https://scontent-mia3-1.cdninstagram.com/v/t51.82787-15/frame.jpg?_nc_sid=a&oe=6A9C9D21',
            'ref' => 'threads:3962887631160101435:0',
        ]])
        ->and($item['facets']['f_link']['url'])->toBe('https://www.threads.com/@mosseri/post/Db_AU3kFko7')
        ->and($item['facets']['f_text']['body'])->toBe('A caption.')
        ->and($item['facets']['f_published']['published_from'])->toBe('2026-08-13T14:57:20Z')
        ->and($item['facets'])->not->toHaveKey('f_playable');
});

it('carries a video post\'s mp4 as a playable video frame with the cover as poster', function () {
    $doc = scThreadsLaneRow('3941113021354514673', ['is_video' => true, 'media' => [
        ['role' => 'cover', 'url' => 'https://scontent.cdninstagram.com/v/poster.jpg?oe=1', 'ref' => 'threads:3941113021354514673:0'],
        ['role' => 'video', 'url' => 'https://scontent.cdninstagram.com/v/clip.mp4?oe=1', 'ref' => 'threads:3941113021354514673:video'],
    ]]);

    $item = (new ThreadsMediaProjector)->project(new RecordView($doc));

    expect(array_column($item['media'], 'role'))->toBe(['cover', 'video'])
        ->and($item['media'][1]['ref'])->toBe('threads:3941113021354514673:video')
        ->and($item['facets']['f_playable']['stream_url'])->toBe('https://scontent.cdninstagram.com/v/clip.mp4?oe=1');
});

it('declines a text-only thread — the media pool is imagery, and a frameless item cannot render', function () {
    expect((new ThreadsMediaProjector)->project(new RecordView(scThreadsLaneRow('3937557373968119426', ['media' => []]))))->toBeNull()
        ->and((new ThreadsMediaProjector)->project(new RecordView(['caption' => 'no id'])))->toBeNull();
});

it('marks every frame mirror-eligible via the owned threads: namespace', function () {
    // The MediaMirror::OWNED_REF_PREFIXES 'threads:' line is what turns the
    // never-hot-link doctrine into behaviour — without it every IG-signed
    // frame stays unmirrored and (on an expiring CDN) never renders.
    expect(MediaMirror::isOwnedEntry(['ref' => 'threads:3962887631160101435:0']))->toBeTrue()
        ->and(MediaMirror::isOwnedEntry(['ref' => 'google_business:abc:0']))->toBeFalse();
});

// ── (d) Manifest: the paid-lane contract and the signed-URL stance ──────────

it('declares the instagram cost contract — off the scheduler, eager on connect, weekly cadence', function () {
    $manifest = ThreadsConnector::manifest();

    expect($manifest->cost)->toBe(CostClass::Actor)
        ->and($manifest->eagerOnConnect)->toBeTrue()
        ->and($manifest->hosts)->toBe([])
        ->and($manifest->stream('media')->orderField)->toBe('taken_at');
});

it('hashes re-signed CDN URLs as unchanged content — only the signature query is volatile', function () {
    $spec = ThreadsConnector::manifest()->stream('media');
    $doc = scThreadsLaneRow('3962887631160101435');

    $resigned = $doc;
    $resigned['media'][0]['url'] = 'https://scontent-mia3-1.cdninstagram.com/v/t51.82787-15/frame.jpg?_nc_sid=ROTATED&oe=NEWSIG';

    $moved = $doc;
    $moved['media'][0]['url'] = 'https://scontent-mia3-1.cdninstagram.com/v/t51.82787-15/DIFFERENT.jpg?_nc_sid=a&oe=6A9C9D21';

    expect(DocHasher::hash($resigned, $spec->volatile))->toBe(DocHasher::hash($doc, $spec->volatile))
        ->and(DocHasher::hash($moved, $spec->volatile))->not->toBe(DocHasher::hash($doc, $spec->volatile));
});
