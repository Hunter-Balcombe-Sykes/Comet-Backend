# RESULT — Pre-pilot P2 promotion tranche (2026-08-25)

**Branch:** `audit-fix/pre-pilot-p2-2026-08-25` (off `development` @ `fe3f4f36d`)
**Status:** all 11 units worked. **0 blocked, 0 deferred.**
**Suite:** 9183 passed, 3 skipped, 0 failed (32403 assertions). Baseline was 9121/3/0 — **+62 tests, no pre-existing red**.
**`pint --test`:** passed. **Not pushed** — review and merge is yours.

---

## 1. Units

| # | Findings | Outcome | Commit |
|---|---|---|---|
| 1 | claim-gate `#SEM-1` → unified `SEM-13` | Already fixed in `16a90f7dd`; `SEM-13` ticked SUPERSEDED, no code change | `8198ebafe` |
| 2 | `#SEC-17`, `#SEC-18`, `#SEC-14` | Fixed | `57b66c4ff` |
| 3 | `#SEC-5` (unified) | Fixed | `30b33c93d` |
| 4 | `#PRIV-3`, `#SEM-4`; `#SEM-2` already fixed + pinned | Fixed | `c72b112f4` |
| 5 | `SEM-9`, `SEM-10` | Fixed | `47cab750d` |
| 6 | `SEM-2` (unified) | Fixed | `bf5b2f0a1` |
| 7 | `CCH-12`, `CCH-13`, `SEM-11` | **All three premises refuted** — WONTFIX + 2 pin tests | `fb7412e4a` |
| 8 | `CCH-11` | Fixed (two halves) | `60281c5b4` |
| 9 | `SCALE-10` | Fixed | `fb7412e4a` |
| 10 | `#TEST-4` | Fixed | `fb7412e4a` |
| 11 | close list | All ticked with evidence | `fb7412e4a`, `bf5b2f0a1` |

Every unit passed an **independent** review by a fresh instance that did not write the code. Two reviews died to an infrastructure watchdog rather than to a verdict; Unit 2's I performed myself (I was not its implementer), re-verifying each claim against primary sources rather than accepting the report.

### Final disposition of all 17 in-scope findings

- **Fixed (13):** `#SEC-14`, `#SEC-17`, `#SEC-18`, `#SEC-5`, `#PRIV-3`, claim-gate `#SEM-4`, `SEM-9`, `SEM-10`, unified `SEM-2`, `CCH-11`, `SCALE-10`, `#TEST-4`, `#TEST-6`, `#TEST-18`
- **Already fixed / superseded (4):** `SEM-13`, claim-gate `#SEM-2`, unified `SEM-4`, `CACHE-1`
- **WONTFIX, premise refuted (5):** `CCH-8`, `CCH-9`, `CCH-12`, `CCH-13`, `SEM-11`

---

## 2. Post-merge steps for Josh

### 2.1 REQUIRED — null the legacy IP hashes (`#PRIV-3`)

New writes are `hash_hmac('sha256', $ip, pepper)`. **Existing rows still hold bare, reversible `sha256(ip)`** — they cannot be re-hashed because the raw IPs are gone, and they are the exact liability. Your 2026-08-24 ruling was to null them.

```sql
UPDATE core.pre_account_builds SET created_ip_hash = NULL WHERE created_ip_hash IS NOT NULL;
```

Run against **each ref separately**:

- **dev** `glncumufgaqcmqhzwrxm`
- **prod** `edplucmvkcnokyygxqsb` — prod carries **no customer data** (`core.users` = 0), so this is very likely a 0-row no-op. Run it anyway for parity.

Not executed here, and deliberately **not** committed as a migration — it is a data scrub, not schema. Safe to run any time after merge; mixed old/new state is harmless because a legacy digest cannot equality-match a new HMAC, so a stale row simply stops counting toward its IP's build cap. Cost is one day of same-day dedupe.

### 2.2 Optional — set a dedicated IP pepper

