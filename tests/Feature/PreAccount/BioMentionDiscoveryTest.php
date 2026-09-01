<?php

use App\Jobs\PreAccount\BioMentionChainsJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\ProfileFetchResult;
use App\Services\Platforms\RouteResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Item 11g (2026-09-01), call site 1: after the mention loop, the build asks
 * the find-social-profiles lane what OTHER platforms its own Instagram
 * identity verifiably has, and each discovered URL rides the SAME router seam
 * the brand chain uses. Pinned against the recorded live payload
 * (tests/fixtures/recorded/scrapecreators-find-social-profiles.json —
 * instagram/mkbhd, youtube + x discovered). The lane is additive and
 * fail-open: every vendor outcome short of a usable map changes nothing.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupWorkplacesTable();
    setupIntegrationConnectionsTable();
    setupBlocksTable();
    setupMediaTables();
    setupSectionsTables();
    setupContentCurationTables();
    Queue::fake();
    Cache::flush();
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.find_social_profiles', 100);
});

function bmdFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-find-social-profiles.json')),
        true
    );
}

function bmdUser(string $handle, array $mentions): User
{
    $user = User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle), 'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => "{$handle}@example.com",
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'subdomain' => $handle,
        'is_published' => 1, 'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => $handle, 'bioMentions' => $mentions],
        'is_active' => false,
    ]);

    return $user->fresh();
}

/** A brand mention whose scrape yields no website — the loop runs but routes nothing. */
function bmdBrandMention(): array
{
    return ['handle' => 'some_brand', 'label' => 'ambassador', 'type' => 'brand'];
}

it('discovers the build’s other platforms from its own IG identity and routes each URL through the brand-chain seam', function () {
    $user = bmdUser('mkbhd', [bmdBrandMention()]);

    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldReceive('fetchProfileResult')
        ->once()->andReturn(ProfileFetchResult::ok(['fullName' => 'Some Brand', 'biography' => '', 'externalUrl' => null])));

    Http::fake(['api.scrapecreators.com/*' => Http::response(bmdFixture())]);

    $routed = [];
    $this->mock(LinkRouter::class, function ($m) use (&$routed) {
        $m->shouldReceive('route')->twice()->withArgs(function ($u, string $url) use (&$routed) {
            $routed[] = $url;

            return true;
        })->andReturn(new RouteResult('seeded', 'youtube', 'r1', 'social'));
    });

    app()->call([new BioMentionChainsJob((string) $user->id, [bmdBrandMention()]), 'handle']);

    // The normalized map exactly, in map order — one best URL per platform.
    expect($routed)->toBe(['https://www.youtube.com/@mkbhd', 'https://x.com/mkbhd']);

    // At most ONE vendor call per build, addressed by the build's OWN handle.
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/find-social-profiles')
        && $request['platform'] === 'instagram'
        && $request['handle'] === 'mkbhd');
});

it('reads a vendor husk as no discoveries — the run completes with the router untouched', function () {
    $user = bmdUser('huskcase', [bmdBrandMention()]);

    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldReceive('fetchProfileResult')
        ->once()->andReturn(ProfileFetchResult::ok(['fullName' => 'Some Brand', 'biography' => '', 'externalUrl' => null])));
    $this->mock(LinkRouter::class, fn ($m) => $m->shouldNotReceive('route'));

    // The NotFound quirk: billed, success-shaped, no graph inside.
    Http::fake(['api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 10])]);

    app()->call([new BioMentionChainsJob((string) $user->id, [bmdBrandMention()]), 'handle']);

    Http::assertSentCount(1);
});

it('spends nothing without a key — the lane is dormant, not broken', function () {
    config()->set('services.scrapecreators.key', null);
    $user = bmdUser('nokeycase', [bmdBrandMention()]);

    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldReceive('fetchProfileResult')
        ->once()->andReturn(ProfileFetchResult::ok(['fullName' => 'Some Brand', 'biography' => '', 'externalUrl' => null])));
    $this->mock(LinkRouter::class, fn ($m) => $m->shouldNotReceive('route'));
    Http::fake();

    app()->call([new BioMentionChainsJob((string) $user->id, [bmdBrandMention()]), 'handle']);

    Http::assertNothingSent();
});

it('never discovers for a business account — the job’s capability gate returns first', function () {
    $user = bmdUser('bizdisc', [['handle' => 'x_venue', 'label' => 'Owner', 'type' => 'workplace']]);
    $user->forceFill(['account_type' => 'business'])->save();
    Http::fake();

    app()->call([new BioMentionChainsJob((string) $user->id, [['handle' => 'x_venue', 'label' => 'Owner', 'type' => 'workplace']]), 'handle']);

    Http::assertNothingSent();
});

it('skips discovery when the build carried no mentions — the job returns before the loop', function () {
    // Pins the placement decision: discovery runs AFTER the mention loop, so
    // a mention-less build (which returns before the loop) never spends the
    // 10-credit call. If discovery should also serve mention-less builds,
    // move the call above the empty-mentions return — and flip this test.
    $user = bmdUser('nomention', []);
    Http::fake();

    app()->call([new BioMentionChainsJob((string) $user->id), 'handle']);

    Http::assertNothingSent();
});
