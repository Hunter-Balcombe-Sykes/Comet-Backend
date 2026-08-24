# EXECUTE — Pre-pilot P2 promotion tranche (2026-08-25)

**Run this by saying:** `execute audit audits/consolidation/2026-08-25-pre-pilot-p2-promotion/EXECUTE.md`

This is a cross-sweep tranche, not a single `CONSOLIDATED.md`. It promotes 16 findings that were
adjudicated P2 across four 2026-08-24 sweeps but read as pilot-blocking, plus one open P1 that turned
out to be the parent of two of them. Follow `scripts/audit/fix-flow.md` for the per-unit
plan → implement → **independent** review loop, with the overrides in §1 below.

---

## 0. Execution policy

- **Plan:** Opus 5 · **Implement:** Sonnet 5 · **Review:** Sonnet 5 — a *separate, independent*
  instance, never the implementer.
- **Combine plan+impl:** YES for S/XS units · NO for the M units (1, 6, 7).
- **Per-item override:** escalate implement → Opus 5 where a unit's notes say so.

> This header names Opus 5 / Sonnet 5, not the `Opus 4.8 / Sonnet 4.6` that `scripts/audit/audit.sh:174`
> stamps into generated files — that template string is stale, and `fix-flow.md` makes THIS file's
> policy authoritative. Use the values above.

## 1. THIS RUN DOES NOT STOP — gate overrides

`fix-flow.md` §1a would pause for sign-off on five of these items. **Josh pre-authorised all five on
2026-08-24.** Implement them without pausing, and write `Blocker gate waived (pre-authorised
2026-08-24)` into the commit body of each so the record shows a decision, not an oversight:

| Item | Why it would have gated |
|---|---|
| `#SEC-14`, `#SEC-17`, `#SEC-18` (unified-actions-security) | listed under `## Standalone — do NOT bundle` |
| claim-gate `#SEM-2`, `#SEM-4` | claim-path authorization |

**Other things that must NOT halt the run:**

- **A unit that fails review twice** → mark it `BLOCKED` in §7's ledger with the reviewer's defects,
  leave its boxes unticked, and **move to the next unit**. Do not retry a third time, do not stop.
- **A refuted premise** → close it per §5's protocol and move on. Do not escalate.
- **A test that was already red on `development`** → record it as pre-existing (with the commit that
  broke it), do not fix it as part of this run, move on.
- **Anything genuinely outside this file's scope** → note it in §7 under "Surfaced, not worked". Do
  not expand scope to chase it.

