<?php

use App\Ingest\Connectors\TwitchConnector;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\RecordView;
use App\Ingest\Projection\TwitchVideoProjector;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\TwitchVendorDriver;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\Strategies\Connect\TwitchConnect;
use App\Services\Platforms\TwitchScraper;
use Illuminate\Support\Facades\Http;

// Item 10a (2026-09-01), the wiring half: link-only Twitch becomes a synced
// watch-pool source. The adapter suite (ScrapeCreatorsTwitchTest) already
// pins the two normalizers and TwitchScraper's budget mechanics against the
// recorded 2026-09-01 payloads; THIS suite pins the lane built on top —
// TwitchConnect puts identity on the connection payload, TwitchVendorDriver
// answers VOD rows under the Item 8 budget contract, TwitchConnector lands
// them as the `watch` stream, and TwitchVideoProjector cards them with
// provider 'twitch'. Same two properties as every ScrapeCreators lane suite:
// the contract is exact, and every vendor outcome short of usable shape
// reads as a miss, never as an empty channel.

function scTwitchLaneFixture(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/fixtures/recorded/scrapecreators-{$name}.json")),
        true
    );
}

function scTwitchLaneCtx(array $input): BilledEffectContext
{
    return new BilledEffectContext('vendor', 'twitch', $input, 'run-1', 'source-1', 'user-1');
}

function scTwitchLaneIo(array $effect): Io
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

function scTwitchLanePull(array $config = []): Pull
{
    return new Pull(identifier: '@Jynxzi', stream: TwitchConnector::manifest()->stream('watch'), config: $config);
}

function scTwitchLaneRow(string $id, string $published, array $extra = []): array
{
    return $extra + [
        'id' => $id,
        'title' => 'A recorded stream',
        'url' => 'https://www.twitch.tv/videos/'.$id,
        'published' => $published,
        'thumbnail' => 'https://static-cdn.jtvnw.net/cf_vods/x/thumb/thumb0-320x180.jpg',
        'duration' => 42552,
        'views' => 2985639,
        'game' => 'Just Chatting',
    ];
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.twitch', 100);
});

// ── (a) Connect: identity onto the payload, degrading to link-only ──────────

it('stores the channel identity on the connect payload when the vendor answers', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTwitchLaneFixture('twitch-profile'))]);

    $result = app(TwitchConnect::class)->resolve('https://twitch.tv/Pokimane');

    expect($result->failed())->toBeFalse()
        ->and($result->accountKey)->toBe('pokimane')
        ->and($result->selection['username'])->toBe('pokimane')
        ->and($result->selection['url'])->toBe('https://www.twitch.tv/pokimane')
        ->and($result->selection['name'])->toBe('pokimane')
        ->and($result->selection['thumbnail'])->toContain('profile_image-150x150')
        ->and($result->selection['description'])->toContain('i stream sometimes')
        ->and($result->selection['followers'])->toBe(9450698)
        ->and($result->selection['isPartner'])->toBeTrue()
        ->and($result->selection['isLive'])->toBeFalse()
        // The detection layer's input rides the payload as full URLs.
        ->and($result->selection['socialLinks'])->toHaveCount(5)
        ->and($result->selection['socialLinks']['instagram'])->toBe('https://www.instagram.com/imane');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/twitch/profile')
        && $request['handle'] === 'pokimane');
});

it('stamps the live block with its check time — a snapshot, not a poller', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTwitchLaneFixture('twitch-profile-live'))]);

    $selection = app(TwitchConnect::class)->resolve('jynxzi')->selection;

    expect($selection['isLive'])->toBeTrue()
        ->and($selection['liveViewers'])->toBe(38707)
        ->and($selection['liveGame'])->toBe('Just Chatting')
        ->and($selection['liveStartedAt'])->toBe('2026-08-31T22:21:33Z')
        ->and($selection['liveCheckedAt'])->not->toBeNull()
        ->and($selection['socialLinks'])->toBe([]);
});

it('degrades every vendor miss to the exact link-only payload — the connect is never gated on the vendor', function () {
    // Transport miss.
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);
    $result = app(TwitchConnect::class)->resolve('@Pokimane');

    expect($result->failed())->toBeFalse()
        ->and($result->selection)->toBe(['username' => 'pokimane', 'url' => 'https://www.twitch.tv/pokimane'])
        ->and($result->accountKey)->toBe('pokimane');
});

