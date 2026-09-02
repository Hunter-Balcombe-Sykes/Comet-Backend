<?php

use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\SocialActorDriver;
use App\Services\Platforms\ScrapeCreators\FacebookPostsNormalizer;
use App\Services\Platforms\ScrapeCreators\TiktokVideosNormalizer;
use Illuminate\Support\Facades\Http;

// Item 8 G3 (2026-09-01): the vendor lane fronting the TikTok/Facebook social
// actors, pinned against RECORDED live payloads (slimmed /v3/tiktok/profile/
// videos + /v1/facebook/profile/posts answers from the 2026-09-01 trial). Two
// properties, the Instagram test's exact frame:
//
//  1. When the vendor answers usably, the connectors read rows EXACTLY as
//     they read an actor dataset — same keys, same mapVideo/mapPost outcomes.
//  2. When the vendor answers any other way, the Apify path runs completely
//     unchanged — the lane can only ever make things faster, never absent.

function scTtVideosFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-tiktok-profile-videos.json')),
        true
    );
}

function scFbPostsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-facebook-profile-posts.json')),
        true
    );
}

function scFeedCtx(string $name, array $input): BilledEffectContext
{
    return new BilledEffectContext('actor', $name, $input, 'run-1', 'source-1', 'user-1');
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('services.apify.token', 'apify-test-token');
    config()->set('partna.limits.apify.global_daily_cap', 100);
    config()->set('partna.limits.apify.actors.tiktok', 10);
    config()->set('partna.limits.apify.actors.facebook', 10);
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.tiktok', 50);
    config()->set('partna.limits.scrapecreators.sources.facebook', 50);
});

// ── (a) Normalizer contracts against the recorded fixtures ──────────────────

it('normalizes recorded tiktok videos into the exact actor row shape the connector reads', function () {
    $rows = app(TiktokVideosNormalizer::class)->rows(scTtVideosFixture(), 'ryanfitzbarber');

    expect($rows)->toHaveCount(4);

    $pinned = collect($rows)->firstWhere('id', '7338015612460977416');
    expect($pinned['text'])->toContain('Wednesday work flow')
        ->and($pinned['createTimeISO'])->toBe('2024-02-21T11:28:24.000Z')
        ->and($pinned['webVideoUrl'])->toBe('https://www.tiktok.com/@ryanfitzbarber/video/7338015612460977416')
        // The trial-verified ms→s quirk: 31367ms must land as 31 seconds.
        ->and($pinned['videoMeta']['duration'])->toBe(31)
        ->and($pinned['videoMeta']['coverUrl'])->toStartWith('https://')
        ->and($pinned['isPinned'])->toBeTrue();
});

it('reads a tiktok husk as a vendor miss, never as an empty account', function () {
    expect(app(TiktokVideosNormalizer::class)->rows(['success' => true, 'credits_charged' => 1, 'aweme_list' => []], 'x'))->toBeNull()
        ->and(app(TiktokVideosNormalizer::class)->rows(['success' => false, 'message' => 'nope'], 'x'))->toBeNull();
});

it('normalizes recorded facebook posts into the exact actor row shape the connector reads', function () {
    $rows = app(FacebookPostsNormalizer::class)->rows(scFbPostsFixture());

    expect($rows)->toHaveCount(3);

    // The reel: its imagery is a Video thumbnail, exactly the actor's shape.
    $reel = collect($rows)->firstWhere('postId', '1714402917354894');
    expect($reel['isVideo'])->toBeTrue()
        ->and($reel['time'])->toBe('2026-08-31T10:00:29.000Z')
        ->and($reel['url'])->toBe('https://www.facebook.com/reel/1058925316844002/')
        ->and($reel['media'][0]['thumbnail'])->toStartWith('https://');

    // The multi-photo post: one row, gallery rows in order (the carousel rule).
    $gallery = collect($rows)->firstWhere('postId', '1714008014061051');
    expect($gallery['isVideo'])->toBeFalse()
        ->and($gallery['media'])->toHaveCount(4)
        ->and(data_get($gallery, 'media.0.photo_image.uri'))->toStartWith('https://');

    // The single-image post (`image` only, no `images[]`) still carries media.
    $single = collect($rows)->firstWhere('postId', '1706775301450989');
    expect($single['media'])->toHaveCount(1)
        ->and(data_get($single, 'media.0.photo_image.uri'))->toStartWith('https://');
});

it('reads a facebook husk as a vendor miss, never as an empty page', function () {
    expect(app(FacebookPostsNormalizer::class)->rows(['success' => true, 'posts' => []]))->toBeNull();
});

// ── (b) Vendor-first happy paths ────────────────────────────────────────────

