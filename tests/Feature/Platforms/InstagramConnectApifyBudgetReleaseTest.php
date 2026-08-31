<?php

// #FU-4b: InstagramController::connect() claims a single Apify budget slot
// via guardApifyBudget() BEFORE the placeholder-write lock, then had two
// exits that spent the slot on nothing — a Cache::lock() timeout (423) and an
// AuthorizationException from either authorizeForUser() call inside the lock
// closure (403) — with no release on either. Every retry of a stuck 423/403
// burned one of the 600/day instagram slots for no scrape. Mirrors the
// $connection-sentinel + finally fix already shipped for
// GoogleBusinessAutoSync::dispatchInstagram() (#FU-4, 2d0795983).
//
// CACHE_STORE=array in phpunit.xml (AutoSyncSeederLockTest.php's header note
// applies here too): Cache::lock() is a real in-process ArrayLock so the
// lock-timeout test's block(5, ...) wait is genuine wall time, and
// DailyCounterClaim's counters live on the SAME array cache store the
// controller's ApifyBudget reads — so remaining()/raw Cache::get() reads
// prove real release behaviour, driver-independent of the pgsql-vs-sqlite
// split this repo's tests otherwise run under (the budget counters are a
// cache concern, not a DB one).

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Policies\IntegrationConnectionPolicy;
use App\Services\Cache\ApifyBudget;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function igBudgetUser(string $h): User
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

// Gate::policy() resolves a string via the container, so this is registered by
// class name, not by instance — same technique as PoolBorrowedMediaPinTest's
// DenyPinContentItemPolicyForSec1Test. There is no real fixture shape that
// makes the real IntegrationConnectionPolicy deny 'create' for a brand-new
// user's own skeleton (ownerMatches always passes, and the pending-deletion
// gate is already preempted by the EnforcePendingDeletionReadOnly route
// middleware before the controller runs) — this subclass proves the SEAM
// (an AuthorizationException from inside the lock closure releases the
// budget exactly once) independent of whether a real denial can occur today.
class DenyCreateIntegrationConnectionPolicyForFu4bTest extends IntegrationConnectionPolicy
{
    public function create(User $actor, Model $resource): bool|Response
    {
        return Response::deny('denied for #FU-4b test', 403);
    }
}

// ── Q1: success must NOT release ──────────────────────────────────────────

it('a successful connect consumes exactly one slot and does not release it', function () {
    Queue::fake();
    config(['services.apify.token' => 'test-token']);
    config()->set('partna.limits.apify.global_daily_cap', 5);
    config()->set('partna.limits.apify.actors.instagram', 2);
    $budget = app(ApifyBudget::class);
    expect($budget->remaining('instagram'))->toBe(2);

    $user = igBudgetUser('igbudsucc1');

    actingAsUser($user)
        ->postJson('/api/platforms/instagram/connect', ['username' => 'someuser'])
        ->assertStatus(202);

    Queue::assertPushed(InstagramConnectJob::class);
    // The claim must stand — releasing here would make the 600/day cap unbounded.
    expect($budget->remaining('instagram'))->toBe(1);
});

// ── Q2: denial must not be double-released ────────────────────────────────

it('a 429 budget denial neither spends nor credits a slot on repeat', function () {
    config(['services.apify.token' => 'test-token']);
    config()->set('partna.limits.apify.global_daily_cap', 5);
    // Actor cap 0: guardApifyBudget()'s tryClaim() always denies before the
    // try/finally block is ever entered, so connect()'s release() call must
    // never fire on this path.
    config()->set('partna.limits.apify.actors.instagram', 0);

    $user = igBudgetUser('igbud429');
    $date = now()->format('Y-m-d');
    $globalKey = CacheKeyGenerator::apifyGlobalDailyLimit($date);
    $actorKey = CacheKeyGenerator::apifyActorDailyLimit('instagram', $date);

    foreach (range(1, 3) as $_) {
        actingAsUser($user)
            ->postJson('/api/platforms/instagram/connect', ['username' => 'someuser'])
            ->assertStatus(429);
    }

    // tryClaim() nets each denial back to zero itself (claims global, then
    // releases it when the actor cap denies). If connect()'s new finally ever
    // fired an EXTRA release on this path, one of these counters would be
    // driven negative — a phantom credit that inflates the cap above its
    // configured ceiling on the next real claim.
    expect((int) Cache::get($globalKey, 0))->toBe(0);
    expect((int) Cache::get($actorKey, 0))->toBe(0);
});

// ── Q3a: lock timeout must release exactly once ───────────────────────────

it('a lock-timeout 423 releases the claimed slot exactly once', function () {
    config(['services.apify.token' => 'test-token']);
    config()->set('partna.limits.apify.global_daily_cap', 5);
    config()->set('partna.limits.apify.actors.instagram', 2);
    $budget = app(ApifyBudget::class);
    expect($budget->remaining('instagram'))->toBe(2);

    $user = igBudgetUser('igbud423');

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('instagram', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)
            ->postJson('/api/platforms/instagram/connect', ['username' => 'someuser'])
            ->assertStatus(423);
    } finally {
        $lock->release();
    }

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->exists())->toBeFalse();
    // Released exactly once: back to the pre-claim headroom, not below it
    // (double release) and not still down one (no release / the original bug).
    expect($budget->remaining('instagram'))->toBe(2);
});

// ── Q3b: an authorize() throw must release exactly once ───────────────────

it('an AuthorizationException from inside the lock closure releases the claimed slot exactly once', function () {
    Gate::policy(IntegrationConnection::class, DenyCreateIntegrationConnectionPolicyForFu4bTest::class);
    Queue::fake();
    config(['services.apify.token' => 'test-token']);
    config()->set('partna.limits.apify.global_daily_cap', 5);
    config()->set('partna.limits.apify.actors.instagram', 2);
    $budget = app(ApifyBudget::class);
    expect($budget->remaining('instagram'))->toBe(2);

    $user = igBudgetUser('igbud403');

    actingAsUser($user)
        ->postJson('/api/platforms/instagram/connect', ['username' => 'someuser'])
        ->assertStatus(403);

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->exists())->toBeFalse();
    Queue::assertNotPushed(InstagramConnectJob::class);
    // Released exactly once — same bar as the lock-timeout case above.
    expect($budget->remaining('instagram'))->toBe(2);
});