`config('partna.pre_account.ip_hash_key')` defaults to `APP_KEY`. `PARTNA_PRE_ACCOUNT_IP_HASH_KEY` exists so the pepper can rotate without rotating `APP_KEY`. Ships blank in `.env.example`; nothing to do unless you want them separated.

---

## 3. Surfaced, not worked

Adjacent findings this run deliberately left alone.

**Named in the run file:**
- `SEM-18` — the Catalog `canonicalUrl` lane. Same shape as `#SEM-1`, different subsystem; `compiled.php` is a git-tracked generated artefact that conflicts silently across branches.
- `#TEST-20` — the LinkRouter seeder check-then-write spans are unlocked. `withReservationsXorLock()` exists in the trait and is tempting, but `applyFinding()` takes it too, so that is a concurrency change with its own blast radius.
- `SCALE-9` / `#API-7` — the duplicate-detection query runs on the public payload path and the result is then stripped.
- The dev-insights **env-gate question** — whether `/api/professional/dev-insights` should be env-gated at all. It is reachable in production today (`routes/api/user.php:397`, plain `user.api` group); "dev" is naming, not gating. `#SEC-14` only added the ownership check.
- `SCALE-10`'s **deferred async shape** — queueing the paste seed. Changes the public response contract (`canonicalUrl`/`pool`/`explanation` are unknown at response time); needs a `docs/wire-changes/` manifest plus frontend work.

**Newly surfaced by this run:**
- **Six other bare-`sha256` PII call sites**, same reversibility class as `#PRIV-3` and a larger input space (email addresses): `BootstrapController:106`, `PublicEmailSubscriptionController:53,171`, `PublicEarlyAccessController:35`, `SendEnquiryConfirmationJob:149`, `SendSubscriptionConfirmationJob:146`. `PerTargetReportThrottle:29` already HMACs correctly and is the model to follow. **Worth its own finding.**
- **`ShopAutoSelector` has two redundant idempotency guards** — an outer read-check (`:49-52`) and an inner compare-and-set. Removing either alone still passes a sequential test; only removing both goes red. The inner one's real job is race-safety between *concurrent* callers, which a sequential test cannot distinguish from a no-op.
- **`connect_budget_seconds` code/config default mismatch** — ~20 call sites pass a default of `20` in code while `config/partna.php` declares `45`. Pre-existing, unrelated to `SCALE-10`.
- **`UpdateSiteAction`'s `filter_var` omits `FILTER_NULL_ON_FAILURE`** — a genuinely malformed value from a *direct* caller coerces to `false` and skips the completeness guard rather than erroring. Only reachable by a direct caller passing garbage, and `save()` would then hit a Postgres type error rather than publish. Low severity.
- **`NormalizesSiteUpdateInput` has no isolated unit test** — only end-to-end coverage through the request. The reviewer flagged that this is why the `withValidator()` guard could not be mutation-proved.
- **`.env.example` does not document `PARTNA_PREVIEW_BUDGET_SECONDS`** (pre-existing); `PARTNA_PASTE_BUDGET_SECONDS` follows that precedent and is likewise undocumented. Worth a consistency pass.

---

## 4. Where the run file's premises did not hold

Six, all verified against code rather than assumed. Recorded because the run file will otherwise read as if these were done as written.

1. **`#SEC-17`'s rationale was wrong.** The file states `firstOrNew(['site_id' => …])` / `updateOrCreate(…)` "keep working" because the array is a query condition. It is one only on the *found* branch; the **new-row** branch of both goes through `newModelInstance()` → `fill()`, which filters on `$fillable`. Six write paths needed explicit assignment, not the two predicted.
2. **`#SEM-2` (claim-gate) was already fixed** in `b41cfbd71`. Not re-fixed; a real DB-level integration pin was added instead, since the pre-existing tests build an in-memory model and never exercise the `ON DELETE SET NULL` FK that is the finding's whole premise.
3. **`#TEST-6`'s blocker was resolved.** The file says to leave it open because `#MIG-1` is "still open". `#MIG-1` is ticked and its fix shipped the very test `#TEST-6` asks for. Closing it properly still required finding a real residual first — see §5.
4. **`CCH-12`/`CCH-13`/`SEM-11` are all refuted**, and the prescribed `SiteCacheLanes::bust()` fix would be a **regression**, not a no-op. Detail in §5.
5. **EXECUTE §8's correction is a no-op.** `ScoringWindow.php:8` already reads *"SCALE-3's raw-event queries"*. Independently confirmed twice.
6. **A peer session's addendum mis-mapped `#SEM-4`.** `68c5c5ee8` claims `b41cfbd71`'s `#9`/`#21` already fixed it. They fixed the **primary** `findLive()` branch; `#SEM-4` is about the **race-loser** `catch (UniqueConstraintViolationException)` arm, which still called `reserve()` with no reconciliation. Different code paths — the finding was genuinely open and has been fixed.

