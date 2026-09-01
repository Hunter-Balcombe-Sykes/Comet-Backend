<?php

use App\Jobs\PreAccount\BioMentionChainsJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaWorkplaceLinker;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\ProfileFetchResult;
use App\Services\Platforms\RouteResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * T14 (2026-08-27, D10): the bio-mention chains — workplace fill via the
 * Fresha linker's Places machinery, brand store via the router's commerce
 * lane, Fresha precedence held by the still-empty check, chained scrapes
 * globally cached.
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
});

function bmcUser(string $handle, array $mentions): User
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

function bmcProfile(array $overrides = []): ProfileFetchResult
{
    return ProfileFetchResult::ok(array_merge([
        'username' => 'star_barber_darwin',
        'fullName' => 'Star Barber Darwin',
        'biography' => "Shop 6 Star Village Arcade, 32 Smith Street Mall\nDarwin NT 0800",
        'externalUrl' => null,
    ], $overrides));
}

it('connects via the Places-first one hop without spending a chained scrape (4-EXT, emdinonhair shape)', function () {
    $user = bmcUser('emdinonhair', [
        ['handle' => 'star_barber_darwin', 'label' => 'Owner @star_barber_darwin.', 'type' => 'workplace'],
    ]);

    // The whole point of 4-EXT: when the bare handle-name corroborates on its
    // own, the paid chained scrape is never made.
    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldNotReceive('fetchProfileResult'));

    $this->mock(FreshaWorkplaceLinker::class, function ($m) {
        $m->shouldReceive('attempt')->once()->withArgs(function ($user, array $venue) {
            return $venue['name'] === 'Star Barber Darwin'
                && $venue['postcode'] === null
                && $venue['street'] === null;
        })->andReturn(['outcome' => 'connected', 'placeId' => 'p1', 'reason' => null]);
    });

    app()->call([new BioMentionChainsJob((string) $user->id, data_get($user->integrationConnections()->first()?->payload, 'bioMentions', [])), 'handle']);
});

it('holds Fresha precedence — an existing workplace means the linker is never called', function () {
    $user = bmcUser('barber-in-law-x', [
        ['handle' => 'studio___san', 'label' => 'Co- Owner', 'type' => 'workplace'],
    ]);
    $site = Site::query()->where('user_id', $user->id)->firstOrFail();
    $workplace = new Workplace(['name' => 'Studio San']);
    $workplace->site_id = $site->id;
    $workplace->save();

    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldNotReceive('fetchProfileResult'));
    $this->mock(FreshaWorkplaceLinker::class, fn ($m) => $m->shouldNotReceive('attempt'));

    app()->call([new BioMentionChainsJob((string) $user->id, data_get($user->integrationConnections()->first()?->payload, 'bioMentions', [])), 'handle']);
});

it('routes a brand mention’s website through the commerce lane', function () {
    $user = bmcUser('brandfan', [
        ['handle' => 'andisco_aunz', 'label' => '@andisco_aunz ambassador.', 'type' => 'brand'],
    ]);

    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldReceive('fetchProfileResult')
        ->once()->andReturn(bmcProfile(['fullName' => 'Andis Australia', 'externalUrl' => 'https://andisclippers.com.au'])));

    $this->mock(LinkRouter::class, function ($m) {
        $m->shouldReceive('route')->once()->withArgs(
            fn ($user, string $url) => $url === 'https://andisclippers.com.au'
        )->andReturn(new RouteResult('seeded', 'website', 'r1', 'link'));
    });

    app()->call([new BioMentionChainsJob((string) $user->id, data_get($user->integrationConnections()->first()?->payload, 'bioMentions', [])), 'handle']);
});

