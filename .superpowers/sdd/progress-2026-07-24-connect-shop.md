# W9 · Shop async connect — progress ledger

Branch: `audit-fix/connect-shop-async-2026-07-24`
Worktree: `/Users/joshuahunter/Herd/Side Street/backend-wt/connect-shop-async-2026-07-24`
Base: `origin/development` @ `58c70bc0`
Source prompt: `docs/superpowers/plans/2026-07-24-connect-w9-shop-PROMPT.md`
Design: `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md` §3.5, §5 (W9)

---

## Step 0 — anchor verification (2026-07-24, against `58c70bc0`)

All anchors in the prompt's table hold. No `PREMISE-STALE` on line numbers.

| Symbol | Prompt | Verified |
|---|---|---|
| `MAX_BRANDS = 5` | `:66` | ✅ `:66` |
| `MARKER` | `:81` | ✅ `:81` |
| `brands()` | `:100` | ✅ `:100` |
| `addBrand()` | `:110` | ✅ `:110` |
| cap check | `:171-172` | ✅ `:171` |
| `updateBrand()` | `:228` | ✅ `:228` |
| `catalog()` | `:340` | ✅ `:340` |
| `brandProducts()` | `:361` | ✅ `:361` |
| `setProducts()` | `:380` | ✅ `:380` |
| `selection()` | `:441` | ✅ `:441` |
| `addProduct()` | `:484` | ✅ `:484` |
| decoupling comment | `:54` | ✅ `:54` |

---

## Findings that changed the unit's shape

### PREMISE-STALE 1 — the mission's "reuse `ConnectFetchJob`" does not hold

`ConnectFetchJob::handle()` (`:102-113`) resolves the platform's registered
`FetchStrategy` from `PlatformRegistry`. Shop **is** registered
(`PlatformRegistryServiceProvider.php:351-358`) — with `ShopFetch`, which is the
6-hourly **latest-mode product re-sync** over brands that already exist. It never
touches `ShopProviderDetector` and cannot turn a pasted URL into a brand: it would
throw `FetchNotModifiedException` → `markOk()` → row reports success having created
nothing.

Corroborated by `DefersBespokeConnect`'s own docblock: *"Known limitation:
hardcodes `ConnectFetchJob`. A future platform needing a different job writes its
own dispatch and does not use this helper."*

→ **Shop gets its own job (`ShopBrandConnectJob`).**

### NEW BLOCKER 4 — `ConnectFetchJob::uniqueId()` collapses N brands into 1 job

```php
public int $uniqueFor = 120;
public function uniqueId(): string { return "{$this->platform}:{$this->connectionId}"; }
```

Shop is the ONLY platform where one connection row fans out to many brands
(`MAX_BRANDS = 5`). Two brands added inside 120 s share a `uniqueId`; the second
job is silently dropped and brand B stays `pending` forever.

→ **Shop's job keys uniqueness on the brand, not the connection.**

### NEW BLOCKER 5 — the shared poll action cannot be reused

`DefersBespokeConnect::bespokeConnectStatus()` reads `$row->last_refresh_status`
off the **connection** row — the prompt's blocker 2 restated (one status field, N
brands).

→ **Shop gets its own per-brand poll endpoint.**

### Cost split (measured structurally, `ShopProviderDetector.php:70-119`)

- `detectDetailed()` — 1–4 sequential HTTP probes (bigcartel host check is free;
  then shopify → woocommerce → squarespace → generic). Yields `provider` truthfully.
- `brandProfileFor()` (`ShopController.php:658`) — **0–1 further HTTP**: zero for
  generic / BigCartel / client-assisted (already in hand), one for Shopify /
  WooCommerce / Squarespace. Yields `brand_id`.
- The genuinely fat product scrapes live in `brandProducts()` / `setProducts()`,
  already cache-backed and decoupled from connect.

→ There is **no cheap-`identify()` seam** in Shop the way there is in Apple/Skool.
Recorded so W9's modest win is not overstated later.

---

## Decisions (Josh, 2026-07-24)

1. **Design path: (c) hybrid** — detection stays synchronous (truthful `provider`
   + `brand_id`, so both `NOT NULL` columns are satisfiable); write a real
   `ShopBrand` row with `connect_status='pending'`; defer only `brandProfileFor()`.
   One nullable-column migration. No sentinel `provider`, no provisional-key
   rename, no `UNIQUE (connection_id, brand_id)` collision, no id-swap on the
   wire. Same shape as W6 (Fresha).
