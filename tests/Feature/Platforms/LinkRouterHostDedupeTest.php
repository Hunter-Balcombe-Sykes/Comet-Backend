<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
});

it('spends one probe per website, not one per page', function () {
    // The live 2026-08-10 shape: six nav pages of one studio's site consumed the
    // whole budget of 6 and starved the three links behind them.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    foreach ([
        'https://crucibletattooco.com.au/',
        'https://crucibletattooco.com.au/appointment.html',
        'https://crucibletattooco.com.au/artists.html',
        'https://crucibletattooco.com.au/aftercare.html',
        'https://crucibletattooco.com.au/accessibility.html',
        'https://crucibletattooco.com.au/feedback.html',
    ] as $url) {
        $seeder->seed($user, $url, $ctx);
    }

    Queue::assertPushed(CommerceProbeJob::class, 1);
    expect($ctx->probesUsed())->toBe(1);
    // Nothing vanishes: the five deduped links still become cards. The probed
    // one is 'pending' and writes no card until its job misses.
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->count())->toBe(5);
});

it('leaves the budget free for the links behind the repeated website', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    foreach ([
        'https://crucibletattooco.com.au/',
        'https://crucibletattooco.com.au/appointment.html',
        'https://crucibletattooco.com.au/artists.html',
        'https://paytherent.net.au',
        'https://bsky.app/profile/someone',
        'https://au.pinterest.com/someone',
    ] as $url) {
        $seeder->seed($user, $url, $ctx);
    }

    // One for crucible, one each for the three that used to be starved. The
    // latter three must stay UNCLASSIFIED for that count to hold — adding
    // bsky or pinterest to the platform registry drops this to 3, and the
    // failure will read like a dedupe bug rather than a registry change.
    Queue::assertPushed(CommerceProbeJob::class, 4);
    expect($ctx->probesDenied())->toBe(0);
});

it('treats www and the bare host as one website', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://crucibletattooco.com.au/', $ctx);
    $seeder->seed($user, 'http://www.crucibletattooco.com.au/artists.html', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 1);
});

it('treats a subdomain as a different website', function () {
    // A shop subdomain is very often a separate storefront from the marketing
    // site, and the probes target scheme://host — so these are two questions.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://crucibletattooco.com.au/', $ctx);
    $seeder->seed($user, 'https://shop.crucibletattooco.com.au/', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 2);
});

it('spends a probe for each distinct website', function () {
    // Guard against the dedupe over-reaching: two unrelated hosts are two
    // questions and must both be asked.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://firstsite.example/', $ctx);
    $seeder->seed($user, 'https://secondsite.example/', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 2);
});

it('grants a second probe to a product page on an already-seen website', function () {
    // A homepage probe cannot find a specific product: the platform probes hit
    // scheme://host, and only reading the pasted page extracts a product.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://maker.example/', $ctx);
    $seeder->seed($user, 'https://maker.example/products/black-tee', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 2);
});

it('is symmetric — a product link first still leaves the homepage a probe', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://maker.example/products/black-tee', $ctx);
    $seeder->seed($user, 'https://maker.example/', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 2);
});

it('caps a website at two probes however many product links it has', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    foreach ([
        'https://maker.example/',
        'https://maker.example/products/black-tee',
        'https://maker.example/products/white-tee',
        'https://maker.example/item/999',
        'https://maker.example/about',
    ] as $url) {
        $seeder->seed($user, $url, $ctx);
    }

    Queue::assertPushed(CommerceProbeJob::class, 2);
    expect($ctx->sitesDeduped())->toBe(3);
});

it('grants a deep collections url the product slot rather than the homepage slot', function () {
    // ProbeGate's >=2-internal-slash refusal binds only the shop arm
    // (seedShop -> StoreBrandSeeder). This arm runs readProductPage() on the
    // pasted URL un-gated and is documented to keep deep URLs, so the probe is
    // worth spending. Keying it :plain instead would let it evict the
    // homepage's probe whenever it happened to be seen first.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://maker.example/', $ctx);
    $seeder->seed($user, 'https://maker.example/collections/summer/products/tee', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 2);
});

it('still probes the homepage when a deep product url is seen first', function () {
    // Order must not decide which of the two links gets examined — the failure
    // this whole change exists to remove, and one a :plain-bucketed deep URL
    // would silently reintroduce as a "healthy" sites_deduped.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://maker.example/collections/summer/products/tee', $ctx);
    $seeder->seed($user, 'https://maker.example/', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 2);
    expect($ctx->sitesDeduped())->toBe(0);
});

it('probes each unparseable url rather than folding them together', function () {
    // siteKey() returns null with no host to key on. Fail OPEN: two unrelated
    // junk strings must not collapse into one question just because neither
    // parses.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'not-a-url-at-all', $ctx);
    $seeder->seed($user, 'also-not-a-url', $ctx);

    expect($ctx->probesUsed())->toBe(2);
    expect($ctx->sitesDeduped())->toBe(0);
});

it('spends one probe for two links to the same store', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://alice.myshopify.com/', $ctx);
    $seeder->seed($user, 'https://alice.myshopify.com/pages/about', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 1);
});

it('still probes two different merchants on the same platform', function () {
    // Every shop-classified link carries the literal slug 'shop'
    // (WebsiteLinkHarvester.php:401-404), so platform keying would collapse
    // these two merchants into one probe. Host keying must not.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://alice.myshopify.com/', $ctx);
    $seeder->seed($user, 'https://bob.myshopify.com/', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 2);
});
