<?php

// PWL-5: FreshaController::connect() and saveSelection() wrote the fresha
// connection row (writeConnection) with no lock — a concurrent dashboard
// action (setServiceVisibility, already locked) or another connect/
// saveSelection call could race and clobber the JSONB payload
// (last-write-wins). Mirrors SessionA2LockTest.php's proof shape: pre-acquire
// the SAME key CacheKeyGenerator::platformConnectionLock('fresha', $userId) a
// concurrent writer would hold, then prove each newly-wrapped method
// genuinely contends on it (423, ~5s block) instead of sailing through and
// clobbering the row underneath the "in-use" lock.
//
// CACHE_STORE=array in phpunit.xml, so Cache::lock() here is a real
// in-process ArrayLock — the block(5, ...) wait in withConnectionLock() is
// genuine wall time, not mocked. That wall-clock cost per test IS the proof.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\FreshaScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // saveSelection() projects into site.services (FreshaServiceProjector::sync,
    // per-user pg_advisory_xact_lock) regardless of the lock outcome — needed
    // even though the fix blocks the write, since this call happens before the
    // fix ships and before the closure boundary once it does.
    setupServicesTable();
    shimPgAdvisoryLockForSqlite();
});

function freshaLockUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('fresha connect (writeConnection write in connect) is blocked by a held platform lock and leaves the existing row untouched', function () {
    // Bind the scraper mock BEFORE the connection row is created — stripLocale
    // / fetchMenu run OUTSIDE the lock in connect(), so they fire regardless
    // of the pre-held lock and would otherwise hit the network.
    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('stripLocale')->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->andReturn(['storeName' => 'Ollies', 'team' => [], 'services' => []]);
    });

    $user = freshaLockUser('frlock1');
    $existing = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/original', 'selection' => null],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('fresha', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
            ->assertStatus(423);
    } finally {
        $lock->release();
    }

    // Untouched — proves the write never got past the lock.
    expect($existing->fresh()->payload['url'])->toBe('https://www.fresha.com/a/original');
});

it('fresha saveSelection (writeConnection write in saveSelection) is blocked by a held platform lock and leaves the existing selection untouched', function () {
    // fetchLocation / extractTeam / slugFromUrl / fetchEmployeeServices all run
    // OUTSIDE the lock in saveSelection() (the re-scrape + employee lookup),
    // so they must be mocked regardless of the pre-held lock.
    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('fetchLocation')->andReturn(['name' => 'Acme']);
        $m->shouldReceive('extractTeam')->andReturn([
            ['employeeId' => 'e1', 'displayName' => 'Jo', 'jobTitle' => null, 'avatarUrl' => null, 'rating' => null],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('acme');
        $m->shouldReceive('fetchEmployeeServices')->andReturn([
            ['serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null, 'price' => '$50', 'priceValue' => null, 'currency' => null, 'category' => 'Cuts', 'hasVariants' => false],
        ]);
        // extractStoreName is called building $selection — that step is inside
        // the lock closure post-fix (never reached, blocked at 423), but runs
        // unconditionally pre-fix, so it must be stubbed either way.
        $m->shouldReceive('extractStoreName')->andReturn('Acme');
    });

    $user = freshaLockUser('frlock2');
    $existing = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/acme',
            'selection' => [
                'url' => 'https://www.fresha.com/a/acme',
                'storeName' => 'Acme',
                'mode' => 'employee',
                'employee' => ['employeeId' => 'e0', 'displayName' => 'Original'],
                'services' => [],
                'hiddenServiceIds' => [],
            ],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('fresha', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/fresha/selection', ['employeeId' => 'e1'])
            ->assertStatus(423);
    } finally {
        $lock->release();
    }

    // Untouched — proves the write never got past the lock.
    expect($existing->fresh()->payload['selection']['employee']['employeeId'])->toBe('e0');
});