it('serves a repeated mention from the global cache — one scrape across users', function () {
    $one = bmcUser('barberone', [['handle' => 'andisco_aunz', 'label' => 'ambassador', 'type' => 'brand']]);
    $two = bmcUser('barbertwo', [['handle' => 'andisco_aunz', 'label' => 'ambassador', 'type' => 'brand']]);

    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldReceive('fetchProfileResult')
        ->once()->andReturn(bmcProfile(['fullName' => 'Andis Australia', 'externalUrl' => 'https://andisclippers.com.au'])));
    $this->mock(LinkRouter::class, fn ($m) => $m->shouldReceive('route')->twice()->andReturn(new RouteResult('seeded', 'website', 'r1', 'link')));

    app()->call([new BioMentionChainsJob((string) $one->id, [['handle' => 'andisco_aunz', 'label' => 'ambassador', 'type' => 'brand']]), 'handle']);
    app()->call([new BioMentionChainsJob((string) $two->id, [['handle' => 'andisco_aunz', 'label' => 'ambassador', 'type' => 'brand']]), 'handle']);
});

it('does nothing for a business account or when there are no mentions', function () {
    $none = bmcUser('nomentions', []);
    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldNotReceive('fetchProfileResult'));
    app()->call([new BioMentionChainsJob((string) $none->id), 'handle']);

    $biz = bmcUser('bizacct', [['handle' => 'x_venue', 'label' => 'Owner', 'type' => 'workplace']]);
    $biz->forceFill(['account_type' => 'business'])->save();
    app()->call([new BioMentionChainsJob((string) $biz->id, [['handle' => 'x_venue', 'label' => 'Owner', 'type' => 'workplace']]), 'handle']);
});

it('falls back from the one hop to the chained scrape and retries with the handle-derived name (star_barber_darwin shape)', function () {
    $user = bmcUser('emdinon2', [
        ['handle' => 'star_barber_darwin', 'label' => 'Owner @star_barber_darwin.', 'type' => 'workplace'],
    ]);

    // The venue account's own fullName names the BARBERS, not the venue.
    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldReceive('fetchProfileResult')
        ->once()->andReturn(bmcProfile(['fullName' => 'Em|Holley|Finley'])));

    $this->mock(FreshaWorkplaceLinker::class, function ($m) {
        // 4-EXT one hop first: bare handle-name, no corroborators, misses.
        $m->shouldReceive('attempt')->once()
            ->withArgs(fn ($u, array $v) => $v['name'] === 'Star Barber Darwin' && $v['postcode'] === null)
            ->andReturn(['outcome' => 'no_match', 'placeId' => null, 'reason' => 'no_confident_match']);
        // Then the scrape-evidenced ladder, exactly as before.
        $m->shouldReceive('attempt')->once()
            ->withArgs(fn ($u, array $v) => $v['name'] === 'Em|Holley|Finley')
            ->andReturn(['outcome' => 'no_match', 'placeId' => null, 'reason' => 'no_confident_match']);
        $m->shouldReceive('attempt')->once()
            ->withArgs(fn ($u, array $v) => $v['name'] === 'Star Barber Darwin' && $v['postcode'] === '0800')
            ->andReturn(['outcome' => 'connected', 'placeId' => 'p9', 'reason' => null]);
    });

    app()->call([new BioMentionChainsJob((string) $user->id, [['handle' => 'star_barber_darwin', 'label' => 'Owner', 'type' => 'workplace']]), 'handle']);
});

// ── Item 9a: state-gated Fresha precedence ──────────────────────────────────

it('defers in 30s steps while an auto Fresha connect is still in flight, touching nothing', function () {
    $user = bmcUser('deferme', [
        ['handle' => 'some_studio', 'label' => 'Owner', 'type' => 'workplace'],
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://fresha.com/x', 'connectMode' => 'auto'],
        'is_active' => false,
    ]);

    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldNotReceive('fetchProfileResult'));
    $this->mock(FreshaWorkplaceLinker::class, fn ($m) => $m->shouldNotReceive('attempt'));

    app()->call([new BioMentionChainsJob((string) $user->id, [['handle' => 'some_studio', 'label' => 'Owner', 'type' => 'workplace']]), 'handle']);

    Queue::assertPushed(BioMentionChainsJob::class, fn (BioMentionChainsJob $job) => $job->deferrals === 1
        && $job->userId === (string) $user->id
        && (int) $job->delay === BioMentionChainsJob::FRESHA_RECHECK_SECONDS);
});

