# RESULT — Pre-launch hardening PART 1 · run of 2026-08-26 05:07 AEST

**Status: BLOCKED AT SETUP — no code changed, no branch created, no commit made.**

**Cause: the checkout was already occupied by a live peer session.** Per `EXECUTE-PART-1.md` §1.3,
"the tree holds uncommitted changes that are not yours and cannot be safely stashed" is one of the
three conditions that ends the run early. It was true at 05:07 and had not cleared. This report is
written per §7, which requires it even on an early end.

**Everything below §2 is still useful:** all six units' premises were verified read-only against the
tree while blocked. A re-run can skip straight to implementation.

---

## 1. The blocker, with evidence

At launch (05:07:34 AEST) the repo was **not** in the state §0/§2 assumes:

```
$ git branch --show-current
audit-fix/pre-pilot-blockers-2026-08-25          # ← the PRE-PILOT tranche's branch, not development

$ git status --porcelain
 M app/Ingest/SourceProvisioner.php
 M app/Observers/Core/IntegrationConnectionObserver.php
 M tests/Feature/Platforms/CheckPlatformRefreshBacklogCommandTest.php
?? .audit-work/pre-pilot-blockers/
?? tests/Feature/Ingest/IngestSourceSyncReportingTest.php
?? tests/Feature/Ingest/SourceProvisionerInsertRaceTest.php
?? tests/Postgres/SourceProvisionerInsertRacePgTest.php
```

The uncommitted work is **live, not abandoned**:

- `ps -p 82480` → an **interactive** `claude` session (not `claude -p`), started Tue 25 Aug 19:05,
  still running.
- It wrote `.audit-work/pre-pilot-blockers/mutation-backups/RefreshObservabilityTest.php.orig` at
  **05:07:01** — 33 seconds before this run's first command.
- `.audit-work/pre-pilot-blockers/unit6-PLAN.md` (04:31) and the last commit
  `9a1881523 fix(audit): unit 5 …` place it **mid-unit-6 of 9**. Units 7, 8, 9 remain (8 and 9 are
  the pre-authorised ones).

### Why this run stopped rather than proceeding

`EXECUTE-PART-1.md` §0 is explicit on both counts:

> **This part runs THIRD overall.** … 1. `../2026-08-25-pre-pilot-blockers/EXECUTE.md` — own branch,
> **must be finished first**.

> **Never run two PARTs, or a PART and the pre-pilot tranche, concurrently in the same checkout.**
> A peer session switching the branch mid-task will silently destroy work.

§2 step 2 — `git checkout audit-fix/pre-launch-hardening-2026-08-25` — **is** that branch switch. Run
at 05:07 it would have pulled the branch out from under a session actively writing
`SourceProvisioner.php` and `IntegrationConnectionObserver.php`, destroying ~2.5h of in-flight unit-6
work plus three unwritten-to-git test files.

**The git worktree escape hatch is closed by the file itself:** §0 offers it only with a real
(non-symlinked) `vendor/` and `.env`, then rules "That setup is out of scope for this run: **run
sequentially.**"

So: no branch was created, no file was modified, nothing was stashed, and nothing was committed.
The only write this run made to the tree is **this file**.

> Safety check before writing even this: `.audit-work/` has stayed `??` across all five of the peer's
> commits, which proves the peer commits with targeted `git add`, not `git add -A`. An untracked file
> in `audits/…/` will not be swept into its next commit.

### The direct file collision, for the record

This is not merely a scheduling clash. **PART 1 unit 3's `#LIFE-13` targets
`app/Observers/Core/IntegrationConnectionObserver.php`** — one of the two files the peer session has
dirty right now. §1.2 trigger 7 (conflicting concurrent edit to the same file) would have fired on
that unit regardless.

---

## 2. Ledger

No unit was implemented. Dispositions are **premise verdicts from read-only inspection**, not fixes.
**Every source-audit checkbox was left unticked.**

