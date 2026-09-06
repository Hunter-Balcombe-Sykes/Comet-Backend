<?php

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessAutoSync;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

// Retest 2026-08-20 (T9 follow-up): a business listing carried an
// instagram.com/reel/<shortcode> link, and seedInstagram()'s standalone regex
// extracted the reserved segment as username "reel" — Apify then scraped a
// 9M-follower stranger into an auto-connected account. The seed now delegates
// identity to the catalog projection (same G4-4 lesson as the facebook arm),
// which only yields an identifier for a real profile path.
//
// A5 (2026-09-06): a fresh Instagram discovery on a claimed account no longer
// connects directly either way — it proposes, like every other social. The
// two "still seeds a real profile" tests below now assert the projection
// identifier landed correctly in routing.source_intents rather than on a
// live IntegrationConnection; the reserved-segment rejection above is
// unaffected (it was already blocked before reaching that fork).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
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

    $intent = DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'instagram.profile')->firstOrFail();
    expect($intent->state)->toBe('proposed')
        ->and($intent->identifier)->toBe('fadelab');
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->exists())->toBeFalse();
    Bus::assertNotDispatched(InstagramConnectJob::class);
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

    $intent = DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'instagram.profile')->firstOrFail();
    expect($intent->state)->toBe('proposed')
        ->and($intent->identifier)->toBe('fadelab');
    Bus::assertNotDispatched(InstagramConnectJob::class);
})->with([
    'reels tab' => 'https://www.instagram.com/fadelab/reels/',
    'tagged tab' => 'https://www.instagram.com/fadelab/tagged/',
]);

// ── M-2: stranded pending placeholder (retry after a mid-flight kill) ────

it('re-dispatches the scrape when the existing row is its own pending placeholder', function () {
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbigrUser('gbigr-m2a');

    // Attempt 1 died between the placeholder write and the scrape running.
    $placeholder = new IntegrationConnection([
        'surface_key' => 'instagram.profile', 'routing_class' => 'social',
        'resource_id' => 'instagram', 'payload' => ['source' => 'google-business'],
        'is_active' => false,
    ]);
    $placeholder->user_id = $user->id;
    $placeholder->platform = 'instagram';
    $placeholder->last_refresh_status = 'pending';
    $placeholder->save();
    // STALE: older than InstagramConnectJob's 15-min retryUntil window — a
    // FRESH pending placeholder means the scrape is in flight and must NOT
    // re-dispatch (the #JOB-1 budget guard).
    $placeholder->timestamps = false;
    $placeholder->forceFill(['updated_at' => now()->subMinutes(20)])->save();

    $findings = app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        ['socials' => ['instagram' => 'https://instagram.com/fadelab']],
        'Fade Lab',
    );

    Bus::assertDispatched(InstagramConnectJob::class, fn ($job) => $job->username === 'fadelab');
    expect(collect($findings)->firstWhere('platform', 'instagram')['outcome'] ?? null)->toBe('seeded');
});

it('still files a conflict when the existing Instagram is enriched or user-connected', function () {
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbigrUser('gbigr-m2b');

    $own = new IntegrationConnection([
        'surface_key' => 'instagram.profile', 'routing_class' => 'social',
        'resource_id' => 'instagram', 'payload' => ['username' => 'myself'],
        'is_active' => true,
    ]);
    $own->user_id = $user->id;
    $own->platform = 'instagram';
    $own->last_refresh_status = 'ok';
    $own->save();

    $findings = app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        ['socials' => ['instagram' => 'https://instagram.com/fadelab']],
        'Fade Lab',
    );

    Bus::assertNotDispatched(InstagramConnectJob::class);
    expect(collect($findings)->firstWhere('platform', 'instagram')['outcome'] ?? null)->toBe('conflict');
});

it('leaves a FRESH pending placeholder alone — the scrape is in flight, no budget re-spend', function () {
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    $user = gbigrUser('gbigr-m2c');

    $placeholder = new IntegrationConnection([
        'surface_key' => 'instagram.profile', 'routing_class' => 'social',
        'resource_id' => 'instagram', 'payload' => ['source' => 'google-business'],
        'is_active' => false,
    ]);
    $placeholder->user_id = $user->id;
    $placeholder->platform = 'instagram';
    $placeholder->last_refresh_status = 'pending';
    $placeholder->save();

    $findings = app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        ['socials' => ['instagram' => 'https://instagram.com/fadelab']],
        'Fade Lab',
    );

    Bus::assertNotDispatched(InstagramConnectJob::class);
    expect(collect($findings)->firstWhere('platform', 'instagram')['outcome'] ?? null)->toBe('conflict');
});