2. **W2 reuse: cherry-pick `904d51c7`** onto this branch (`e49f302b`). Identical
   blob → merges as a no-op whichever branch lands first. Shop uses exactly one
   method from it: `shouldDeferConnect('shop')`.
3. **R1 — proceed with all 7 units**, with the quantified win on record: only
   shopify / woocommerce / squarespace defer any HTTP (2 / 2 / 1 calls); bigcartel,
   generic and client-assisted defer **zero** and stay synchronous. The probe
   cascade stays synchronous by design — that is what makes both `NOT NULL`
   columns truthful.
4. **R2 — provider-dependent status code.** `POST /brands` returns `202` only when
   a job is actually dispatched, `200` with the complete brand otherwise.
   Documented loudly in the frontend contract (Unit 6).
5. **R3 (third staleness-check copy)** — accepted, not generalised. Editing
   `DefersBespokeConnect` would convert a guaranteed no-op merge with the sibling
   branch into a real conflict for a cosmetic win.
6. **R4 (stranded-brand backlog-alarm gap)** — accepted as a post-merge follow-up.
   The fix belongs in `CheckPlatformRefreshBacklogCommand`, which the sibling
   branch rewrote in `7330baad`; doing it here collides head-on.
7. **Open decision 5 (`setProducts()` async?)** — **no**. Unit 5 is the lock
   restructure only. The catalog is warm on the common path (the picker was just
   open) and a 202 there would need a second per-selection pending state for no win.

### Corrections absorbed into the plan

- The ledger's "0–1 further HTTP" understated the deferred work: the full
  `fetchBrand()` is up to **2** calls for Shopify and WooCommerce. `0–1` was the
  cost to reach `brand_id`, not the cost of the whole deferred profile.
- The design sketch's "warm the picker catalog in the job" bullet is a **no-op**:
  `$detectedProducts` is non-null only for generic and client-assisted, both of
  which stay synchronous. The warm stays where it already is.

---

## Units

Plan: `.superpowers/sdd/PLAN-2026-07-24-connect-shop.md` (Opus 4.8).

| # | Unit | Size | Status |
|---|---|---|---|
| 0 | Worktree + baseline | — | ✅ `58c70bc0`, real `vendor`/`.env`, W2 cherry-picked `e49f302b`, baseline **5128 passed / 158 skipped / 0 failed** (307 s) |
| P | Plan (Opus 4.8) | — | ✅ all 8 sub-questions resolved, signed off |
| 1 | Migration + model + SQLite mirror | S | ✅ implemented + independently reviewed (no P0/P1) |
| 2 | Brand-identity seam (behaviour-neutral) | M | ✅ implemented + independently reviewed (clean on all 9 points; 1 nit fixed) |
| 3 | `ShopBrandConnectJob` (inert) | M | ✅ implemented + reviewed; P1 compare-and-set fixed, P2 retracted as false |
| 4 | Deferred `addBrand()` + poll + resource | L | ✅ implemented + reviewed; 2 gaps fixed (Shopify currency, atomic sync-path id) |
| 5 | `setProducts()` fetch-outside-lock | S/M | ✅ implemented; review in flight |
| 6 | Frontend contract + design-doc tick | S | ✅ §8 Shop section + §3.5/§5 amended; §6 decision 6 + §7 sequence marked superseded |
| 7 | Apply migration to dev Supabase | S | ✅ applied + verified on `glncumufgaqcmqhzwrxm`; ledger realigned |
| R | Whole-branch review (Opus 4.8) + fix pass | — | ✅ 2 P1 + 1 P2 + 1 nit found and fixed |

**Final state: `composer test` 5209 passed / 159 skipped / 0 failed** (exit 0).
`pint --dirty --test` clean. (Repo-wide `pint --test` flags 13 files, all with
**zero diff** against `origin/development` — pre-existing baseline drift, left
alone to keep this branch surgical.)

---

## Log

- **2026-07-24** — Worktree created off `origin/development` `58c70bc0`. Real
  `vendor/` copied (lockfile parity verified against main `7d81ba70` — identical),
  real `.env` copied, not symlinked. `ShopRelationalStorageTest` 14/14 green as a
  boot smoke test. Full baseline running.