| Unit | Findings | Disposition | Premise verdict (verified 2026-08-26 ~05:10, tree = `development@bb237b6b5` + 5 pre-pilot commits) |
|---|---|---|---|
| 2 | `CCH-3`, `CCH-6` | **NOT STARTED — blocked** | **CONFIRMED.** Re-diagnosis in the file is right. |
| 3 | `#LIFE-15`, `CCH-5`, `#LIFE-13` | **NOT STARTED — blocked**; `#LIFE-13` also a live file collision | **CONFIRMED ×3.** |
| 4 | `#LIFE-16`≡`#SCALE-20`, `#LIFE-17`≡`#SCALE-21` | **NOT STARTED — blocked** | **CONFIRMED**, but the `DECIDED` needs a judgement call — see §3.4. |
| 5 | `#CFG-3` | **NOT STARTED — blocked** | **CONFIRMED**, and a **5th mirror** was found — see §3.5. |
| 7 | `#JOB-3` | **NOT STARTED — blocked** | **CONFIRMED**, and the ⚠️ retry caveat **fires** — see §3.7. |
| 11a | `SEM-8` | **NOT STARTED — blocked** | **CONFIRMED**, and the obvious fix is wrong — see §3.11a. |
| 11b | `SEM-14` | **NOT STARTED — blocked** | ⚠️ **PRIMARY PREMISE REFUTED** — see §3.11b. |
| 11c | `SEM-17` | **NOT STARTED — blocked** | **CONFIRMED**, exact line identified — see §3.11c. |
| 11d | `#SEC-12`≡`SEM-6` | **NOT STARTED — blocked**; **would DEFER on inspection** | See §3.11d — the file's own ⚠️ escape clause fires. |

**Suite counts:** not run. A baseline `php artisan test --parallel` was deliberately **not** executed —
it would have run against the peer's half-finished unit-6 edits, producing a meaningless number while
competing for 10 cores with the peer's own test runs.

For reference, the peer's baseline on the same base (`.audit-work/pre-pilot-blockers/NOTES.md`) was
**GREEN — 9253 passed, 3 skipped, 0 failed, 73.86s**. No pre-existing red is known.

---

## 3. Verified premises — the pack a re-run should start from

Read-only, no writes. Audit line numbers were stale as warned; these are current.

### 3.2 Unit 2 — `CCH-3` · CONFIRMED
`app/Services/Cache/CacheLockService.php`

- `rememberLockedNullable` writes **unjittered** at **`:462`** (`self::NULL_SENTINEL`, `$nullTtl ?? $ttl`)
  and **`:464`** (`$value`, `$ttl`) — both bare `writeOrDegrade(...)`.
- `rememberLocked` routes through `writeWithJitter()` at `:164` and `:218`.
- Note the third path, **`:252-253`**: it is the one whose comment says *"Deliberately NOT
  writeWithJitter"* — but it **still applies jitter manually** (`self::applyJitter($ttl)` on both the
  key and its stale twin). So the deliberate exception is about the *stale-twin multiplier*, **not**
  about jitter. `:462`/`:464` are the only genuinely unjittered writes in the class.
  **`:252-253` is therefore the exact shape to copy** — it preserves nullable semantics *and* jitters,
  which is precisely what the `DECIDED` asks for.
- `applyJitter()` is `private static` — a test asserting two same-tick TTLs differ must go through a
  public entry point or the cache store, not call it directly.

`CCH-6` is stale as the file predicts: `AppleSearch::itunes()` reads
`config('partna.refresh.host_limits.itunes.cache_ttl_seconds')`. Tick as stale; no code change.

### 3.3 Unit 3 — CONFIRMED ×3
- **`#LIFE-15`** — `app/Site/Pools/PoolResolver.php` **`:813`** `} catch (QueryException) {` (the file
  moved to `app/Site/Pools/`, **not** `app/Services/PublicSite/`). The sibling `:463` `catch (\Throwable)`
  is a *different* catch — do not conflate them.
- **`CCH-5`** — `app/Routing/ShortLinkExpander.php` **`:135`** `} catch (\Throwable) {`, inside
  `resolveFinal()` (declared `:111`). Confirmed that `:76` documents the deliberate
  `rememberLockedNullable` choice and `:87` is the closure — **leave both alone**, and leave the TTL alone.
- **`#LIFE-13`** — `IntegrationConnectionObserver.php`'s last *commit* is `ef72a4605`, i.e. the
  pre-pilot unit-6 fix is **not committed yet**; it is in the peer's working tree. **A re-run must
  re-check this file after the peer commits** and, if it now reports instead of `Log::debug`, tick it
  `ALREADY FIXED — pre-pilot unit 6, <sha>` per the file's instruction.
- Precedent confirmed present: `App\Services\Analytics\Concerns\EscalatesRepeatedFaults`, used at
  `ContentPopularityReader.php:46`.

### 3.4 Unit 4 — CONFIRMED, but the `DECIDED` needs a call the file did not anticipate
`routes/console.php`

- **`:499-502`** `platforms:enrich-pending-cards --older-than=30` → `->dailyAt('03:20')`,
  `->withoutOverlapping()` (no arg), `->onOneServer()`. No `runInBackground()`, no `onFailure()`.