it('runs anyway once the deferral cap is reached — waiting forever was never the contract', function () {
    $user = bmcUser('capreached', [
        ['handle' => 'the_studio', 'label' => 'Owner', 'type' => 'workplace'],
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://fresha.com/x', 'connectMode' => 'auto'],
        'is_active' => false,
    ]);

    $this->mock(FreshaWorkplaceLinker::class, function ($m) {
        $m->shouldReceive('attempt')->once()
            ->withArgs(fn ($u, array $v) => $v['name'] === 'The Studio')
            ->andReturn(['outcome' => 'connected', 'placeId' => 'p3', 'reason' => null]);
    });
    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldNotReceive('fetchProfileResult'));

    app()->call([new BioMentionChainsJob(
        (string) $user->id,
        [['handle' => 'the_studio', 'label' => 'Owner', 'type' => 'workplace']],
        BioMentionChainsJob::MAX_FRESHA_DEFERRALS,
    ), 'handle']);

    Queue::assertNotPushed(BioMentionChainsJob::class);
});

// ── Item 4: multiple workplace candidates, evidence-ordered ─────────────────

it('tries every workplace candidate in evidence order — the corroboration gate decides, not the classifier (ryanfitzsimonshair shape)', function () {
    // Ryan's bio order: akro.studio, akrorecclub, orka.bali — all nominated.
    // Evidence order puts the two venue-shaped handles first; akro connects
    // on its one-hop, so nothing later is attempted and no scrape is spent.
    $user = bmcUser('ryanshape', [
        ['handle' => 'orka.bali', 'label' => '', 'type' => 'workplace'],
        ['handle' => 'akro.studio', 'label' => '', 'type' => 'workplace'],
    ]);

    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldNotReceive('fetchProfileResult'));
    $this->mock(FreshaWorkplaceLinker::class, function ($m) {
        // Venue-shaped handle ranks ABOVE the bio-order-first orka.bali.
        $m->shouldReceive('attempt')->once()
            ->withArgs(fn ($u, array $v) => $v['name'] === 'Akro Studio')
            ->andReturn(['outcome' => 'connected', 'placeId' => 'p7', 'reason' => null]);
    });

    app()->call([new BioMentionChainsJob((string) $user->id, [
        ['handle' => 'orka.bali', 'label' => '', 'type' => 'workplace'],
        ['handle' => 'akro.studio', 'label' => '', 'type' => 'workplace'],
    ]), 'handle']);
});

it('moves to the next candidate when the first cannot corroborate anywhere', function () {
    $user = bmcUser('twovenues', [
        ['handle' => 'first.studio', 'label' => '', 'type' => 'workplace'],
        ['handle' => 'second.salon', 'label' => '', 'type' => 'workplace'],
    ]);

    $this->mock(InstagramScraper::class, function ($m) {
        // Only candidate ONE pays a scrape (its one-hop missed); candidate
        // two connects on its own one-hop before any scrape — the 4-EXT
        // saving, visible in the count.
        $m->shouldReceive('fetchProfileResult')->once()
            ->andReturn(bmcProfile(['fullName' => null, 'biography' => 'no evidence here']));
    });
    $this->mock(FreshaWorkplaceLinker::class, function ($m) {
        // One hop ('First Studio') AND the scrape leg's fallback venue name
        // ('first studio' — venueFrom() lowercases nothing but also ucwords
        // nothing) both miss.
        $m->shouldReceive('attempt')
            ->withArgs(fn ($u, array $v) => strcasecmp((string) $v['name'], 'First Studio') === 0)
            ->andReturn(['outcome' => 'no_match', 'placeId' => null, 'reason' => 'no_confident_match']);
        $m->shouldReceive('attempt')->once()
            ->withArgs(fn ($u, array $v) => $v['name'] === 'Second Salon' && $v['postcode'] === null)
            ->andReturn(['outcome' => 'connected', 'placeId' => 'p8', 'reason' => null]);
    });

    app()->call([new BioMentionChainsJob((string) $user->id, [
        ['handle' => 'first.studio', 'label' => '', 'type' => 'workplace'],
        ['handle' => 'second.salon', 'label' => '', 'type' => 'workplace'],
    ]), 'handle']);
});
