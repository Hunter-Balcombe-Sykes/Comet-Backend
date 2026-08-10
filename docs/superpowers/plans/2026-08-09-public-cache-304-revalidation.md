# 304 Revalidation Cache Headers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `AddPublicCacheHeaders` apply the public caching contract to 304 revalidations on allow-listed paths, so the edge stops being told `no-cache, private` every time it revalidates a route we intend to cache.

**Architecture:** One conditional widened in one middleware, pinned by a unit test and by an end-to-end test that exercises the real `bootstrap/app.php` pipeline. The end-to-end test is the important one: the defect exists *only* because of middleware ordering, so a test that constructs the middleware in isolation cannot catch a regression of the original bug.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, SQLite in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-08-09-public-cache-304-revalidation-design.md`
**Evidence:** `scripts/launch-check/k6/results/2026-08-09-full-rerun.md`

## Global Constraints

- **Do not** add `stale-while-revalidate` in this change. Deliberately deferred so re-measurement changes one variable.
- **Do not** change `PARTNA_CACHE_PUBLIC_MAX_AGE` (currently `30` in dev and prod; `900` default applies in tests).
- **Do not** attempt to fix the duplicated `Accept-Encoding` in `Vary`. `mergeVary()` already dedupes case-insensitively; the duplicate is added by the edge, not our code.
- Tests are **Pest**. New tests go in the existing files named below — do not create new test files.
- `composer test -- --filter` is **broken** in this repo. Run a single file with `php artisan test <path>`.
- 4-space indent, LF. Comments explain **why**, not what. No banners, no restatements.
- Commits are the operator's call in this repo. Stage changes and surface the suggested message; **do not push**.

---

### Task 1: Middleware applies the public contract to 304s

**Files:**
- Modify: `app/Http/Middleware/AddPublicCacheHeaders.php:79-80`
- Test: `tests/Unit/AddPublicCacheHeadersTest.php`

**Interfaces:**
- Consumes: `AddPublicCacheHeaders::CACHEABLE_PATH_PREFIXES`, `NO_STORE_PATH_PREFIXES`, `mergeVary()` — all existing, unchanged.
- Produces: no new public API. Behaviour change only: a 304 on an allow-listed public GET now carries `Cache-Control: public, max-age=N, s-maxage=N` and `Vary: Accept-Encoding`.

- [x] **Step 1: Write the failing test**

Append to `tests/Unit/AddPublicCacheHeadersTest.php`:

```php
// RFC 9111 lets a 304 update the stored entry's headers, so a revalidation that
// answers `no-cache, private` un-caches a route we mean to cache. Measured on
// dev as a poison-then-refetch cycle: 19x200 / 18x304 at the origin where 304s
// should dominate. See the 2026-08-09 spec.
it('applies the public cache contract to a 304 revalidation on an allow-listed path', function () {
    $request = Request::create('/api/public/profiles/jane', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('', 304));

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('public')
        ->toContain('max-age=900')
        ->toContain('s-maxage=900');
    expect((string) $response->headers->get('Vary', ''))->toContain('Accept-Encoding');
});

it('does not add public cache headers to a 304 on a non-allow-listed path', function () {
    $request = Request::create('/api/customers', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('', 304));

    expect((string) $response->headers->get('Cache-Control', ''))->not->toContain('public');
});

