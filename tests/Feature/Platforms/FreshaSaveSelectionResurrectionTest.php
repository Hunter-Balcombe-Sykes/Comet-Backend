<?php

// Fix A (whole-branch review pt.2): saveSelection() reads the saved URL,
// re-scrapes for up to 20s UNLOCKED, then acquires only the per-platform
// 'fresha' lock before writing. forget() deletes the connection row (and
// soft-deletes synced services) under bookingXorLock alone — a key
// saveSelection() never takes — so a forget() landing inside that 20s window
// used to go unnoticed: writeConnection()'s updateOrCreate would resurrect
// the row, and projector->sync() would resurrect the tombstoned
// deleted_origin='sync' service rows, right alongside whatever booking
// provider (e.g. Square) connected in the meantime. The fix re-checks the
// connection still exists immediately inside the lock, before any write,
// mirroring FreshaConnectFetch.php's identical guard for the async path.
//
// CACHE_STORE=array in phpunit.xml — Cache::lock() here is real, but this
// test doesn't rely on lock contention; it simulates the race by having the
// scraper mock itself perform the "concurrent" forget() side effect, since
// PHP test execution is single-threaded and the scrape genuinely runs before
// any lock is taken.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    // Fix round 1, Finding 3: FreshaSelectionResource's services[] now reads
    // content.* for real inside an authenticated request (POST /selection
    // below constructs it with a real user id) — without this the query
    // 500s on "no such table: content.items".
    setupContentTables();
    shimPgAdvisoryLockForSqlite();
});

function freshaSaveSelectionRaceUser(string $h): User
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

