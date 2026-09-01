<?php

use App\Services\Platforms\ScrapeCreators\BlueskyPostsNormalizer;
use App\Services\Platforms\ScrapeCreators\BlueskyProfileNormalizer;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use Illuminate\Support\Facades\Http;

// Item 10b (2026-09-01): the Bluesky vendor lane's contract, pinned against
// RECORDED live payloads (bsky.app profile + posts, espn.com posts for the
// own-author video shape, and the verbatim NotFound husk — all from the
// 2026-09-01 capture). Bluesky has no Apify lane behind it, so these
// normalizers ARE the platform contract: anything they let through is what
// the future connector wiring may persist, and anything malformed must read
// as a vendor miss, never as an empty account.

function scBskyProfileFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-bluesky-profile.json')),
        true
    );
}

function scBskyPostsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-bluesky-user-posts.json')),
        true
    );
}

function scBskyEspnPostsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-bluesky-user-posts-espn.json')),
        true
    );
}

const SC_BSKY_DID = 'did:plc:z72i7hdynmk6r22z27h6tvur';

// ── (a) Profile normalizer contract ─────────────────────────────────────────

it('normalizes the recorded profile into the identity card the socials lane consumes', function () {
    $profile = app(BlueskyProfileNormalizer::class)->normalize(scBskyProfileFixture());

    expect($profile)->not->toBeNull()
        ->and($profile['did'])->toBe(SC_BSKY_DID)
        ->and($profile['handle'])->toBe('bsky.app')
        ->and($profile['url'])->toBe('https://bsky.app/profile/bsky.app')
        ->and($profile['displayName'])->toBe('Bluesky')
        ->and($profile['description'])->toContain('official Bluesky account')
        ->and($profile['avatar'])->toStartWith('https://cdn.bsky.app/img/avatar/')
        ->and($profile['banner'])->toStartWith('https://cdn.bsky.app/img/banner/')
        ->and($profile['followersCount'])->toBe(34741208)
        ->and($profile['postsCount'])->toBe(822)
        ->and($profile['createdAt'])->toBe('2023-04-12T04:53:57.057Z');

    // Nothing billing-shaped survives normalization — credits_* must never
    // travel toward persistence.
    expect(json_encode($profile))->not->toContain('credits');
});

it('reads the recorded NotFound husk as a vendor miss, never as an empty profile', function () {
    // Bluesky's husk diverges from Spotify's: success:true AND zero credits
    // billed — the shape gate must not care either way.
    $husk = json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-bluesky-profile-notfound.json')),
        true
    );

    expect(app(BlueskyProfileNormalizer::class)->normalize($husk))->toBeNull();
});

it('reads every malformed profile shape as a vendor miss', function () {
    $normalizer = app(BlueskyProfileNormalizer::class);

    expect($normalizer->normalize(['success' => true, 'credits_charged' => 1]))->toBeNull()
        // A did that is not a did, or a handle-less body, is shape drift.
        ->and($normalizer->normalize(['did' => 'bsky.app', 'handle' => 'bsky.app']))->toBeNull()
        ->and($normalizer->normalize(['did' => SC_BSKY_DID, 'handle' => '']))->toBeNull()
        ->and($normalizer->normalize(['did' => SC_BSKY_DID]))->toBeNull();
});

// ── (b) Posts normalizer contract ───────────────────────────────────────────

it('normalizes the recorded feed to the account\'s own authored posts only', function () {
    $rows = app(BlueskyPostsNormalizer::class)->rows(scBskyPostsFixture(), SC_BSKY_DID);

    // The recorded page carries 6 items: 3 own top-level posts, 1 own REPLY
    // (record.reply), and 2 foreign-author reposts (a video and a link card,
    // with no reason marker — the vendor strips it). Only the 3 survive,
    // in feed order (pinned first — re-sorting is the caller's job).
    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'id'))->toBe(['3l6oveex3ii2l', '3mu3jzayuys2k', '3msqpuobiwk2t']);

    // The pinned text post: no media, real url, the 2024 createdAt that
    // proves the feed leads out of chronological order.
    expect($rows[0]['url'])->toBe('https://bsky.app/profile/bsky.app/post/3l6oveex3ii2l')
        ->and($rows[0]['uri'])->toBe('at://'.SC_BSKY_DID.'/app.bsky.feed.post/3l6oveex3ii2l')
        ->and($rows[0]['createdAt'])->toBe('2024-10-17T07:06:51.491Z')
        ->and($rows[0]['isVideo'])->toBeFalse()
        ->and($rows[0]['images'])->toBe([])
        ->and($rows[0]['video'])->toBeNull()
        ->and($rows[0]['text'])->toContain('open social network');

    // The images post: fullsize + thumb CDN URLs, alt text, real dimensions.
    $imagePost = collect($rows)->firstWhere('id', '3mu3jzayuys2k');
    expect($imagePost['images'])->toHaveCount(1)
        ->and($imagePost['images'][0]['url'])->toStartWith('https://cdn.bsky.app/img/feed_fullsize/')
        ->and($imagePost['images'][0]['thumb'])->toStartWith('https://cdn.bsky.app/img/feed_thumbnail/')
        ->and($imagePost['images'][0]['alt'])->toContain('Hide your posts')
        ->and($imagePost['images'][0]['width'])->toBe(3300)
        ->and($imagePost['images'][0]['height'])->toBe(1968);

    expect(json_encode($rows))->not->toContain('credits');
});

