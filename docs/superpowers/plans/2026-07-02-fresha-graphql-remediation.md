# Fresha GraphQL Remediation (Pilot-Readiness Step 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** Draft — awaiting Josh's sign-off. **BLOCKER GATE: legal.** This is legal remediation #1 from the platform-integrations review; the review calls for **Australian-counsel sign-off before launch**. This plan takes Fresha CRITICAL→MEDIUM; it does NOT reach fully-compliant.

**Goal:** Remove the private-GraphQL impersonation from `FreshaController` (legal remediation #1 — `[[project_platform_integrations_legal]]`), so Fresha can enter the pilot without the CRITICAL criminal-impersonation exposure. The two callers already fall back to the public-page menu, so this is a clean removal — and it also deletes the **12-second `fresha.com/graphql` call** the JOB-1 audit flagged as an FPM-blocking risk (two fixes, one change).

**Architecture:** `FreshaController::fetchEmployeeServices()` POSTs to Fresha's internal booking GraphQL with a pinned persisted-query hash + spoofed `x-client-version`/`origin`/Chrome-UA headers. Both call sites (`saveSelection`, `employeeServices`) already do `fetchEmployeeServices(...) ?? extractServices($location)` — the public-page `__NEXT_DATA__` menu is the existing fallback. Remediation = delete the GraphQL method and make both sites use `extractServices()` unconditionally.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4. No new dependencies. Deletion-heavy.

**Source:** Legal review `docs/legal/2026-06-01-scraping-legal-review.md` (Fresha = 🔴 CRITICAL: CFAA + AU Criminal Code s478.1 + ToS "misrepresent your relationship"; fix #1 = drop the GraphQL call → MEDIUM). Confirmed with Josh 2026-07-02: **Fresha is entering the pilot** and **the GraphQL impersonation is remediated first, as its own plan** (separate from Plan 3's link controllers).

**Key finding (verified against source):** `FreshaController`'s header comment still says "test-mode / single-tenant cache / no auth" — this is **stale**. The code already uses `currentUser()` + per-user `writeConnection`/`readConnection` (the multi-tenant migration landed 2026-06-03). So Fresha is already per-user + authed; "promotion" is essentially done. The real pilot-readiness work is this legal remediation.

## Global Constraints

- **NO Laravel migration files** — no schema change.
- **Preserve the public API contract.** The `/employee-services`, `/selection`, `/connect`, `/team`, `/service-visibility` endpoints and their JSON shapes are UNCHANGED — only the *source* of the services list changes (public-page menu instead of the per-employee GraphQL menu). `IntegrationContractGoldenMasterTest` references `/employee-services` as a route; that route stays.
- **Behavioural change — conditional on Task 0:** if the spike finds NO per-employee mapping in the public page (`MAPPING_ABSENT`), a BY-EMPLOYEE salon returns the whole-location menu for every employee (the documented accepted fallback, `~/Developer/platform link capabilites/fresha.md`, and already how the code behaves whenever the pinned hash rotates); the user curates via `hiddenServiceIds`. If the spike finds the mapping (`MAPPING_PRESENT`), per-employee filtering is preserved automatically and there is NO behavioural change. Task 0 decides which.
- **Tests on SQLite; fake the page fetch** with the harness the existing `tests/Feature/Platforms/FreshaPayloadTest.php` already uses (match it — see soft-spot note in Task 1).
- Run `php artisan pint` on changed files; keep commits surgical.

---

## Legal Context (read before implementing)

The offending code is `fetchEmployeeServices()` (lines ~302–406): a POST to `https://www.fresha.com/graphql` carrying `extensions.persistedQuery.sha256Hash = config('services.fresha.booking_init_hash')`, `x-client-version`, `x-graphql-operation-name: mutation BookingFlow_Initialize_Mutation`, `origin: https://www.fresha.com`, and a fake Chrome UA. Per the review this impersonates Fresha's first-party booking client on a **private** API — CFAA + AU Criminal Code s478.1 (2yr) + ToS misrepresentation. Dropping it downgrades Fresha to **MEDIUM** (a public-page scrape: ToS breach + brittle, but facts/hot-linked, no impersonation).

**MEDIUM is not clean.** The fully-defensible path is Fresha's **partner API (OAuth + embed + a real licence-in-ToS)** — the controller header itself notes "the real version uses Fresha's partner API." That is a larger, separate effort. This plan gets Fresha *shippable for pilot with counsel sign-off*, not *fully compliant*.

---

## File Structure

**Modified files:**
- `app/Http/Controllers/Api/Platforms/FreshaController.php` — delete GraphQL method + rewire 2 call sites + drop dead consts/imports/helper + fix stale header.
- `config/services.php` — remove the `fresha` block.
- `.env.example` — remove `FRESHA_BOOKING_INIT_HASH` / `FRESHA_CLIENT_VERSION`.
- `tests/Feature/Platforms/FreshaPayloadTest.php` (or a new sibling) — assert services now come from the public page and NO GraphQL POST is made.

---

## Task 0: Spike — is per-employee service data already on the public page?

**Files:** none (investigation). **Output:** a recorded decision (`MAPPING_PRESENT` or `MAPPING_ABSENT`) that selects which Task 1 variant to build.

**Goal:** The private GraphQL was the *convenient* source of per-employee service filtering — but maybe not the *only* one. Determine whether Fresha's public location page (`__NEXT_DATA__`) already carries a per-employee → service association. If it does, the remediation keeps per-employee filtering for free (extract it from public data, filtered by the `employeeId` the user already picks) — dropping the illegal call loses nothing. If not, Task 1 falls back to the whole-location menu.

**Legal note:** this is a one-off manual inspection of a public page for a feasibility decision (not automated collection) — acceptable research. Do NOT build any new automated fetch here.

- [ ] **Step 1: Pull a real salon page through the app fetcher** (pick a BY-EMPLOYEE salon where staff offer different services, plus one BY-TOPIC salon). In `php artisan tinker`:

```php
$html = app(\App\Services\Http\SafeUrlFetcher::class)
    ->fetch('https://www.fresha.com/a/<real-slug>', ['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'])['body'];
preg_match('#<script id="__NEXT_DATA__"[^>]*>(.+?)</script>#s', $html, $m);
$loc = data_get(json_decode($m[1], true), 'props.pageProps.data.location');
dump(array_keys($loc));
dump(data_get($loc, 'employeeProfiles.edges.0.node'));  // does an employee carry serviceIds / offerings?
dump(data_get($loc, 'services.0.items.0'));             // does a service item carry employeeIds / bookableBy?
```

- [ ] **Step 2: Look for a link in EITHER direction** — an employee node with `serviceIds`/`offerings`/`services`, OR a service item with `employeeIds`/`employees`/`bookableBy`. Also scan `props.pageProps.data` siblings of `location` for a separate employee↔service edge.

- [ ] **Step 3: Decide + record the finding** (with the exact JSON path as evidence) in the Task 2 status doc:
  - **`MAPPING_PRESENT`** → Task 1 builds `extractServices(array $location, ?string $employeeId = null)` that filters the whole-location items to the picked employee's set when the mapping is available. **Per-employee filtering preserved; GraphQL gone; no manual picking.**
  - **`MAPPING_ABSENT`** → Task 1 as written (whole-location menu + `hiddenServiceIds` manual curation). The partner API (OAuth) is then the only path to restore auto-filtering — noted as future work.

- [ ] **Step 4: Hand the decision to Task 1** — no code, no commit (investigation only). The recorded branch tells Task 1 which variant of `extractServices` to implement.

---

## Task 1: Drop the GraphQL impersonation from `FreshaController`

**Depends on Task 0's decision.** The GraphQL deletion is identical either way; only the `extractServices` signature + the two call sites differ (whole-menu vs per-employee-filtered). Both variants are shown in Step 3.

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/FreshaController.php`
- Test: `tests/Feature/Platforms/FreshaPayloadTest.php` (add cases)

**Interfaces:** `saveSelection()` and `employeeServices()` keep their exact response shapes; internally they now build `$services` only from `extractServices($location)`.

**Soft-spot note:** match the fetch-mocking harness the existing `FreshaPayloadTest` uses (it already exercises `connect`/`saveSelection`, which call `fetchLocation()` → `SafeUrlFetcher::fetch`). Reuse that page-HTML fixture; the new assertion is simply that no request goes to `fresha.com/graphql`.

- [ ] **Step 1: Write the failing tests**

```php
use Illuminate\Support\Facades\Http;

it('employeeServices returns the public-page menu without calling the Fresha GraphQL', function () {
    Http::fake([
        'www.fresha.com/graphql' => Http::response(['unexpected' => true], 200),
        // page fetch fixture served via the harness FreshaPayloadTest already uses
    ]);

    // ... connect a fresha URL for the user (reuse the file's setup), then:
    $res = $this->getJson('/api/integrations/fresha/employee-services?employeeId=emp-1');

    $res->assertOk();
    // Services came from the location page's extractServices(), not GraphQL:
    expect($res->json('data.services'))->not->toBeEmpty();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fresha.com/graphql'));
});

it('saveSelection builds services from the public page (no GraphQL)', function () {
    Http::fake(['www.fresha.com/graphql' => Http::response([], 200) /* + page fixture */]);
    // ... connect + saveSelection for a known employeeId ...
    // assert the saved selection.services is non-empty and no graphql request was sent
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fresha.com/graphql'));
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/Platforms/FreshaPayloadTest.php`
Expected: FAIL — the current code still POSTs to `fresha.com/graphql`.

- [ ] **Step 3: Delete `fetchEmployeeServices()` and rewire the two call sites**

In `app/Http/Controllers/Api/Platforms/FreshaController.php`:

**(a)** In `saveSelection()`, replace the GraphQL branch (currently lines ~158–162):

```php
        // Services from the public location page (was: per-employee booking GraphQL,
        // removed as legal remediation #1 — see docs/legal/2026-06-01-…).
        // MAPPING_ABSENT variant (whole-location menu):
        $services = $this->extractServices($location);
        // MAPPING_PRESENT variant (Task 0 found employee↔service data) — instead:
        // $services = $this->extractServices($location, $validated['employeeId']);
```

**(b)** In `employeeServices()`, replace (currently lines ~197–199):

```php
        // MAPPING_ABSENT: $services = $this->extractServices($this->fetchLocation($url));
        // MAPPING_PRESENT: pass $validated['employeeId'] as the 2nd arg.
        $services = $this->extractServices($this->fetchLocation($url), $validated['employeeId'] ?? null);
```

**(a/b) MAPPING_PRESENT only:** extend `extractServices()` to `extractServices(array $location, ?string $employeeId = null)` — when `$employeeId` is non-null and the public page carries the employee↔service link Task 0 found, filter the flattened items to that employee's set; when `$employeeId` is null or the link is absent for a row, return the item (fail-open to the whole menu). Add a test asserting a BY-EMPLOYEE salon returns only the picked employee's services.

**(c)** Delete the entire `fetchEmployeeServices()` method (lines ~291–406).

**(d)** Delete the now-unused `slugFromUrl()` helper (lines ~285–289) — it fed only the GraphQL call. (Verified: no other caller.)

**(e)** Delete the `GRAPHQL_URL` constant (line 47) and the pinned-hash comment block (lines ~45–54).

**(f)** Remove now-unused imports: `use Illuminate\Support\Facades\Http;` and `use Throwable;` (both only used by the deleted method). Keep `Log` only if still referenced elsewhere — after deletion it is not, so remove `use Illuminate\Support\Facades\Log;` too. (Run pint / static check to confirm no other use.)

- [ ] **Step 4: Fix the stale header comment**

Replace the class docblock (lines ~23–32) with an accurate one:

```php
// Fresha integration — a connected salon's booking URL + the team/services menu
// scraped from the page's __NEXT_DATA__ blob, stored per-user in
// site.platform_connections and rendered on the sitepage. The private booking
// GraphQL client-impersonation was REMOVED (legal remediation #1,
// docs/legal/2026-06-01-scraping-legal-review.md) — services come from the public
// location page only. Fully-compliant path (Fresha partner API / OAuth) is a
// separate future effort; this reaches the review's MEDIUM tier for pilot.
```

- [ ] **Step 5: Run to verify the tests pass + no dead references**

Run: `php artisan test tests/Feature/Platforms/FreshaPayloadTest.php`
Run: `grep -rn "fetchEmployeeServices\|GRAPHQL_URL\|slugFromUrl" app/` → expect no matches.
Expected: PASS; no dead references.

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Http/Controllers/Api/Platforms/FreshaController.php tests/Feature/Platforms/FreshaPayloadTest.php
git add app/Http/Controllers/Api/Platforms/FreshaController.php tests/Feature/Platforms/FreshaPayloadTest.php
git commit -m "fix(fresha): drop private-GraphQL impersonation, use public-page menu (legal remediation #1)"
```

---

## Task 2: Remove the pinned-hash config/env + full-suite gate

**Files:**
- Modify: `config/services.php`, `.env.example`
- Create: `docs/legal/2026-07-02-fresha-remediation-status.md`

- [ ] **Step 1: Remove the config block**

In `config/services.php`, delete the `fresha` block (lines ~102–105):

```php
    'fresha' => [
        'booking_init_hash' => env('FRESHA_BOOKING_INIT_HASH', '…'),
        'client_version' => env('FRESHA_CLIENT_VERSION', '…'),
    ],
```

- [ ] **Step 2: Remove the env keys**

In `.env.example`, delete lines 415–416 (`FRESHA_BOOKING_INIT_HASH=`, `FRESHA_CLIENT_VERSION=`).

- [ ] **Step 3: Record the legal-status delta**

Create `docs/legal/2026-07-02-fresha-remediation-status.md`: a short note stating the GraphQL impersonation was removed (remediation #1 done), Fresha is now MEDIUM (public-page scrape), what's still outstanding for FULL compliance (partner API / OAuth + embed + licence-in-ToS), and that Australian-counsel sign-off is still required before launch per the 2026-06-01 review. Cross-link the review.

- [ ] **Step 4: Full suite**

Run: `composer test`
Expected: PASS — full suite green in the main checkout. Watch `FreshaPayloadTest`, the AAL2 security test, and `IntegrationContractGoldenMasterTest` (the `/employee-services` route still exists, so the golden master should be unaffected).

- [ ] **Step 5: Commit**

```bash
php artisan pint config/services.php
git add config/services.php .env.example docs/legal/2026-07-02-fresha-remediation-status.md
git commit -m "chore(fresha): remove pinned booking-GraphQL hash config/env (legal remediation #1)"
```

---

## Deferred — decisions for you (NOT in this plan)

1. **Async-ify the remaining page fetches (JOB-1 Phase 2).** After the GraphQL drop, the outbound HTTP left on the request thread is `fetchLocation()` (a normal public-page GET) in `connect`/`team`/`saveSelection`/`employeeServices`. The acute 12s risk is gone; whether to move these page GETs off-thread (202 + poll, like Plan 3) is a lower-priority hygiene call and a bigger UX change to the interactive team-picker. **Recommendation:** defer; revisit if these show up as slow FPM holds in Nightwatch.
2. **De-spoof the User-Agent.** `fetchLocation()` still sends a fake Chrome UA — the review calls the spoofing a bad-faith/evasion aggravator. Switching to an honest `PartnaBot` UA strengthens the good-faith posture but risks Fresha bot-blocking the fetch. **Recommendation:** test an honest UA against a live Fresha page; adopt if it isn't blocked. Counsel input.
3. **The fully-compliant path (Fresha partner API / OAuth).** The durable fix. Larger effort; plan separately if/when Fresha graduates from pilot.

---

## Self-Review

**1. Spec coverage:** legal remediation #1 (drop GraphQL impersonation) → Task 1 ✓; pinned-hash config/env removed → Task 2 ✓; JOB-1's 12s Fresha call eliminated as a side-effect ✓; API contract + endpoints preserved ✓; stale test-mode header corrected ✓.

**2. Placeholder scan:** the one soft spot (matching `FreshaPayloadTest`'s page-fetch harness) is explicitly flagged with the pinned assertion (no `fresha.com/graphql` request; services from the public page). Line numbers are approximate ("~") because deletions shift them — the anchors (method/const names) are exact.

**3. Type consistency:** `extractServices(array $location): array` (unchanged) now feeds both call sites; response shapes via `FreshaSelectionResource` unchanged. No new symbols introduced.

**Legal caveat restated:** this reaches MEDIUM, not clean. Australian-counsel sign-off before launch still required per the review.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-02-fresha-graphql-remediation.md`. **Legal blocker gate** — needs your sign-off, and the review's call for counsel sign-off before launch still stands. Two execution modes once approved:

**1. Subagent-Driven (recommended)** — fresh subagent per task, independent review between tasks.

**2. Inline Execution** — task-by-task in this session with checkpoints.