The only legitimate stop is: `development` won't check out, or the suite is red before you start
(§2's precondition).

## 2. Setup + preconditions

1. `git fetch && git pull` on `development`.
2. **Create a new branch off freshly-pulled `development`: `audit-fix/pre-pilot-p2-2026-08-25`.**
   Do not build on, rebase onto, or check out `audit-fix/p1-sweep-2026-08-24` — that branch was merged
   and cleaned up on 2026-08-25 and may no longer exist. Everything this run needs is in `development`.
3. **Landed-work check — by content, not by branch name.** Three units and two close-list ticks assume
   the P1 sweep's fixes are already in `development`. Confirm each artefact directly:

   | Probe | Expected | Used by |
   |---|---|---|
   | `app/Services/Analytics/ScoringWindow.php` exists | SCALE-3 landed | unit 8, close-list `CACHE-1` |
   | `ContentPopularityReader::pageRanksFromActions()` sorts by a stored `rank`, not `arsort()` | RANK-1 landed | unit 8, close-list `SEM-4` |
   | `PoolWire` / `PoolResolver` no longer hydrate every pool's full library | SCALE-2 landed | unit 3 |
   | `git log --oneline development \| grep -i "MIG-1"` | MIG-1 landed *(may legitimately be absent — it is Standalone and may have been deferred)* | unit 11 `#TEST-6` |

   - **If a probe fails, still work the unit.** Do not defer anything. Re-read the finding against the
     code as it actually stands and fix what is there — the unit notes below describe the pre-P1 shape,
     so treat them as context, not as a description of the current file.
   - **If a probe fails for a close-list tick** (`SEM-4`, `CACHE-1`), do not tick it as SUPERSEDED —
     that would close a finding against a fix that never landed. Leave the box open, record it in §7
     under "Surfaced, not worked" with the failed probe, and move on.
4. Baseline the suite: `composer test`. Record pass/fail counts. Anything already red is pre-existing
   and is not yours.

## 3. House rules that bite in this tranche

Standing rules are in `CLAUDE.md`; these are the ones these specific units will trip:

- **No Laravel migrations.** Nothing here needs a schema change — verify that before writing one.
  `pre_account_builds.created_ip_hash` is already nullable `text`
  (`supabase/migrations/20260726000000_baseline_pilot.sql:1028`), so unit 4 needs no DDL.
- **Three-lane cache contract.** Units 7 and 8 touch owner-initiated public-payload invalidation. Use
  `App\Site\Documents\SiteCacheLanes::bust()` — never `BuildState::bump()` alone, which is lane 1 only
  and is a per-item primitive by design.
- **Authorization via Policies**, never inline `abort_unless`. `authorizeForUser($user, ...)`, never
  `authorize()` — `Auth::user()` is always null under Supabase JWT.
- **Tests run SQLite, prod is Postgres.** Any constraint-bound write must be checked against
  `supabase/migrations/` DDL, not just a green suite.
- **`pint --test` is the CI gate, not `pint`.** `pint` silently fixes and reports "passed".
- **Every new assertion must be mutation-proved.** Break the code the assertion covers, watch the test
  go red, restore. Record the mutation in the commit body. This repo has a measured history of
  vacuous assertions (`shouldNotHaveReceived` with a single-arg matcher, negated `toContain`,
  `toThrow(Interface::class)`); unit 10 exists *because* of exactly that trap.
- Before each unit: **verify the finding's premise against the code as it stands today.** Three of the
  premises in the source sweeps were already wrong (§5). A `//` comment cited as Evidence narrates
  history, not current state.

## 4. Units — work in order

### Unit 1 — Social canonical URLs keep the path shape they matched · P1 · M
**Findings:** claim-gate `#SEM-1` (P1) → closes unified `SEM-13` (P2) as a duplicate.
**Source:** `audits/sweeps/2026-08-24-claim-gate-security/CONSOLIDATED.md:373`;
`audits/sweeps/2026-08-24-unified-actions-security/CONSOLIDATED.md:940`
**Files:** `config/partna.php` (`social_platforms.linkedin` ~:486, `social_platforms.spotify` ~:532);
`app/Services/Site/SocialLinkNormalizer.php` (`normalizeUrl()` ~:186-194, `normalizeHandle()` ~:138-157)

`url_path_extractor` accepts two disjoint namespaces (`/in/` + `/company/`; `/user/` + `/artist/`) but
`url_template` can only rebuild one. The capture group discards which alternative matched, so a pasted
company or artist page is stored as a personal-profile URL that 404s on the target platform. This ships
a broken link on a live public sitepage.

- Preserve the matched discriminator through `normalizeUrl()` → `normalizeHandle()` and select the
  matching template variant. Prefer a per-shape template map over a second regex pass.
- LinkedIn's extractor in the unified sweep's evidence also lists `school` and `pub`. Check the live
  config value, not the audit's excerpt, and cover every alternative the regex actually accepts.
- Regression tests: `linkedin.com/company/acme-inc`, `linkedin.com/school/x`, `linkedin.com/in/y`,
  `open.spotify.com/artist/z`, `open.spotify.com/user/w` — each asserts the stored canonical URL keeps
  its original path segment.
- **Do NOT** touch the Catalog lane (`SEM-18`, P3 — `canonicalUrl` templates in `app/Catalog/`). It is
  the same shape in a different subsystem, and `compiled.php` is a git-tracked generated artefact that
  conflicts silently across branches. Note it in §7 as adjacent.
- Tick `#SEM-1` in the claim-gate file. Tick `SEM-13` in the unified file with:
  `SUPERSEDED by claim-gate #SEM-1 — same defect, fixed in <commit>.`

### Unit 2 — Mass-assignment allowlists + dev-insights authz · P2 · S×3
**Findings:** `#SEC-17`, `#SEC-18`, `#SEC-14` (unified-actions-security :430, :448, :378)
**Files:** `app/Models/Core/Site/Workplace.php:47-51`; `app/Models/Core/Site/Site.php:104-124`;
`app/Http/Controllers/Api/User/Analytics/DevInsightsController.php:62`
**Blocker gate waived.**

All three are latent, not live — the audit verified no current write path reaches them. Fix them as
allowlist hygiene, and do not overclaim in the commit message.

- `#SEC-17`: drop `'site_id'` from `Workplace::$fillable`. `firstOrNew(['site_id' => …])` /
  `updateOrCreate(['site_id' => …], …)` set it via the query condition, not mass assignment, so both
  current write paths keep working. **Prove it** — run the workplace upsert + `previousWebsite` tests
  after the change, and add one asserting `Workplace::create(['site_id' => $other])` does NOT set it.
- `#SEC-18`: drop `'moderation_state'` and `'unpublished_at'` from `Site::$fillable`. Find every writer
  first (`SuspendSiteJob` and system code per the audit) and convert each to explicit assignment.
  A silently-dropped write here strands a site in the wrong moderation state with no error — this is
  the same failure shape `PreAccountBuild`'s SEC-4 comment warns about, so grep hard before you cut.
- `#SEC-14`: add `$this->authorizeForUser($professional, 'view', $site)` immediately after
  `currentSite()`, mirroring `UserSiteActionsController::show`.
  **Verified 2026-08-25:** the route (`routes/api/user.php:397`) sits in the plain
  `['user.api', EnforcePendingDeletionReadOnly, 'throttle:authenticated']` group at :58 — no env gate,
  no staff gate. It IS reachable in production; "dev" is naming, not gating. It is still own-site-only,
  so this is defense-in-depth. Whether the route should be env-gated at all is **out of scope** —
  note it in §7.

### Unit 3 — Duplicate-candidate query carries structural tenant scoping · P2 · S
**Finding:** `#SEC-5` (unified-actions-security :192)
**File:** `app/Site/Pools/PoolResolver.php` ~:880-892
**Note:** the P1 sweep also edited this file (SCALE-2). Read the current `PoolResolver` before planning —
§2.3's probe tells you which shape you are looking at. Work the unit either way.

The `content.identity_candidates` join filters on `dismissed_at`/`removed_at` plus an OR-membership
check against `$ids`, so same-tenancy is a convention upheld by one writer, not a property of the
query. Make it structural: scope both sides through the already-user-scoped `$ids`, or add an explicit
`user_id` predicate on both joined item aliases.

- Regression test: seed a cross-tenant `identity_candidates` row and assert it never surfaces.
- Mutation-prove it: revert the scoping, watch the new test go red.
- Adjacent, **not** in scope: `SCALE-9` / `#API-7` (this query runs on the public payload path and the
  result is then stripped). Note in §7.

### Unit 4 — Pre-account claim path · P2 · S×3
**Findings:** claim-gate `#PRIV-3` (:282), `#SEM-2` (:410), `#SEM-4` (:455)
**Files:** `app/Models/Core/User/PreAccountBuild.php:25,77-80,122-126`;
`app/Services/PreAccount/PreAccountBuildService.php:193-204` (compare :81-97)
**Blocker gate waived.** Escalate implement → Opus 5: this is the claim-takeover surface.

- **`#PRIV-3`** — replace the unsalted `sha256(CF-Connecting-IP)` with **HMAC-SHA256 under a dedicated
  app secret** (`hash_hmac`, key derived from `APP_KEY` or a new `config('partna.pre_account.*')`
  entry — a config key, never a bare `env()` read outside `config/`). Unsalted SHA-256 of an IP is
  reversible by exhausting all ~4.3B IPv4 addresses.
  **Josh's ruling 2026-08-24: null out the existing hashes.** They cannot be re-hashed (the raw IPs are
  gone) and they are the exact liability. `created_ip_hash` is nullable, so this is a data UPDATE, not
  a migration:
  ```sql
  UPDATE core.pre_account_builds SET created_ip_hash = NULL WHERE created_ip_hash IS NOT NULL;
  ```
  **Do not execute this against dev or prod.** Write it into §7's report as a post-merge step for Josh
  to run against each ref, and make sure every read path tolerates `NULL` (it already must — staff-built
  rows have no visitor IP). Cost is one day of same-day dedupe; state that plainly in the commit body.
