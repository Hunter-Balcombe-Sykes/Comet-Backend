# Pre-pilot / pre-launch triage — the whole open backlog (2026-08-25)

Scope: **every** open finding in `audits/` (not just this tranche's sweeps), triaged against the
rubric below and **verified against the code as it stands today**. Line numbers in the source
sweeps are stale — everything here was matched by symbol.

**Standing facts, verified live 2026-08-25 (do not re-derive):**

- Prod DB: **0 of `content`/`ingest`/`routing`/`catalog` exist**; ledger **4 rows**, latest `20260803100001`. `core.users` = 0.
- Prod Cloud env: **stopped**. `usesPushToDeploy: true`, no CI gate — the push *is* the deploy.
- `QUEUE_CONNECTION=redis` on both envs; dev runs Horizon (1 supervisor, 0 queued).
- "Dark Until Claimed" was **reverted** (`1d26800a9`); a pre-account site is publicly routable pre-claim and `isVisibleWhileUnclaimed()` no longer exists.

**Rubric.** PRE-PILOT = data loss/corruption · cross-tenant leak · public sitepage broken · money ·
claim/ownership · privacy with real users. PRE-LAUNCH = bites only at volume/cost/observability.
LATER = cosmetic, doc drift, coverage without a demonstrated defect, refuted premises.

> ⚠️ **IDs collide across sweeps.** There are two different `#SEC-7`s, two `#SEC-3`s, two `SCALE-1`s,
> two `SCALE-3`s and several `SEM-4`s. Every row below is keyed by ID **plus** its file, and the two
> `#SEC-7`s are both real and both listed. Never match these by ID alone.

---

## P1 — open (4 real, 1 was already fixed)

| ID · file | Verdict | What a user loses | Effort |
|---|---|---|---|
| **`#SEC-3`** · claim-gate | **STILL REAL — most urgent for pilot** | Any Supabase-authenticated stranger who reaches a business's handle before the builder returns takes **permanent ownership** of the site — scraped name, photos, hours — and the real owner is locked out. The outreach arm is gated (`CLAIM_NOT_INVITED`); the self-serve arm is not. Restored by the revert. Must be closed at the **claim step** (ownership proof) — hiding the site was tried and rejected. | L (~1–2d, cross-repo) |
| **`#DEPLOY-1`** · reviews/2026-08-05 | **STILL REAL — a deploy tripwire, not a live defect** | Nothing fails today (prod has neither the code nor the schema). It becomes a hard outage the moment `development` is pushed to `production` without applying the migration backlog: first pilot user to paste a link gets a 500. Honest scope is the whole reconciliation — **4 schemas, ~130 migrations**. | M for routing alone; L for the real scope |
| **`#SCALE-3`** · overnight-run | **STILL REAL — dev-only today** | `MediaMirror` holds a full body in memory (80 MB cap) against a 256 MB `images` supervisor; ~3 concurrent video mirrors trips the restart threshold and assets silently fail to mirror, leaving gallery gaps. Cannot fire on prod — writes `content.media_assets`, which prod lacks. | M |
| **`#SCALE-1`** · overnight-run | **STILL REAL but over-stated — recommend re-grade to P2** | Both facet tables carry `PRIMARY KEY (item_id, source_id)`, so each correlated evaluation is a **PK index probe, not a scan**. Cost is a few thousand probes per cache-miss render for a large library: slower first paint, no error. | M |
| **`#MONEY-1`/`#MONEY-2`** · ingest/2026-08-12 | ✅ **ALREADY FIXED** — `b5d5e7aec`, `5ef774bbb` | Ticked and archived this session. A P1 money bug had been sitting open in every triage for weeks. | — |

---

## PRE-PILOT — 11 distinct defects

Ordered by what I'd do first.

| # | ID · file | What breaks for a user | Effort |
|---|---|---|---|
| 1 | **`#SEC-7`** · overnight (`RoutingController`) | Paste a link-in-bio URL carrying `?token=`/`?key=` and the **raw secret-bearing URL is published** as a public link card and queued verbatim. `:95` passes `trim($url)`; the sibling branch at `:227` calls `SecretParams::redactUrl()` under a comment saying *"never fall back to the raw, possibly-secret-bearing URL."* One branch protected, one not. | S |
| 2 | **`#SEC-7`** · unified-security (`LinkInBioImporter`) | **Different finding, same class.** Raw pasted URL persisted durably to `routing.import_runs.source_url` (`:111`) and into a queued payload (`:645`), while the same file redacts correctly at `:226` and `:691` with an explicit "Scope B PII" comment. | S |
| 3 | **`#TEST-10`** · delta (`ActionCandidates`) | `catch (QueryException) { $pools = []; }` with **no `report()`, no log**. A content-lane fault silently blanks every pool/action on the public page — 200 OK, content gone, nothing in Nightwatch. **On prod this will fire on every public profile request** the moment the env starts, masking the schema gap entirely. Sibling of `CCH-11`, closed today. | S |
| 4 | **`SEM-7`** · unified-security (`PoolController::reorder`) | Owner drags a pool into order; a pinned item the client omitted keeps its old `sort_key` and interleaves the fresh 1..N sequence, so the **public sitepage renders an order nobody chose**. The method's own comment says the design exists to prevent exactly this. | S |
| 5 | **`#CCH-2`** · overnight (`FreshaController`) | Owner reconnects to a **different salon**; the staff picker keeps serving the previous salon's roster for up to 24h because the deferred payload merge preserves `teamMenuCache` and `team()` checks freshness only — never the URL. Reconnecting is exactly what a pilot user does. | S |
| 6 | **`#LIFE-11`** · overnight (`GoogleBusinessAutoSync`) | Owner types a workplace description/category while a GB enrich runs → unlocked read-modify-write **clobbers what they just typed**. The only data-loss finding in that file, and no lock exists anywhere in the class to copy. | S |
| 7 | **`#LIFE-14` + `#LIFE-13`** · overnight (`SourceProvisioner`, `IntegrationConnectionObserver`) | **Fix as one unit.** Unguarded find-then-insert loses a race on `sources_unique_per_connection`, and `syncIngestSource()` swallows the `QueryException` at `Log::debug` — so the platform **silently never syncs** and nobody can see it. Add `insertOrIgnore`/`onConflict` *and* stop swallowing. | S |
| 8 | **`#SEC-3`** · overnight (`config/partna.php`) | Square's `order.` host pattern uses a zero-width lookahead with **no terminal anchor**, so any host starting `order.` (e.g. `order.attacker.example`) is accepted and rendered on the public sitepage **under Square's brand identity**. One character-class fix. | S |
| 9 | **`SEM-16`** · unified-security (`ActionCandidates`) | `$fallback ??= $cid` is computed at `:210` and **never read again** — a dead variable. Sidecar-only items (an Uber-Eats-only dish) render as loose standalone actions instead of grouping under their ordering platform. **Same file as #3 — do them together.** | S |
| 10 | **`#SEM-3`** · claim-gate (`ClaimController`) | `CLAIM_NOT_INVITED` returns a distinct **409 + code** where `CLAIM_NOT_FOUND` returns a bare 404 — a working enumeration oracle handing anyone with a free Supabase account the list of staff-groomed outreach sites worth squatting. **Compounds the open P1 `#SEC-3`.** The branch's own comment says it must not become this. | S |
| 11 | **`#SEC-16` ≡ claim `#SEC-4`** · config | Bot protection enforces nothing on either env. **Corrected against the live envs — the audit's "unset on dev AND prod" is stale:** dev has nothing set (`driver=null`, `mode=off`, middleware short-circuits); **prod has `turnstile` + real keys in `shadow` mode**, which verifies and logs but *always* passes through by design. So on prod this is a **one-variable flip to `enforce`** — gate it on the `bot_protection.shadow_reject` logs showing the frontend actually mints tokens, or enforcing locks out real users. | S (env) / M (with a boot guard) |

**Ten of the eleven are S.** Items 3 and 9 are the same file; 1 and 2 are the same fix applied twice.

---

## PRE-LAUNCH — ~30 IDs, ~26 actual defects

Grouped, because several are one change:

- **`ProjectionWriter` scale cluster** — `#CACHE-2`, `#CACHE-4`, `#SCALE-8`, `#SCALE-9`, `#SCALE-12`. **One architectural change** ("scope identity resolution to the changed rows, not the whole kind"), not five tickets. Plan as a unit. L.
- **`CacheLockService` jitter** — `CCH-3` + `CCH-6`, **re-diagnosed**: the real bug is that `rememberLockedNullable` never calls `applyJitter` while its sibling `rememberLocked` routes through `writeWithJitter()`. **One fix covers every caller.** S.
- **Silent-swallow family** — `#LIFE-13`, `#LIFE-15`, `CCH-5`: `catch (QueryException) {}` with no log on the public hot path. Same shape as `CCH-11`/`#TEST-10`. S each.
- **Scheduler hygiene** — `#LIFE-16`/`#SCALE-20`, `#LIFE-17`/`#SCALE-21`: `withoutOverlapping()` with no expiry → 24h stale lock after a crash; no `onFailure()`. `compute-popularity` already has the correct shape to copy. S.
- **Defense-in-depth authz** — `#SEC-10` (5 `ShopController` methods). Verified **structurally user-scoped, no cross-tenant reach** — same class as `#SEC-14`, fixed today. M.
- **Staff batch onboarding** — `CACHE-2` ≡ `SCALE-7`: 500 synchronous `requestBuild()` calls in one HTTP request. **This is the tool you'll onboard the pilot cohort with**; on timeout staff can't tell which rows landed. Mitigated: re-upload is safe (dedupe re-serves as `reused`). M.
- **`#CFG-3`** — `MAX_BRANDS = 5` in `StoreBrandSeeder` vs `10` in `ShopController` and `ConnectStoreFromProductJob`, with seven catalog comments saying "10". A 6th store half-connects and never renders. S–M.
- **`#TEST-20`** — promote off the deferred pile. The unique index that backstops `#TEST-19` does **not** save this: two *different* reservation brands race through cleanly and both write into a single-slot family. M.
- Plus: `#SEC-4/6/8/9/11/12/13`, `SEM-8/14/17`, `#PRIV-2`, `#JOB-3`, `#RANK-2`, `SCALE-5/6/8/9/11/12/13/14`, `#SCALE-15/16/17`, `#CCH-1`, `CCH-10`, `#LIFE-9/10`, `#SEC-5` (AAL2 staged rollout — a checklist item, not a defect).

---

## Closed during triage — do not re-open

**Already fixed:** `#MONEY-1`, `#MONEY-2` (`b5d5e7aec`, `5ef774bbb`) · `#API-2` (`e482045e3`).

**Premise refuted or DB-backstopped:** `#TEST-19` (unique index makes the race a reported violation, not a duplicate) · `#TEST-17` (all six sites use `CommerceProbeJob::dispatch`, not `Bus::dispatch`, so the `ShouldBeUnique` gotcha doesn't apply) · `#TEST-7` (`display_name` is `NOT NULL`) · `#TEST-2` (`content.storefronts` has exactly one FK) · `CCH-4` and `CCH-7` (both refuted by the comments of the fixes that created them — the *absence* of SWR is deliberate; feeding a null through it would poison the stale twin) · `#CFG-2` (auto-booking-on is the shipped decision, `d39cc6e61`) · `CCH-6` partial (`AppleSearch::itunes()` already reads config).

**Duplicate ID pairs — fix once, tick both:** `#SEC-16` ≡ claim `#SEC-4` · `#SEC-12` ≡ `SEM-6` · `CACHE-2` ≡ `SCALE-7` · `SCALE-9` ≡ `#API-7` · `#LIFE-16` ≡ `#SCALE-20` · `#LIFE-17` ≡ `#SCALE-21`.

---

## Recommended order

1. **`#SEC-3` (P1, claim-gate)** — the only one where a stranger permanently takes a real business's site. Everything else is recoverable.
2. **The eight S-effort pre-pilot fixes** (#1–#10 above, excluding the two L/M items) — plausibly one focused session; several share files.
3. **Bot protection** — flip prod to `enforce` once shadow logs prove tokens arrive; wire dev at all.
4. **`#DEPLOY-1` / prod reconciliation** — must precede any prod deploy, and is far larger than one schema.
5. **The `ProjectionWriter` cluster** — plan as one architectural change before launch.

**Do not run a "clear the backlog" campaign.** Per `CLAUDE.md`, recall degrades past ~100K tokens and the low tier carries a measured ~40% already-fixed rate — this pass closed 10 findings without a code change, which is the policy working.