it('filters by author when given a handle instead of a did', function () {
    $rows = app(BlueskyPostsNormalizer::class)->rows(scBskyPostsFixture(), 'bsky.app');

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'id'))->toBe(['3l6oveex3ii2l', '3mu3jzayuys2k', '3msqpuobiwk2t']);
});

it('normalizes the recorded own-author video into the HLS playlist + poster shape', function () {
    $rows = app(BlueskyPostsNormalizer::class)->rows(scBskyEspnPostsFixture(), 'did:plc:x7d6j54pm22ufehkes6jo4jf');

    expect($rows)->toHaveCount(2);

    $video = collect($rows)->firstWhere('id', '3m2d3syyfrt2v');
    expect($video['isVideo'])->toBeTrue()
        ->and($video['url'])->toBe('https://bsky.app/profile/espn.com/post/3m2d3syyfrt2v')
        ->and($video['video']['playlist'])->toEndWith('/playlist.m3u8')
        ->and($video['video']['thumbnail'])->toEndWith('/thumbnail.jpg')
        // The recorded own-video embed carries NO aspectRatio or alt — the
        // vendor omits optional keys rather than nulling them, and the row
        // mirrors that (never invents dimensions).
        ->and($video['video'])->not->toHaveKeys(['width', 'height', 'alt'])
        ->and($video['images'])->toBe([]);

    $image = collect($rows)->firstWhere('id', '3m2ckw5f35a2g');
    expect($image['isVideo'])->toBeFalse()
        ->and($image['images'])->toHaveCount(1);
});

it('reads every husk feed shape as a vendor miss, never as an empty account', function () {
    $normalizer = app(BlueskyPostsNormalizer::class);

    // Success-shaped husks and shape drift.
    expect($normalizer->rows(['success' => true, 'credits_charged' => 1, 'feed' => []], SC_BSKY_DID))->toBeNull()
        ->and($normalizer->rows(['success' => true, 'error' => 'not_found'], SC_BSKY_DID))->toBeNull()
        ->and($normalizer->rows(['feed' => 'nope'], SC_BSKY_DID))->toBeNull()
        // A page of exclusively foreign/filtered posts is a miss too — the
        // lane may never be the reason an account reads as empty.
        ->and($normalizer->rows(scBskyPostsFixture(), 'did:plc:nobodyatall'))->toBeNull()
        ->and($normalizer->rows(scBskyPostsFixture(), 'nobody.example'))->toBeNull()
        ->and($normalizer->rows(scBskyPostsFixture(), ''))->toBeNull();
});

// ── (c) The client fetches the real endpoints ───────────────────────────────

it('fetches and normalizes the profile through the shared client', function () {
    config()->set('services.scrapecreators.key', 'test-key');
    Http::fake(['api.scrapecreators.com/*' => Http::response(scBskyProfileFixture())]);

    $body = app(ScrapeCreatorsClient::class)->get('/v1/bluesky/profile', ['handle' => 'bsky.app']);
    $profile = app(BlueskyProfileNormalizer::class)->normalize($body);

    expect($profile['handle'])->toBe('bsky.app');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/bluesky/profile')
        && $request['handle'] === 'bsky.app');
});

it('fetches and normalizes posts through the shared client', function () {
    config()->set('services.scrapecreators.key', 'test-key');
    Http::fake(['api.scrapecreators.com/*' => Http::response(scBskyPostsFixture())]);

    $body = app(ScrapeCreatorsClient::class)->get('/v1/bluesky/user/posts', ['user_id' => SC_BSKY_DID]);
    $rows = app(BlueskyPostsNormalizer::class)->rows($body, SC_BSKY_DID);

    expect($rows)->toHaveCount(3);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/bluesky/user/posts')
        && $request['user_id'] === SC_BSKY_DID);
});