- **2026-07-24** — Step 0 anchors verified; three findings above recorded; both
  gate decisions taken. `904d51c7` cherry-picked as `e49f302b`.
- **2026-07-24 — Unit 1 done.** `20260724150000_shop_brands_connect_status.sql`
  (two-window `NOT VALID` → `VALIDATE`, two nullable no-default columns, no index),
  `ShopBrand::CONNECT_STATUSES` + `$fillable`, SQLite mirror, constraint-exists
  test, vocabulary-lockstep test, content-proxy round-trip.
  - Guards: migration-safety lint **passed**; no-laravel-migrations **clean**.
  - `tests/Feature/Platforms/` **1051 passed**, 0 failed.
  - `tests/Feature/Database/` **43 passed / 45 skipped**, 0 failed.
  - `./vendor/bin/pint --dirty` passed.
  - Independent review (Sonnet, separate agent): **no P0/P1**. Mass-assignment
    trace clean — every `ShopBrand` write builds its array by hand and neither
    Form Request validates a `connectStatus` key, so the new `$fillable` entries
    are unreachable from client input. Zero production reads/writes of either
    column outside `$fillable`, so behaviour is genuinely unchanged.
  - Two P2/nits, both resolved as doc fixes: T26 lives in
    `ShopRelationalStorageTest` (deliberate — Unit 1 must not create the file
    Unit 4 owns; plan matrix corrected), and the SQLite mirror's column position
    differs from the plan snippet with no functional effect.
  - ⚠ The constraint-exists test **skips on SQLite** and is therefore unproven
    until Unit 7 applies the migration to dev Postgres. Expected, not a gap.
  - Note: `php artisan pint` does not exist; the binary is `./vendor/bin/pint`.
    `CLAUDE.md`'s Commands block says `php artisan pint` and is wrong.
  - True post-Unit-1 baseline: **5150 passed / 159 skipped / 0 failed**. The +22
    over the 5128 opening baseline reconciles exactly: 20 tests from
    `DefersBespokeConnectTest` (the W2 cherry-pick landed after the baseline run
    started) + Unit 1's 2 passing tests; the +1 skip is the Postgres-only
    constraint test.

### Unit 2 finding — a collaborator-surface change breaks strict doubles

`ShopProviderDetector:98` now calls `ShopifyScraper::probeMeta()` where it used to
call `probe()`. **Production behaviour is unchanged** — `probe()` is now literally
`probeMeta($origin) !== null`, `probeMeta()` applies the identical condition, and
it is the *same single* `/meta.json` GET, so the HTTP-call count does not move.

But six test files mock `ShopifyScraper` as a **strict Mockery double** stubbing
`probe()` only, so the unstubbed `probeMeta()` throws. Full
`tests/Feature/Platforms/` run: **15 failed / 1036 passed**, every failure the same
`Mockery_..._ShopifyScraper->probeMeta()` from `ShopProviderDetector.php:98`:

`ShopRelationalStorageTest` (8) · `IntegrationsV2ConnectionTest` (3) ·
`ConnectFetchBudgetTest` (1) · `IntegrationsV3ConnectionTest` (1) ·
`PlatformResourceContractTest` (1) · `ScraperPlatformsConnectionTest` (1)

**The lesson:** these doubles mock the *scraper*, so they encode which methods the
detector calls. `ShopUrlValidationTest` mocks `SafeUrlFetcher` one level lower and
is unaffected — mocking the transport instead of the collaborator survives
refactors that mocking the collaborator does not.

**The trap in the fix** (caught before it bit): each block's `fetchBrand()` double
returns a hand-picked id (`rel-brand`, `again-brand`, `pub-brand`, `purge-brand`,
`modes-brand`) that the tests then route on. Today `addBrand()` reads its `$id`
from the profiler, so `probeMeta`'s contents are inert — but **Unit 4 switches
`addBrand()` to derive the id via `ShopBrandIdentity`**, which for Shopify reads
`$meta['id']`. A `probeMeta` stub returning `[]` or an arbitrary id would go green
here and fail confusingly in Unit 4. So each `probeMeta` stub must return a meta
whose `id` equals that block's `fetchBrand` id, and must return `null` wherever
`probe()` returned `false`.

**Resolved.** All ~10 stubs added under that rule; `git diff --numstat tests/`
shows **zero deletions** in every test file, so no assertion changed.
`tests/Feature/Platforms/` back to **1051 passed / 0 failed** — identical to the
pre-Unit-2 count.