- **`#SEM-2`** — `isOutreach()` returns `built_by_staff_id !== null || built_via === VIA_EARLY_ACCESS`.
  `built_by_staff_id` is `ON DELETE SET NULL`, so hard-deleting the creating staff row silently reverts
  a staff-built site to first-come claiming. Key the invite-gate on something that survives staff
  deletion — `built_via === VIA_STAFF` is the obvious candidate. **Read the method's existing comment
  first**: it explicitly warns that an anonymous caller able to set `built_via` would use it to exempt
  themselves. Confirm `built_via` is not settable from public input before you lean on it; if it is,
  gate on a persisted column instead and say so.
  - Regression test: build → hard-delete the staff row → assert the claim gate still holds.
- **`#SEM-4`** — the unique-constraint race-loser branch (:193-204) re-serves an existing build without
  the same-day contact-email conflict/attach logic that the primary path (:81-97) applies. Factor that
  logic into one place both branches call. **Do not reorder the dedupe-before-pairing-map sequence** —
  `CLAUDE.md` pins it as deliberate (spec §4.1).
  - Regression test: force the race-loser branch (insert the conflicting row mid-flight, or call the
    branch directly with a pre-existing live build) and assert the same conflict outcome as the winner.

### Unit 5 — Publish guard + promoted-settings hoist · P2 · S×2
**Findings:** `SEM-9` (unified-actions-security :808), `SEM-10` (:837)
**Files:** `app/Http/Requests/Api/User/Site/UpdateSiteRequest.php` (`withValidator`, ~:113);
`app/Services/Site/UpdateSiteAction.php:90-102` and `:104-158`

