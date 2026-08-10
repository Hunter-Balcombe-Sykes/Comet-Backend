# Link Probe Host Dedupe Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop one website's nav menu from consuming the entire per-run commerce probe budget and starving every link behind it.

**Architecture:** `RouteContext` gains a per-run map of websites already probed, checked inside a new `consumeProbeFor(string $url)` that wraps the existing `consumeProbe()`. Because both of `LinkRouter`'s probe-spending arms already call `consumeProbe()`, moving the dedupe *inside* that method makes "check both arms" structural rather than a rule someone has to remember. A website gets at most two probes per run — one for a plain URL, one for a product-looking URL — both charged to the existing budget of 6.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4. No new dependencies, no migration, no config change.

**Spec:** `docs/superpowers/specs/2026-08-10-link-probe-host-dedupe-design.md`

## Global Constraints

- **`composer test --filter` is broken in this repo** — it reads like "no tests matched". Always run a single file as `php vendor/bin/pest <path>`.
- **`php artisan pint` is broken** — use `./vendor/bin/pint <paths>`.
- **PHPStan needs a raised memory limit** — `./vendor/bin/phpstan analyse <paths> --no-progress --memory-limit=1G`. Without it the worker crashes at 128M and reports a false error.
- **Never create a Laravel migration file.** A Composer guard rejects them. Nothing in this plan needs one.
- **Do not commit.** Josh commits. Each task's final step stages nothing and runs no `git commit`; stop after verification and report.
- **Comment for WHY, not what.** Brief docblocks on public methods, one line above non-trivial blocks. No paragraphs, no banners.
- **`RouteResult::pending()` writes no custom-link card.** A probed URL produces no `platform='custom'` row; only a `custom` outcome does. Every card-count assertion below depends on this.
- **`sites_deduped` must stay a separate counter from `probes_denied`.** `probes_denied > 0` means starvation (bad); `sites_deduped > 0` means the guard working (good). Merging them destroys the signal the shipped log line exists to carry.

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `app/Services/Platforms/RouteContext.php` | Per-run probe budget + dedupe maps. Owns `siteKey()`, `$seenSites`, `$sitesDeduped`, `consumeProbeFor()` | Modify |
| `app/Services/Platforms/LinkRouter.php` | Routing gateway. Two call sites switch from `consumeProbe()` to `consumeProbeFor($url)` | Modify `:117`, `:232` |
| `tests/Feature/Platforms/LinkRouterHostDedupeTest.php` | Dedupe behaviour driven through the real router path | Create |
| `tests/Feature/Platforms/LinkInBioScanJobTest.php` | Extend the shipped completion-log test with `sites_deduped` | Modify |

All four tasks touch `RouteContext.php`. Run them in order.

**Test driver.** Every dedupe test drives the router through `CustomLinkSeeder::seed($user, $url, $ctx)` with one shared `RouteContext`, then counts `Queue::assertPushed(CommerceProbeJob::class, N)`. This is the pattern `tests/Feature/Platforms/CustomLinkSeederTest.php:146-155` already uses. Do **not** call `LinkRouter::route()` directly in a way that bypasses the seeder's custom-link fallback — the card-count assertions are what prove "nothing vanishes" still holds.

---

### Task 1: Dedupe by website

**Files:**
- Modify: `app/Services/Platforms/RouteContext.php`
- Modify: `app/Services/Platforms/LinkRouter.php:117`
- Test: `tests/Feature/Platforms/LinkRouterHostDedupeTest.php` (create)

**Interfaces:**
- Consumes: `RouteContext::consumeProbe(): bool` (existing, unchanged)
- Produces: `RouteContext::consumeProbeFor(string $url): bool` — the new single entry point for spending a probe. Returns `true` when a probe was claimed, `false` when the budget is spent **or** this website was already probed this run. Task 2 extends its keying; Task 3 wires the second call site; Task 4 reads its counter.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Platforms/LinkRouterHostDedupeTest.php`:

```php
<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
});

