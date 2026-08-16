<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\Queue;

/**
 * The probe budget must be spent on links that could actually BE a storefront.
 *
 * Both guards here exist for the same user: a creator whose page is mostly
 * affiliate and marketplace links. Before them, a run's six probes were spent
 * discovering that amazon.com is amazon.com, and every link behind them was
 * starved — a worse page for having MORE links on it.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Phase 6: custom links live in the custom_links POOL.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupNotificationsTable();
});

/**
 * Phase 6: an unclassified link becomes a custom_links POOL item, and a pool
 * item needs a section, which hangs off the site.
 */
function probeBudgetPoolUser(array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    $site = new Site(['subdomain' => 'pu'.substr((string) $user->id, 0, 8), 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

it('spends no probe on a recognised marketplace or creator-commerce link', function () {
    Queue::fake();
    $user = probeBudgetPoolUser(['account_type' => 'partna']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    foreach ([
        'https://www.liketoknow.it/creator',
        'https://www.amazon.com.au/shop/creator',
        'https://poshmark.com/closet/creator',
        'https://shopmy.us/collections/12345',
        'https://au.pinterest.com/creator',
    ] as $url) {
        $seeder->seed($user, $url, $ctx);
    }

    // A probe fetches a page to ask "is this a self-hosted storefront?". For
    // these hosts the answer is known without asking, and it is always no.
    Queue::assertNotPushed(CommerceProbeJob::class);
    expect($ctx->probesUsed())->toBe(0);

    // Nothing vanishes — each is still a link card.
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(5);
});

it('gives each marketplace link its own card instead of skipping the second', function () {
    // An influencer legitimately has several LTK links. First-link-per-platform
    // is a rule about CONNECTIONS; these are cards, so it must not apply.
    Queue::fake();
    $user = probeBudgetPoolUser(['account_type' => 'partna']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://www.liketoknow.it/creator/post-one', $ctx);
    $seeder->seed($user, 'https://www.liketoknow.it/creator/post-two', $ctx);

    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(2);
    expect($ctx->probesUsed())->toBe(0);
});

it('leaves the whole budget for real websites sitting behind marketplace links', function () {
    Queue::fake();
    $user = probeBudgetPoolUser(['account_type' => 'partna']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    foreach ([
        'https://www.amazon.com/shop/creator',
        'https://www.liketoknow.it/creator',
        'https://poshmark.com/closet/creator',
        'https://au.pinterest.com/creator',
        'https://shopmy.us/collections/1',
        'https://herownlabel.example/',
    ] as $url) {
        $seeder->seed($user, $url, $ctx);
    }

    // The one link that might genuinely be her store still gets asked about.
    Queue::assertPushed(CommerceProbeJob::class, 1);
    expect($ctx->probesDenied())->toBe(0);
});

it('does not charge the budget for a url no probe could ever fetch', function () {
    Queue::fake();
    $user = probeBudgetPoolUser(['account_type' => 'partna']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    foreach ([
        'https://bit.ly/3xamPle',        // shortener — ProbeGate refuses on arrival
        'https://192.168.1.10/',         // IP literal — the shape every SSRF takes
        'https://cdn.partna.au/asset',   // our own infrastructure
    ] as $url) {
        $seeder->seed($user, $url, $ctx);
    }

    Queue::assertNotPushed(CommerceProbeJob::class);
    expect($ctx->probesUsed())->toBe(0);
    // Counted apart from probesDenied: "we never asked" and "we ran out of
    // budget" are different diagnoses and the run card must not conflate them.
    expect($ctx->probesSkippedIneligible())->toBe(3);
    expect($ctx->probesDenied())->toBe(0);
});

it('reports the ineligible count in the run summary', function () {
    Queue::fake();
    $user = probeBudgetPoolUser(['account_type' => 'partna']);
    $ctx = new RouteContext;

    app(CustomLinkSeeder::class)->seed($user, 'https://bit.ly/3xamPle', $ctx);

    expect($ctx->summary())->toMatchArray([
        'probe_budget' => RouteContext::DEFAULT_MAX_PROBES,
        'probes_spent' => 0,
        'probes_denied' => 0,
        'probes_skipped_ineligible' => 1,
    ]);
});

it('still enforces the cap — the pre-filter must never loosen the bound', function () {
    // The cap is not only a budget: unbounded outbound fan-out on a user's
    // say-so is an amplification vector aimed at someone else's server. A
    // filter that skips work must not become a filter that grants extra work.
    Queue::fake();
    $user = probeBudgetPoolUser(['account_type' => 'partna']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $urls = [];
    foreach (range(1, 10) as $n) {
        $urls[] = "https://bit.ly/skip{$n}";
    }
    foreach (range(1, 8) as $n) {
        $urls[] = "https://realsite{$n}.example/";
    }
    foreach ($urls as $url) {
        $seeder->seed($user, $url, $ctx);
    }

    Queue::assertPushed(CommerceProbeJob::class, RouteContext::DEFAULT_MAX_PROBES);
    expect($ctx->probesUsed())->toBe(RouteContext::DEFAULT_MAX_PROBES);
    expect($ctx->probesDenied())->toBe(2);              // the 7th and 8th real site
    expect($ctx->probesSkippedIneligible())->toBe(10);  // never counted against the cap
});

it('does not let a trailing dot or bracketed ipv6 walk past the pre-filter', function (string $url) {
    // Both resolve identically to the form the filter checks, and both slip a
    // naive suffix/IP comparison — SafeUrlFetcher normalises for this reason.
    Queue::fake();
    $user = probeBudgetPoolUser(['account_type' => 'partna']);
    $ctx = new RouteContext;

    app(CustomLinkSeeder::class)->seed($user, $url, $ctx);

    expect($ctx->probesUsed())->toBe(0);
    expect($ctx->probesSkippedIneligible())->toBe(1);
})->with([
    'https://bit.ly./3xamPle',
    'https://[::1]/',
    'https://cdn.partna.au./asset',
]);

it('still probes an ordinary website on a port or with a long path', function (string $url) {
    // The fail-open direction: anything the filter cannot positively
    // disqualify keeps the behaviour it has today.
    Queue::fake();
    $user = probeBudgetPoolUser(['account_type' => 'partna']);
    $ctx = new RouteContext;

    app(CustomLinkSeeder::class)->seed($user, $url, $ctx);

    expect($ctx->probesUsed())->toBe(1);
    expect($ctx->probesSkippedIneligible())->toBe(0);
})->with([
    'https://herownlabel.example:8443/',
    'https://xn--80ak6aa92e.example/',
]);

it('still probes an ordinary website', function () {
    // Guard against the pre-filter over-reaching: the whole point is that a
    // plausible storefront still gets its probe.
    Queue::fake();
    $user = probeBudgetPoolUser(['account_type' => 'partna']);
    $ctx = new RouteContext;

    app(CustomLinkSeeder::class)->seed($user, 'https://herownlabel.example/', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 1);
    expect($ctx->probesUsed())->toBe(1);
    expect($ctx->probesSkippedIneligible())->toBe(0);
});