it('never adds public cache headers to a 304 on a no-store path', function () {
    $request = Request::create('/api/public/unsubscribe/tok123', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('', 304));

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->not->toContain('public');
});
```

Note on expectations: only the **first** test is red before the change. The second and third are guards that pass both before and after — the `NO_STORE_PATH_PREFIXES` loop and the allow-list check already run independently of status. They are here to pin that widening the status check did not widen the *path* scope. Do not "fix" them if they pass immediately; that is the point.

- [x] **Step 2: Run the tests to verify the first one fails**

Run: `php artisan test tests/Unit/AddPublicCacheHeadersTest.php`

Expected: the 304 allow-listed test FAILS — `Cache-Control` is Symfony's default `no-cache, private`, so `toContain('public')` fails. The two guard tests PASS.

If the first test instead passes, stop: the defect is not what the spec describes and the plan needs revisiting.

- [x] **Step 3: Write the implementation**

In `app/Http/Middleware/AddPublicCacheHeaders.php`, replace lines 79-80:

```php
        // Only cache successful GET requests to explicitly allow-listed public paths.
        if ($request->isMethod('GET') && $response->isSuccessful()) {
```

with:

```php
        // A 304 carries the same contract as the 200 it replaces: RFC 9111 lets a
        // 304 update the stored entry's headers, so answering a revalidation with
        // Symfony's default `no-cache, private` un-caches a route we mean to cache.
        $revalidated = $response->getStatusCode() === Response::HTTP_NOT_MODIFIED;

        if ($request->isMethod('GET') && ($response->isSuccessful() || $revalidated)) {
```

`Response` is already imported as `Symfony\Component\HttpFoundation\Response` at the top of the file — no new import.

- [x] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/AddPublicCacheHeadersTest.php`

Expected: PASS, including the pre-existing tests in that file (the 200 path and authenticated path must be unaffected).

- [x] **Step 5: Stage and surface the commit message**

```bash
git add app/Http/Middleware/AddPublicCacheHeaders.php tests/Unit/AddPublicCacheHeadersTest.php
# Suggested message (operator commits):
# fix(cache): 304 revalidations must carry the public cache contract
```

---

### Task 2: End-to-end guard through the real middleware pipeline

**Files:**
- Test: `tests/Feature/Cache/PublicCacheMiddlewareTest.php`

**Interfaces:**
- Consumes: `bindPublicProfileCache(array $returns)` — existing helper at the bottom of that file. Its Mockery mock uses `andReturn(...$returns)`, which returns the supplied values on successive calls and then repeats the last one. The controller calls `rememberLocked()` **twice per request** (handle resolve, then payload), so a test making **two** HTTP requests must supply **four** values or the second request receives the payload array where it expects the resolve array.
- Produces: nothing consumed by later tasks.

- [x] **Step 1: Write the failing test**

Append to `tests/Feature/Cache/PublicCacheMiddlewareTest.php`, before the `// Helper` divider:

```php
/**
 * The ordering guard. AddETagHeaders is appended to the `api` group AFTER
 * AddPublicCacheHeaders, so it unwinds first and converts the response to 304
 * before AddPublicCacheHeaders runs. That is the entire cause of the defect —
 * a unit test on the middleware alone cannot catch a regression of it, because
 * in isolation the middleware was always correct. This test fails if anyone
 * reorders the pipeline back.
 */
it('a conditional GET on the profile route returns 304 still carrying the public cache contract', function () {
    $resolve = ['pro_id' => 'p1', 'site_id' => 's1', 'updated_at_ts' => 123];
    $payload = ['profile' => ['handle' => 'jane']];

    // Four values: two HTTP requests x two rememberLocked() calls each.
    bindPublicProfileCache([$resolve, $payload, $resolve, $payload]);

    $first = $this->getJson('/api/public/profiles/jane');
    $first->assertOk();

    $etag = (string) $first->headers->get('ETag', '');
    expect($etag)->not->toBe('');

    $second = $this
        ->withHeader('If-None-Match', $etag)
        ->getJson('/api/public/profiles/jane');

    $second->assertStatus(304);

    $cacheControl = (string) $second->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('public')
        ->toContain('s-maxage=900');
    expect((string) $second->headers->get('Vary', ''))->toContain('Accept-Encoding');
});
```

- [x] **Step 2: Run the test to verify it fails for the right reason**

Run: `php artisan test tests/Feature/Cache/PublicCacheMiddlewareTest.php`

Expected (if run **before** Task 1): FAIL on `toContain('public')` — the response is a 304 whose `Cache-Control` is `no-cache, private`.

Expected (if run **after** Task 1): PASS.

Diagnostic: if it instead fails on `assertStatus(304)` — i.e. a 200 came back — the conditional request is not matching. Check that `$etag` is non-empty and that `bindPublicProfileCache` received four values; a second request that got the payload array in place of the resolve array produces a different body and therefore a different ETag.

- [x] **Step 3: Run the whole cache test directory**

Run: `php artisan test tests/Feature/Cache/`

Expected: PASS. This catches collateral damage to `CacheKeyspaceConstraintsTest` and the other cache suites.

- [x] **Step 4: Stage and surface the commit message**

```bash
git add tests/Feature/Cache/PublicCacheMiddlewareTest.php
# Suggested message (operator commits):
# test(cache): pin the 304 cache contract through the real middleware pipeline
```

---

### Task 3: Correct the false CDN-topology comment

**Files:**
- Modify: `app/Http/Middleware/AddPublicCacheHeaders.php:41-50`

**Interfaces:** none — comment only, no behaviour change.

- [x] **Step 1: Replace the comment**

Replace the `VARY_BY_PREFIX` docblock body:

```php
    private const VARY_BY_PREFIX = [
        // SEC-1: no shared cache in front of this route honors Vary today —
        // the router Worker passes /api straight through, and this endpoint
        // has no CDN edge cache in the current topology. This header is a
        // forward-guard: if someone later adds a Cloudflare Cache Rule
        // ("Cache Everything") for this path WITHOUT a matching custom Cache
        // Key on X-Site-Subdomain, one tenant's response could be served to
        // another. Not a live exposure today — kept defensively.
        'api/public/site-by-slug' => ['X-Site-Subdomain', 'Accept-Encoding'],
    ];
```

with:

```php
    private const VARY_BY_PREFIX = [
        // SEC-1: there IS a shared cache in front of these routes and it does key
        // on Vary — Laravel Cloud's own Cloudflare honours the s-maxage set below.
        // Measured 2026-08-09: only ~8% of /api/public/profiles requests reach the
        // origin, against ~100% for /api/health (results/2026-08-09-full-rerun.md).
        // So this token is load-bearing, not merely defensive: site-by-slug resolves
        // its tenant from a client-supplied header, and without it one tenant's
        // response could be served to another. Profiles key on the {handle} path
        // segment, which a cache keys on natively, so they need only Accept-Encoding.
        'api/public/site-by-slug' => ['X-Site-Subdomain', 'Accept-Encoding'],
    ];
```

- [x] **Step 2: Verify nothing else asserts the old claim**

Run: `grep -rn "no CDN edge cache\|passes /api straight through" app/ docs/ config/`

Expected: no remaining occurrences in `app/`. Occurrences inside `docs/superpowers/specs/` describing the historical finding are fine and should be left alone.

- [x] **Step 3: Run the middleware tests**

Run: `php artisan test tests/Unit/AddPublicCacheHeadersTest.php tests/Feature/Cache/PublicCacheMiddlewareTest.php`

Expected: PASS (comment-only change; this is a regression check, not a new assertion).

- [x] **Step 4: Stage and surface the commit message**

```bash
git add app/Http/Middleware/AddPublicCacheHeaders.php
# Suggested message (operator commits):
# docs(cache): correct the false "no CDN edge cache" claim with measured evidence
```

---

## Final verification

- [x] Run the full suite: `COMPOSER_PROCESS_TIMEOUT=0 composer test`

Expected: no new failures. Compare against the pre-change baseline — if a failure appears, confirm it is not pre-existing by checking it on the prior commit before attributing it to this change.

- [x] Confirm on dev **after the operator deploys**, with `curl` sending the client's encoding (a bare `curl` sends no `Accept-Encoding`, and `Vary` includes it, so it reads a different cache key and reports `BYPASS`):

```bash
ET=$(curl -s -D - -o /dev/null -H "Accept-Encoding: gzip" \
  https://dev-api.partna.au/api/public/profiles/loadtest \
  | grep -i '^etag:' | sed 's/etag: //I' | tr -d '\r')

curl -s -o /dev/null -D - -H "Accept-Encoding: gzip" -H "If-None-Match: $ET" \
  https://dev-api.partna.au/api/public/profiles/loadtest | grep -iE "^(HTTP|cache-control|vary)"
```

Expected: `304` with `cache-control: public, max-age=30, s-maxage=30` instead of `no-cache, private`.

- [x] Optional, higher-cost: re-run the 10-minute probe with origin capture and check the falsifiable prediction — the origin's profile split moves from ~50/50 200/304 to **304-dominant**, and origin reach drops below 8.2%.

**Honest limit:** this fixes a defect with a demonstrated mechanism. It is **not proven** to cause the intermittent 4–8.5 s stall, which never reproduced under capture. If the stall recurs after this ships, the next lever is `stale-while-revalidate`.
