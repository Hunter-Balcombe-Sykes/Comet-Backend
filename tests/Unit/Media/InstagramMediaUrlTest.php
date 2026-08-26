<?php

use App\Services\Media\InstagramMediaUrl;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Plan 3 R3 — expiry parsing and the two refresh legs, with the live-verified
// shapes: oe= hex epochs, the embed page's double-escaped video_url, the
// single-post actor's videoUrl/childPosts.

it('parses oe= expiries and judges them with skew', function () {
    $urls = app(InstagramMediaUrl::class);

    // 0x6A88938E = 2026-08-21T18:06:06Z — the live stuck asset's stamp.
    expect($urls->expiresAt('https://scontent.cdninstagram.com/o1/v/x.mp4?oe=6A88938E'))->toBe(0x6A88938E)
        ->and($urls->isExpired('https://scontent.cdninstagram.com/o1/v/x.mp4?oe=6A88938E'))->toBeTrue()
        // Far-future stamp: not expired.
        ->and($urls->isExpired('https://scontent.cdninstagram.com/x.jpg?oe=7A000000'))->toBeFalse()
        // No oe= → never "provably expired".
        ->and($urls->isExpired('https://scontent.cdninstagram.com/x.jpg'))->toBeFalse();
});

it('refreshes a video from the embed page, tolerating the double-escaped payload', function () {
    Http::fake([
        'www.instagram.com/p/Db5SLNCIZCP/embed/*' => Http::response(
            '<script>{"video_url\":\"https:\\\\/\\\\/scontent.cdninstagram.com\\\\/o1\\\\/v\\\\/fresh.mp4?oe=7A000000\"}</script>'
        ),
    ]);

    expect(app(InstagramMediaUrl::class)->freshUrl('Db5SLNCIZCP', 'video'))
        ->toBe('https://scontent.cdninstagram.com/o1/v/fresh.mp4?oe=7A000000');
});

it('falls back to the single-post actor when the embed page yields nothing, matching carousel children by position', function () {
    config()->set('services.apify.token', 'test-token');
    Http::fake([
        'www.instagram.com/*' => Http::response('<html>login</html>'),
        'api.apify.com/*' => Http::response([[
            'videoUrl' => null,
            'displayUrl' => 'https://scontent.cdninstagram.com/first.jpg?oe=7A000000',
            'childPosts' => [
                ['displayUrl' => 'https://scontent.cdninstagram.com/child0.jpg?oe=7A000000'],
                ['displayUrl' => 'https://scontent.cdninstagram.com/child1.jpg?oe=7A000000'],
            ],
        ]]),
    ]);

    expect(app(InstagramMediaUrl::class)->freshUrl('Db5SLNCIZCP', 'image', 1))
        ->toBe('https://scontent.cdninstagram.com/child1.jpg?oe=7A000000');
});

it('refuses refresh targets off Instagram CDNs and malformed shortcodes', function () {
    Http::fake([
        'www.instagram.com/*' => Http::response('<script>{"video_url\":\"https:\\\\/\\\\/evil.example\\\\/x.mp4\"}</script>'),
    ]);
    $urls = app(InstagramMediaUrl::class);

    expect($urls->freshUrl('Db5SLNCIZCP', 'video'))->toBeNull()
        ->and($urls->freshUrl('../../etc', 'video'))->toBeNull();
});