it('spends one probe per website, not one per page', function () {
    // The live 2026-08-10 shape: six nav pages of one studio's site consumed the
    // whole budget of 6 and starved the three links behind them.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    foreach ([
        'https://crucibletattooco.com.au/',
        'https://crucibletattooco.com.au/appointment.html',
        'https://crucibletattooco.com.au/artists.html',
        'https://crucibletattooco.com.au/aftercare.html',
        'https://crucibletattooco.com.au/accessibility.html',
        'https://crucibletattooco.com.au/feedback.html',
    ] as $url) {
        $seeder->seed($user, $url, $ctx);
    }

    Queue::assertPushed(CommerceProbeJob::class, 1);
    expect($ctx->probesUsed())->toBe(1);
    // Nothing vanishes: the five deduped links still become cards. The probed
    // one is 'pending' and writes no card until its job misses.
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->count())->toBe(5);
});

it('leaves the budget free for the links behind the repeated website', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    foreach ([
        'https://crucibletattooco.com.au/',
        'https://crucibletattooco.com.au/appointment.html',
        'https://crucibletattooco.com.au/artists.html',
        'https://paytherent.net.au',
        'https://bsky.app/profile/someone',
        'https://au.pinterest.com/someone',
    ] as $url) {
        $seeder->seed($user, $url, $ctx);
    }

    // One for crucible, one each for the three that used to be starved.
    Queue::assertPushed(CommerceProbeJob::class, 4);
    expect($ctx->probesDenied())->toBe(0);
});

it('treats www and the bare host as one website', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://crucibletattooco.com.au/', $ctx);
    $seeder->seed($user, 'http://www.crucibletattooco.com.au/artists.html', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 1);
});

it('treats a subdomain as a different website', function () {
    // A shop subdomain is very often a separate storefront from the marketing
    // site, and the probes target scheme://host — so these are two questions.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://crucibletattooco.com.au/', $ctx);
    $seeder->seed($user, 'https://shop.crucibletattooco.com.au/', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 2);
});

it('spends a probe for each distinct website', function () {
    // Guard against the dedupe over-reaching: two unrelated hosts are two
    // questions and must both be asked.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://firstsite.example/', $ctx);
    $seeder->seed($user, 'https://secondsite.example/', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 2);
});
```

- [x] **Step 2: Run the test and verify it fails for the right reason**

Run: `php vendor/bin/pest tests/Feature/Platforms/LinkRouterHostDedupeTest.php`

Expected: the first three tests FAIL. The first should report 6 pushed jobs where 1 was expected — that is the bug reproducing. `subdomain` and `no parseable host` should already PASS; they are regression guards, not new behaviour. If the first test passes, stop — the driver is wrong and the test proves nothing.

- [x] **Step 3: Add the dedupe map and `consumeProbeFor` to `RouteContext`**

In `app/Services/Platforms/RouteContext.php`, add after the `$probesDenied` property:

```php
    /**
     * Websites already probed this run. Bounded by the caller — the widest
     * producer, WebsiteLinkHarvester::extractLinks(), caps at 500 unique hrefs
     * (WebsiteLinkHarvester.php:422,444) — so this map cannot grow unbounded
     * unless a future caller becomes the first one that does.
     *
     * @var array<string, true>
     */
    private array $seenSites = [];

    /** Probes NOT spent because this website was already probed this run. */
    private int $sitesDeduped = 0;
```

Then add, after `consumeProbe()`:

```php
    /**
     * Claim a probe for $url — at most one per website per run.
     *
     * The dedupe lives here rather than at LinkRouter's two call sites so that
     * every path that can spend a probe goes through it. Six nav links of one
     * host spent the entire budget live 2026-08-10 and starved the three links
     * behind them; a probe answers a question about a HOST, so asking the same
     * host twice is the same question.
     */
    public function consumeProbeFor(string $url): bool
    {
        $site = $this->siteKey($url);

        if ($site !== null && isset($this->seenSites[$site])) {
            $this->sitesDeduped++;

            return false;
        }

        if (! $this->consumeProbe()) {
            return false;
        }

        if ($site !== null) {
            $this->seenSites[$site] = true;
        }

        return true;
    }

    public function sitesDeduped(): int
    {
        return $this->sitesDeduped;
    }

    /**
     * Lowercased host with one leading `www.` stripped; scheme, port and path
     * ignored. Null when there is no parseable host — an unparseable URL is
     * probed normally rather than deduped together with other unparseable ones.
     *
     * Deliberately coarser than what the probes fetch (scheme://host, www
     * retained): the question here is "have I already asked about this
     * website?", not "what exactly did I request?".
     */
    private function siteKey(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return null;
        }

        return (string) preg_replace('~^www\.~', '', $host);
    }
