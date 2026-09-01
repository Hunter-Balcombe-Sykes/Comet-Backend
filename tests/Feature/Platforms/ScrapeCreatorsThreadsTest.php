<?php

use App\Services\Platforms\ScrapeCreators\ThreadsPostsNormalizer;
use App\Services\Platforms\ScrapeCreators\ThreadsProfileNormalizer;

// Item 10a (2026-09-01): the Threads adapter's contract, pinned against
// RECORDED live payloads (zuck profile, mosseri posts — slimmed from the
// 2026-09-01 captures; the notfound husk is verbatim). Threads has NO Apify
// fallback lane — the vendor is the only source — so the lossy contract is
// the whole safety story: any husk or shape drift must normalize to null and
// the caller simply has no Threads data, never a fabricated empty account.
//
// The other pinned property is the mirror mark: every asset entry must carry
// a ref in the owned `threads:` namespace, because every URL in this lane is
// IG-signed and expiring (never hot-link — Item 10a's stated constraint).

function scThreadsProfileFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-threads-profile.json')),
        true
    );
}

function scThreadsNotFoundFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-threads-profile-notfound.json')),
        true
    );
}

function scThreadsPostsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-threads-user-posts.json')),
        true
    );
}

// ── Profile: the identity card ──────────────────────────────────────────────

it('normalizes the recorded profile into the identity card contract', function () {
    $card = app(ThreadsProfileNormalizer::class)->normalize(scThreadsProfileFixture());

    expect($card)->not->toBeNull()
        ->and($card['id'])->toBe('63055343223')
        ->and($card['username'])->toBe('zuck')
        ->and($card['full_name'])->toBe('Mark Zuckerberg')
        ->and($card['biography'])->toBe('Mostly superintelligence and MMA takes')
        ->and($card['follower_count'])->toBe(5720502)
        ->and($card['followersCount'])->toBe(5720502)
        ->and($card['is_verified'])->toBeTrue()
        ->and($card['is_private'])->toBeFalse()
        ->and($card['url'])->toBe('https://www.threads.com/@zuck')
        ->and($card['scrapedVia'])->toBe('scrapecreators');

    // The largest hd version wins over the 150px profile_pic_url thumbnail.
    expect($card['profile_pic_url'])->toContain('s640x640');

    // Synthesized, never spread: no credit accounting or vendor noise may
    // ever reach a persisted connection payload.
    expect($card)->not->toHaveKeys(['success', 'credits_charged', 'credits_remaining', 'text_app_biography', 'profile_tags']);
});

it('reads the recorded notfound husk as a vendor miss, never as an empty profile', function () {
    // The live husk answers success:true + error:not_found and no username —
    // shape is the gate, exactly the Item 8 NotFound rule.
    expect(app(ThreadsProfileNormalizer::class)->normalize(scThreadsNotFoundFixture()))->toBeNull()
        ->and(app(ThreadsProfileNormalizer::class)->normalize([]))->toBeNull()
        ->and(app(ThreadsProfileNormalizer::class)->normalize(['success' => true, 'username' => '']))->toBeNull()
        ->and(app(ThreadsProfileNormalizer::class)->normalize(['username' => ['not' => 'a-string']]))->toBeNull();
});

// ── Posts: the media-feed rows ──────────────────────────────────────────────