- **`SEM-9` — confirmed 2026-08-25, and worse than the finding states.** Both guards use `=== true`:
  `$this->input('is_published') === true` and `($data['is_published'] ?? null) === true`. Laravel's
  `boolean` rule *accepts* `1` / `"1"` / `"true"` but neither `input()` nor `validated()` casts them —
  they return the raw value. So `{"is_published": 1}` passes validation, skips **both** guards, and
  then the model's boolean cast publishes the site with no display name.
  - Fix at the source: normalise in `prepareForValidation()` (or compare with `filter_var(...,
    FILTER_VALIDATE_BOOL)`) so both call sites see a real bool. Fixing only one leaves the other open.
  - Tests: `true`, `1`, `"1"`, `"true"`, `"on"` each blocked when `display_name` is empty; each still
    permitted when it is set. Mutation-prove by restoring `=== true`.
- **`SEM-10`** — the `Site::PROMOTED_SETTINGS_KEYS` hoist reads from `$merged` (existing-on-disk JSONB
  ∪ incoming), so a stale legacy key still sitting in `settings` is hoisted over a typed column the
  request never touched. The comment at :141 claims "only keys the client actually sent are written" —
  that is true of `$incomingSettings`, not of `$merged`. Hoist from `$incomingSettings`; keep stripping
  the key from `$merged` so the column stays the sole write target.
  - Test: seed a site whose `settings` holds a stale promoted key and whose column holds a newer value,
    PATCH an unrelated field, assert the column is unchanged.
  - This runs inside the `lockForUpdate` transaction (LIFE-3). Do not move the lock or the merge order.

### Unit 6 — LinkRouter seeders honour the tombstone guard · P2 · M
**Finding:** `SEM-2` (unified-actions-security :607)
**File:** `app/Services/Platforms/LinkRouter.php:434-441` (`seedReservation`), `:495-502`
(`seedOnlineOrdering`)

Both seeders skip the soft-delete tombstone check the sibling seed paths apply, so a connection the
owner removed is silently resurrected on the next seed. Apply the same guard the other seeders use —
find it and reuse it rather than writing a second copy.