```

- [x] **Step 4: Wire the unclassified arm**

In `app/Services/Platforms/LinkRouter.php`, in `routeUnclassified()` at line 117, change:

```php
        if (! $ctx->consumeProbe()) {
```

to:

```php
        if (! $ctx->consumeProbeFor($url)) {
```

Leave `seedShop()` alone — that is Task 3.

- [x] **Step 5: Run the test and verify it passes**

Run: `php vendor/bin/pest tests/Feature/Platforms/LinkRouterHostDedupeTest.php`

Expected: all five PASS.

- [x] **Step 6: Verify nothing else broke**

Run: `php vendor/bin/pest tests/Feature/Platforms/`

Expected: all pass. Roughly 1360 tests, ~5 minutes. `CustomLinkSeederTest`'s ten-`unclassified{$i}.example` budget test still passes because each of its URLs is a distinct host.

---

### Task 2: Grant a product page its own probe

**Files:**
- Modify: `app/Services/Platforms/RouteContext.php`
- Test: `tests/Feature/Platforms/LinkRouterHostDedupeTest.php`

**Interfaces:**
- Consumes: `RouteContext::consumeProbeFor(string $url): bool` from Task 1
- Produces: no new signature. `consumeProbeFor` now keys on `"<siteKey>:<shape>"`, raising the per-website ceiling from one probe to two.

- [x] **Step 1: Write the failing test**

Append to `tests/Feature/Platforms/LinkRouterHostDedupeTest.php`:

```php
it('grants a second probe to a product page on an already-seen website', function () {
    // A homepage probe cannot find a specific product: the platform probes hit
    // scheme://host, and only reading the pasted page extracts a product.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://maker.example/', $ctx);
    $seeder->seed($user, 'https://maker.example/products/black-tee', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 2);
});

it('is symmetric — a product link first still leaves the homepage a probe', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://maker.example/products/black-tee', $ctx);
    $seeder->seed($user, 'https://maker.example/', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 2);
});

it('caps a website at two probes however many product links it has', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    foreach ([
        'https://maker.example/',
        'https://maker.example/products/black-tee',
        'https://maker.example/products/white-tee',
        'https://maker.example/item/999',
        'https://maker.example/about',
    ] as $url) {
        $seeder->seed($user, $url, $ctx);
    }

    Queue::assertPushed(CommerceProbeJob::class, 2);
    expect($ctx->sitesDeduped())->toBe(3);
});

it('does not treat a deep collections url as a product shape', function () {
    // ProbeGate refuses >=2 internal slashes as not_a_storefront_root
    // (ProbeGate.php:112), so /collections/x/products/y would spend a budget
    // slot on a URL the gate then refuses. Excluded from the hint list.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://maker.example/', $ctx);
    $seeder->seed($user, 'https://maker.example/collections/summer/products/tee', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 1);
});
```

- [x] **Step 2: Run the test and verify it fails**

Run: `php vendor/bin/pest tests/Feature/Platforms/LinkRouterHostDedupeTest.php`

Expected: the three new product tests FAIL with 1 pushed where 2 was expected. `deep collections url` should already PASS — it is a guard that the hint list stays narrow.

- [x] **Step 3: Key the map by website AND shape**

In `app/Services/Platforms/RouteContext.php`, add the shape const above `$seenSites`:

```php
    /**
     * Path fragments that mark a URL as a product page rather than a page on a
     * site. A homepage probe cannot find a specific product, so one of these
     * earns a website its second and last probe of the run.
     *
     * `/shop` and `/store` are absent deliberately — SquarespaceScraper already
     * walks them off the origin (SquarespaceScraper.php:14,31-34), so any URL on
     * the host already covers them. `/collections/` is absent because the
     * canonical `/collections/x/products/y` carries 3 internal slashes and
     * ProbeGate refuses >=2 (ProbeGate.php:112) — the hint would spend a slot on
     * a URL the gate then refuses. `/p/` is absent as too generic (`/p/about`).
     */
    private const PRODUCT_PATH_HINTS = ['/product/', '/products/', '/item/'];