- **`:506-509`** `content:refresh-item-caches` → `->dailyAt('03:25')`, same three gaps. **Both confirmed.**
- The precedent, **`:154-159`**, is:
  ```php
  Schedule::command('analytics:compute-popularity')
      ->everyFifteenMinutes()
      ->onOneServer()
      ->withoutOverlapping(16) // 16min lock (cadence + 1) …
      ->runInBackground()
      ->onFailure($reportScheduledFailure('compute-popularity'));
  ```
  It **does** have `runInBackground()`, so per the `DECIDED` both targets should get it.

⚠️ **The one thing to flag:** the `DECIDED` says copy the precedent *"including its **exact expiry
value**"*. That value is **16**, and its own inline comment derives it as **"cadence + 1"** for a
**15-minute** command. The two targets are **daily**. A 16-minute lock on a daily command means any
run exceeding 16 minutes has its lock expire mid-flight — reintroducing the overlap the finding asks
us to prevent, on a `--older-than=30` backfill that is exactly the kind of job that can run long.

Copying `16` verbatim is defensible only as "any expiry beats the 24h default". **Recommendation:**
keep the *shape* verbatim but pick the expiry by the precedent's own stated **rule** (cadence-derived),
not its literal number — or find a **daily** `withoutOverlapping(N)` elsewhere in the file and match
that instead. Either is truer to "match an in-file precedent" than a 15-min number on a daily job.
Flagged rather than decided, because §1.1 has no gate and this is a judgement call.

### 3.5 Unit 5 — `#CFG-3` · CONFIRMED, plus a fifth mirror
| Site | Value |
|---|---|
| `app/Services/Brand/StoreBrandSeeder.php:53` | **5** ← the defect (used at `:152`) |
| `app/Http/Controllers/Api/Platforms/ShopController.php:105` | 10 (used at `:351-352`) |
| `app/Jobs/Platforms/ConnectStoreFromProductJob.php:56` | 10 (used at `:99`) |
| 7 × `app/Catalog/Definitions/*.php` | comments only — `Shopify, Gumroad, Squarespace, Stan, Woocommerce, BigCartel, Bandcamp` |

**New, not in the finding:** `app/Jobs/Platforms/ShopBrandConnectJob.php:40` carries a **stale comment**
— *"MAX_BRANDS=5 stores, so uniqueness/failure state has to key on the STORE"*. No constant, no
behaviour, but it is a fourth code-adjacent claim of `5` and should be corrected in the same commit
(this is exactly the CLAUDE.md "opportunistic P3 in a file you have open" lane).

Out of scope as instructed: `ManagesIntegrationConnection.php:638` `maxAccounts()` — see §4.

### 3.7 Unit 7 — `#JOB-3` · CONFIRMED, and the ⚠️ retry caveat **fires**
`app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php`. Current lines for the four dispositions:

| Path | Line | Per the `DECIDED` |
|---|---|---|
| `early_access.approve.build_failed` | `:121-126` (`report($e)` `:124`, `return` `:126`) | `$this->fail($e)` |
| `early_access.approve.build_collision` | `:133-138` (`return` `:138`) | leave as quiet `return` |
| `early_access.approve.scrape_failed` | `:173-176` (`report($e)` `:173`, `return` `:176`) | `$this->fail($e)` |
| generic `\Throwable` | `:198-199` | `$this->fail($e)` |

⚠️ **`public int $tries = 0;` (`:39`) — the job retries indefinitely by design.** The docblock at
`:27` and `:38` explains why: the Apify-stampede rate limiter **releases** the job when over budget,
and a release counts as an attempt, so a finite `$tries` would hard-fail on the first throttle.

This is the exact case the file's ⚠️ describes. Under `$tries = 0`, `$this->fail()` is the *only* way
a path can ever terminate as failed — which **strengthens** the case for the three `fail()` calls
(there is no retry budget that would eventually surface them). But it also means `fail()` on a
**transient** fault is final, with no retry. `scrape_failed` is the one plausibly-transient path of the
three. Per the file, **do not change the retry policy** — implement all three `fail()` calls as
decided and note this here, which is done.

Also worth knowing: two *earlier* quiet returns exist that the finding does not list —
`early_access.approve.no_link` (`:76-78`) and `early_access.approve.no_source` (`:96-98`). Both log at
`Log::info` and are structurally the same "legitimate no-op" as the collision path. **Leave them.**

### 3.11a `SEM-8` · CONFIRMED — and the obvious fix is wrong
`app/Http/Requests/Concerns/SiteOrderingValidationRules.php`

