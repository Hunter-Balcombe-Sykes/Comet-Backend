# Public-cache `stale-while-revalidate` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a config-gated `stale-while-revalidate` directive to `AddPublicCacheHeaders` so an
expired edge entry is served instantly from stale while the edge refreshes in the background,
instead of blocking every waiting client on a synchronous revalidation.

**Architecture:** One new integer config key drives one extra `Cache-Control` token. It defaults to
**0, which omits the directive entirely** — so the code ships inert and the behaviour change is a
separate, reversible env-var flip, per environment. Whether Cloudflare honours the directive at all
is treated as **unknown and measured**, not assumed.

**Tech Stack:** Laravel 12 middleware, `config/partna.php`, Pest 4, and the k6 diagnostics shipped
at `f8498a866` (`probe-stall.js`, `poll-origin-logs.sh`, `analyse-stall.py`).

## Global Constraints

- **This is a public-wire change.** Every response on `api/public/site-by-slug` and
  `api/public/profiles` carries the new header once enabled. CLAUDE.md's blocker gate applies:
  Task 4 and Task 5 are checkpoints requiring Josh's sign-off, not steps to run unattended.
- **Default MUST be inert.** `PARTNA_CACHE_PUBLIC_SWR` unset ⇒ byte-identical `Cache-Control` to
  today. A green test suite on the default path is the ship gate for Tasks 1–3.
- **The 304 path is not optional.** `AddETagHeaders` converts a matching conditional request to 304
  and unwinds *before* `AddPublicCacheHeaders`; a directive that appears only on 200s poisons the
  stored entry on every revalidation. That exact defect is what
  `results/2026-08-09-full-rerun.md` diagnosed and `59aec7497` fixed. **A unit test cannot catch
  it** — the ordering only exists in the real pipeline, so the 304 assertion must be a Feature test
  through the full HTTP stack (`reference_middleware_ordering_drops_304_headers`).
- **Do not edit `.env`.** Document the key in `.env.example` only.
- **Do not touch `PARTNA_CACHE_PUBLIC_MAX_AGE`.** It is 30 in both dev and prod; changing it in the
  same pass would make the measurement in Task 4 uninterpretable.
- **This is not a fix for the multi-second stall.** That was attributed to the tester's network path
  on 2026-08-10 (`results/2026-08-10-stall-probe.md`). Do not describe this change as fixing it in
  any commit message, comment, or write-up.

## What this is actually for

Two things, both modest and both real:

1. **The per-TTL blip.** With `max-age=30, s-maxage=30` and no SWR, one client per 30 s window pays
   the full revalidation synchronously. Measured on dev 2026-08-10: ~200 ms every ~32 s on the
   profile route, with the origin answering in 78–154 ms.
2. **The collapsing amplifier.** Cloudflare collapses concurrent requests for one cache key, so a
   single slow revalidation stalls *every* in-flight request for that key. On 2026-08-03 this turned
   one blockage into seven stalled profile requests. Serving stale removes the queue.

## Out of scope, deliberately

- **`stale-if-error`.** Serving stale when the origin returns 5xx is a genuinely useful resilience
  lever, but it is a different failure mode with a different blast radius and it deserves its own
  decision. Adding both at once makes Task 4's measurement ambiguous.
- **Per-prefix SWR values.** One knob for both cacheable prefixes. A second knob is YAGNI until a
  prefix actually needs a different value.
- **Raising `s-maxage`.** Out of scope for the same reason as `max-age`: one variable per pass.

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `config/partna.php` | owns the knob and its documentation | add `cache.public_swr` next to `cache.public_max_age` (~line 2236) |
| `app/Http/Middleware/AddPublicCacheHeaders.php` | builds the header | build `Cache-Control` from parts instead of one interpolated string (line 90) |
| `tests/Unit/AddPublicCacheHeadersTest.php` | header-shape unit coverage | add inert-default and enabled cases |
| `tests/Feature/Cache/PublicCacheMiddlewareTest.php` | real-pipeline coverage | add the **304 parity** case — the one that matters |
| `.env.example` | documents the key | add `# PARTNA_CACHE_PUBLIC_SWR=` |