it('normalizes the recorded posts into feed rows with owned threads: refs on every asset', function () {
    $rows = app(ThreadsPostsNormalizer::class)->rows(scThreadsPostsFixture());

    expect($rows)->toHaveCount(4);

    // Single-image post: one cover frame, position-stable ref.
    $image = collect($rows)->firstWhere('id', '3962887631160101435');
    expect($image['code'])->toBe('Db_AU3kFko7')
        ->and($image['taken_at'])->toBe('2026-08-13T14:57:20Z')
        ->and($image['url'])->toBe('https://www.threads.com/@mosseri/post/Db_AU3kFko7')
        ->and($image['is_video'])->toBeFalse()
        ->and($image['like_count'])->toBe(5367)
        ->and($image['reply_count'])->toBe(3764)
        ->and($image['media'])->toHaveCount(1)
        ->and($image['media'][0]['role'])->toBe('cover')
        ->and($image['media'][0]['ref'])->toBe('threads:3962887631160101435:0')
        ->and($image['media'][0]['url'])->toStartWith('https://');

    // Video post: poster cover + the mp4 under its own :video ref.
    $video = collect($rows)->firstWhere('id', '3941113021354514673');
    expect($video['is_video'])->toBeTrue()
        ->and($video['taken_at'])->toBe('2026-07-14T13:55:07Z')
        ->and(array_column($video['media'], 'role'))->toBe(['cover', 'video'])
        ->and($video['media'][0]['ref'])->toBe('threads:3941113021354514673:0')
        ->and($video['media'][1]['ref'])->toBe('threads:3941113021354514673:video')
        ->and($video['media'][1]['url'])->toContain('.mp4');

    // Text-only post: the row survives with media:[] — the projector, not
    // this class, decides whether a textual thread enters the media pool.
    $text = collect($rows)->firstWhere('id', '3937557373968119426');
    expect($text['media'])->toBe([])
        ->and($text['is_video'])->toBeFalse()
        ->and($text['caption'])->toContain('Excited about this launch');

    // Every asset on every row is marked mirror-required via the owned
    // `threads:` ref namespace — the never-hot-link contract, in the exact
    // vocabulary MediaMirror::isOwnedEntry() reads.
    foreach ($rows as $row) {
        foreach ($row['media'] as $entry) {
            expect($entry['ref'])->toStartWith('threads:')
                ->and($entry['url'])->toStartWith('https://');
        }
    }
});

it('maps a mixed carousel as ONE row of poster frames — child videos never become video entries', function () {
    $rows = app(ThreadsPostsNormalizer::class)->rows(scThreadsPostsFixture());

    // The recorded carousel carries 3 children, two of which have their own
    // video_versions (the mixed shape the 2026-09-01 capture proved exists).
    // Instagram's sidecar precedent holds: children contribute poster frames
    // in order, and only a post's own top-level mp4 may mint a video ref.
    $carousel = collect($rows)->firstWhere('id', '3907102092363893630');
    expect($carousel['is_video'])->toBeFalse()
        ->and($carousel['taken_at'])->toBe('2026-05-28T15:41:26Z')
        ->and($carousel['media'])->toHaveCount(3)
        ->and(array_column($carousel['media'], 'role'))->toBe(['cover', 'gallery', 'gallery'])
        ->and(array_column($carousel['media'], 'ref'))->toBe([
            'threads:3907102092363893630:0',
            'threads:3907102092363893630:1',
            'threads:3907102092363893630:2',
        ]);
});

it('reads a posts husk as a vendor miss, never as an empty account', function () {
    $normalizer = app(ThreadsPostsNormalizer::class);

    expect($normalizer->rows(['success' => true, 'credits_charged' => 1, 'posts' => []]))->toBeNull()
        ->and($normalizer->rows(['success' => true, 'error' => 'not_found']))->toBeNull()
        ->and($normalizer->rows(['posts' => ['garbage', 42, ['pk' => 'not-digits']]]))->toBeNull();
});

it('drops replies and shape-broken posts without taking the page with them', function () {
    $fixture = scThreadsPostsFixture();
    $fixture['posts'][] = [
        // A reply riding the profile feed: conversation, not surface.
        'pk' => '999900001', 'code' => 'Xreply', 'taken_at' => 1786000000,
        'text_post_app_info' => ['is_reply' => true],
    ];
    $fixture['posts'][] = ['caption' => ['text' => 'no pk at all']];

    $rows = app(ThreadsPostsNormalizer::class)->rows($fixture);

    expect($rows)->toHaveCount(4)
        ->and(array_column($rows, 'id'))->not->toContain('999900001');
});