- Regression test per seeder: soft-delete a connection, run the seeder, assert it stays deleted.
- Adjacent, **not** in scope: `#TEST-20` (these same check-then-write spans are unlocked and have no
  concurrency test). Note in §7 — do not expand this unit into a locking change.

### Unit 7 — IntegrationConnectionObserver: cache lanes + a premise to test · P2 · M
**Findings:** `CCH-12` (remainder :1034), `CCH-13` (:1078), `SEM-11` (unified-actions-security :862)
**File:** `app/Observers/Core/IntegrationConnectionObserver.php`

- **`CCH-12` / `CCH-13`** — bundled per the audit's own note; same fix. `clearListingSourcedWorkplaceFields`
  (:304-335) and `syncIdentityFromGoogle` (:158-167, called from `saved()` at :117-120) write
  public-profile fields without rotating the timestamp-keyed `public.profile:{handle}:{ts}` key, so a
  user disconnects Google and their stale business info keeps serving for the TTL.
  - Route both through `SiteCacheLanes::bust()` — **all three lanes**. Lane 2
    (`site.sites.updated_at`) is the one that actually rotates this key and the one people forget.
  - Cross-check `reference_user_public_field_cache_bump_list` before you finish: an omission here is
    invisible, because Redis busts on the opposite branch and only `sites.updated_at` reveals it.
  - Test: assert the cache key changes across the disconnect and across an identity sync.
- **`SEM-11` — VERIFY BEFORE YOU CHANGE ANYTHING. The premise is already refuted for the tested path.**
  The finding claims `getOriginal()` reads post-sync so mirrored-media cleanup "never fires".
  `tests/Feature/Platforms/InstagramR2CleanupTest.php:87` asserts that exact dispatch on a folder
  change, and it passes (9/9, run 2026-08-25). Mechanism the finding missed:
  `DatabaseTransactionsManager::addCallback()` invokes immediately when no transaction is open — which
  is *before* `finishSave()` calls `syncOriginal()` — so `getOriginal()` is still the pre-update value.
  The observer's own docblock reasons this out correctly.
  - **The residual is real and untested.** The class docblock (LIFE-16) says
    `App\Routing\SourceReconciler::reconcile()` wraps `IntegrationConnection::save()` in
    `DB::transaction`. On *that* path the callback genuinely defers past `syncOriginal()` and the
    comparison silently fails — failing safe (skips a cleanup, never deletes a live folder), but
    orphaning R2 media.
  - **Task:** determine whether any Instagram `_folder` change can reach `updated()` through a
    transactional write path. Write a test that exercises it.
    - If it can and the cleanup does not fire → fix it (capture the old value in `updating()`, or read
      `getRawOriginal()` before the sync) and tick `SEM-11` normally.
    - If it cannot → tick `SEM-11` as `WONTFIX — premise refuted; InstagramR2CleanupTest:87 proves the
      dispatch fires on every reachable path. Evidence: <test run>.` and land the transactional-path
      test anyway as a pin.

### Unit 8 — Popularity reads fail closed, not empty · P2 · S
**Finding:** `CCH-11` (remainder :1008)
**File:** `app/Services/Analytics/ContentPopularityReader.php` — `forSite` :39-51,
`actionScoresForSite` :68-83, `itemScoresForSite` :125-140
**Note:** RANK-1 rewrites two of these three methods. If §2.3's RANK-1 probe passed, plan against the
rewritten shape, not the line numbers above.

A `QueryException` in any of the three readers is swallowed into an empty ranking, which is then cached
behind the public-profile cache. One DB blip poisons a public page for the whole TTL.

- Distinguish "no rows" from "the query failed". On failure: `report($e)` and signal the caller not to
  cache — do not write a cache entry that claims to be a valid empty result.
- Follow the existing fail-open-and-log precedent in this codebase (`reference_analytics_failopen_escalation`)
  rather than inventing a new convention; the page must still render.
- Test: force a `QueryException` in each of the three readers, assert nothing durable is cached and the
  exception is reported. **Mutation-prove the negative assertion** — a `Cache::shouldNotHaveReceived`
  with a single-argument matcher is vacuous in this repo; use `[key, Mockery::any()]` or assert on
  `Cache::has()` directly.

