<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * An Instagram bio must treat a link the same way a link-in-bio page does.
 *
 * The bio path routes in TWO passes — InstagramAutoSync over the classified
 * links, then autoSaveUnmatchedLinks over what was left — and the two used to
 * carry SEPARATE RouteContexts. Pass 2 therefore started with an empty
 * seen-platforms map and re-litigated decisions pass 1 had already settled,
 * which is how the second link to one platform ended up with no card, no
 * connection and no finding, while the identical page unrolled through
 * LinkInBioScanJob got a card.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Phase 6: custom links live in the custom_links POOL.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupNotificationsTable();
    // A Fresha link auto-dispatches ConnectFetchJob, and QUEUE_CONNECTION=sync
    // runs it INLINE — without this the seed below scrapes fresha.com for real.
    Http::fake();
});

/** Drive the real two-pass bio path with $bioLinks, returning the user. */
function igParitySeed(array $bioLinks, string $accountType = 'business'): User
{
    Storage::fake('media');

    $handle = 'parity'.substr(sha1(implode('|', $bioLinks)), 0, 8);
    $user = User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => 'Parity',
        'first_name' => 'Parity',
        'account_type' => $accountType,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);

    // Phase 6: an unrecognised bio link lands in the custom_links POOL, and a
    // pool item needs a section, which hangs off the site.
    $site = new Site(['subdomain' => $handle, 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();
    $user->refresh();

    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [],
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('latestMedia')->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->andReturn(null);
    $scraper->shouldReceive('bioLinks')->andReturn($bioLinks);
    app()->instance(InstagramScraper::class, $scraper);

    app(InstagramConnectionSeeder::class)->seed($connection, $handle, (string) $user->id, ['username' => $handle]);

    return $user;
}

/** Re-scrape the SAME user's bio — the second connect/refresh of an existing account. */
function igParityReseed(User $user, array $bioLinks): void
{
    $connection = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'instagram'])->firstOrFail();

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('latestMedia')->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->andReturn(null);
    $scraper->shouldReceive('bioLinks')->andReturn($bioLinks);
    app()->instance(InstagramScraper::class, $scraper);

    app(InstagramConnectionSeeder::class)->seed(
        $connection, (string) $user->handle, (string) $user->id, ['username' => $user->handle],
    );
}

it('gives the second booking link of a bio a card, exactly as the unroll does', function () {
    $user = igParitySeed([
        'https://www.fresha.com/a/venue-1',
        'https://www.fresha.com/a/venue-1/colour',
    ]);

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeTrue();
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(1);
});

it('gives the second social link of a bio a card too', function () {
    $user = igParitySeed([
        'https://www.youtube.com/@creator',
        'https://www.youtube.com/@creator/videos',
    ]);

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'youtube'])->exists())->toBeTrue();
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(1);
});

it('spends ONE probe budget across both passes, not one each', function () {
    // Before the shared context, a bio could spend N probes in the auto-sync
    // pass and N more in the unmatched sweep — twice the documented cap, on
    // outbound requests aimed at other people's servers. Link count kept a
    // few past the budget so this still exercises the shared-cap behaviour
    // after the cap was raised (2026-09-05).
    Queue::fake();
    $half = intdiv(RouteContext::DEFAULT_MAX_PROBES, 2) + 1;
    $links = [];
    // 'shop'-classified: these spend budget in PASS 1 (LinkRouter::seedShop)
    foreach (range(1, $half) as $n) {
        $links[] = "https://shop{$n}.myshopify.com/";
    }
    // unclassified: deferred to `unmatched`, so these spend in PASS 2
    foreach (range(1, $half) as $n) {
        $links[] = "https://site{$n}.example/";
    }
    $user = igParitySeed($links);

    expect($user)->not->toBeNull();
    Queue::assertPushed(CommerceProbeJob::class, RouteContext::DEFAULT_MAX_PROBES);
});

it('logs one run card for the whole scrape, starvation included', function () {
    Queue::fake();
    Log::spy();

    $half = intdiv(RouteContext::DEFAULT_MAX_PROBES, 2) + 1;
    $links = [];
    foreach (range(1, $half) as $n) {
        $links[] = "https://shop{$n}.myshopify.com/";
    }
    foreach (range(1, $half) as $n) {
        $links[] = "https://site{$n}.example/";
    }
    igParitySeed($links);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.instagram.bio_links_routed'
            && $context['links_seen'] === $half * 2
            && $context['probes_spent'] === RouteContext::DEFAULT_MAX_PROBES
            // The two links the shared cap starved — invisible before this log
            // existed, because a starved link becomes an ordinary card.
            && $context['probes_denied'] === 2
            && $context['sites_deduped'] === 0)
        ->once();
});

it('counts a starved link once, not once per pass', function () {
    // A CLASSIFIED link starved in pass 1 is deferred to `unmatched` and routed
    // again in pass 2, where the shared budget denies it a second time. Counting
    // attempts rather than links makes probes_denied exceed the budget itself —
    // and this is the number the run card exists to report. Link count kept 2
    // past the budget so this still exercises starvation after the cap was
    // raised (2026-09-05).
    Queue::fake();
    Log::spy();

    $links = [];
    foreach (range(1, RouteContext::DEFAULT_MAX_PROBES + 2) as $n) {
        $links[] = "https://shop{$n}.myshopify.com/";
    }
    igParitySeed($links);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.instagram.bio_links_routed'
            && $context['probes_spent'] === RouteContext::DEFAULT_MAX_PROBES
            && $context['probes_denied'] === 2)
        ->once();
});

it('lets one bad link fail without abandoning the rest of the bio', function () {
    // The call site already promised this ("a bad link can't fail this job"),
    // but only pass 1 had the try/catch. A throw out of pass 2 aborted seed()
    // BEFORE the payload write and left the connection stuck 'pending'.
    Queue::fake();
    Storage::fake('media');

    $user = User::create([
        'handle' => 'faulty', 'handle_lc' => 'faulty',
        'display_name' => 'Faulty', 'first_name' => 'Faulty',
        'account_type' => 'business',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'faulty@example.com',
    ]);
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => [], 'is_active' => false, 'last_refresh_status' => 'pending',
    ]);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('latestMedia')->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->andReturn(null);
    $scraper->shouldReceive('bioLinks')->andReturn([
        'https://unknown-a.example/',
        'https://unknown-b.example/',
    ]);
    app()->instance(InstagramScraper::class, $scraper);

    $links = Mockery::mock(CustomLinkSeeder::class);
    $links->shouldReceive('seed')->twice()->andReturnUsing(function ($u, string $url) {
        throw_if(str_contains($url, 'unknown-a'), new RuntimeException('one bad link'));

        return null;
    });
    app()->instance(CustomLinkSeeder::class, $links);

    app(InstagramConnectionSeeder::class)->seed($connection, 'faulty', (string) $user->id, ['username' => 'faulty']);

    // The row completed rather than being left mid-write by the throw.
    expect($connection->fresh()->last_refresh_status)->not->toBe('pending');
});

it('writes no card for a bio link already synced to the same url', function () {
    // The no-op case must stay a no-op: a card here would sit on top of a live
    // connection and render the platform twice. The FIRST seed connects Fresha;
    // the second re-scrapes the same bio, which is when the already-synced
    // branch (LinkRouter::outcomeFrom -> skipped) actually runs.
    $user = igParitySeed(['https://www.fresha.com/a/venue-1']);
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->count())->toBe(1);

    igParityReseed($user, ['https://www.fresha.com/a/venue-1']);

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->count())->toBe(1);
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(0);
});
