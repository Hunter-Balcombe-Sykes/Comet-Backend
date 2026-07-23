<?php

use App\Services\Auth\TokenRevocationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

it('is registered on the daily scheduler', function () {
    // A dropped Schedule::command() line silently stops the backstop with no
    // other test failure — guard it explicitly.
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) ($e->command ?? ''), 'sessions:reconcile-tracked'));

    expect($event)->not->toBeNull('sessions:reconcile-tracked is not registered in the scheduler');
    expect($event->expression)->toBe('10 3 * * *');
});

// sessions:reconcile-tracked SCANs every tracked-session set and drops members
// Supabase no longer considers live. The live lookup is Postgres-only, so we bind
// a TokenRevocationService whose fetchLiveSessionIds() is canned per user — and
// fails open (returns null) for every OTHER user, so this test's global SCAN can
// never disturb a concurrent Redis suite's keys.

it('prunes dead members across users and skips a user whose live lookup fails', function () {
    $userA = 'user-'.Str::uuid();
    $userB = 'user-'.Str::uuid();
    $aLive = 'sess-'.Str::uuid();
    $aDead = 'sess-'.Str::uuid();
    $bX = 'sess-'.Str::uuid();
    $bY = 'sess-'.Str::uuid();

    $svc = new class($userA, $userB, $aLive) extends TokenRevocationService
    {
        public function __construct(private string $userA, private string $userB, private string $aLive) {}

        protected function fetchLiveSessionIds(string $userId): ?array
        {
            return match ($userId) {
                $this->userA => [$this->aLive],                     // aDead is not live -> pruned
                $this->userB => throw new RuntimeException('boom'), // lookup fails -> user skipped
                default => null,                                    // fail-open for any other (parallel) user
            };
        }
    };
    $this->app->instance(TokenRevocationService::class, $svc);

    Redis::sadd('auth:user-sessions:'.$userA, $aLive, $aDead);
    Redis::sadd('auth:user-sessions:'.$userB, $bX, $bY);

    $this->artisan('sessions:reconcile-tracked')->assertExitCode(0);

    // userA: dead member pruned, live member kept.
    expect((bool) Redis::sismember('auth:user-sessions:'.$userA, $aLive))->toBeTrue();
    expect((bool) Redis::sismember('auth:user-sessions:'.$userA, $aDead))->toBeFalse();

    // userB: live lookup failed -> set left untouched (fail-open per user).
    expect((int) Redis::scard('auth:user-sessions:'.$userB))->toBe(2);
});

it('escalates to Nightwatch when the live lookup is unavailable for every user', function () {
    // A run that reaches users but reconciles NONE means the lookup is
    // systemically down (broken grant/RPC) — the backstop is silently dead, so
    // it must alert. On the SQLite test driver the real fetchLiveSessionIds()
    // returns null for every user, which is exactly that systemic-outage state.
    Exceptions::fake();
    Redis::sadd('auth:user-sessions:user-'.Str::uuid(), 'sess-'.Str::uuid());

    $this->artisan('sessions:reconcile-tracked')->assertExitCode(0);

    Exceptions::assertReported(
        fn (RuntimeException $e) => str_contains($e->getMessage(), 'live-session lookup unavailable')
    );
});