- Sibling rule **`:49`**: `'settings.actions.slots.*.position' => ['required','integer','min:0','max:…','distinct']`
  — Laravel's `integer` is **non-strict** and accepts the string `"1"`.
- Closure **`:100`**: `if (is_array($slot) && is_int($slot['position'] ?? null))` — strict, **rejects** `"1"`.
- Consequence: with string positions the payload passes `:49`, `$positions` stays `[]`, and the guard
  `&& $positions !== []` at `:104` makes the contiguity check a **no-op**. Confirmed exactly.

⚠️ **Do not simply swap `is_int` for `is_numeric`.** `:104` compares `$positions !== range(0, n-1)`,
and `range()` yields **ints**. Admitting `"0","1"` without casting gives `["0","1"] !== [0,1]` → `true`
→ **valid input starts failing 422**. The fix must **cast to `(int)` on collection**, e.g. admit when
the value is an int or an integer-valued numeric string, and push `(int) $slot['position']`.
A test must cover the string-position case in **both** directions: contiguous strings must PASS,
non-contiguous strings must FAIL. A one-direction test would go green on the broken fix.

### 3.11b `SEM-14` · ⚠️ PRIMARY PREMISE **REFUTED**
`app/Routing/ConnectionIdentity.php`

The finding says `matchExisting` *"folds case for every surface, ignoring the `$foldable` allowlist
computed two lines above"* and that the variable *"exists and is unused"*. **That is not the current
code.** Scheme 0 honours it:

```php
:112  $foldable = in_array($surfaceKey, self::CASE_INSENSITIVE_HANDLE_SURFACES, true);
:113  $needle1  = $foldable ? self::fold($identifier) : $identifier;
:115  $candidate = $foldable ? self::fold((string) $row->resource_id) : (string) $row->resource_id;
```

`:100-111` even records the *"critic catch, 2026-08-21"* about `discord.server` invite codes by name,
and `discord.server` is correctly **absent** from `CASE_INSENSITIVE_HANDLE_SURFACES` (`:51-60`).
`$foldable` is used, twice. **Disposition: `WONTFIX — premise refuted`, with this as the disproof.**

**But there is a narrow real residual, which §5 step 3 says to pin rather than re-file.** Schemes 2
and 3 fold **unconditionally**:
- `:128` `$needle = self::fold($identifier);`
- `:130` compares `self::fold((string) $row->canonical_key) === $needle`
- `:145` compares `self::fold($derived) === $needle`

So for a **non-foldable** surface (e.g. `discord.server`) with `canonical_key` populated, two
invite codes differing only in case still collapse. `canonical_key` is a real, written column
(`IntegrationConnection.php:118` fillable; stamped in `ManagesIntegrationConnection.php:220`), so this
is reachable, not theoretical — just much narrower than the finding claims, and **still within one
tenant** (the query is user-scoped): not a tenancy bug.
**Recommendation:** land a test pinning "a non-foldable surface does not fold in scheme 2", and treat
gating `:128`/`:145` on `$foldable` as the follow-up. It is a behaviour change to a dedupe path, which
is more than the S this unit was scoped as.

### 3.11c `SEM-17` · CONFIRMED — exact lines
`app/Site/Pools/PoolResolver.php`

- **The correct call, in the same file, `:462`**: `Carbon::parse((string) $value)->utc()->toIso8601String()`
  — with a docblock at `:442` stating outright *"`->utc()` is not decoration."*
- **The defect, `:843`**: `$iso = fn ($v) => $v === null ? null : Carbon::parse((string) $v)->toIso8601String();`
  — **no `->utc()`**. This closure feeds `$sourcesByItem` (`:831`), which reaches the payload at
  `:1286` as `'sources' => $sourcesByItem[$itemId] ?? []`.
- `sources` is in `DASHBOARD_ONLY_ITEM_KEYS` (`:73`), so this is **dashboard-only, not the public wire** —
  which keeps the unit inside PART 1's "nothing touches the public wire" claim. Worth saying when ticking.
- Fix is a one-token copy of `:462`'s form. Test must set a non-UTC app timezone or the assertion is vacuous.

### 3.11d `#SEC-12` ≡ `SEM-6` · **would DEFER** — the file's own escape clause fires
The 422 comes from `Rule::exists('pgsql.site.sites', 'id')` in a **Form Request**, not a controller —
and in **eight** of them:

```
app/Http/Requests/Api/PublicSite/Analytics/{Pageview,Ping,ItemSeen,Click,ActionTap,ActionSeen,SectionDwell,SectionSeen}Request.php
'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')]
```

