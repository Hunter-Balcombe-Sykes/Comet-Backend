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

it('fills an empty workplace through the linker from a workplace mention (emdinonhair shape)', function () {
    $user = bmcUser('emdinonhair', [
        ['handle' => 'star_barber_darwin', 'label' => 'Owner @star_barber_darwin.', 'type' => 'workplace'],
    ]);

    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldReceive('fetchProfileResult')
        ->once()->with('star_barber_darwin', $user->id)->andReturn(bmcProfile()));

    $this->mock(FreshaWorkplaceLinker::class, function ($m) {
        $m->shouldReceive('attempt')->once()->withArgs(function ($user, array $venue) {
            return $venue['name'] === 'Star Barber Darwin'
                && $venue['postcode'] === '0800'
                && is_string($venue['street'])
                && str_contains($venue['street'], 'Star Village Arcade');
        })->andReturn(['outcome' => 'connected', 'placeId' => 'p1', 'reason' => null]);
    });

    app()->call([new BioMentionChainsJob((string) $user->id), 'handle']);
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

    app()->call([new BioMentionChainsJob((string) $user->id), 'handle']);
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

    app()->call([new BioMentionChainsJob((string) $user->id), 'handle']);
});

it('serves a repeated mention from the global cache — one scrape across users', function () {
    $one = bmcUser('barberone', [['handle' => 'andisco_aunz', 'label' => 'ambassador', 'type' => 'brand']]);
    $two = bmcUser('barbertwo', [['handle' => 'andisco_aunz', 'label' => 'ambassador', 'type' => 'brand']]);

    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldReceive('fetchProfileResult')
        ->once()->andReturn(bmcProfile(['fullName' => 'Andis Australia', 'externalUrl' => 'https://andisclippers.com.au'])));
    $this->mock(LinkRouter::class, fn ($m) => $m->shouldReceive('route')->twice()->andReturn(new RouteResult('seeded', 'website', 'r1', 'link')));

    app()->call([new BioMentionChainsJob((string) $one->id), 'handle']);
    app()->call([new BioMentionChainsJob((string) $two->id), 'handle']);
});

it('does nothing for a business account or when there are no mentions', function () {
    $none = bmcUser('nomentions', []);
    $this->mock(InstagramScraper::class, fn ($m) => $m->shouldNotReceive('fetchProfileResult'));
    app()->call([new BioMentionChainsJob((string) $none->id), 'handle']);

    $biz = bmcUser('bizacct', [['handle' => 'x_venue', 'label' => 'Owner', 'type' => 'workplace']]);
    $biz->forceFill(['account_type' => 'business'])->save();
    app()->call([new BioMentionChainsJob((string) $biz->id), 'handle']);
});
