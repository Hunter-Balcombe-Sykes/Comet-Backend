<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
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

it('gives the second booking link of a bio a card, exactly as the unroll does', function () {
    $user = igParitySeed([
        'https://www.fresha.com/a/venue-1',
        'https://www.fresha.com/a/venue-1/colour',
    ]);

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeTrue();
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->count())->toBe(1);
});

it('gives the second social link of a bio a card too', function () {
    $user = igParitySeed([
        'https://www.youtube.com/@creator',
        'https://www.youtube.com/@creator/videos',
    ]);

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'youtube'])->exists())->toBeTrue();
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->count())->toBe(1);
});

it('spends ONE probe budget across both passes, not one each', function () {
    // Before the shared context, a bio could spend 6 probes in the auto-sync
    // pass and 6 more in the unmatched sweep — twice the documented cap, on
    // outbound requests aimed at other people's servers.
    Queue::fake();
    $user = igParitySeed([
        // 'shop'-classified: these spend budget in PASS 1 (LinkRouter::seedShop)
        'https://one.myshopify.com/',
        'https://two.myshopify.com/',
        'https://three.myshopify.com/',
        'https://four.myshopify.com/',
        // unclassified: deferred to `unmatched`, so these spend in PASS 2
        'https://siteone.example/',
        'https://sitetwo.example/',
        'https://sitethree.example/',
        'https://sitefour.example/',
    ]);

    expect($user)->not->toBeNull();
    Queue::assertPushed(CommerceProbeJob::class, RouteContext::DEFAULT_MAX_PROBES);
});

it('logs one run card for the whole scrape, starvation included', function () {
    Queue::fake();
    Log::spy();

    igParitySeed([
        'https://one.myshopify.com/',
        'https://two.myshopify.com/',
        'https://three.myshopify.com/',
        'https://four.myshopify.com/',
        'https://siteone.example/',
        'https://sitetwo.example/',
        'https://sitethree.example/',
        'https://sitefour.example/',
    ]);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.instagram.bio_links_routed'
            && $context['links_seen'] === 8
            && $context['probes_spent'] === RouteContext::DEFAULT_MAX_PROBES
            // The two links the shared cap starved — invisible before this log
            // existed, because a starved link becomes an ordinary card.
            && $context['probes_denied'] === 2
            && $context['sites_deduped'] === 0)
        ->once();
});

it('writes no card for a bio link already synced to the same url', function () {
    // The no-op case must stay a no-op: a card here would sit on top of a live
    // connection and render the platform twice.
    $user = igParitySeed(['https://www.fresha.com/a/venue-1']);

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeTrue();
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->count())->toBe(0);
});