it('serves tiktok videos from the vendor, sorted by create time, without ever calling the actor', function () {
    config()->set('partna.social_actors.tiktok.results_limit', 4);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scTtVideosFixture()),
        'api.apify.com/*' => Http::response(['should' => 'never-be-reached'], 500),
    ]);

    $result = app(SocialActorDriver::class)->run(scFeedCtx('tiktok', ['username' => '@RyanFitzBarber']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        // The fixture lists the two pinned videos first (is_top=1, the live
        // feed order) — the newest video must still lead after the re-sort.
        ->and(array_column($result->data, 'id'))->toBe([
            '7563587930095275272', '7441844723272256786', '7338015612460977416', '7305528815629962504',
        ]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
    Http::assertSentCount(1);
});

it('pages facebook posts by cursor, stops when the cursor ends, and dedupes across pages', function () {
    $secondPage = [
        'success' => true, 'credits_charged' => 1,
        'posts' => [
            // First row repeats a page-1 post — the dedupe must fold it.
            ['id' => '1706775301450989', 'creation_time' => '2026-08-23T09:30:03.000Z', 'image' => 'https://scontent.example/dupe.jpg'],
            ['id' => '1705612301567289', 'creation_time' => '2026-08-20T09:00:00.000Z', 'url' => 'https://www.facebook.com/x/posts/1', 'image' => 'https://scontent.example/a.jpg'],
            ['id' => '1704901391638380', 'creation_time' => '2026-08-18T09:00:00.000Z', 'url' => 'https://www.facebook.com/x/posts/2', 'image' => 'https://scontent.example/b.jpg'],
        ],
        // No further cursor — the loop must stop at two pages, not the cap.
    ];
    Http::fake(function ($request) use ($secondPage) {
        if (! str_contains($request->url(), 'api.scrapecreators.com')) {
            return Http::response(['should' => 'never-be-reached'], 500);
        }

        return str_contains($request->url(), 'cursor=')
            ? Http::response($secondPage)
            : Http::response(scFbPostsFixture());
    });

    $result = app(SocialActorDriver::class)->run(scFeedCtx('facebook', ['page_url' => 'https://www.facebook.com/thefamishedwolf']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and(array_column($result->data, 'postId'))->toBe([
            '1714402917354894', '1714008014061051', '1706775301450989',
            '1705612301567289', '1704901391638380',
        ]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
    Http::assertSentCount(2);
});

// ── (c) Fall-through: the Apify path must run completely unchanged ──────────

it('falls through to the actor when the vendor transport fails', function () {
    Http::fake([
        'api.scrapecreators.com/*' => Http::response('upstream sad', 502),
        'api.apify.com/*' => Http::response([['id' => '71', 'text' => 'actor row']], 201),
    ]);

    $result = app(SocialActorDriver::class)->run(scFeedCtx('tiktok', ['username' => 'someone']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data[0]['text'])->toBe('actor row');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
});

it('falls through to the actor on a success-shaped tiktok husk (the NotFound quirk)', function () {
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 1, 'aweme_list' => []]),
        'api.apify.com/*' => Http::response([['id' => '71', 'text' => 'actor answered']], 201),
    ]);

    $result = app(SocialActorDriver::class)->run(scFeedCtx('tiktok', ['username' => 'someone']));

    expect($result->data[0]['text'] ?? null)->toBe('actor answered');
});

it('falls through to the actor on a success-shaped facebook husk', function () {
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 1, 'posts' => []]),
        'api.apify.com/*' => Http::response([['postId' => 'p1', 'text' => 'actor answered']], 201),
    ]);

    $result = app(SocialActorDriver::class)->run(scFeedCtx('facebook', ['page_url' => 'https://www.facebook.com/nasa']));

    expect($result->data[0]['text'] ?? null)->toBe('actor answered');
});

// ── (d) Vendor lane refusals: no key, no budget ─────────────────────────────

it('skips the vendor lane entirely when no key is configured', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake(['api.apify.com/*' => Http::response([['id' => '71']], 201)]);

    app(SocialActorDriver::class)->run(scFeedCtx('tiktok', ['username' => 'someone']));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
});

it('skips the vendor lane when its budget is exhausted, without touching the Apify budget', function () {
    config()->set('partna.limits.scrapecreators.sources.facebook', 0);
    Http::fake(['api.apify.com/*' => Http::response([['postId' => 'p1']], 201)]);

    app(SocialActorDriver::class)->run(scFeedCtx('facebook', ['page_url' => 'https://www.facebook.com/nasa']));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
});

it('tiktok cover prefers origin_cover over the (sometimes animated) cover (2026-09-02)', function () {
    $rows = (new TiktokVideosNormalizer)->rows(['aweme_list' => [['aweme_id' => '123456', 'desc' => 'x', 'create_time' => 1700000000, 'video' => ['cover' => ['url_list' => ['https://c/anim']], 'origin_cover' => ['url_list' => ['https://c/static.jpg']], 'duration' => 12000]]]], 'someone');
    expect($rows[0]['videoMeta']['coverUrl'])->toBe('https://c/static.jpg');
});
