<?php

namespace App\Providers;

use App\Services\Redis\GuardedPhpRedisConnector;
use App\Services\Redis\RedisRequestBreaker;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;

/**
 * Binds RedisRequestBreaker as a singleton and installs GuardedPhpRedisConnector
 * as the 'phpredis' driver on the 'redis' manager, so every connection this
 * app resolves (cache, cache_locks, app, default, session, queue, and
 * Horizon's alias of default) goes through the guarded classes.
 *
 * TWO INSTALL PATHS, because provider registration order relative to first
 * Redis use isn't guaranteed:
 *   - $this->app->resolving('redis', ...) installs the connector BEFORE the
 *     manager builds its first connection, which is the normal case — this
 *     provider's register() runs during boot, well before any request
 *     resolves 'redis'.
 *   - $this->app->resolved('redis') defensively extends an ALREADY-resolved
 *     manager too, so a future refactor that resolves 'redis' unusually
 *     early (e.g. from another provider's register()) doesn't silently skip
 *     the guard.
 * Both are guarded with `instanceof RedisManager` so a swapped or faked
 * manager (a test double bound over 'redis') can never fatal here — it just
 * doesn't get extended, and falls back to whatever behaviour the fake has.
 */
class RedisBreakerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RedisRequestBreaker::class);

        $app = $this->app;

        $installGuardedConnector = function (RedisManager $manager) use ($app): void {
            // A RESOLVER, not a resolved instance: the guarded connection reads
            // the breaker through this on every command, so rebinding the
            // singleton (a test injecting a fake) can never leave the
            // middleware and the connections looking at different objects.
            $resolveBreaker = fn (): RedisRequestBreaker => $app->make(RedisRequestBreaker::class);

            // RedisManager::extend() does `$callback->bindTo($this, $this)`,
            // rebinding $this inside the closure to the RedisManager — so the
            // resolver is captured via `use` rather than read off $this.
            $manager->extend('phpredis', function () use ($resolveBreaker) {
                return new GuardedPhpRedisConnector($resolveBreaker);
            });
        };

        $this->app->resolving('redis', function ($manager) use ($installGuardedConnector): void {
            if ($manager instanceof RedisManager) {
                $installGuardedConnector($manager);
            }
        });

        if ($this->app->resolved('redis')) {
            $existing = $this->app->make($this->redisAbstract());
            if ($existing instanceof RedisManager) {
                $installGuardedConnector($existing);
            }
        }
    }

    /**
     * Returns the literal 'redis' container key through a non-literal call
     * site, deliberately. `->make('redis')` written inline resolves to a
     * DOUBLE PHPStan trap on this string specifically, not a hypothetical:
     *
     *   1. Larastan's container return-type extension sees this app's own
     *      RedisServiceProvider binding and infers the make() call as
     *      guaranteed RedisManager, so `instanceof RedisManager` right after
     *      it is flagged "always true" — even though the guard exists for a
     *      test/future provider swapping the binding, which PHPStan cannot
     *      and need not see (it only scans app/, never tests/).
     *   2. Container::get()'s own return type is `($id is class-string<T> ?
     *      T : mixed)` — and 'redis' the container KEY happens to also be a
     *      valid (case-insensitive) class-string for the phpredis extension's
     *      global `\Redis` class, so get('redis') infers as `\Redis`, not
     *      RedisManager, making the SAME instanceof "always false" instead.
     *
     * Routing the key through this method means the call site sees a plain
     * `string` return type with no constant-string backing either extension
     * can act on, so PHPStan falls back to `mixed` and the instanceof check
     * stays meaningful — which is the actual, correct static type here: we
     * do not statically know what 'redis' resolves to from this file alone.
     */
    private function redisAbstract(): string
    {
        return 'redis';
    }
}