it('spends nothing on the profile when the twitch budget is exhausted, and still connects', function () {
    config()->set('partna.limits.scrapecreators.sources.twitch', 0);
    Http::fake();

    $result = app(TwitchConnect::class)->resolve('pokimane');

    expect($result->failed())->toBeFalse()
        ->and($result->selection)->toBe(['username' => 'pokimane', 'url' => 'https://www.twitch.tv/pokimane']);
    Http::assertNothingSent();
});

it('refuses invalid input with the parse-stage 422, before any vendor call', function () {
    Http::fake();

    expect(app(TwitchConnect::class)->resolve('https://twitch.tv/')->failed())->toBeTrue()
        ->and(app(TwitchConnect::class)->resolve('no')->failed())->toBeTrue()
        ->and(app(TwitchConnect::class)->resolve('directory')->failed())->toBeTrue();
    Http::assertNothingSent();
});

// ── (b) Driver: VOD rows, budget mechanics, no-fallback refusals ────────────

it('answers the recorded VOD page as normalized watch rows', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTwitchLaneFixture('twitch-user-videos'))]);

    $result = app(TwitchVendorDriver::class)->run(scTwitchLaneCtx(['login' => '@Jynxzi']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toHaveCount(5)
        ->and(array_keys($result->data[1]))->toBe(['id', 'title', 'url', 'published', 'thumbnail', 'duration', 'views', 'game'])
        ->and($result->data[1]['id'])->toBe('2860751804');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/twitch/user/videos')
        && $request['handle'] === 'jynxzi'
        && $request['sort_by'] === 'TIME');
});

