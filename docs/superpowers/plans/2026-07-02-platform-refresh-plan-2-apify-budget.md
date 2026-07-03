# Platform Refresh Plan 2 — Apify Budget & Burst Control (Bundle A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** Draft — awaiting Josh's sign-off (P1-adjacent cost control; no DB migration).

**Goal:** Put a single, shared cost ceiling on all paid Apify scraping (audit **#SCALE-2**) so one integration can't exhaust the account and take the others down with it, and pace the every-15-min menu-retry burst (audit **#SCALE-4**) so it can't dispatch 200 metered jobs at once.

**Architecture:** Generalise the Instagram-only `InstagramApifyBudget` into a shared **`ApifyBudget`** service enforcing **two** atomic daily caps — a per-actor cap *and* a global cap across all actors. Every paid Apify entry point claims a slot before spending: the two existing Instagram call sites (migrated), `GoogleBusinessApifyScraper::fetch()`, and **both** menu scrape paths (`MenuApifyScraper::fetch()` **and** `fetchStores()`). `RetryUnavailableMenusCommand` then drops its default cap, staggers dispatch, and stops early when the menu budget is already spent.

**Tech Stack:** PHP 8.2, Laravel 12, Redis cache (array cache in tests), Pest 4. Builds on the atomic `Cache::add + increment/decrement` pattern already proven in `InstagramApifyBudget`.

**Source:** Strategy doc `docs/superpowers/plans/2026-07-01-platform-refresh-scaling-strategy.md` §8 (Bundle A = first thin follow-on). Depends on Plan 1 (landed on this branch): the per-provider `RateLimiter` and `platform_refresh` queue already exist; this plan reuses the same *config-driven cost-control* philosophy but operates on the **Apify spend** axis (a different lever than Plan 1's per-request rate limit).

**⚠️ Premise correction (verified against source 2026-07-02):** the audit names `MenuApifyScraper::fetch()`, but `MenuFetchJob` actually calls **`fetchStores()`** (the `Http::pool` path). Gating only `fetch()` would leave the real menu-scrape path unmetered. Plan 2 gates both. `fetch()` is still gated because `fetchStores()` falls back to it per-target on a transient miss.

## Global Constraints

- **NO Laravel migration files** — no schema change in this plan.
- **Tests run on SQLite + `array` cache store** (`phpunit.xml`: `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`). `Cache::add/increment/decrement` all work on the array store — no Redis needed in tests.
- **Budget claims must be atomic** — use `Cache::add` (init, TTL-preserving) then `Cache::increment`, and `Cache::decrement` to release on over-cap. Never `get`+`put` (read-modify-write race). This mirrors the existing `InstagramApifyBudget`.
- **Fail toward LESS spend.** A cost guard must err on the side of *not* scraping. A rare double-claim (e.g. the `fetchStores`→`fetch` fallback) is acceptable because it only makes the cap more conservative — it can never cause over-spend.
- **Preserve existing external contracts.** The Instagram connect endpoint still returns **429** with the same message when the budget is exhausted; scrapers still return **null / `[]`** on skip (callers keep the prior payload) — no new failure surface.
- Business logic in services; raw `Cache::*` calls stay in the `app/Services/Cache/` layer (GS-1 rule).
- Run `php artisan pint` on changed files; keep commits surgical.

---

## File Structure

**New files:**
- `app/Services/Cache/ApifyBudget.php` — shared per-actor + global daily budget.
- `tests/Feature/Platforms/ApifyBudgetTest.php`

**Modified files:**
- `app/Services/Cache/CacheKeyGenerator.php` — add global + per-actor Apify keys; remove `instagramDailyLimit`.
- `config/partna.php` — add `limits.apify` block; `.env.example` keys.
- `app/Http/Controllers/Api/Platforms/InstagramController.php` — `guardApifyBudget()` → `ApifyBudget::tryClaim('instagram')`.
- `app/Services/Platforms/GoogleBusinessAutoSync.php` — constructor + call site → `ApifyBudget`.
- `app/Services/Platforms/GoogleBusinessApifyScraper.php` — claim `'google-business'` before the post.
- `app/Services/Platforms/MenuApifyScraper.php` — claim `'menu'` in `fetch()` and per-target in `fetchStores()`.
- `app/Console/Commands/RetryUnavailableMenusCommand.php` — lower default limit, stagger, budget-aware stop.
- `routes/console.php` — (comment only) note the new pacing.

**Deleted files:**
- `app/Services/Cache/InstagramApifyBudget.php` — folded into `ApifyBudget`.

---

## Task 1: The shared `ApifyBudget` service

**Files:**
- Create: `app/Services/Cache/ApifyBudget.php`
- Modify: `app/Services/Cache/CacheKeyGenerator.php`
- Modify: `config/partna.php`, `.env.example`
- Test: `tests/Feature/Platforms/ApifyBudgetTest.php`

**Interfaces:**
- Produces: `ApifyBudget::tryClaim(string $actor): bool` (atomic; enforces per-actor AND global daily cap) and `ApifyBudget::remaining(string $actor): int` (advisory headroom, min of actor/global remaining, floored at 0). Consumed by Tasks 2–5.
- Produces config: `config('partna.limits.apify.global_daily_cap')`, `config('partna.limits.apify.actors.{actor}')`.

- [ ] **Step 1: Add the config block**

In `config/partna.php`, inside the existing `'limits' => [ … ]` array (after the `'platforms' => [ … ]` sub-array), add:

```php
        // Shared Apify cost ceiling (SCALE-2). Every paid actor (instagram, menu,
        // google-business) claims a slot from ApifyBudget before spending: a
        // per-actor daily cap PLUS a global daily cap so one integration's runaway
        // can't exhaust the account and starve the others.
        'apify' => [
            'global_daily_cap' => (int) env('PARTNA_APIFY_GLOBAL_DAILY_CAP', 1000),
            'actors' => [
                // Instagram reuses its existing tuned env var (behaviour preserved).
                'instagram' => (int) env('PARTNA_INSTAGRAM_APIFY_DAILY_CAP', 200),
                'menu' => (int) env('PARTNA_MENU_APIFY_DAILY_CAP', 300),
                'google-business' => (int) env('PARTNA_GB_APIFY_DAILY_CAP', 300),
            ],
        ],
```

In `.env.example`, add:

```dotenv
PARTNA_APIFY_GLOBAL_DAILY_CAP=1000
PARTNA_MENU_APIFY_DAILY_CAP=300
PARTNA_GB_APIFY_DAILY_CAP=300
```

(`PARTNA_INSTAGRAM_APIFY_DAILY_CAP` already exists.)

- [ ] **Step 2: Write the failing tests**

```php
<?php
// tests/Feature/Platforms/ApifyBudgetTest.php

use App\Services\Cache\ApifyBudget;

beforeEach(function () {
    config()->set('partna.limits.apify.global_daily_cap', 5);
    config()->set('partna.limits.apify.actors.menu', 2);
    config()->set('partna.limits.apify.actors.google-business', 2);
});

it('grants claims up to the per-actor cap then rejects', function () {
    $budget = new ApifyBudget;
    expect($budget->tryClaim('menu'))->toBeTrue()
        ->and($budget->tryClaim('menu'))->toBeTrue()
        ->and($budget->tryClaim('menu'))->toBeFalse(); // 3rd exceeds actor cap of 2
});

it('rejects once the GLOBAL cap is hit even if the actor cap is not', function () {
    $budget = new ApifyBudget;
    // global cap 5: 2 menu + 2 gb = 4, one more of either = 5 (ok), next = reject
    expect($budget->tryClaim('menu'))->toBeTrue()
        ->and($budget->tryClaim('menu'))->toBeTrue()
        ->and($budget->tryClaim('google-business'))->toBeTrue()
        ->and($budget->tryClaim('google-business'))->toBeTrue()   // global now 4
        ->and($budget->remaining('google-business'))->toBe(0);    // gb actor cap (2) reached
});

it('reports remaining headroom as the min of actor and global', function () {
    $budget = new ApifyBudget;
    expect($budget->remaining('menu'))->toBe(2);  // actor cap 2 < global 5
    $budget->tryClaim('menu');
    expect($budget->remaining('menu'))->toBe(1);
});

it('a rejected claim does not consume budget (decrement releases it)', function () {
    config()->set('partna.limits.apify.actors.menu', 1);
    $budget = new ApifyBudget;
    $budget->tryClaim('menu');          // 1/1
    $budget->tryClaim('menu');          // rejected, must release
    // global should reflect only the 1 successful claim
    expect($budget->remaining('google-business'))->toBe(2); // global 5 - 1 = 4, gb cap 2 → 2
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Platforms/ApifyBudgetTest.php`
Expected: FAIL — `Class "App\Services\Cache\ApifyBudget" not found`.

- [ ] **Step 4: Add the cache keys**

In `app/Services/Cache/CacheKeyGenerator.php`, replace the `instagramDailyLimit` method with:

```php
    /** Global daily Apify claim counter across ALL actors (SCALE-2 cost ceiling). */
    public static function apifyGlobalDailyLimit(string $date): string
    {
        return 'platforms:apify:global:daily:'.$date;
    }

    /** Per-actor daily Apify claim counter (actor = instagram|menu|google-business). */
    public static function apifyActorDailyLimit(string $actor, string $date): string
    {
        return 'platforms:apify:'.$actor.':daily:'.$date;
    }
```

- [ ] **Step 5: Implement the service**

```php
<?php
// app/Services/Cache/ApifyBudget.php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;

// The shared daily Apify scrape budget (SCALE-2). Every paid entry point —
// Instagram connect, Google Business enrichment, menu scrapes — claims a slot
// here before spending, so a runaway in one integration can't exhaust the paid
// Apify account and take the others down with it.
//
// TWO caps enforced atomically: a per-actor daily cap AND a global daily cap
// across all actors. Lives in the cache layer (GS-1) so raw Cache::* stays
// canonical; atomic Cache::add + increment (never get+put) means two concurrent
// claims can't both slip past the boundary. Generalises the former
// InstagramApifyBudget.
class ApifyBudget
{
    /**
     * Try to claim one scrape slot for $actor today. Returns false when EITHER the
     * per-actor or the global daily cap is already reached — the caller skips the
     * scrape (429 on the Instagram manual path; null/[] elsewhere so the prior
     * payload is kept).
     */
    public function tryClaim(string $actor): bool
    {
        $actorCap = (int) config("partna.limits.apify.actors.{$actor}", 0);
        $globalCap = (int) config('partna.limits.apify.global_daily_cap');
        $date = now()->format('Y-m-d');
        $expiry = now()->addDay(); // date-keyed key rotates at midnight; TTL just outlives the day

        $globalKey = CacheKeyGenerator::apifyGlobalDailyLimit($date);
        $actorKey = CacheKeyGenerator::apifyActorDailyLimit($actor, $date);

        Cache::add($globalKey, 0, $expiry);
        Cache::add($actorKey, 0, $expiry);

        $global = Cache::increment($globalKey);
        $actorCount = Cache::increment($actorKey);

        // Over EITHER ceiling → release both counters and reject.
        if ($global > $globalCap || $actorCount > $actorCap) {
            Cache::decrement($globalKey);
            Cache::decrement($actorKey);

            return false;
        }

        return true;
    }

    /**
     * Advisory remaining headroom for $actor today = min(actor remaining, global
     * remaining), floored at 0. Racy vs concurrent claims — for coarse "should I
     * keep dispatching?" decisions (SCALE-4), not as a hard gate. tryClaim() is the
     * only authority on whether a scrape may proceed.
     */
    public function remaining(string $actor): int
    {
        $actorCap = (int) config("partna.limits.apify.actors.{$actor}", 0);
        $globalCap = (int) config('partna.limits.apify.global_daily_cap');
        $date = now()->format('Y-m-d');

        $global = (int) Cache::get(CacheKeyGenerator::apifyGlobalDailyLimit($date), 0);
        $actorCount = (int) Cache::get(CacheKeyGenerator::apifyActorDailyLimit($actor, $date), 0);

        return max(0, min($actorCap - $actorCount, $globalCap - $global));
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Platforms/ApifyBudgetTest.php`
Expected: PASS (4 passed).

- [ ] **Step 7: Commit**

```bash
php artisan pint app/Services/Cache/ApifyBudget.php app/Services/Cache/CacheKeyGenerator.php config/partna.php tests/Feature/Platforms/ApifyBudgetTest.php
git add app/Services/Cache/ApifyBudget.php app/Services/Cache/CacheKeyGenerator.php config/partna.php .env.example tests/Feature/Platforms/ApifyBudgetTest.php
git commit -m "feat(apify): shared ApifyBudget (per-actor + global daily cap) (SCALE-2)"
```

---

## Task 2: Migrate Instagram off `InstagramApifyBudget`

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/InstagramController.php`
- Modify: `app/Services/Platforms/GoogleBusinessAutoSync.php`
- Delete: `app/Services/Cache/InstagramApifyBudget.php`

**Interfaces:**
- Consumes: `ApifyBudget::tryClaim('instagram')` (Task 1).
- Behaviour preserved: the Instagram connect endpoint still 429s at the (now shared) cap; the Google Business → Instagram auto-sync still skips silently at the cap.

**Note:** No test references `InstagramApifyBudget` (verified via grep), so deleting it breaks no test double. The daily counter key changes from `platforms:instagram:apify-daily:*` to `platforms:apify:instagram:daily:*` — a one-time counter reset on deploy day, harmless for a daily cap.

- [ ] **Step 1: Update the Instagram controller**

In `app/Http/Controllers/Api/Platforms/InstagramController.php`:
- change the import `use App\Services\Cache\InstagramApifyBudget;` → `use App\Services\Cache\ApifyBudget;`
- in `guardApifyBudget()`, change the claim:

```php
        if (! app(ApifyBudget::class)->tryClaim('instagram')) {
            return $this->error('Instagram is busy right now — please try again later.', 429);
        }
```

- [ ] **Step 2: Update Google Business auto-sync**

In `app/Services/Platforms/GoogleBusinessAutoSync.php`:
- change the import to `use App\Services\Cache\ApifyBudget;`
- constructor param `private readonly InstagramApifyBudget $instagramBudget,` → `private readonly ApifyBudget $apifyBudget,`
- the call site (~line 556):

```php
        if (! config('services.apify.token') || ! $this->apifyBudget->tryClaim('instagram')) {
```

(Update any other `$this->instagramBudget` references in the file to `$this->apifyBudget`.)

- [ ] **Step 3: Delete the old service**

```bash
git rm app/Services/Cache/InstagramApifyBudget.php
```

- [ ] **Step 4: Verify nothing else references it**

Run: `grep -rn "InstagramApifyBudget\|instagramDailyLimit" app/ tests/`
Expected: no matches.

- [ ] **Step 5: Run the affected suites**

Run: `php artisan test tests/Feature/Platforms/InstagramConnect*Test.php tests/Feature/Platforms/GoogleBusiness*Test.php`
Expected: PASS — behaviour unchanged (the shared budget default cap equals the old Instagram cap for the `instagram` actor). If a test seeds the old cache key to force a 429, update it to the new key `platforms:apify:instagram:daily:<date>` or (preferred) drive it via `ApifyBudget`.

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Http/Controllers/Api/Platforms/InstagramController.php app/Services/Platforms/GoogleBusinessAutoSync.php
git add app/Http/Controllers/Api/Platforms/InstagramController.php app/Services/Platforms/GoogleBusinessAutoSync.php app/Services/Cache/InstagramApifyBudget.php
git commit -m "refactor(apify): migrate Instagram budget to shared ApifyBudget (SCALE-2)"
```

---

## Task 3: Gate Google Business scraping

**Files:**
- Modify: `app/Services/Platforms/GoogleBusinessApifyScraper.php`
- Test: `tests/Feature/Platforms/GoogleBusinessApifyTest.php` (add cases)

**Interfaces:**
- Consumes: `ApifyBudget::tryClaim('google-business')`.
- `fetch()` returns `null` (its existing skip contract) without any HTTP call when the budget is exhausted.

- [ ] **Step 1: Write the failing test** (append to `tests/Feature/Platforms/GoogleBusinessApifyTest.php`)

```php
use App\Services\Cache\ApifyBudget;
use App\Services\Platforms\GoogleBusinessApifyScraper;
use Illuminate\Support\Facades\Http;

it('skips the scrape and sends no HTTP when the apify budget is exhausted', function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.limits.apify.actors.google-business', 0); // no budget
    Http::fake();

    $result = app(GoogleBusinessApifyScraper::class)->fetch('ChIJtest', 'user-1');

    expect($result)->toBeNull();
    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/GoogleBusinessApifyTest.php`
Expected: FAIL — HTTP *is* sent (no budget gate yet), so `Http::assertNothingSent()` fails.

- [ ] **Step 3: Add the gate**

In `app/Services/Platforms/GoogleBusinessApifyScraper.php`, add the import `use App\Services\Cache\ApifyBudget;`, then in `fetch()` immediately after the token check (after line 42, before the `try`):

```php
        // SCALE-2: claim a slot from the shared Apify budget before spending. Null
        // here = same skip contract as a failed scrape (caller keeps prior payload).
        if (! app(ApifyBudget::class)->tryClaim('google-business')) {
            Log::warning('google_business.apify.budget_exhausted', ['place_id' => $placeId, 'user_id' => $userId]);

            return null;
        }
```

- [ ] **Step 4: Run to verify it passes (and existing cases still pass)**

Run: `php artisan test tests/Feature/Platforms/GoogleBusinessApifyTest.php`
Expected: PASS — the new case passes; existing cases still pass because the default `google-business` cap (300) leaves ample budget.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Services/Platforms/GoogleBusinessApifyScraper.php tests/Feature/Platforms/GoogleBusinessApifyTest.php
git add app/Services/Platforms/GoogleBusinessApifyScraper.php tests/Feature/Platforms/GoogleBusinessApifyTest.php
git commit -m "feat(apify): budget-gate Google Business scraper (SCALE-2)"
```

---

## Task 4: Gate BOTH menu scrape paths

**Files:**
- Modify: `app/Services/Platforms/MenuApifyScraper.php`
- Test: `tests/Feature/Platforms/MenuApifyScraperTest.php` (add cases)

**Interfaces:**
- Consumes: `ApifyBudget::tryClaim('menu')`.
- `fetch()` returns `null`, and `fetchStores()` omits budget-less targets (returning `[]` or a partial map), each without an HTTP call.

**Design:** `fetch()` claims once before its retry loop (retries are reliability for ONE store, not extra budget units). `fetchStores()` claims per-target before building the `Http::pool`; targets with no budget are dropped. The rare `fetchStores`→`fetch` fallback may double-claim a target — accepted (fails toward less spend, per Global Constraints).

- [ ] **Step 1: Write the failing tests** (append to `tests/Feature/Platforms/MenuApifyScraperTest.php`)

```php
use App\Services\Platforms\MenuApifyScraper;
use Illuminate\Support\Facades\Http;

it('fetch() skips and sends no HTTP when the menu budget is exhausted', function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.limits.apify.actors.menu', 0);
    Http::fake();

    $result = app(MenuApifyScraper::class)->fetch('https://ubereats.com/store/x', 'ubereats', 'user-1');

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

it('fetchStores() scrapes no target when the menu budget is exhausted', function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.limits.apify.actors.menu', 0);
    Http::fake();

    $result = app(MenuApifyScraper::class)->fetchStores(
        ['ubereats' => ['pickupUrl' => 'https://ubereats.com/store/x', 'deliveryUrl' => null, 'storeUrl' => null, 'modes' => ['pickup']]],
        'user-1',
    );

    expect($result)->toBe([]);
    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/Platforms/MenuApifyScraperTest.php`
Expected: FAIL — HTTP is sent (no gate yet).

- [ ] **Step 3: Gate `fetch()`**

In `app/Services/Platforms/MenuApifyScraper.php`, add the import `use App\Services\Cache\ApifyBudget;`. In `fetch()`, after the actor/token guard (after line 79, before the `for` loop):

```php
        // SCALE-2: one budget slot per store-scrape (retries below are reliability
        // for THIS store, not extra spend). Null = existing skip contract.
        if (! app(ApifyBudget::class)->tryClaim('menu')) {
            return null;
        }
```

- [ ] **Step 4: Gate `fetchStores()` per target**

In `fetchStores()`, after the `$targets` map is built and the `if ($targets === []) { return []; }` guard (after line 147), before the `Http::pool` call, filter targets through the budget:

```php
        // SCALE-2: claim one budget slot per target before firing the pool; drop
        // targets with no budget so the metered pool never exceeds the daily cap.
        $budget = app(ApifyBudget::class);
        $targets = array_filter($targets, function () use ($budget) {
            return $budget->tryClaim('menu');
        });
        if ($targets === []) {
            return [];
        }
```

- [ ] **Step 5: Run to verify they pass (and existing cases still pass)**

Run: `php artisan test tests/Feature/Platforms/MenuApifyScraperTest.php`
Expected: PASS — new cases pass; existing cases pass because the default `menu` cap (300) leaves ample budget.

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Services/Platforms/MenuApifyScraper.php tests/Feature/Platforms/MenuApifyScraperTest.php
git add app/Services/Platforms/MenuApifyScraper.php tests/Feature/Platforms/MenuApifyScraperTest.php
git commit -m "feat(apify): budget-gate both menu scrape paths (fetch + fetchStores) (SCALE-2)"
```

---

## Task 5: Pace the menu-retry burst (SCALE-4)

**Files:**
- Modify: `app/Console/Commands/RetryUnavailableMenusCommand.php`
- Modify: `routes/console.php` (comment only)
- Test: `tests/Feature/Platforms/RetryUnavailableMenusCommandTest.php` (create if absent)

**Interfaces:**
- Consumes: `ApifyBudget::remaining('menu')` (Task 1); `MenuFetchJob` (existing).
- Produces: dispatches at most `--limit` (new default **50**) jobs, staggered by a per-index delay, and stops early once `remaining('menu')` reaches 0.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Platforms/RetryUnavailableMenusCommandTest.php

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    Queue::fake();
});

function retryMenuFor(string $handle): Menu
{
    $user = User::create([
        'handle' => $handle, 'handle_lc' => $handle, 'display_name' => $handle,
        'account_type' => 'individual', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => $handle.'@example.com',
    ]);
    $menu = Menu::create(['user_id' => $user->id, 'last_fetched_at' => now()->subHour()]);
    $menu->platformLinks()->create(['platform' => 'ubereats', 'status' => 'unavailable', 'url' => 'https://ubereats.com/x']);

    return $menu;
}

it('caps dispatch at the --limit', function () {
    foreach (range(1, 4) as $i) {
        retryMenuFor("u{$i}");
    }

    $this->artisan('menu:retry-unavailable', ['--limit' => 2])->assertSuccessful();

    Queue::assertPushed(MenuFetchJob::class, 2);
});

it('stops dispatching once the menu apify budget is exhausted', function () {
    config()->set('partna.limits.apify.actors.menu', 0); // no budget at all
    retryMenuFor('u1');

    $this->artisan('menu:retry-unavailable')->assertSuccessful();

    Queue::assertNothingPushed();
});
```

*(If the `Menu` / `platformLinks` factory shape here doesn't match the real models, adjust the seeding to the actual columns — confirm against `app/Models/Core/Site/Menu.php` during implementation. The command behaviour under test is what matters: cap + budget-stop.)*

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/Platforms/RetryUnavailableMenusCommandTest.php`
Expected: FAIL — the budget-stop test fails (command currently ignores budget) and/or the limit default differs.

- [ ] **Step 3: Update the command**

Rewrite the body of `handle()` in `app/Console/Commands/RetryUnavailableMenusCommand.php`. Change the signature default and add the import `use App\Services\Cache\ApifyBudget;`:

```php
    protected $signature = 'menu:retry-unavailable {--limit=50 : Max menus to retry this run} {--hours=6 : Only retry menus fetched within this many hours} {--stagger-seconds=6 : Delay spacing between dispatches}';
```

```php
    public function handle(ApifyBudget $budget): int
    {
        $limit = (int) $this->option('limit');
        $hours = (int) $this->option('hours');
        $stagger = (int) $this->option('stagger-seconds');
        $since = now()->subHours($hours);

        $menus = Menu::query()
            ->whereHas('platformLinks', fn ($q) => $q->where('status', 'unavailable'))
            ->where('last_fetched_at', '>=', $since)
            ->orderByRaw('last_fetched_at ASC NULLS FIRST')
            ->limit($limit)
            ->get();

        $dispatched = 0;
        foreach ($menus as $i => $menu) {
            // SCALE-4: stop as soon as the shared menu budget is spent — don't
            // enqueue jobs that would only no-op at the scraper's budget gate.
            if ($budget->remaining('menu') <= 0) {
                break;
            }

            // Stagger so a full run doesn't hit the scraping queue / Apify all at
            // once — spread across the window instead of a single burst.
            MenuFetchJob::dispatch((string) $menu->user_id, true)
                ->delay(now()->addSeconds($i * $stagger));
            $dispatched++;
        }

        $this->info("Menu retries dispatched: {$dispatched} of {$menus->count()} candidate(s) (budget-paced).");

        return self::SUCCESS;
    }
```

- [ ] **Step 4: Note the pacing in the schedule**

In `routes/console.php`, update the comment above the `menu:retry-unavailable` schedule (~line 262) to note it's now budget-paced and staggered (default limit 50). No behavioural change to the schedule entry itself.

- [ ] **Step 5: Run to verify they pass**

Run: `php artisan test tests/Feature/Platforms/RetryUnavailableMenusCommandTest.php`
Expected: PASS (2 passed).

- [ ] **Step 6: Full suite**

Run: `composer test`
Expected: PASS — full suite green (run in the main checkout, not a filtered subset).

- [ ] **Step 7: Commit**

```bash
php artisan pint app/Console/Commands/RetryUnavailableMenusCommand.php routes/console.php tests/Feature/Platforms/RetryUnavailableMenusCommandTest.php
git add app/Console/Commands/RetryUnavailableMenusCommand.php routes/console.php tests/Feature/Platforms/RetryUnavailableMenusCommandTest.php
git commit -m "feat(apify): budget-paced + staggered menu-retry dispatch (SCALE-4)"
```

---

## Self-Review

**1. Spec coverage:**
- SCALE-2 "generalise InstagramApifyBudget → shared per-actor + global cap" → Task 1 ✓
- SCALE-2 "call tryClaim() in Menu + Google Business scrapers" → Tasks 3, 4 ✓ (+ premise correction: both menu paths)
- SCALE-2 "global ceiling so one integration can't starve others" → Task 1 global cap ✓
- SCALE-2 "keep existing per-tenant cooldown" → untouched (Instagram has no active cooldown; cooldown config left as-is) ✓
- SCALE-4 "pace the dispatch loop + per-run cap tied to budget + lower default limit" → Task 5 (stagger + `remaining('menu')` stop + limit 200→50) ✓

**2. Placeholder scan:** every code step has complete code; the one soft spot (Task 5 `Menu` seeding) is explicitly flagged to verify against the real model at implementation time, with the behavioural assertion pinned. ✓

**3. Type consistency:** `ApifyBudget::tryClaim(string $actor): bool` and `remaining(string $actor): int` used identically across Tasks 2–5. Actor keys `'instagram'`/`'menu'`/`'google-business'` match the config `actors` sub-keys exactly. Cache key helpers `apifyGlobalDailyLimit`/`apifyActorDailyLimit` defined in Task 1, used only inside `ApifyBudget`. ✓

**Deferred / not in this plan:** per-HTTP-call (vs per-store) budget accounting; a `Bus::batch` progress UI; conditional requests (Plan 5). The scraper-level gate + stagger is the right-sized fix for the M-effort finding.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-02-platform-refresh-plan-2-apify-budget.md`. Two execution options once you sign off:

**1. Subagent-Driven (recommended)** — fresh subagent per task, independent review between tasks.

**2. Inline Execution** — task-by-task in this session with checkpoints.