### Unit 9 — Paste path stops holding a worker for 45s · P2 · S
**Finding:** `SCALE-10` (remainder :574)
**Files:** `app/Http/Controllers/Api/Routing/RoutingController.php:148-151, 198-201`;
`config/partna.php` (`http_fetch.connect_budget_seconds`)

**Josh's ruling 2026-08-24: cut the budget, stay synchronous.** Do NOT queue the seed — that changes
the public response contract (`canonicalUrl` / `pool` / `explanation` are not known at response time)
and needs a `docs/wire-changes/` manifest plus frontend work. Note the async option in §7 as deferred.

- Both call sites read `config('partna.http_fetch.connect_budget_seconds', 45)` — a **connect** budget
  being reused for an interactive paste. Give the paste path its own config key (e.g.
  `partna.http_fetch.paste_budget_seconds`, default 8-10s) rather than lowering the connect budget and
  breaking the connect lane.
- On budget exhaustion, fall through the existing `$written === null` / review branch. Confirm both
  branches already degrade cleanly — the item branch reports and nulls, and the route branch must not
  500.
- Test: force the budget to expire and assert a clean degraded response (not a 500, not a hang), for
  both the item branch and the route branch.

### Unit 10 — The SSRF ban gets a positive control · P2 · S
**Finding:** `#TEST-4` (unified-actions-delta :809)
**File:** `tests/Feature/Architecture/OutboundHttpGuardTest.php:96-105`

`OutboundHttpScanner::alternativeTransports()` is Rule 1 of the outbound-HTTP guard — the
`curl_*` / direct-Guzzle / `file_get_contents('http…')` ban. It has **no test proving it can detect a
violation**. A guard nobody has proved can fail is a guard you do not have, and this repo's history
contains exactly this trap more than once.

- Add a positive control: feed the scanner a fixture containing each banned transport and assert it is
  flagged. One case per transport — `curl_init`, `new GuzzleHttp\Client`, `file_get_contents('http...')`.
- Add the matching negative: a file using the `Http` facade is not flagged.
- **The control must live where a moved reader cannot silently orphan it** — a fixture the scanner no
  longer reads makes the test vacuously green. Assert on the scanner's returned findings, not on a side
  effect, and mutation-prove by neutering `alternativeTransports()` and watching each case go red.
- This is a guard-integrity unit. Its own review must confirm the mutation was actually run.

### Unit 11 — Close list: tick with evidence, write the two partials
No production code changes except where noted. Tick each box in its **source** audit file and bump that
file's `## Progress` counts.

| Finding | Source | Disposition |
|---|---|---|
| `SEM-4` (unified-actions-security :664) | strict subset of `#RANK-1` | `SUPERSEDED by #RANK-1 (audits/correctness/2026-08-24-actions-ordering-math) — same pageRanksFromActions() defect; RANK-1 additionally covers ActionSlots::order(). Fixed in <commit>.` |
| `CACHE-1` (remainder :73) | `SCALE-3` restated | `SUPERSEDED by SCALE-3 — the ActionScorer::aggregate() half. Fixed by App\Services\Analytics\ScoringWindow in <commit>.` |
| `CCH-8` (remainder :959) | **premise refuted** | `WONTFIX — premise wrong. config/cache.php:80 sets lock_connection => cache_locks on the default redis store; CacheManager::createRedisDriver() does $store->setLockConnection($config['lock_connection'] ?? $connection); RedisStore::flush() calls $this->connection()->flushdb() (data conn, DB 1), never lockConnection() (DB 4). Cache::lock() ALREADY takes the lock on cache_locks — the suggested fix is a no-op. Verified 2026-08-25.` |
| `CCH-9` (remainder :977) | **premise refuted** | Same reason as `CCH-8` — same root, different call site. |
| `SEM-13` (unified-actions-security :940) | dup | ticked by unit 1 |
| `#TEST-18` (unified-actions-delta :1081) | **partial** | see below |
| `#TEST-6` (unified-actions-delta :846) | **partial** | see below |

