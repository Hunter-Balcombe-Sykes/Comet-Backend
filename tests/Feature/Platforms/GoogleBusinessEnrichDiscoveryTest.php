<?php

use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\GoogleBusinessApifyScraper;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Item 11g (2026-09-01), call site 2: when the website harvest names an
 * Instagram or Facebook account, GoogleBusinessEnrichJob asks the
 * find-social-profiles lane for the identity's OTHER platforms and unions
 * them into the socials merge as the LOWEST authority — a link published on
 * the site always beats a vendor corroboration. Proven end-to-end against
 * the recorded live payload (tests/fixtures/recorded/
 * scrapecreators-find-social-profiles.json): the discovered x.com URL rides
 * the union into GoogleBusinessAutoSync::seedSocials and lands as a real
 * 'x' connection. Fail-open throughout: husk, no key, or an unprojectable
 * harvest URL leaves the enrichment exactly as it was.
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
    setupRoutingTables();
    Queue::fake();
    Cache::flush();
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.find_social_profiles', 100);
});

function gbdFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-find-social-profiles.json')),
        true
    );
}

// Socials seed for EVERY account type (owner ruling R14), so a standard
// partna account is the sharper default here — it proves the discovery union
// needs no gate of its own beyond the capability checks seed() already makes.
function gbdUser(string $h, string $accountType = 'partna'): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => $accountType,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function gbdConnection(User $user, string $placeId = 'ChIJdisc'): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'url' => 'https://maps.google.com/?cid=1',
            'placeId' => $placeId,
            'name' => 'Fade Lab Barbers',
            'website' => 'https://fadelab.example',
            'rating' => 4.8,
        ],
        'place_id' => $placeId,
        'apify_status' => 'pending',
        'last_refreshed_at' => now(),
    ]);
}

/** Harvester stub answering with a fixed socials map — non-empty harvest + non-food category also skips the paid Apify leg. */
function gbdHarvester(array $socials): WebsiteLinkHarvester
{
    return new class($socials) extends WebsiteLinkHarvester
    {
        public function __construct(private readonly array $socials)
        {
            parent::__construct(app(SafeUrlFetcher::class));
        }

        public function harvest(?string $websiteUrl): array
        {
            return ['socials' => $this->socials];
        }
    };
}

function gbdRunJob(User $user, array $socials): void
{
    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJdisc'))
        ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), gbdHarvester($socials));
}

function gbdXConnection(User $user): ?IntegrationConnection
{
    return IntegrationConnection::query()
        ->where('user_id', $user->id)
        ->where('platform', 'x')
        ->first();
}

it('discovers off the harvested IG handle and unions the x profile into the socials seed', function () {
    $user = gbdUser('gbd1');
    gbdConnection($user);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(gbdFixture()),
        '*' => Http::response([], 404),
    ]);

    gbdRunJob($user, ['instagram' => 'https://instagram.com/mkbhd']);

    // One vendor call, addressed by the projected harvest handle.
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/find-social-profiles')
        && $request['platform'] === 'instagram'
        && $request['handle'] === 'mkbhd');

    // The discovered map arrived in the union in seedSocials' vocabulary
    // ('twitter', aliased back to platform 'x' by the seeder) and filled a
    // network no other source carried.
    $x = gbdXConnection($user);
    expect($x)->not->toBeNull()
        ->and(data_get($x->payload, 'url'))->toBe('https://x.com/mkbhd')
        ->and(data_get($x->payload, 'username'))->toBe('mkbhd')
        ->and(data_get($x->payload, 'source'))->toBe('google-business');

    // The run itself settled normally around the discovery.
    expect($user->integrationConnections()->where('platform', 'google-business')->first()->apify_status)->toBe('ok');
});

it('keeps the harvest as the higher authority — a site-published x link beats the discovered one', function () {
    $user = gbdUser('gbd2');
    gbdConnection($user);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(gbdFixture()),
        '*' => Http::response([], 404),
    ]);

    gbdRunJob($user, [
        'instagram' => 'https://instagram.com/mkbhd',
        'twitter' => 'https://x.com/fadelab',
    ]);

    Http::assertSentCount(1);
    expect(data_get(gbdXConnection($user)?->payload, 'url'))->toBe('https://x.com/fadelab');
});

it('falls to the facebook handle when the harvest carries no instagram', function () {
    $user = gbdUser('gbd3');
    gbdConnection($user);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(gbdFixture()),
        '*' => Http::response([], 404),
    ]);

    gbdRunJob($user, ['facebook' => 'https://facebook.com/mkbhd']);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/find-social-profiles')
        && $request['platform'] === 'facebook'
        && $request['handle'] === 'mkbhd');
    expect(data_get(gbdXConnection($user)?->payload, 'url'))->toBe('https://x.com/mkbhd');
});

it('reads a vendor husk as nothing discovered — the enrichment lands exactly as it was', function () {
    $user = gbdUser('gbd4');
    gbdConnection($user);
    Http::fake([
        // The NotFound quirk: billed, success-shaped, no graph inside.
        'api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 10]),
        '*' => Http::response([], 404),
    ]);

    gbdRunJob($user, ['instagram' => 'https://instagram.com/nobody_here']);

    Http::assertSentCount(1);
    expect(gbdXConnection($user))->toBeNull()
        ->and($user->integrationConnections()->where('platform', 'google-business')->first()->apify_status)->toBe('ok');
});

it('spends nothing on a reserved-segment harvest URL — the projection yields no handle', function () {
    // The /reel/ shape is the exact incident class seedInstagram documents
    // (literal username "reel", 2026-08-20) — discovery rides the same
    // catalog projection, so it refuses before any vendor call.
    $user = gbdUser('gbd5');
    gbdConnection($user);
    Http::fake();

    gbdRunJob($user, ['instagram' => 'https://instagram.com/reel/abc123']);

    Http::assertNothingSent();
    expect(gbdXConnection($user))->toBeNull();
});