- **2026-07-24 — Unit 2 done.** `brandIdFrom()`/`probeMeta()` on `ShopifyScraper`
  (`probe()` is now literally `probeMeta($origin) !== null`), `brandIdFor()` on
  `WooCommerceScraper` (now shared by `fetchBrand()` **and** `brandFromClient()`,
  which held a second copy), `idFromOrigin()` made public on `SquarespaceScraper`,
  `'meta'` carried on the detected array, new `ShopBrandIdentity` +
  `ShopBrandProfiler`, `brandProfileFor()` deleted from the controller.
  - Independent review (Sonnet): **clean on all 9 checks.** Confirmed `probe()` is
    behaviour-identical (same expression, captured not discarded), **no HTTP-call
    increase** on any branch, `ShopBrandIdentity` genuinely delegates to the same
    scraper methods `fetchBrand()` calls (no reimplemented slug expression), the
    Woo/Squarespace divergence is pinned to the **concrete strings**
    `www-example-com` / `example-com`, and `addBrand()` is behaviour-neutral.
  - One nit fixed: the extraction left `SquarespaceScraper` as a dead constructor
    dependency. Removed it, and also removed `ShopBrandIdentity` from the
    constructor — Unit 4 injects it when it actually uses it, so no unit leaves
    dead code behind. Verified: 377 + 101 tests green after the removal.

- **2026-07-24 — Unit 3 done.** `app/Jobs/Platforms/ShopBrandConnectJob.php`,
  inert (nothing dispatches it until Unit 4).
  - `uniqueId()` = `"shop-brand:{$brandRowId}"` — **the NEW BLOCKER 4 fix.**
    `ConnectFetchJob`'s `"{platform}:{connectionId}"` would collapse two brands
    added within `uniqueFor` (120 s) into one job and strand the second in
    `pending` forever, because Shop is the only platform where one connection
    fans out to up to 5 content rows. Pinned by a regression test.
  - `tries=3`, `backoff=[5,20]`, `timeout=45`, `maxExceptions=2`, `middleware()=[]`
    — mirrors `ConnectFetchJob`'s human-paced (not cron-paced) tuning.
  - Fetch outside the lock; single locked write on the **same**
    `CacheKeyGenerator::platformConnectionLock('shop', $userId)` key
    `withConnectionLock()` uses, so it can never race a dashboard brand edit.
  - `LockTimeoutException` → terminal `'failed'`, **never** `$this->release()`
    (on the sync driver `release()` is a silent no-op that would strand the row).
  - Explicit `IntegrationConnectionCacheRefresher::refresh()` on success — the
    observer's `wasChanged('payload')` gate can never fire for Shop's frozen
    MARKER, and it watches `IntegrationConnection`, never `ShopBrand`.
  - `brand_id` deliberately never rewritten in the settle: `forRow()` re-reads the
    **stored** url/source_url and could resolve a drifted id; recomputing would
    key-shift an existing row.
  - Availability API confirmed: `FeatureAvailability::for($user)->allows('integration.shop')`
    — the plan's guess was correct (verified via `ManagesIntegrationConnection::assertPlatformAvailable()`).
  - Null-safety checked: `FetchBudget::open()` is transparent (returns `$work()`),
    and all three `fetchBrand()` methods are typed `: array` with all five keys
    present on every branch — so `$profile['name']` cannot fatal. The recurring
    `tryFetch`-null-deref pattern does **not** apply here.

### ⚠ Unit 3 risk — three copies of two published contract strings

The job cannot reach `DefersBespokeConnect`'s `private const` sentences without
`use`-ing the trait, which would drag `deferredConnectResponse()` /
`bespokeConnectStatus()` (both `ApiController`-shaped) into a Job class **and**
touch a file that is currently byte-identical with the sibling Phase-3 branch.
So the two strings were forked as job-local consts. Current census:

```
"We couldn't save your connection just then — please try again."
  GenericPlatformController:249 · DefersBespokeConnect:38 · ShopBrandConnectJob:65
'We could not load that account. Please try again.'
  DefersBespokeConnect:40 · ConnectFetchJob:232 · ShopBrandConnectJob:67
```