The file's ⚠️ says verbatim: *"If the 422 comes from a Form Request validation rule rather than the
controller, moving it may change the response shape for other keys in the same request. If that is the
case and it is not cleanly separable, **DEFER 11d only** and keep 11a–11c."* It is not cleanly separable:

1. Eight files, each with sibling keys whose 422 shape must not change → **not S** (§1.2 trigger 3).
2. These are the **public analytics ingest routes** — the SEC-1 `Origin`-gated wire. Collapsing a
   success-adjacent status code there is a wire change a client could observe (§1.2 trigger 2).
3. `required_without:subdomain` means removing `exists` also has to preserve the
   `site_id`-XOR-`subdomain` semantics, per file.

**Recommendation: DEFER 11d, keep 11a-11c**, exactly as the file authorises. Note the oracle needs a
**guessed UUID** to exploit, so its practical severity is low, and prod has `core.users = 0`.

---

## 4. Surfaced, not worked

- **`ManagesIntegrationConnection::maxAccounts()` (`:638`)** — returns 10, comment *"mirrors shop's
  MAX_BRANDS"*. **Correctly out of scope** per unit 5: it caps *connected accounts per platform*,
  a different quantity that coincidentally shares the value. Folding it into the new config key would
  couple two unrelated limits. Left alone.
- **`ShopBrandConnectJob.php:40`** — stale `MAX_BRANDS=5` **comment** (new; not in `#CFG-3`). No
  behaviour. Fix opportunistically with unit 5.
- **`ConnectionIdentity` schemes 2 & 3 fold unconditionally** — the real residual behind the refuted
  `SEM-14`. See §3.11b.
- **`ApproveEarlyAccessBuildJob` `:76-78` / `:96-98`** — two further quiet returns not in `#JOB-3`,
  both legitimate no-ops. Left alone deliberately.
- **`PoolResolver` moved to `app/Site/Pools/`** — the audit's `app/Services/PublicSite/` path is dead.

---

## 5. Questions for Josh, with recommended answers

1. **Why did two sessions share one checkout?** PART 1 was launched at 05:07 (`timeout 14400 claude -p
   … EXECUTE-PART-1.md`) while the interactive pre-pilot session from 19:05 was still on unit 6 of 9.
   §0 requires them to be sequential.
   → **Recommended:** chain the launches (PART 1 starts only once the pre-pilot session has written
   `RESULT.md` **and** `git status` is clean), rather than launching PART 1 on a wall-clock guess. A
   wrapper that polls for both conditions before invoking `claude -p` would make this unattendable.

2. **Unit 4's expiry number** (§3.4) — copy `16` verbatim onto a *daily* command, or derive it from the
   precedent's stated cadence+1 rule?
   → **Recommended:** derive it. A 16-minute lock on a daily backfill can expire mid-run, which is the
   overlap the finding exists to prevent. Copying the *shape* verbatim is the safe part; copying a
   15-minute-cadence number onto a daily job is not.

3. **Unit 7 under `$tries = 0`** (§3.7) — `fail()` on `scrape_failed` is final, with no retry, on a
   plausibly-transient path.
   → **Recommended:** implement as decided (the alternative is that it stays invisible forever), and
   revisit the retry policy separately. Not changed here — out of scope per the file.

---

## 6. Handoff for PART 2

**This part changed no source files.** There is no conflict surface for PART 2 from this run.

The **real** handoff hazard is unchanged and applies to PART 2 as much as to PART 1:

- The pre-pilot tranche was still mid-**unit 6 of 9** at 05:10 on `audit-fix/pre-pilot-blockers-2026-08-25`,
  with `app/Ingest/SourceProvisioner.php` and `app/Observers/Core/IntegrationConnectionObserver.php`
  dirty. **PART 2 must not start until that session has finished and committed.**
- `IntegrationConnectionObserver.php` is contested between the pre-pilot tranche and **PART 1 unit 3
  (`#LIFE-13`)**. Re-check it after the peer commits; the file's own instruction is to tick
  `ALREADY FIXED` rather than re-fix.

## 7. Re-run instructions

Preconditions, all three:

```bash
pgrep -f 'claude' | grep -q 82480 || echo "peer gone"    # peer session exited
test -f audits/consolidation/2026-08-25-pre-pilot-blockers/RESULT.md   # pre-pilot wrote its report
git status --porcelain                                    # empty
```

Then follow `EXECUTE-PART-1.md` §2 from step 1 unchanged. Start implementation directly from §3 of
this document — the premises are verified; only `#LIFE-13` (§3.3) needs re-checking, because the code
it targets was still uncommitted when this run inspected it.

**Nothing was pushed. No PR was opened. No source-audit checkbox was ticked.**