---

## 5. Three findings worth reading in full

**`CCH-12`/`CCH-13` — the prescribed fix would have made things worse.** Lane 2 already rotates: the clear ends in a full Eloquent `$workplace->save()`, `category`/`description` are both in `WorkplaceObserver::CACHE_AFFECTING_COLUMNS`, and `touchSite()` rolls `site.sites.updated_at` — exactly what `cacheKey()` derives from. Adding `bust()` on top would double-fire the CDN purge and the Redis DEL sweep, because `bust()` writes via the query builder (firing no `SiteObserver`) and dispatches the purge itself, whereas `touchSite()` reaches the CDN *through* the observer. `SiteCacheInvalidator`'s docblock forbids exactly this. Cost would have been a duplicate purge and warm job on every google-business save and every hourly refresh tick.

**`CCH-11` needed both halves.** The reader learning to report a fault does nothing on its own — the poisoning happens in the callers. This unit was first dispatched with the reader alone in its allowlist (my scoping error). The implementer declined to overreach, traced all six call sites, and reported the gap rather than ticking a half-closed finding. The wiring landed as a follow-up.

**`#TEST-6`'s residual.** The scrub command strips **three** settings keys but the test asserted only **two** — `manual_order_pools` was in both the selector and the strip list, entirely unproven. Now covered. Noted while proving it: dropping that key from the strip list *alone* makes the command **loop forever**, because the `jsonb_exists_any` selector still matches the row it can no longer clean. Selector and strip list must stay in sync.

---

## 6. Notes for the next run

- **The Postgres lane IS runnable locally**, contrary to an earlier belief that it was CI-only. The local Supabase container serves `54322`; the lane runs against a **disposable scratch database** with `PG_LANE_DISPOSABLE=1`. Do **not** override that guard on the `postgres` database — it exists to stop `core.*`/`site.*` being provisioned over the local dev stack. Create a throwaway DB instead and drop it after (this run did; nothing was left behind).
- **A peer session committed to this branch mid-run** (`894de8e0b`, `0e9429313`, `68c5c5ee8`). No collision — the working diff stayed byte-identical — but one of them **changed a hard rule in `CLAUDE.md`**: "Dark Until Claimed" means an unvetted self-serve pre-account build is no longer publicly routable, killing the "public pre-claim by design" rule. Findings were matched by content, not line number, for the rest of the run.
- **Two specified mutations turned out to prove nothing** and were caught by the implementers rather than banked as passes: `SEM-11`'s `dispatch($old)`→`dispatch($new)` (inside a transaction `$old === $new`, so the guard never opens either way) and `#TEST-18`'s single-guard removal (`ShopAutoSelector` has two). A mutation that fails to go red is a finding about the *test*, not a formality.
- **Two Laravel behaviours worth remembering:** `Model::observe($instance)` does not retain the instance — `registerObserver()` collapses it to `"ClassName@event"` and the container re-resolves a fresh one per fire, so a spy must use a `static` property. And under `DB::transaction()`, a deferred `$afterCommit` callback observes transaction level **0 again** (it runs post-commit) — transaction level does not discriminate; `getOriginal()` having already synced is what does.
- **`archive-done.sh` was NOT run**, per the run file. These sweeps carry many findings outside this tranche and a partial tick set must not trigger an archive.