it('does not resurrect the fresha connection when forget() lands during saveSelection()\'s scrape window', function () {
    $user = freshaSaveSelectionRaceUser('frsel1');

    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/acme', 'selection' => null],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    // A previously-synced service — forget() soft-deletes rows like this with
    // deleted_origin='sync'; FreshaServiceProjector::sync() would otherwise
    // resurrect it, which is exactly what must NOT happen here.
    $service = createServiceFor($user, [
        'title' => 'Haircut',
        'source' => 'fresha',
        'is_manual' => false,
        'external_id' => 'ext-1',
        'is_active' => true,
        'sort_order' => 0,
    ]);

    // projector->sync() must never be reached once the connection is gone.
    $this->mock(FreshaServiceProjector::class, fn ($m) => $m->shouldNotReceive('sync'));

    $this->mock(FreshaScraper::class, function ($m) use ($connection, $service) {
        // fetchLocation is the first scraper call inside saveSelection()'s
        // FetchBudget::open() closure — stand in for a concurrent forget()
        // landing mid-scrape by performing its exact teardown here.
        $m->shouldReceive('fetchLocation')->andReturnUsing(function () use ($connection, $service) {
            $connection->delete();

            $service->deleted_origin = 'sync';
            $service->saveQuietly();
            $service->delete();

            return ['name' => 'Acme'];
        });
        $m->shouldReceive('extractTeam')->andReturn([
            ['employeeId' => 'e1', 'displayName' => 'Jo', 'jobTitle' => null, 'avatarUrl' => null, 'rating' => null],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('acme');
        $m->shouldReceive('fetchEmployeeServices')->andReturn([
            ['serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null, 'price' => '$50', 'priceValue' => null, 'currency' => null, 'category' => 'Cuts', 'hasVariants' => false],
        ]);
        $m->shouldReceive('extractStoreName')->andReturn('Acme');
    });

    actingAsUser($user)->postJson('/api/platforms/fresha/selection', ['employeeId' => 'e1'])
        ->assertStatus(404);

    // Not resurrected: still soft-deleted, no new row.
    $freshConnection = IntegrationConnection::withTrashed()
        ->where('user_id', $user->id)
        ->where('platform', 'fresha')
        ->first();
    expect($freshConnection)->not->toBeNull();
    expect($freshConnection->deleted_at)->not->toBeNull();

    // The tombstoned service was never resurrected by a sync() that never ran.
    expect(Service::where('user_id', $user->id)->where('source', 'fresha')->whereNull('deleted_at')->count())->toBe(0);
    $freshService = Service::withTrashed()->find($service->id);
    expect($freshService->deleted_at)->not->toBeNull();
});

// pt.3 fix: the case above only exercises a forget() landing during the
// UNLOCKED scrape (caught by the existence re-check). The residual half-fix bug
// was a forget()+Square-connect landing in the window BETWEEN the re-check and
// the write — while saveSelection holds its lock. The pt.2 guard held only the
// per-platform 'fresha' key there; forget()/clearBooking() delete under
// bookingXorLock, a DIFFERENT key, so nothing stopped that interleaving.
//
// PHP test execution is single-threaded, so we cannot literally run a
// concurrent forget() mid-write. Instead we prove the STRUCTURAL property that
// makes the interleaving impossible: at the exact point the pt.2 guard missed
// (inside projector->sync(), after the re-check, before the write),
// saveSelection now holds the SAME bookingXorLock key forget() takes. The probe
// (Cache::lock(...)->get(), the BookingXorLockTest convention) stands in for
// forget()'s own acquisition: it fails, so a lock-respecting forget() would
// block and could NOT delete the row before the write completes — the row
// survives and the write returns 200.
//
// What this proves: saveSelection and forget serialise on one key across the
// whole check→sync→write span, closing the TOCTOU. What it does NOT prove: real
// multi-process Redis lock semantics (array store here) or wall-clock ordering
// under genuine contention — that is covered structurally by the shared key.
it('holds the booking-XOR lock across saveSelection\'s re-check → sync → write span, so a lock-respecting forget() cannot interleave and resurrect the row', function () {
    $user = freshaSaveSelectionRaceUser('frsel2');
    // Hard-coded, NOT read from CacheKeyGenerator — the whole point is that
    // saveSelection and forget() derive the identical key.
    $key = "platforms:booking-xor:lock:{$user->id}";

    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/acme', 'selection' => null],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    // Probe fires at the point the pt.2 guard missed: inside sync(), AFTER the
    // existence re-check and BEFORE writeConnection(). If saveSelection holds
    // bookingXorLock here, this acquisition (forget()'s own) must fail.
    $lockHeldDuringWriteSpan = false;
    $this->mock(FreshaServiceProjector::class, function ($m) use (&$lockHeldDuringWriteSpan, $key) {
        $m->shouldReceive('sync')->once()->andReturnUsing(function () use (&$lockHeldDuringWriteSpan, $key) {
            $probe = Cache::lock($key, 5);
            $lockHeldDuringWriteSpan = ! $probe->get();
            if (! $lockHeldDuringWriteSpan) {
                // MANDATORY release — a wrongly-acquired ArrayLock left held
                // would deadlock the write this same request is about to do.
                $probe->release();
            }

            return ['services' => [], 'hiddenServiceIds' => [], 'raw' => []];
        });
    });

    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('fetchLocation')->andReturn(['name' => 'Acme']);
        $m->shouldReceive('extractTeam')->andReturn([
            ['employeeId' => 'e1', 'displayName' => 'Jo', 'jobTitle' => null, 'avatarUrl' => null, 'rating' => null],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('acme');
        $m->shouldReceive('fetchEmployeeServices')->andReturn([
            ['serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null, 'price' => '$50', 'priceValue' => null, 'currency' => null, 'category' => 'Cuts', 'hasVariants' => false],
        ]);
        $m->shouldReceive('extractStoreName')->andReturn('Acme');
    });

    actingAsUser($user)->postJson('/api/platforms/fresha/selection', ['employeeId' => 'e1'])
        ->assertStatus(200);

    // The lock was genuinely held across the re-check → sync → write span: a
    // forget() on the same key could not have deleted the row in between.
    expect($lockHeldDuringWriteSpan)->toBeTrue();

    // And the row survived + was written (not deleted, not resurrected-from-nothing).
    $fresh = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->first();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
    expect($fresh->payload['selection']['employee']['employeeId'])->toBe('e1');
});