```

Then replace the two `$site` lines in `consumeProbeFor()` so the map is keyed by shape too:

```php
    public function consumeProbeFor(string $url): bool
    {
        $key = $this->probeKey($url);

        if ($key !== null && isset($this->seenSites[$key])) {
            $this->sitesDeduped++;

            return false;
        }

        if (! $this->consumeProbe()) {
            return false;
        }

        if ($key !== null) {
            $this->seenSites[$key] = true;
        }

        return true;
    }
```

And add, next to `siteKey()`:

```php
    /** Website plus shape — a site gets one plain probe and one product probe. */
    private function probeKey(string $url): ?string
    {
        $site = $this->siteKey($url);

        if ($site === null) {
            return null;
        }

        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'));

        foreach (self::PRODUCT_PATH_HINTS as $hint) {
            if (str_contains($path, $hint)) {
                return $site.':product';
            }
        }

        return $site.':plain';
    }
```

- [x] **Step 4: Run the test and verify it passes**

Run: `php vendor/bin/pest tests/Feature/Platforms/LinkRouterHostDedupeTest.php`

Expected: all nine PASS.

---

### Task 3: Wire the shop arm

**Files:**
- Modify: `app/Services/Platforms/LinkRouter.php:232`
- Test: `tests/Feature/Platforms/LinkRouterHostDedupeTest.php`

**Interfaces:**
- Consumes: `RouteContext::consumeProbeFor(string $url): bool` from Tasks 1–2
- Produces: nothing new.

This arm is **not** a defensive no-op. `seedShop()` returns `RouteResult::pending('shop','shop','shop')` (`LinkRouter.php:238`), and `pending()` is the one factory that does not pass `handled: true`. `routeClassified()` sets `seenPlatforms` only when `$result->handled` (`LinkRouter.php:103-105`), so a shop route never consumes its platform slot — two links to the same store spend two probes today. And because `SHOP_HOSTS` is only `*.myshopify.com` and `*.bigcartel.com`, every shop link carries the literal slug `'shop'`, so platform keying would wrongly collapse two different merchants into one probe. Host keying does not.

- [x] **Step 1: Write the failing test**

Append to `tests/Feature/Platforms/LinkRouterHostDedupeTest.php`:

```php
it('spends one probe for two links to the same store', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://alice.myshopify.com/', $ctx);
    $seeder->seed($user, 'https://alice.myshopify.com/pages/about', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 1);
});

