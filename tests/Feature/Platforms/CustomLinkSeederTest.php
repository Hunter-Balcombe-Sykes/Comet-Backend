<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// These exercise seedCustom() — the RAW custom-link write. They used to call
// seed(), which since the 2026-07-25 link classification consolidation is the
// routing GATEWAY: it hands the URL to LinkRouter first, and an unclassified URL
// there becomes a commerce probe returning 'pending' with no row written (the
// probe's own seedCustom() fallback writes it later, on a miss). Under
// Queue::fake() that meant no row at all. The gateway behaviour has its own
// tests at the bottom of this file; everything above them is about the write.

it('seeds a custom link card idempotently, enriches it, and enforces the same 20-link cap the manual UI uses', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);

    $row = app(CustomLinkSeeder::class)->seedCustom($user, 'https://someblog.example/post');
    app(CustomLinkSeeder::class)->seedCustom($user, 'https://someblog.example/post'); // again

    $rows = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->get();
    expect($rows)->toHaveCount(1);
    expect($row->resource_kind)->toBe('link');
    Queue::assertPushed(EnrichLinkCardJob::class, 1); // enrichment dispatched once, not on the idempotent re-seed
});

it('refuses to seed a link for a pending-deletion account', function () {
    $user = User::factory()->create(['status' => 'pending_deletion']);
    expect(app(CustomLinkSeeder::class)->seedCustom($user, 'https://example.com'))->toBeNull();
    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeFalse();
});

it('stops at the 20-link cap', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    for ($i = 0; $i < 20; $i++) {
        $result = app(CustomLinkSeeder::class)->seedCustom($user, "https://example{$i}.com");
        expect($result)->not->toBeNull();
    }
    $result = app(CustomLinkSeeder::class)->seedCustom($user, 'https://one-too-many.example');
    expect($result)->toBeNull();
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->count())->toBe(20);
});

it('returns null for a URL that fails to normalize', function () {
    $user = User::factory()->create();
    expect(app(CustomLinkSeeder::class)->seedCustom($user, 'not a url'))->toBeNull();
});

it('respects an existing link already at the cap — re-seeding it is still idempotent, not blocked', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    for ($i = 0; $i < 20; $i++) {
        app(CustomLinkSeeder::class)->seedCustom($user, "https://example{$i}.com");
    }
    // Re-seeding the FIRST link (already stored) must still succeed — the cap
    // only blocks genuinely NEW rows, not idempotent re-seeds of an existing one.
    $result = app(CustomLinkSeeder::class)->seedCustom($user, 'https://example0.com');
    expect($result)->not->toBeNull();
});

// ── seed() as the routing GATEWAY (link classification consolidation, Phase 5) ──
//
// seed() routes first and only writes a custom link when LinkRouter says
// 'custom'. These pin the four outcomes, because nothing else did: the original
// change shipped 999 insertions of routing with no LinkRouter test at all, and a
// missing `use App\Jobs\Platforms\CommerceProbeJob` import in the router — which
// fataled every one of these paths — reached deployed development undetected.

it('seed() dispatches a commerce probe and writes NO custom link for an unclassified URL', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);

    $result = app(CustomLinkSeeder::class)->seed($user, 'https://someblog.example/post');

    expect($result)->toBeNull();
    Queue::assertPushed(CommerceProbeJob::class, 1);
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->count())->toBe(0);
});

it('seed() routes a Booksy link to the shared booking key instead of a custom link', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);

    $result = app(CustomLinkSeeder::class)->seed($user, 'https://booksy.com/en-us/12345_the-salon');

    // Routed, so no custom link row and a null return (Issue F: every caller
    // already discarded this return value, which is why null is safe).
    expect($result)->toBeNull();
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->count())->toBe(0);

    $booking = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'booking'])->first();
    expect($booking)->not->toBeNull();
    // Decision 10: the brand is a `provider` string on the shared key, never a
    // registry key of its own.
    expect($booking->payload['provider'])->toBe('Booksy');
    expect($booking->payload['url'])->toBe('https://booksy.com/en-us/12345_the-salon');
});

it('seed() falls through to a custom link when the routing gate denies the category', function () {
    Queue::fake();
    // Reservations route for business FOOD accounts only; a partna account is
    // denied and the link must still land, as a custom link — never dropped.
    $user = User::factory()->create(['account_type' => 'partna']);

    $result = app(CustomLinkSeeder::class)->seed($user, 'https://www.opentable.com/r/some-restaurant');

    expect($result)->not->toBeNull();
    expect($result->platform)->toBe('custom');
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'reservations'])->count())->toBe(0);
});

it('seed() still routes a Fresha link when the user has custom links DISABLED (Issue I/B2)', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);

    // integration.custom is a CUSTOM-LINK policy and lives in seedCustom(). If it
    // sat in seed() — where it used to — a user with custom links switched off
    // would silently lose booking, social, event and shop routing too.
    config(['partna.feature_availability.disabled' => ['integration.custom']]);

    app(CustomLinkSeeder::class)->seed($user, 'https://www.fresha.com/a/the-salon');

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->count())->toBe(1);
});

it('seed() shares ONE probe budget across a loop, so a page of unclassified links cannot fan out unbounded', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);

    // The regression this pins: LinkInBioScanJob and autoSaveUnmatchedLinks both
    // deleted their own MAX_COMMERCE_PROBES counters and called seed() per URL.
    // Because seed() built a fresh RouteContext each time, every link got its own
    // budget of 6 — i.e. no cap. One shared context is the whole fix.
    $ctx = new RouteContext;
    for ($i = 0; $i < 10; $i++) {
        $seeder->seed($user, "https://unclassified{$i}.example/page", $ctx);
    }

    Queue::assertPushed(CommerceProbeJob::class, RouteContext::DEFAULT_MAX_PROBES);
    // The 4 links past the budget are not lost — they become custom links.
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->count())
        ->toBe(10 - RouteContext::DEFAULT_MAX_PROBES);
});