These are **frontend-contract text** — the published contract tells the dashboard
these exact sentences mean "infrastructure failure, offer retry" as distinct from
a vendor miss. A reworded copy would drift the contract for some platforms and not
others with nothing to catch it, so a **lockstep test** pinning every copy to the
same literal is required (same idiom as `ConstraintVocabularyLockstepTest`).

**Resolved** — `tests/Unit/Platforms/ConnectErrorSentenceLockstepTest.php` reads
every live copy (Reflection for the `private const`s, regex-over-source for the
two inline literals, no fourth copy hardcoded) and diffs them. Validated by
deliberately rewording one copy and confirming a clear failure. Its extractor
throws loudly if a source shape changes, so it cannot degrade into a vacuous pass.

### ⚠ Unit 3 P1 (review finding) — unconditional write can clobber a newer settle

`ShouldBeUnique` locks are acquired by `PendingDispatch::shouldDispatch()` with a
`uniqueFor` TTL and released by `CallQueuedHandler::call()` **only on non-throwing
completion**; the exhaust-all-tries → `Worker::failJob()` path never releases and
just waits out the TTL. With `tries=3`, `backoff=[5,20]`, `timeout=45`, worst-case
elapsed ≈160 s **exceeds** `uniqueFor=120` — so a still-alive job A can overlap a
newly-dispatched job B. B settles correctly, then A's last attempt overwrites it;
worst case a **successful** connect is flipped back to `'failed'`.

Simpler variant with no queue involvement: the user re-adds the same store, the
synchronous path settles the row, and a stale job then clobbers it.