it('refuses to run without a key — there is no fallback lane to fall through to', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(fn () => app(TwitchVendorDriver::class)->run(scTwitchLaneCtx(['login' => 'jynxzi'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('refuses before spending when the daily cap is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.twitch', 0);
    Http::fake();

    expect(fn () => app(TwitchVendorDriver::class)->run(scTwitchLaneCtx(['login' => 'jynxzi'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('releases the claimed slot on a transport failure so a later run may retry', function () {
    config()->set('partna.limits.scrapecreators.sources.twitch', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    $first = app(TwitchVendorDriver::class)->run(scTwitchLaneCtx(['login' => 'jynxzi']));
    expect($first->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    // The slot came back: with cap=1 an unreleased claim would refuse the
    // second run outright (EffectNotAttempted) — instead it claims again,
    // reaches the wire again, and folds the same transport miss.
    $second = app(TwitchVendorDriver::class)->run(scTwitchLaneCtx(['login' => 'jynxzi']));

    expect($second->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and($second->reason)->toContain('did not answer');
    Http::assertSentCount(2);
});

it('keeps the slot spent on a billed husk (the NotFound quirk bills with success:true)', function () {
    config()->set('partna.limits.scrapecreators.sources.twitch', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response([
        'success' => true, 'credits_remaining' => 9999, 'credits_charged' => 1,
        'videos' => [], 'hasNextPage' => false, 'cursor' => null,
    ])]);

    $first = app(TwitchVendorDriver::class)->run(scTwitchLaneCtx(['login' => 'ghost_handle']));
    expect($first->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    expect(fn () => app(TwitchVendorDriver::class)->run(scTwitchLaneCtx(['login' => 'ghost_handle'])))
        ->toThrow(EffectNotAttempted::class);
});

it('settles a malformed login as noAnswer before spending anything', function () {
    config()->set('partna.limits.scrapecreators.sources.twitch', 1);
    Http::fake();

    $result = app(TwitchVendorDriver::class)->run(scTwitchLaneCtx(['login' => 'twitch.tv/x']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    Http::assertNothingSent();
    // No claim was burned on the refusal.
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('twitch'))->toBeTrue();
});

// ── (c) Connector: VODs enter the watch pool newest-first ───────────────────

it('lands VODs newest-first with prefix coverage down to the oldest seen', function () {
    // Vendor order deliberately scrambled: ordering is the connector's
    // contract, not an observation about the wire.
    $io = scTwitchLaneIo(['status' => 'ok', 'cached' => false, 'data' => [
        scTwitchLaneRow('222', '2026-08-28T10:00:00Z'),
        scTwitchLaneRow('333', '2026-08-31T10:00:00Z', ['thumbnail' => null, 'game' => null]),
        scTwitchLaneRow('111', '2026-08-30T10:00:00Z'),
        ['id' => 'not-numeric', 'title' => 'Junk', 'url' => 'https://www.twitch.tv/videos/x'],
    ]]);

    $messages = iterator_to_array((new TwitchConnector)->pull(scTwitchLanePull(), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = collect($messages)->first(fn ($m) => $m instanceof Covered);

    expect($io->calls)->toBe([['vendor', 'twitch', ['login' => 'jynxzi']]])
        ->and($records)->toHaveCount(3)
        ->and($records[0]->stream)->toBe('watch')
        ->and(array_map(fn ($r) => $r->key, $records))->toBe(['333', '111', '222'])
        // Normalizer-nulled fields (processing thumbnail, missing game) are
        // dropped keys, never landed nulls.
        ->and(array_keys($records[0]->doc))->toBe(['id', 'title', 'url', 'published', 'duration', 'views'])
        ->and($covered)->not->toBeNull()
        ->and($covered->coverage->toArray())->toBe(['type' => 'prefix', 'from' => '2026-08-28T10:00:00Z', 'count' => 3]);
});

it('folds a refused effect into Unavailable and an empty answer into a Note', function () {
    $refused = iterator_to_array((new TwitchConnector)->pull(scTwitchLanePull(), scTwitchLaneIo(['status' => 'refused'])), false);
    expect($refused)->toHaveCount(1)->and($refused[0])->toBeInstanceOf(Unavailable::class);

    $empty = iterator_to_array((new TwitchConnector)->pull(scTwitchLanePull(), scTwitchLaneIo(['status' => 'ok', 'cached' => false, 'data' => []])), false);
    expect($empty)->toHaveCount(1)->and($empty[0])->toBeInstanceOf(Note::class);
});

it('honours the pull scope limit inside the watch window', function () {
    $io = scTwitchLaneIo(['status' => 'ok', 'cached' => false, 'data' => [
        scTwitchLaneRow('111', '2026-08-30T10:00:00Z'),
        scTwitchLaneRow('222', '2026-08-28T10:00:00Z'),
    ]]);

    $messages = iterator_to_array((new TwitchConnector)->pull(scTwitchLanePull(['scope' => 'latest_n', 'scope_n' => 1]), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($records)->toHaveCount(1)->and($records[0]->key)->toBe('111');
});

it('declares the lane off the scheduler with connect as the one trigger, and only views volatile', function () {
    $manifest = TwitchConnector::manifest();
    $watch = $manifest->stream('watch');

    expect($manifest->cost)->toBe(CostClass::Actor)
        ->and($manifest->eagerOnConnect)->toBeTrue()
        ->and($manifest->hosts)->toBe([])
        ->and($watch->requires)->toBe(['id', 'title', 'url'])
        // views grows on every row (recorded quirk) and must not churn the
        // doc hash; duration is projector-read, so it must NOT be here
        // (ingest:volatility-audit fails volatile+read paths).
        ->and($watch->volatile)->toBe(['views'])
        ->and($watch->orderField)->toBe('published')
        ->and($watch->mayDelete())->toBeTrue();
});

// ── (d) Projector: the watch card, provider twitch ──────────────────────────

it('projects a VOD to the video kind with the twitch embed provider', function () {
    $item = (new TwitchVideoProjector)->project(new RecordView(scTwitchLaneRow('2860751804', '2026-08-30T16:04:01Z')));

    expect($item['kind'])->toBe('video')
        ->and($item['headline'])->toBe('A recorded stream')
        ->and($item['facets'])->toBe([
            'f_link' => ['url' => 'https://www.twitch.tv/videos/2860751804'],
            'f_published' => ['published_from' => '2026-08-30T16:04:01Z'],
            'f_duration' => ['seconds' => 42552],
            'f_embed' => ['provider' => 'twitch', 'embed_key' => '2860751804'],
        ])
        // Unsigned stable CDN URL: hot-linked like YouTube, no owned ref.
        ->and($item['media'])->toBe([
            ['role' => 'cover', 'url' => 'https://static-cdn.jtvnw.net/cf_vods/x/thumb/thumb0-320x180.jpg'],
        ]);
});

it('refuses to project a title-less or link-less row, and cards a coverless one without media', function () {
    expect((new TwitchVideoProjector)->project(new RecordView(['id' => '111', 'url' => 'https://www.twitch.tv/videos/111'])))->toBeNull()
        ->and((new TwitchVideoProjector)->project(new RecordView(['id' => '111', 'title' => 'Live right now'])))->toBeNull();

    $coverless = (new TwitchVideoProjector)->project(new RecordView([
        'id' => '111', 'title' => 'Live right now', 'url' => 'https://www.twitch.tv/videos/111',
    ]));
    expect($coverless['media'])->toBe([]);
});
