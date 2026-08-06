<?php

// SIGNUP-1 backfill. Dry run is the DEFAULT; writing requires --apply. Writes
// bypass Eloquent (no observer, so no blanket SyncSubdomainToKvJob into the KV
// namespace that dev and prod SHARE) and therefore bust cache keys explicitly.

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupHandleAliasesTable();
    Queue::fake();
});

/** A diverged row: handle_lc says one thing, sites.subdomain another. */
function divergedUser(string $handle, string $subdomain): User
{
    $user = User::factory()->create(['handle' => $handle, 'handle_lc' => $handle]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => $subdomain]);

    // Site::factory fires SiteObserver (cache purge + KV sync). Re-fake so the
    // assertions below see only what the COMMAND dispatched.
    Queue::fake();

    return $user;
}

it('defaults to a dry run and writes nothing', function () {
    $user = divergedUser('errols', 'errol-s');

    $this->artisan('partna:converge-site-subdomains')
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('old subdomain: errol-s')
        ->expectsOutputToContain('new subdomain: errols')
        ->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('subdomain'))
        ->toBe('errol-s');
    Queue::assertNothingPushed();
});

it('converges the row and busts both old and new cache keys under --apply', function () {
    $user = divergedUser('errols', 'errol-s');

    Cache::put(CacheKeyGenerator::publicSitePayload('errol-s'), ['stale' => true], 600);
    Cache::put(CacheKeyGenerator::publicSitePayload('errols'), ['stale' => true], 600);
    Cache::put(CacheKeyGenerator::professionalModel($user->id), ['stale' => true], 600);

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('APPLY')
        ->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('subdomain'))
        ->toBe('errols')
        ->and(Cache::get(CacheKeyGenerator::publicSitePayload('errol-s')))->toBeNull()
        ->and(Cache::get(CacheKeyGenerator::publicSitePayload('errols')))->toBeNull()
        ->and(Cache::get(CacheKeyGenerator::professionalModel($user->id)))->toBeNull();
});

it('does not touch KV for a user with no active handle aliases', function () {
    divergedUser('errols', 'errol-s');

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('KV: none')
        ->assertSuccessful();

    Queue::assertNotPushed(SyncSubdomainToKvJob::class);
});

// The alias KV entry embeds `redirect: <partna_url>`, and partna_url is derived
// from sites.subdomain via compute_user_url() — so it, and only it, changes.
it('dispatches a KV sync only for a user holding an active handle alias', function () {
    $user = divergedUser('errols', 'errol-s');
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'handle' => 'errol-s-old',
        'expires_at' => now()->addDays(90),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('errol-s-old')
        ->assertSuccessful();

    Queue::assertPushed(SyncSubdomainToKvJob::class, fn ($job) => $job->userId === $user->id);
});

it('skips a row whose target subdomain is held by another site', function () {
    $blocker = User::factory()->create(['handle' => 'blocker', 'handle_lc' => 'blocker']);
    Site::factory()->create(['user_id' => $blocker->id, 'subdomain' => 'errols']);
    $user = divergedUser('errols', 'errol-s');

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('SKIP')
        ->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('subdomain'))
        ->toBe('errol-s');
});

// A data repair must not spend the user's 30-day rename cooldown
// (RenameSubdomainAction reads subdomain_changed_at to enforce it).
it('leaves subdomain_changed_at untouched', function () {
    $user = divergedUser('errols', 'errol-s');
    $changedAt = now()->subDays(3)->startOfSecond();
    DB::connection('pgsql')->table('site.sites')
        ->where('user_id', $user->id)
        ->update(['subdomain_changed_at' => $changedAt]);

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])->assertSuccessful();

    $row = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->first();
    expect($row->subdomain)->toBe('errols')
        ->and(Carbon::parse($row->subdomain_changed_at)->equalTo($changedAt))->toBeTrue();
});

// Converging onto a reserved name would park a site on a subdomain that
// ResolvesSubdomainFromHost rejects — the repair there is a handle rename by a human.
it('skips a row whose target subdomain is reserved', function () {
    $user = divergedUser('support', 'tobiasindarwin-fableqa1');

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('reserved subdomain')
        ->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('subdomain'))
        ->toBe('tobiasindarwin-fableqa1');
});

// core_site_subdomain_aliases_subdomain_lower_unique is GLOBAL, so an active alias
// makes the target unusable even though site.sites has no row for it.
it('skips a row whose target subdomain is held by an active alias, but not an expired one', function () {
    $holder = User::factory()->create(['handle' => 'holder', 'handle_lc' => 'holder']);
    $holderSite = Site::factory()->create(['user_id' => $holder->id, 'subdomain' => 'holder']);
    $user = divergedUser('errols', 'errol-s');

    $aliasId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id' => $aliasId,
        'site_id' => $holderSite->id,
        'subdomain' => 'errols',
        'expires_at' => now()->addDays(90),
        'created_at' => now(),
    ]);

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('active alias')
        ->assertSuccessful();
    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('subdomain'))
        ->toBe('errol-s');

    // Expire it — the name is back in the pool and the row converges.
    DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->where('id', $aliasId)->update(['expires_at' => now()->subDay()]);

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])->assertSuccessful();
    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('subdomain'))
        ->toBe('errols');
});

it('is idempotent — a second run finds nothing', function () {
    divergedUser('errols', 'errol-s');

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])->assertSuccessful();
    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('Nothing to converge')
        ->assertSuccessful();
});

it('ignores soft-deleted users', function () {
    $user = divergedUser('errols', 'errol-s');
    DB::connection('pgsql')->table('core.users')->where('id', $user->id)->update(['deleted_at' => now()]);

    $this->artisan('partna:converge-site-subdomains')
        ->expectsOutputToContain('0 diverged row(s)')
        ->assertSuccessful();
});