---

### Task 1: Config key, defaulting to inert

**Files:**
- Modify: `config/partna.php` (beside `public_max_age`, ~line 2236)
- Modify: `app/Http/Middleware/AddPublicCacheHeaders.php:88-90`
- Test: `tests/Unit/AddPublicCacheHeadersTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `config('partna.cache.public_swr')` → `int`. `0` means "omit the directive".

- [ ] **Step 1: Write the failing test — the default must change nothing**

```php
it('omits stale-while-revalidate when the config value is 0', function () {
    config(['partna.cache.public_max_age' => 30, 'partna.cache.public_swr' => 0]);

    $response = (new AddPublicCacheHeaders)->handle(
        Request::create('/api/public/profiles/someone', 'GET'),
        fn () => new Response('{}', 200),
    );

    // Exact equality, not toContain: 'max-age=30' is a substring of 's-maxage=30',
    // so a toContain assertion cannot tell the two directives apart and would pass
    // on a header that is subtly wrong.
    expect($response->headers->get('Cache-Control'))
        ->toBe('max-age=30, public, s-maxage=30');
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Unit/AddPublicCacheHeadersTest.php --filter="omits stale-while-revalidate"`

Expected: FAIL. `config('partna.cache.public_swr')` is undefined, so this currently returns `null`;
the assertion documents the intended shape before the key exists. Note the exact ordering —
Symfony's `HeaderBag` re-serialises `Cache-Control` alphabetically, which is why today's header
reads `max-age=30, public, s-maxage=30` and not the order written in the source string. If the
expected string in Step 1 does not match the current output, **fix the test to match reality
first** and re-run, so the only thing this task changes is the presence of the new directive.

⚠️ `composer test --filter` is broken in this repo (`reference_composer_filter_and_pint_broken`) —
it reads like "no tests matched". Call `./vendor/bin/pest` directly as above.

- [ ] **Step 3: Add the config key**

```php
// CFG-3: seconds an expired edge entry may be served stale while the CDN
// refreshes it in the background. 0 (the default) omits the directive entirely,
// so this ships inert and is enabled per environment via env var.
//
// Purpose is the once-per-TTL blocking revalidation and Cloudflare's
// concurrent-request collapsing, NOT the multi-second stall investigated on
// 2026-08-10 — that was the tester's own network path.
'public_swr' => (int) env('PARTNA_CACHE_PUBLIC_SWR', 0),
```

- [ ] **Step 4: Run the test and confirm it passes**

Run: `./vendor/bin/pest tests/Unit/AddPublicCacheHeadersTest.php`
Expected: PASS. The middleware is untouched so far; the key simply now resolves to `0`.

- [ ] **Step 5: Commit**

```bash
git add config/partna.php tests/Unit/AddPublicCacheHeadersTest.php
git commit -m "chore(cache): add inert public_swr config key"
```

---

### Task 2: Emit the directive when enabled

**Files:**
- Modify: `app/Http/Middleware/AddPublicCacheHeaders.php:88-90`
- Test: `tests/Unit/AddPublicCacheHeadersTest.php`

**Interfaces:**
- Consumes: `config('partna.cache.public_swr')` from Task 1.
- Produces: `Cache-Control: max-age=N, public, s-maxage=N, stale-while-revalidate=M` when `M > 0`.

- [ ] **Step 1: Write the failing test**

```php
it('appends stale-while-revalidate when the config value is positive', function () {
    config(['partna.cache.public_max_age' => 30, 'partna.cache.public_swr' => 60]);

    $response = (new AddPublicCacheHeaders)->handle(
        Request::create('/api/public/profiles/someone', 'GET'),
        fn () => new Response('{}', 200),
    );

    expect($response->headers->get('Cache-Control'))
        ->toBe('max-age=30, public, s-maxage=30, stale-while-revalidate=60');
});

it('applies stale-while-revalidate to site-by-slug as well as profiles', function () {
    config(['partna.cache.public_max_age' => 30, 'partna.cache.public_swr' => 60]);

    $response = (new AddPublicCacheHeaders)->handle(
        Request::create('/api/public/site-by-slug', 'GET'),
        fn () => new Response('{}', 200),
    );

    expect($response->headers->get('Cache-Control'))
        ->toContain('stale-while-revalidate=60');
});
```

- [ ] **Step 2: Run and confirm both fail**

Run: `./vendor/bin/pest tests/Unit/AddPublicCacheHeadersTest.php`
Expected: FAIL — actual header has no `stale-while-revalidate` token.

- [ ] **Step 3: Build the header from parts**

Replace line 89-90 of `app/Http/Middleware/AddPublicCacheHeaders.php`:

```php
// CFG-3: CDN/edge TTL is config-driven (default 15 min) — tunable without a redeploy.
$maxAge = (int) config('partna.cache.public_max_age', 900);
$directives = ["public", "max-age={$maxAge}", "s-maxage={$maxAge}"];

// 0 omits the directive rather than emitting `stale-while-revalidate=0`,
// which is a valid but meaningless header that would still be a wire change.
$swr = (int) config('partna.cache.public_swr', 0);
if ($swr > 0) {
    $directives[] = "stale-while-revalidate={$swr}";
}

$response->headers->set('Cache-Control', implode(', ', $directives));
```

- [ ] **Step 4: Run the full unit + feature cache suites**

Run: `./vendor/bin/pest tests/Unit/AddPublicCacheHeadersTest.php tests/Feature/Cache/`
Expected: PASS, including the pre-existing `max-age=900` / `s-maxage=900` assertions — the default
path is unchanged because `public_swr` is 0.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/AddPublicCacheHeaders.php tests/Unit/AddPublicCacheHeadersTest.php
git commit -m "feat(cache): emit stale-while-revalidate when public_swr is set"
```

---

### Task 3: Prove it survives the 304 path

This is the task the whole change hinges on. Everything above passes with a middleware that silently
drops the directive on every revalidation — which is precisely the defect `59aec7497` fixed for
`s-maxage`, and it would be reintroduced here for `stale-while-revalidate` without this test.

**Files:**
- Test: `tests/Feature/Cache/PublicCacheMiddlewareTest.php`
- Modify: `.env.example`

**Interfaces:**
- Consumes: the header from Task 2.
- Produces: nothing; this is a guard.

- [ ] **Step 1: Write the failing test — full HTTP stack, conditional request**

```php
it('carries stale-while-revalidate on a 304 revalidation, not just the 200', function () {
    config(['partna.cache.public_max_age' => 30, 'partna.cache.public_swr' => 60]);

    $subdomain = 'test-swr-304-'.Str::random(6);
    prewarmSiteCache($subdomain);

    // First request establishes the validator.
    $first = $this->withHeader('X-Site-Subdomain', $subdomain)->getJson('/api/public/site-by-slug');
    $first->assertOk();
    $etag = (string) $first->headers->get('ETag');
    expect($etag)->not->toBe('');

    // Replaying it with the validator must 304 AND keep the full cache contract:
    // RFC 9111 lets a shared cache update the stored entry's headers from a 304,
    // so a 304 that omits the directive un-does it for the whole stored entry.
    $second = $this
        ->withHeader('X-Site-Subdomain', $subdomain)
        ->withHeader('If-None-Match', $etag)
        ->getJson('/api/public/site-by-slug');

    $second->assertStatus(304);

    $cacheControl = (string) $second->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('stale-while-revalidate=60');
    expect($cacheControl)->toContain('s-maxage=30');
    expect($cacheControl)->toContain('public');
});
```

- [ ] **Step 2: Run it**

Run: `./vendor/bin/pest tests/Feature/Cache/PublicCacheMiddlewareTest.php`

Expected: **PASS on the first run**, because `AddPublicCacheHeaders` already handles the 304 case via
`$revalidated` (line 82-85). That is the intended outcome — this test is a regression guard, not a
red-green cycle.

**If it FAILS, stop and do not "fix" it by reordering the two `appendToGroup` calls in
`bootstrap/app.php`.** That reorder was considered and rejected at `59aec7497`: it makes correctness
depend on middleware registration order, which is exactly the fragility that produced the original
bug. Fix it inside the middleware.

- [ ] **Step 3: Mutation-test the guard**

Temporarily change `$revalidated` on line 82 to `false`, re-run the test, and confirm it **fails**.
Restore the line. A guard that passes with the mechanism disabled is guarding nothing — this repo has
been bitten by vacuous assertions before (`reference_negated_tocontain_is_vacuous`).

- [ ] **Step 4: Document the env var**

⚠️ **Name collision.** `.env.example:399` already carries `PARTNA_CACHE_SWR_DEFER_RECOMPUTE`, which
is an unrelated feature — deferring cache *recomputation* to after the response (`DefersRecompute`).
Two different things called "SWR" in one config namespace will be misread. Keep the new key named
`PARTNA_CACHE_PUBLIC_SWR` (the `PUBLIC_` prefix ties it to `PARTNA_CACHE_PUBLIC_MAX_AGE`, which is
the directive it modifies) and say so explicitly in both comments.

Add to `.env.example` beside the existing cache entries (~line 382):

```
# HTTP Cache-Control for public API responses. Distinct from
# PARTNA_CACHE_SWR_DEFER_RECOMPUTE below, which is about internal recompute timing,
# not the wire header.
# PARTNA_CACHE_PUBLIC_MAX_AGE=900
# Seconds an expired public edge entry may be served stale while the CDN refreshes
# it in the background. 0 or unset omits the directive. Enable per environment.
# PARTNA_CACHE_PUBLIC_SWR=
```

Note this also adds `PARTNA_CACHE_PUBLIC_MAX_AGE`, which is live in both environments but was never
documented in `.env.example` — an opportunistic fix in a file the task already has open, per
CLAUDE.md's absorb-the-P3-tail rule.

- [ ] **Step 5: Run the full suite and commit**

Run: `composer test`
Expected: green. Set `COMPOSER_PROCESS_TIMEOUT=0` or the run dies partway
(`reference_full_suite_run_gotchas`).

```bash
git add tests/Feature/Cache/PublicCacheMiddlewareTest.php .env.example
git commit -m "test(cache): guard stale-while-revalidate across the 304 path"
```

---

### Task 4: CHECKPOINT — does Cloudflare actually honour it?

**Requires Josh. Do not run unattended.**

Nothing so far establishes that Laravel Cloud's Cloudflare acts on `stale-while-revalidate`. If it
ignores the directive, this change is a browser-only no-op at the edge and the value proposition
above evaporates. Measure before believing.

- [ ] **Step 1: Merge and deploy to dev**

Push the branch, merge to `development`, confirm the deploy succeeded:

```bash
~/.composer/vendor/bin/cloud deployment:list development | head
```

Confirm the default is still inert on the wire before flipping anything:

```bash
curl -s -D - -o /dev/null -H 'Accept-Encoding: gzip' \
  https://dev-api.partna.au/api/public/profiles/loadtest | grep -i cache-control
# expect: max-age=30, public, s-maxage=30   (no stale-while-revalidate)
```

- [ ] **Step 2: Set the dev env var — READ THE WHOLE SET FIRST**

⚠️ **`cloud environment:update --variables` REPLACES ALL variables.** Setting one key by passing one
key wipes the rest (`reference_env_state_2026_07_18`, `reference_cloud_cli_no_per_var_delete`).
Either use the Laravel Cloud UI, or read the full set, append, and write back:

```bash
~/.composer/vendor/bin/cloud environment:get development --json \
  --fields=environmentVariables --show-sensitive
```

Set `PARTNA_CACHE_PUBLIC_SWR=60`. Redeploy. Re-run the curl above and confirm the directive is now
present on the 200.

- [ ] **Step 3: Confirm the 304 carries it too, against the live edge**

```bash
ETAG=$(curl -s -D - -o /dev/null -H 'Accept-Encoding: gzip' \
  https://dev-api.partna.au/api/public/profiles/loadtest | grep -i '^etag:' | cut -d' ' -f2- | tr -d '\r')
curl -s -D - -o /dev/null -H 'Accept-Encoding: gzip' -H "If-None-Match: $ETAG" \
  https://dev-api.partna.au/api/public/profiles/loadtest | grep -i 'HTTP/\|cache-control'
```

Expected: `304` **and** a `Cache-Control` containing `stale-while-revalidate=60`.

⚠️ Always send `Accept-Encoding: gzip`. `Vary` includes it, so a bare `curl` reads a *different*
cache key and reports `no-cache, private` + `BYPASS` — that trap hid the edge cache for two sessions
(`results/2026-08-09-full-rerun.md`).

- [ ] **Step 4: Measure whether the edge behaviour actually changed**

```bash
cd scripts/launch-check/k6
./poll-origin-logs.sh capture 1320 results/swr-origin.jsonl &
rm -f results/swr-trace.jsonl
k6 run --out json=results/swr.json --log-format=raw \
       --console-output=results/swr-trace.jsonl probe-stall.js
./analyse-stall.py results/swr.json results/swr-trace.jsonl results/swr-origin.jsonl
```

Three questions, in order of how much they matter:

1. **Does `cf-cache-status` ever report `STALE`?** If it never does across ~900 profile requests,
   Cloudflare is not acting on the directive and the change is inert at the edge. That is a real
   finding — record it and stop; do not enable on prod.
2. **Did origin reach drop below 4.4%?** The 2026-08-10 baseline was exactly 40 origin requests per
   20 minutes (one per 30 s window). SWR should not reduce the *count* of revalidations, only
   unblock the clients waiting on them — so a roughly unchanged 40 with fewer slow client requests
   is the expected shape, not fewer origin hits.
3. **Did the ~200 ms per-32 s profile blip disappear?** Compare `profile` p95 against the
   2026-08-10 idle-link baseline of **179.2 ms**.

Run this on an **idle link**. The 2026-08-10 loaded arm proves a congested WAN swamps a 200 ms
effect with multi-second transport noise.

- [ ] **Step 5: Record the result**

Write `scripts/launch-check/k6/results/2026-08-DD-swr-verification.md` with the three answers and
the raw numbers. Raw k6 output is gitignored, so a number not written into markdown cannot be
checked later.

---

### Task 5: CHECKPOINT — production decision

**Requires Josh. Explicit go/no-go.**

- [ ] **Step 1: Gate on Task 4's answer 1**

If Cloudflare never served `STALE` on dev, **do not enable on prod.** Leave the key at 0, keep the
code (it costs nothing inert), and record why in the write-up.

- [ ] **Step 2: If it did work, set the prod variable the same careful way**

Same full-set read-modify-write as Task 4 Step 2, against `production`. Prod carries no customer
data yet (`core.users` = 0), so the blast radius is currently nil — but the same care applies
because the habit is what protects the next change.

- [ ] **Step 3: Verify on prod's own wire**

```bash
curl -s -D - -o /dev/null -H 'Accept-Encoding: gzip' \
  https://api.partna.au/api/public/profiles/<a-real-handle> | grep -i cache-control
```

- [ ] **Step 4: Delete this plan file**

Shipped plans are deleted, not archived (`reference_plans_specs_lifecycle_convention`). The durable
record is the Task 4 write-up and the config comment, not this file.

---

## Self-review notes

- **Coverage:** every claim in "What this is actually for" maps to a measurement in Task 4. The
  inert-default constraint is enforced by Task 1's test; the 304 constraint by Task 3's; the
  "not a stall fix" constraint by wording in the config comment and the commit messages.
- **The riskiest assumption is named, not buried:** Cloudflare's support for the directive is
  Task 4 Step 4 question 1, with an explicit stop-and-record outcome if the answer is no.
- **No placeholder steps.** Every code step carries the actual code; every verification step carries
  the actual command and the expected output.