it('still probes two different merchants on the same platform', function () {
    // Every shop-classified link carries the literal slug 'shop'
    // (WebsiteLinkHarvester.php:401-404), so platform keying would collapse
    // these two merchants into one probe. Host keying must not.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);
    $ctx = new RouteContext;

    $seeder->seed($user, 'https://alice.myshopify.com/', $ctx);
    $seeder->seed($user, 'https://bob.myshopify.com/', $ctx);

    Queue::assertPushed(CommerceProbeJob::class, 2);
});
```

- [x] **Step 2: Run the test and verify it fails**

Run: `php vendor/bin/pest tests/Feature/Platforms/LinkRouterHostDedupeTest.php`

Expected: `same store` FAILS with 2 pushed where 1 was expected. `two different merchants` should already PASS — that is the regression guard, and it must keep passing after Step 3.

- [x] **Step 3: Wire the shop arm**

In `app/Services/Platforms/LinkRouter.php`, in `seedShop()` at line 232, change:

```php
        if (! $ctx->consumeProbe()) {
```

to:

```php
        if (! $ctx->consumeProbeFor($url)) {
```

- [x] **Step 4: Run the test and verify it passes**

Run: `php vendor/bin/pest tests/Feature/Platforms/LinkRouterHostDedupeTest.php`

Expected: all eleven PASS. Confirm `two different merchants` still passes — if it now reports 1, the key is collapsing subdomains and `siteKey()` is wrong.

- [x] **Step 5: Confirm `consumeProbe()` has no callers left outside `RouteContext`**

Run: `grep -rn "consumeProbe()" app/`

Expected: exactly one hit, inside `RouteContext::consumeProbeFor()`. Any hit in `LinkRouter.php` is a call site that escapes the dedupe.

---

### Task 4: Report the dedupe in the completion log

**Files:**
- Modify: `app/Services/Platforms/RouteContext.php`
- Modify: `tests/Feature/Platforms/LinkInBioScanJobTest.php`

**Interfaces:**
- Consumes: `RouteContext::sitesDeduped(): int` from Task 1
- Produces: `RouteContext::summary()` returns `array{probe_budget: int, probes_spent: int, probes_denied: int, sites_deduped: int}`. `LinkInBioScanJob` spreads it into `platforms.link_in_bio_scan.completed` and needs no change.

- [x] **Step 1: Write the failing test**

Append to `tests/Feature/Platforms/LinkInBioScanJobTest.php`:

```php
it('reports how many links the website dedupe absorbed', function () {
    // sites_deduped and probes_denied must stay separate: denied means links
    // went unexamined (bad), deduped means the guard worked (good). One number
    // for both would report the fix as if it were the bug.
    Queue::fake();
    Log::spy();
    $user = User::factory()->create(['account_type' => 'business']);

    $anchors = collect(['/', '/appointment.html', '/artists.html', '/aftercare.html'])
        ->map(fn (string $path) => '<a href="https://crucibletattooco.com.au'.$path.'">Page</a>')
        ->implode('');
    Http::fake(['linktr.ee/*' => Http::response($anchors, 200)]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.link_in_bio_scan.completed'
            && $context['links_seen'] === 4
            && $context['probes_spent'] === 1
            && $context['probes_denied'] === 0
            && $context['sites_deduped'] === 3)
        ->once();
});
```

- [x] **Step 2: Run the test and verify it fails**

Run: `php vendor/bin/pest tests/Feature/Platforms/LinkInBioScanJobTest.php`

Expected: FAIL — the `withArgs` closure returns false because `sites_deduped` is absent from the context array, reported as `info()` called 0 times matching.

- [x] **Step 3: Add `sites_deduped` to the summary**

In `app/Services/Platforms/RouteContext.php`, update `summary()` and its annotation:

```php
    /**
     * This run's budget accounting, for the caller's completion log.
     *
     * @return array{probe_budget: int, probes_spent: int, probes_denied: int, sites_deduped: int}
     */
    public function summary(): array
    {
        return [
            'probe_budget' => $this->maxProbes,
            'probes_spent' => $this->probesUsed(),
            'probes_denied' => $this->probesDenied(),
            'sites_deduped' => $this->sitesDeduped(),
        ];
    }
```

- [x] **Step 4: Run the test and verify it passes**

Run: `php vendor/bin/pest tests/Feature/Platforms/LinkInBioScanJobTest.php`

Expected: all nine PASS, including the existing budget-exhaustion test from this morning.

---

### Task 5: Correct the stale test reference and verify the whole change

**Files:**
- Modify: `app/Services/Platforms/RouteContext.php:22`

**Interfaces:**
- Consumes: nothing. Produces: nothing. Documentation only.

`RouteContext.php:22` cites a `LinkRouterProbeCapTest` that has never existed. Absorbed here because the file is already open — the opportunistic-fix rule in `CLAUDE.md`.

- [x] **Step 1: Fix the docblock**

In `app/Services/Platforms/RouteContext.php`, change:

```php
     * Default probe budget per run — the value both deleted inline counters
     * used (signup-v2 C4). Pinned against them by LinkRouterProbeCapTest.
```

to:

```php
     * Default probe budget per run — the value both deleted inline counters
     * used (signup-v2 C4). Pinned by CustomLinkSeederTest and
     * LinkInBioScanJobTest, which assert against this constant directly.
```

- [x] **Step 2: Confirm the named tests really do pin it**

Run: `grep -rn "DEFAULT_MAX_PROBES" tests/`

Expected: hits in `tests/Feature/Platforms/CustomLinkSeederTest.php` and `tests/Feature/Platforms/LinkInBioScanJobTest.php`, and no hit naming `LinkRouterProbeCapTest`.

- [x] **Step 3: Format**

Run: `./vendor/bin/pint app/Services/Platforms/RouteContext.php app/Services/Platforms/LinkRouter.php tests/Feature/Platforms/LinkRouterHostDedupeTest.php tests/Feature/Platforms/LinkInBioScanJobTest.php`

Expected: `{"tool":"pint","result":"passed"}`.

- [x] **Step 4: Static analysis**

Run: `./vendor/bin/phpstan analyse app/Services/Platforms/RouteContext.php app/Services/Platforms/LinkRouter.php --no-progress --memory-limit=1G`

Expected: `[OK] No errors`. If it reports a mismatch on `summary()`, the `@return` annotation from Task 4 Step 3 was not updated.

- [x] **Step 5: Full platforms suite**

Run: `php vendor/bin/pest tests/Feature/Platforms/`

Expected: all pass (~1370 tests, ~5 minutes).

- [x] **Step 6: Report, do not commit**

Report the final test count, the Pint result and the PHPStan result. Leave the working tree dirty — Josh commits.

---

## Execution record (2026-08-10)

Executed in worktree `.claude/worktrees/link-probe-host-dedupe`, branch `feat/link-probe-host-dedupe` off `development` @ `23558094f`. All 25 steps done. Final: **1370 passed** in `tests/Feature/Platforms/`, Pint `passed`, PHPStan `[OK] No errors`. Not committed — Josh commits.

Three deviations from the plan as written. All three were forced by the code, not preferences.

**1. Task 4 Step 4's prediction was wrong — an existing test had to change.** `LinkInBioScanJobTest`'s `reports how much of the probe budget the scan spent and how many links it starved` exhausted the budget with 8 URLs on ONE host (`someblog.example/page-{i}`) — the exact pathology this plan removes. Measured after Task 1: `probes_spent=1, probes_denied=0, sites_deduped=7`, so it failed. Fixture changed to 8 distinct hosts (`someblog-{i}.example/page`); the test's subject (budget exhaustion is reported) is unchanged and still real, since only distinct sites can exhaust the budget now. Added `sites_deduped === 0` to it so it cannot go green on the dedupe absorbing the links instead of on starvation.

**2. Task 2's `/collections/` rationale was wrong, and its guard test was inverted.** The plan claimed omitting `/collections/` from `PRODUCT_PATH_HINTS` keeps `/collections/x/products/y` out of the product slot. It does not — that URL contains `/products/`. A depth guard mirroring `ProbeGate` was tried and then removed: `ProbeGate` binds only the shop arm, while the unclassified arm runs `readProductPage()` on the pasted URL un-gated and is documented to keep deep URLs (`GenericShopScraper.php:107-109`). The guard also created an ordering hazard — a deep URL seen first took the `:plain` slot and evicted the homepage's probe, reported as a healthy `sites_deduped` rather than a miss. **Final state: no depth filter**, matching the plan's literal `probeKey()`. The `does not treat a deep collections url as a product shape` test is replaced by two that pin the correct behaviour, and the spec's §Shape carries a correction note.

**3. Fixes from independent review.** `consumeProbe()` made `private` so the dedupe is enforced by the compiler, not convention. Two stale docblocks corrected (`consumeProbeFor` still claimed "at most one per website"; `siteKey` mis-described what the probes fetch). Added a null-host test, an ordering test, and a comment naming the unclassified-registry assumption in `leaves the budget free…`.

## Self-Review

**Spec coverage.** Every spec section maps to a task: decision 1 (keep all six cards) is asserted by Task 1 Step 1's card count; decision 2 (host key, `www.` stripped, subdomains distinct) by Task 1's `www`/subdomain tests; decision 3 (hint charged to the same budget) by Task 2's two-probe ceiling test; decision 4 (dedupe inside `consumeProbeFor`) by Task 1 Step 3 plus Task 3 Step 5's grep; decision 5 (no `SiteKey` class) by keeping `siteKey()` private; decision 6 (memo deferred) by building nothing. The spec's "Why the `seedShop` arm genuinely needs this" is Task 3. The `sites_deduped` counter and its separateness from `probes_denied` is Task 4. The `$seenSites` bound is a docblock in Task 1 Step 3.

**Placeholders.** None — every code step contains the literal code, every run step the literal command and the expected outcome.

**Type consistency.** `consumeProbeFor(string $url): bool` is introduced in Task 1 and unchanged thereafter. Task 1 names the private helper `siteKey()`; Task 2 adds `probeKey()` which calls it and switches `consumeProbeFor` from `siteKey()` to `probeKey()` — Task 2 Step 3 shows the whole rewritten method rather than a fragment, so the rename cannot be half-applied. `sitesDeduped()` is defined in Task 1 and consumed in Task 4.

**Deliberate gap.** No test pins `siteKey()` in isolation, per the spec's decision 5 — the normalisation is covered through the `www`, subdomain and merchant tests, which exercise it on the real path.