**`#TEST-18` — do the missing third test.** Unit 4 of the P1 sweep (`76f4568a5`) created
`tests/Unit/Jobs/ShopInitialFillJobTest.php`, which covers the two failure-isolation asks (fill throws →
auto-select still runs; both failures reported independently). It does **not** cover the idempotency
ask. Add it: run `handle()` twice, assert identical catalogue/pin end-state. Then tick with
`Failure isolation covered by 76f4568a5; idempotency added in <commit>.`

**`#TEST-6` — do NOT close it.** It depends on `#MIG-1` (unified-actions-delta :65), which is still
open and is listed Standalone. Instead: append to `#MIG-1`'s entry an explicit acceptance criterion —
*"must include a data-driven test seeding both legacy-format and new-format `analytics.action_events`
rows plus `site.sites.settings` carrying `smart_actions`/`manual_actions`/`manual_order_pools`, and
asserting only the legacy rows/keys are removed (`#TEST-6`)"* — and leave both boxes unticked. Record
this in §7.

**Do not run `archive-done.sh`** on any source sweep folder. These files carry many findings outside
this tranche; a partial tick set must not trigger an archive.

## 5. Protocol for a refuted premise

If a unit's premise does not survive §3's verification step:

1. Write the disproof down — the config line, the passing test path + its run output, the framework
   source, whatever settles it.
2. Tick the box as `WONTFIX — premise refuted` with that evidence inline. A ticked box means *resolved
   as an open question*, not *the code changed*.
3. If a real residual survives (as with `SEM-11`), land a test pinning the behaviour so the next sweep
   does not re-file it.
4. Move to the next unit. Do not escalate, do not stop.

## 6. Per-unit close-out

Per `fix-flow.md` §1e, for every unit:

1. Independent reviewer returns PASS (a fresh instance, never the implementer). The reviewer verifies
   the fix addresses the finding, no regression, **tests genuinely pass and are not vacuous**, house
   rules honoured.
2. Run the relevant targeted tests, then `composer test` for the branch.
3. `./vendor/bin/pint --test` and the phpstan gate must be clean. Note:
   phpstan surfaces *latent* findings in untouched files when the dependency graph changes — cold
   cache + `--debug` + `--memory-limit=1G` before blaming this run's diff for one.
4. Tick the boxes in the source audit file(s), bump `## Progress`.
5. Commit code + ticked audit file together: `fix(audit): <unit> — <ids>`. Include the mutation proof
   for any new assertion, and the gate-waiver line for unit 2 and unit 4.

## 7. Final report — write this into `RESULT.md` beside this file

- Units done / blocked (with the reviewer's defects). No unit is deferred — if §2.3's landed-work probes
  came back unexpected, say which probe and what you found instead.
- **Post-merge steps for Josh**, in runnable form — at minimum unit 4's
  `UPDATE core.pre_account_builds SET created_ip_hash = NULL …`, stated per ref (dev
  `glncumufgaqcmqhzwrxm`, prod `edplucmvkcnokyygxqsb`) with the note that prod carries no customer data
  yet.
- **Surfaced, not worked** — adjacent findings this run deliberately left alone: `SEM-18` (Catalog
  canonicalUrl lane), `#TEST-20` (LinkRouter seeder locking), `SCALE-9` / `#API-7` (duplicate-detection
  query on the public path), the dev-insights env-gate question, `SCALE-10`'s deferred async shape, and
  `#TEST-6`'s dependency on `#MIG-1`.
- Suite status with counts, and any pre-existing red carried in from `development`.
- Branch name. **Do not push to `development` or `production`.** Josh reviews and merges.

## 8. One correction to carry in

`app/Services/Analytics/ScoringWindow.php` (landed with the P1 sweep) has a docblock citing
*"SCALE-2's raw-event queries"*. The raw-event scan finding is **SCALE-3**; SCALE-2 is the pool-library
hydration finding. Correct the reference — unit 8 is in the neighbouring file, so absorb it there per
`CLAUDE.md`'s opportunistic-fix rule and mention it in that commit body. If the file is absent
(§2.3's probe failed), note it in §7 instead.
