<?php

use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\FeatureAvailability\UserFeatureAvailability;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * FeatureAvailability::for() must fail OPEN when its cache store is unreachable.
 *
 * Drill 03 (2026-08-05) found `GET /api/site` returning a raw 500 during a Redis
 * outage whenever the rate limiter did not preempt it. Root cause: `for()` wraps
 * its `rememberLocked` call in a try/catch, but the two `Cache::get()` calls that
 * run BEFORE that try were unguarded, so a dead store threw straight past the
 * fail-open handler.
 *
 * In production today the limiter usually masks this as a 503 — which is why it
 * had never been seen as a 500. That masking is not a guarantee: the five
 * fail-open limiters (`public-site`, `public-profile`, `analytics`,
 * `analytics-click`, `health-check`) do NOT throw, and any route whose limiter
 * joins that list, or any request with throttling disabled, reaches this code
 * with a live exception.
 *
 * The contract: absence == enabled. A cache outage must degrade to
 * "everything available", never to a 500.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    FeatureAvailability::flush();
});

/** Swap the cache repository for one whose reads throw like a dead Redis. */
function bindThrowingCacheStore(): void
{
    $throwing = new class implements CacheRepository
    {
        private function boom(): never
        {
            throw new RedisException('Connection refused');
        }

        public function get($key, $default = null): mixed
        {
            $this->boom();
        }

        public function put($key, $value, $ttl = null): bool
        {
            $this->boom();
        }

        public function add($key, $value, $ttl = null): bool
        {
            $this->boom();
        }

        public function increment($key, $value = 1)
        {
            $this->boom();
        }

        public function decrement($key, $value = 1)
        {
            $this->boom();
        }

        public function forever($key, $value): bool
        {
            $this->boom();
        }

        public function forget($key): bool
        {
            $this->boom();
        }

        public function has($key): bool
        {
            $this->boom();
        }

        public function many(array $keys): array
        {
            $this->boom();
        }

        public function putMany(array $values, $ttl = null): bool
        {
            $this->boom();
        }

        public function pull($key, $default = null): mixed
        {
            $this->boom();
        }

        public function remember($key, $ttl, Closure $callback): mixed
        {
            $this->boom();
        }

        public function sear($key, Closure $callback): mixed
        {
            $this->boom();
        }

        public function rememberForever($key, Closure $callback): mixed
        {
            $this->boom();
        }

        public function missing($key): bool
        {
            $this->boom();
        }

        public function clear(): bool
        {
            $this->boom();
        }

        public function delete($key): bool
        {
            $this->boom();
        }

        public function deleteMultiple($keys): bool
        {
            $this->boom();
        }

        public function getMultiple($keys, $default = null): iterable
        {
            $this->boom();
        }

        public function setMultiple($values, $ttl = null): bool
        {
            $this->boom();
        }

        public function set($key, $value, $ttl = null): bool
        {
            $this->boom();
        }

        public function getStore()
        {
            $this->boom();
        }
    };

    Cache::swap($throwing);
}

it('fails open instead of throwing when the cache store is unreachable', function () {
    $pro = createTenant('feat-avail-outage');

    bindThrowingCacheStore();

    // Absence == enabled: an empty override set means "everything available".
    $availability = FeatureAvailability::for($pro);

    expect($availability)->toBeInstanceOf(UserFeatureAvailability::class);
});

it('serves GET /api/site rather than 500ing when the cache store is unreachable', function () {
    // The route-level proof. This is the exact request drill 03 saw return 500.
    $pro = createTenant('feat-avail-site');   // creates the site.sites row too

    bindThrowingCacheStore();

    actingAsUser($pro)
        ->getJson('/api/site')
        ->assertOk();
});