**Fix:** compare-and-set — both the success write and `markTerminal()` gain
`->where('connect_status', 'pending')`, and a 0-row result skips the cache purge.
`Eloquent\Builder::update()` calls `addUpdatedAtColumn()`, so `updated_at` (which
Unit 4's 5-minute staleness backstop reads) is still maintained; dropping to
`DB::table()` would have silently broken that.

**Resolved** — compare-and-set implemented on both writes; a 0-row result skips
the purge. Three regression tests added (stale job cannot clobber a settled row;
a late `failed()` cannot un-settle one; the happy path still settles).

### ❌ The review's P2 was WRONG — retracted, and the code removed

The review claimed the `ShouldBeUnique` lock stays held after a permanent failure,
silently swallowing a user's retry. **It does not.** `CallQueuedHandler::failed()`
calls `ensureUniqueJobLockIsReleased()` at `:348-350`, **before** invoking the
job's own `failed()` at `:359-360`, guarded by
`! commandShouldBeUniqueUntilProcessing()` — which is true for a plain
`ShouldBeUnique` job like this one. So the lock is released on every production
failure path, sync driver included.

An explicit release was added on my instruction, then **removed** along with its
test: it was dead code that implied a protection the framework already provides,
and its test only "passed" because it invoked `failed()` directly, bypassing the
handler that does the real release. A comment now records why there is
deliberately no release there, so nobody re-adds it.

**Process note:** the reviewer's P1 was correct and valuable; its P2 was a
plausible-but-false sub-claim that I propagated without checking the framework
first. Both agent findings and my own instructions need verifying against source.

**➜ Follow-up for after both branches merge:** `ConnectFetchJob` has the *identical*
unconditional-write pattern and identical tuning numbers, so the same race exists
for the eight registry platforms and the six bespoke ones. Deliberately NOT fixed
here — it is a sibling-branch file (see R4's reasoning).

### ⚠ Unit 4 review finding — dark-merge id was not atomic for Shopify

`addBrand()` derived `$id = $identity->for($detected)` **unconditionally**. For
Shopify that reads the `meta.json` body `probeMeta()` captured at **detection**
time, while the synchronous branch then called `profiler->forDetected()` →
`ShopifyScraper::fetchBrand()`, which performs its **own second** `GET /meta.json`.

So with the flag off — the state that must be byte-identical to pre-W9 — the row's
identity key and its display fields came from **two separate HTTP round-trips**.
Pre-W9, `$id = $brand['id']` came from the same single call, atomic by
construction. If the two responses ever disagreed (edge-cache inconsistency, a
`myshopify_domain` change mid-flight), the persisted `brand_id` would diverge from
what an identical re-POST derives, and the retry would create a **duplicate**
`ShopBrand` row instead of updating the existing one. The other five providers are
provably immune (same in-memory array, or pure HTTP-free functions of one input).

**Fixed structurally, not documented:** `$id` is now derived **per branch** —
`$brand['id']` on the synchronous path (exactly pre-W9), `$identity->for()` only on
the deferred path, which has no profile fetch to derive from. `identity->for()` now
appears exactly once in the controller.

**Test-design lesson recorded in `ShopBrandIdentityTest`:** that test could never
have caught this. `Http::fake()` serves the same canned body to every call site, so
it proves *expression* parity, not *fetch* atomicity — a blind spot shared by any
test built on a stubbed transport.

### ⚠ Unit 4 gap — Shopify currency omitted on the deferred branch (orchestrator error)

Plan §3(c) resolved that Shopify's `currency` is written on **both** branches (it is
free from the carried `meta`), and §3(e) relied on it. The Unit 4 brief omitted that
exception; the implementer flagged the contradiction and followed the brief.
Consequence, verified: `ShopCatalog:62` passes `$brand['currency']` into
`shopify->fetchProducts()`, used at `ShopifyScraper:189` as the per-variant
fallback — so the dashboard picker showed Shopify prices with **no currency** during
the pending window.

**Fixed** by extracting `ShopifyScraper::currencyFrom(?array $meta)` (one expression,
`fetchBrand()` and the deferred path as its two callers — the `brandIdFrom()`
precedent) plus `ShopBrandProfiler::syncCurrencyFor()`. Woo's currency is always
null; Squarespace's genuinely requires the deferred fetch, so it stays omitted.

### 🚨 PROCESS VIOLATION — a subagent ran `git stash` (Unit 5)

Despite an explicit prohibition in its brief, the Unit 5 implementer ran
`git stash push -- ShopController.php` to attempt a before/after check, not
realising that stashing one file reverts that file's **entire** uncommitted history
— i.e. all of Units 2, 3 and 4, not just its own diff. It self-reported plainly,
popped immediately, and redid the check safely with a plain text edit.

**Verified clean by the orchestrator, not taken on trust:**
- `git stash list` holds only a **pre-existing foreign** entry
  (`On audit-fix/middleware-2026-07-06`) from an earlier session — untouched, and
  not ours to clear.
- Every expected new file present; `ShopController.php` at 922 lines with all unit
  markers intact (`DEFERRABLE_PROVIDERS`, `connectStatus`, one `identity->for`,
  `syncCurrencyFor`, the byte-identity comment, the pre-lock read) and
  `--numstat` showing 227/56.
- Full `composer test`: **5200 passed / 159 skipped / 0 failed**, +50 over the
  post-Unit-1 baseline, reconciling exactly (8 identity + 12 job + 2 lockstep +
  22 async-connect + 6 selection-lock).

It escaped damage only by luck of ordering: the agent's own stash was `stash@{0}`
when it popped. Had another session stashed in between, the pop would have applied
**foreign WIP** into this worktree. `git stash` is repo-global and must stay
forbidden in every subagent brief.

- **2026-07-24 — Unit 5 done.** `setProducts()` restructured to fetch-outside /
  write-inside. The pre-lock read (for the 404 + a brand shape for
  `providerProducts()`) and the authoritative in-lock re-read are **both**
  deliberate; the comment explains the delete-between-read-and-write race so
  neither gets "optimised" away. Response shape, status codes and the
  `selection_mode` flip are unchanged.
  - New `tests/Feature/Platforms/ShopSelectionLockTest.php` (6 tests). Its
    structural proof was validated **against the old code**: the implementer
    temporarily restored the pre-fix body via a text edit (not `git stash`) and
    confirmed `Failed asserting that false is true`, then reapplied. The
    mechanism relies on `CACHE_STORE=array` and `ArrayLock::acquire()` not
    checking ownership, so a second lock object for the same key genuinely fails
    while another holds it.
  - `ShopRelationalStorageTest` passed **unmodified** (`--numstat` shows only
    Unit 1's 43-line addition).

- **2026-07-24 — Unit 7 done.** Migration applied to **dev** Supabase
  (`glncumufgaqcmqhzwrxm`) as two separate `apply_migration` calls, deliberately
  preserving the two-window split — the MCP wraps each call in its own
  transaction, so one combined call would have collapsed `NOT VALID` and
  `VALIDATE` into a single transaction and defeated CONVENTIONS.md §2.
  - Verified on the live DB, not trusted from the success flag:
    `convalidated = true`; both columns `text`, `is_nullable = YES`,
    `column_default = null`; all 9 existing rows NULL for both — no backfill, no
    data change.
  - Pre-flight census: 9 brands across 6 connections, 0 individual buckets.
  - **Ledger realigned.** `apply_migration` stamps its own version, so it landed
    as `20260724131131` + `20260724131142` while the repo file is
    `20260724150000_shop_brands_connect_status.sql`. Inserted `20260724150000`
    and deleted the two MCP entries, so a future `db push` sees the repo file as
    applied rather than pending. (The file is idempotent either way —
    `ADD COLUMN IF NOT EXISTS` + drop-then-add — but a clean ledger avoids the
    question.)
  - ⚠ **Dev only.** Prod remains on the pre-standalone schema; this migration has
    NOT been applied there.

- **2026-07-24 — final whole-branch review (Opus).** Four findings, all fixed;
  both P1s were proven empirically by the reviewer, not merely argued.

  **P1-A — a retry of a pending brand polled `failed` immediately.**
  `updateOrCreate` only UPDATEs when something is dirty. Re-POSTing an
  already-pending brand writes byte-identical values, so nothing is dirty,
  `updateTimestamps()` never runs, and `updated_at` keeps its original value —
  so the very next poll fires the 5-minute stale backstop on a retry that is
  genuinely in flight. Since the contract treats `failed` as terminal, the client
  stops polling. This broke exactly the retry path plan §3g depends on.
  **Fixed with `$brandRow->touch()`.** Note the orchestrator's suggested fix
  (`$values['updated_at'] = now()`) would have **silently done nothing** —
  `updated_at` is not in `ShopBrand::$fillable`, and `updateOrCreate()`'s
  `fill()->save()` drops non-fillable keys. The implementer caught that and
  recorded it in a comment so the broken form isn't reintroduced. The
  synchronous branch needs no equivalent: it settles `connect_status` to null,
  and the backstop only reads `updated_at` for `pending` rows.

  **P1-B — a pending brand with products rendered an empty public Shop page.**
  `SitepageDataResolverService`'s `shop_active_product_exists` counted
  `ShopProduct` rows via `brand → connection → user_id` with **no** status
  filter, while `PublicIntegrationConnectionResource::filterPayload()` rejects
  pending brands. Plan §3d assumed "a pending brand has zero products" — true only
  at 202 time, since `GET /brands/{id}/products` and `PUT …/selection` both work
  during the pending window **by design** (§3e). So page-presence said "shop",
  the payload was `[]`, and the result was CDN-cached. A stranded row stays
  `'pending'` forever (the backstop is synthetic, by design), making it permanent.
  **Fixed by aligning the predicates.** The SQL matters: `!= 'pending'` would be
  wrong, because `NULL != 'pending'` is NULL/falsy and would have hidden the page
  for **every** settled brand. Uses
  `whereNull('connect_status')->orWhere('connect_status', '<>', 'pending')`.

  **P2 — `$brandRow->fresh('products')` had moved outside the lock**, so a
  concurrent `removeBrand`/`forget` between lock release and the read gave
  `Call to a member function toBrandArray() on null` → 500. This applied on the
  **synchronous** branch too, making it the branch's only non-dark delta.
  **Fixed:** the response is built inside the closure again (`:339`); only the
  job dispatch stays outside (`:362`), which is all that needs to be.

  **Nit — the settle could blank a truthful currency.** The deferred write stores
  Shopify's real currency from the carried meta; the job then overwrote it with
  `$profile['currency']`, which is null when its own re-read degrades (scrapers
  degrade to nulls rather than throwing). **Fixed** with `?? $brand->currency`, so
  a genuine change still wins but a degraded null cannot clobber.

  **Verified clean by that review:** dark-merge byte-identity (T1 uses
  `assertExactJson`, not a key spot-check); every write audited against the four
  real DDL files (the bulk `insert()` supplies all NOT NULL columns, `Str::uuid7()`
  is byte-identical to `HasUuids::newUniqueId()` on v12.62, and json-string-into-
  `jsonb` is the existing `MenuFetchJob::persist()` idiom); job and all controller
  writers share one lock key and genuinely serialise; `DefersBespokeConnect.php`
  still byte-identical to the sibling's blob; contract §8 matches the code on all
  four poll states, the 202 body and the provider split.
