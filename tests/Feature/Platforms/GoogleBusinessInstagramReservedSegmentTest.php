<?php

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessAutoSync;
use Illuminate\Support\Facades\Bus;

// Retest 2026-08-20 (T9 follow-up): a business listing carried an
// instagram.com/reel/<shortcode> link, and seedInstagram()'s standalone regex
// extracted the reserved segment as username "reel" — Apify then scraped a
// 9M-follower stranger into an auto-connected account. The seed now delegates
// identity to the catalog projection (same G4-4 lesson as the facebook arm),
// which only yields an identifier for a real profile path.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function gbigrUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'business',
        'sector' => null,
        'status' => 'active',
    ]);
}

it('never seeds an Instagram connection from a reserved-segment URL on a listing', function (string $url) {
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbigrUser('gbigr-'.substr(md5($url), 0, 6));

    $findings = app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        ['socials' => ['instagram' => $url]],
        'Kings Domain',
    );

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->exists())->toBeFalse();
    Bus::assertNotDispatched(InstagramConnectJob::class);
    expect(collect($findings)->where('platform', 'instagram'))->toBeEmpty();
})->with([
    'reel' => 'https://www.instagram.com/reel/C91ylnhPT5A',
    'post' => 'https://www.instagram.com/p/C91ylnhPT5A/',
    'stories' => 'https://www.instagram.com/stories/someone/123456/',
    'explore' => 'https://www.instagram.com/explore/tags/barber/',
]);

it('still seeds a real profile URL, tracking params and all, via the projection identifier', function () {
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbigrUser('gbigr-ok');

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        ['socials' => ['instagram' => 'https://www.instagram.com/fadelab/?igsh=abc123&utm_source=qr']],
        'Fade Lab',
    );

    $ig = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->firstOrFail();
    expect($ig->last_refresh_status)->toBe('pending');
    Bus::assertDispatched(InstagramConnectJob::class, fn ($job) => $job->username === 'fadelab');
});

it('still seeds from a profile sub-tab share link — the projection retry cuts the path to the handle', function (string $url) {
    // Critic catch on the first fix: the profile detector matches the bare
    // profile path only, so /<handle>/reels/ (Instagram's own "share this
    // tab" URL) silently dropped. The first-segment retry recovers it.
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbigrUser('gbigr-'.substr(md5($url), 0, 6));

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        ['socials' => ['instagram' => $url]],
        'Fade Lab',
    );

    Bus::assertDispatched(InstagramConnectJob::class, fn ($job) => $job->username === 'fadelab');
})->with([
    'reels tab' => 'https://www.instagram.com/fadelab/reels/',
    'tagged tab' => 'https://www.instagram.com/fadelab/tagged/',
]);
