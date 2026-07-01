# Foundational Audit — Wave 2 Consolidated Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL — execute each `## PRn` section under
> `scripts/audit/fix-flow.md` (plan already done → Sonnet implement → independent Sonnet/Opus review →
> full-suite gate → commit), **one PR per session**, gated on Josh's sign-off and the prior PR being
> merged. Steps use checkbox (`- [ ]`) syntax. This document is a PLAN — it builds nothing on its own.

**Goal:** turn every open Wave-2 finding of the 2026-06-25 foundational audit (DB migrations + auth + the
platform HTTP layer) into a single, ready-to-execute implementation plan an engineer can follow verbatim.

**Architecture:** ten PRs, each self-contained, sequenced to respect intra-dependencies. Six carry a raw
SQL migration (`supabase/migrations/`); two are auth/HTTP-layer code-only; the JSONB→table conversions are
done now (pre-beta, zero rows) so every backfill is a present no-op but correct for prod-shape parity.

**Tech Stack:** PHP 8.2 / Laravel 12 · PostgreSQL (Supabase, dev ref `glncumufgaqcmqhzwrxm` serves both
api domains) · Pest 4 (+ SQLite in-memory for tests) · Redis/Horizon · the typed `PlatformRegistry` spine.

**Source audit:** `audits/sweeps/2026-06-25-foundational/CONSOLIDATED.md`
**Design/decision plan (source of intent):** `docs/superpowers/plans/2026-06-30-foundational-wave2-plan.md`
**Branch the plan was authored against:** `audit-fix/foundational-2026-06-30` (Wave-1 already shipped here).

> **Authoring provenance:** the ten PR sections below were drafted **in parallel** by nine independent
> read-only Opus sub-authors (PR9+PR10 shared one author because PR10 strictly depends on PR9's design),
> each grounded against the live tree, then assembled and cross-reconciled here. Every code block, DDL,
> and signature was confirmed against the real files at authoring time; the executing session must still
> re-confirm (the shared repo moves).

---

## Global Constraints (every task's requirements implicitly include this section)

- **DB schema = raw SQL in `supabase/migrations/` ONLY.** A composer guard (`guard:no-laravel-migrations`)
  rejects Laravel migration files. Filename `supabase/migrations/<YYYYMMDDHHMMSS>_<name>.sql`.
- **Migration timestamps:** the latest existing migration is `20260630000000_drop_smart_links.sql`. Each
  PR's migration shows a placeholder timestamp after it (`20260701000000`, `…000100`, …); **the executing
  session bumps each to a timestamp later than the then-latest migration before applying**, preserving the
  per-file ordering within the PR. PRs that both add migrations (2, 3, 5, 6, 7, 8) coordinate the global
  increment order at execution time, not now.
- Migrations are applied to **dev Supabase `glncumufgaqcmqhzwrxm`** via `supabase db push` or MCP
  `apply_migration` — the env's `migrate --force` is commented out, so they do NOT auto-run on deploy.
- **SQLite test schema is hand-built in `tests/Pest.php`** and does NOT auto-apply the SQL migrations, and
  does NOT enforce Postgres `CHECK` / `NOT NULL` / partial-unique / FK-cascade. Every new column/table is
  added by hand to the matching `setup*Table()` helper, AND every constraint gets a **dev-Supabase
  verification step** (apply via `apply_migration`, then attempt an invalid insert via `execute_sql` and
  confirm rejection).
- API responses go through **Resource classes**; authorization through **Policies** called as
  `$this->authorizeForUser($user, 'ability', $resource)` (never inline `abort_unless(...403)`; 404 for
  missing/not-owned). **Every `ShouldQueue` job declares `public int $backoff`**; dispatch with
  `->afterCommit()` (never a typed `public bool $afterCommit`). No raw `Cache::` facade (GS-1).
- **Per-PR full-suite gate:** the unit is done only after the FULL `composer test` is green — a filtered
  subset is a false signal (Wave-1 hit a 9-test regression only the full suite caught).

---

## PR ordering & dependencies

| # | PR | Findings | Migration? | Hard dependency |
|---|----|----------|-----------|-----------------|
| 1 | Auth freshness gate | FOUND-12 | no | none — do first (clears the auth gate). **Security-review checkpoint.** |
| 2 | Menu schema → child tables | FOUND-2, 6 | 2 files | none (self-contained menu subsystem) |
| 3 | Profile JSONB → tables (+ write-path) | FOUND-4, 5 | ≥1 file (3 tables) | **must precede PR4** (rewrites `SectionVisibilityService`) |
| 4 | Section-type pair-CHECK + visibility registry | FOUND-14, 10 | 1 (CHECK swap) | **after PR3** (absorbs PR3's table predicates) |
| 5 | Block column promotion (+ both views) | FOUND-15 | yes + **views** | **after PR4** (updates `BookingVisibility`'s `category` predicate) |
| 6 | Site column promotion (+ both views) | FOUND-16 | yes + **views** | after PR5 (shared views — see lockstep directive) |
| 7 | SiteMedia cover convention | FOUND-3 | index-only | none (registry-file overlap w/ 9/10 is additive) |
| 8 | Connection async state | FOUND-18 | yes (3 files) | none |
| 9 | Connect Form Requests → descriptor | FOUND-19 | no | none (registry-file overlap w/ 7 is additive) |
| 10 | Route registration over registry | FOUND-21 | no | **strictly after PR9** (consumes PR9's route `->defaults`) |

---

## DECISIONS (confirm before implementing — each can be flipped without a rewrite)

> **✅ ALL 8 CONFIRMED BY JOSH 2026-07-01 — locked for execution:**
> (1) FOUND-6 → per-mode child schema · (2) FOUND-16 → widen `booking_mode` to `manual|none` ·
> (3) FOUND-4 → **keep name-OR-address visibility (NO behavior change)** ·
> (4) FOUND-15/16 → dual-write-then-strip · (5) FOUND-18 → `place_id` indexed mirror ·
> (6) FOUND-4 → `workplaces.name` NULLABLE · (7) FOUND-10 → plural `contextSubqueries()` ·
> (8) FOUND-21 → enum case `MultiAccount`.
> **Decision (3) is the only flip from the plan's original default (was name-only)** — the PR3/PR4
> workplace predicates + reason strings below have been updated to name-OR-address.

The prompt's five canonical decisions:

1. **FOUND-6 (PR2):** per-mode child schema `pickup_price/pickup_url/delivery_price/delivery_url`
   **(recommended)** vs the audit's lossy `(price, modes, url)`. — *plan built on per-mode.*
2. **FOUND-16 (PR6):** widen `booking_mode` validation to accept `'none'` to match the new
   `CHECK IN ('manual','none')` **(recommended)** vs keep `'manual'`-only and narrow the CHECK.
3. **FOUND-4 (PR3/PR4): CONFIRMED — keep workplace visibility name-OR-address (NO behavior change).**
   The predicate moves from `settings` JSONB to a portable `site.workplaces` table predicate (name OR
   address, null-safe); the visibility reason string is unchanged.
4. **FOUND-15 / FOUND-16 (PR5/PR6):** **dual-write-then-strip (recommended** — decouples the two-view
   lockstep) vs strip-in-same-migration. — *both PRs built as expand→contract.*
5. **FOUND-18 (PR8):** `place_id` **indexed mirror (recommended** — keep in payload) vs full
   payload-purity re-thread (larger, separate task).

**Additional decisions surfaced during authoring (also confirm):**

6. **FOUND-4 (PR3) — `site.workplaces.name` NULLABLE (recommended)** vs NOT NULL. NOT NULL would 500 two
   shipped name-less write paths (`setPreviousWebsite`, `GoogleBusinessAutoSync::seedWorkplace`); the plan
   is built NULLABLE (the resolver/visibility already gate on a non-empty name, so behavior is preserved).
7. **FOUND-10 (PR4) — `SectionVisibilityContract::contextSubqueries(): array<string,Builder>` (plural,
   recommended)** vs the brief's singular `contextSubquery(): ?Builder`. Plural is required to represent
   booking's two bundled subqueries without breaking the single-round-trip invariant (design plan §101
   sanctions a keyed map).
8. **FOUND-21 (PR10) — route-shape enum case name `MultiAccount` (as drafted)** vs `MigratedReads`.
   Behaviour identical; cosmetic naming only.

---

## The 7 honored premise corrections (the audit moved; do NOT reintroduce its stale shapes)

1. **FOUND-6:** child schema is PER-MODE (`pickup_*`/`delivery_*`), NOT `(platform, price, modes, url)`.
2. **FOUND-3:** `cover_shopify` is a DEAD slot (drop, don't migrate); convention is `cover_` +
   `str_replace('-','_',$key)`; introduce a `coverable` flag on `PlatformDescriptor` — 4 platforms
   (youtube, apple-music, apple-podcast, eventbrite).
3. **FOUND-18:** `place_id` is a first-class selection identifier — INDEXED column mirror, KEEP in payload;
   only `apify_status` is fully promoted out.
4. **FOUND-16:** `StaffUpdateSiteRequest` is missing `charlie_enabled` (9 keys, not 10); `booking_mode`
   does not accept `'none'` today (`Rule::in(['manual'])`).
5. **FOUND-19:** 22 connect requests (not 24); YouTube is a plain `channel` field (not a video-id
   outlier); only GoogleBusiness is irreducible; `PlatformDescriptor` already carries connect metadata.
6. **FOUND-14:** a `block_type` CHECK and a separate `block_group` CHECK already exist — only the PAIR
   check is missing.
7. **FOUND-5:** the read-side migration is incomplete without rewiring the JSON WRITE path
   (new `SyncUserAboutService`) — the biggest single risk in the wave.

## Additional drift caught during authoring (grounded against the live tree)

- **FOUND-12 (PR1):** there are **THREE** byte-identical `requiresFreshAal2` copies, not two — `BasePolicy`,
  `MfaController`, **and `StaffUserController`** (the design plan's "staff policy" is actually a staff
  controller). All three delegate to `Aal2FreshnessGate`. Resolution via `app(Aal2FreshnessGate::class)`
  (not constructor injection — `BasePolicy` is `new`'d in unit tests).
- **FOUND-2 (PR2):** the new `platforms` output now sources from a DB relation; the golden-master asserts
  order by index — the relation MUST preserve UE-then-DoorDash insertion order; **do NOT add an
  alphabetical `orderBy`** (it would flip them: `doordash < uber-eats`).
- **FOUND-16 (PR6):** a **third** `booking_mode` validator exists — `UpdateBookingSettingsRequest` — widen
  it too. `UpdateSiteAction` lives at `app/Services/Site/` (not `app/Services/User/`).
- **FOUND-15 (PR5):** `settings.platform` is **overloaded** — booking *sections* also use it; the backfill
  AND strip MUST be scoped `WHERE block_group='links'`, and `getBooking()` is NOT modified. Three extra
  consumers (cap-enforcement command, the staff write path, a backfill command) must move to the column.
- **FOUND-19 (PR9):** the audit's "17 share `max:500`" is stale — live maxes already drift (120…2048); the
  descriptor carries each platform's exact rules array. `ConnectSocialLinkRequest` is shared by 6 platforms
  (→ 26 `connectInput()` calls). The frozen `toBe(52)` counts GET read-routes under `api/platforms/`.
- **FOUND-3 (PR7):** there is NO live `cover_fresha` index (already swapped to `cover_shopify`); the
  baseline `placeholder` index has a different shape and is NOT subsumed. The payload-key transform is
  `lcfirst(ucwords(...underscore→space...))`, so the `cover_`+underscore convention is still mandatory.
- **FOUND-18 (PR8):** the migration is **3 files** (DDL+CHECK in a txn, backfill outside a txn, index
  `CONCURRENTLY` outside a txn) per `supabase/migrations/CONVENTIONS.md`.
- **FOUND-4 (PR3):** `Model::preventLazyLoading` is on in tests — every new relation read uses
  `loadMissing`. The dashboard/staff `about` round-trips via `UserDashboardResource`/`UserStaffResource`
  (broader than the audit listed). `StaffWorkplaceController` is read-only (11-key shape).
- **Column rename (PRs 4, 5):** the blocks/sites tenant column is `user_id` (renamed from
  `professional_id` in `20260527030000`) — all predicates use `user_id`.

---

## Cross-PR reconciliation directives (resolved during assembly)

These are the dependencies the parallel authors flagged; obey them when sequencing/executing:

1. **PR5 + PR6 both `CREATE OR REPLACE` the SAME two views** (`site.all_site_data`,
   `site.public_site_payload`, defined in `20260527070000_skeleton_system_cleanup.sql`). **Whichever
   migration lands second silently REVERTS the first's view changes unless it includes them.** Directive:
   PR5 lands first (block-level keys `platform`/`category`/`live_check_enabled` added to the `blocks[]` /
   `payload.links[]` JSON objects); when **PR6** is executed it MUST author its view `CREATE OR REPLACE`
   against the **then-current** view definition (run `\sf site.all_site_data` / inspect migration history
   first), carrying PR5's three block keys forward AND adding PR6's settings re-injection
   (`s.settings || jsonb_strip_nulls(jsonb_build_object(...))` — the views emit the whole `settings` blob,
   so promoted site keys re-inject INTO settings, NOT as new top-level keys). **Gating golden-master for
   BOTH PRs:** after each migration, assert the public payload still contains the *other* PR's keys.
2. **PR3 → PR4:** PR3 rewrites the workplace/credentials/experience predicates in
   `SectionVisibilityService` from JSONB `whereRaw` to portable table-based Eloquent (`Workplace`,
   `UserCredential`, `UserExperience`); workplace visibility stays name-OR-address (no behavior change). PR4 then refactors that service
   into a `SectionVisibilityRegistry`; its `WorkplaceVisibility`/`CredentialsVisibility`/
   `ExperienceVisibility` impls are written against **PR3's table predicates**, not the old JSONB.
   PR4 carries a STOP-gate: if PR3 hasn't merged, reconcile before implementing.
3. **PR4 → PR5:** PR4's `BookingVisibility` reads `settings->category` on links-group blocks. PR5 promotes
   `category` to a column. Directive: **PR5 must update `BookingVisibility`'s predicate** from
   `->where('settings->category', …)` to the promoted `category` column when it lands.
4. **PR7 / PR9 / PR10 share `PlatformDescriptor` + `PlatformRegistryServiceProvider`** — PR7 adds a
   `coverable` flag (+4 `->coverable()` calls), PR9 adds connect-rules metadata, PR10 adds a `routeShape`
   enum. All additive; expect line-level merge overlap if landed out of order, no logical conflict.
5. **`tests/Pest.php` is edited by PRs 2, 3, 5, 6, 8** — disjoint `setup*Table()` helpers / table blocks,
   so three-way merges are clean; never clobber a sibling PR's helper.
6. **`composer dump-autoload -o`** is required after PR9 deletes its 21 connect-request files (per the
   worktree-classmap gotcha).


---

## Execution progress (the execution loop ticks these; `a`/`b` = the dual-write-then-strip phases)

- [x] **PR1** — FOUND-12 — `Aal2FreshnessGate` (AUTH; security-review) — implemented · reviewed · deployed ✅ 2026-07-01 (dev 84a2cdff)
- [x] **PR2** — FOUND-2+6 — menu → child tables (2 migrations) — implemented · reviewed · deployed ✅ 2026-07-01 (dev 2b131002; migration backfill fixed for real legacy-shape data — 102 rows, 0 loss)
- [x] **PR3** — FOUND-4+5 — profile → tables + write-path rewire — implemented · reviewed · deployed ✅ 2026-07-01 (dev 202e439c; backfill verified 2 workplaces + 2 cred + 3 exp, 0 loss; about kept for follow-up drop)
- [x] **PR4** — FOUND-14+10 — pair-CHECK + visibility registry — implemented · reviewed · deployed ✅ 2026-07-01 (dev d99db8f7; blocks_group_type_check applied+verified — cross-group rejected, 117 rows valid)
- [x] **PR5a** — FOUND-15 Phase 1 (expand: add cols + backfill + dual-write) — implemented · reviewed · deployed ✅ 2026-07-01 (dev a6f4460a; 3 cols+CHECK+CONCURRENTLY idx applied; 12 link blocks backfilled, column↔settings synced, 0 mismatch; review found+fixed staff-update category dual-write gap)
- [x] **PR5b** — FOUND-15 Phase 2 (strip JSON + both views + wire flip) — ✅ 2026-07-02 (dev 10afe6ca; Josh confirmed frontend ready; both views rewritten+verified — links top-level, settings stripped, booking untouched, index swapped)
- [x] **PR6a** — FOUND-16 Phase 1 (expand) — implemented · reviewed · deployed ✅ 2026-07-02 (dev 6ec90b30; 10 cols+booking_mode CHECK applied; backfill 0 mismatch; CHECK rejects junk; wire byte-identical)
- [ ] **PR6b** — FOUND-16 Phase 2 (strip + both views + wire flip) — ⛔ **FRONTEND-GATED — hard-pause**
- [ ] **PR7** — FOUND-3 — cover convention (index-only migration) — implemented · reviewed · deployed
- [ ] **PR8** — FOUND-18 — connection async state (3 files) — implemented · reviewed · deployed
- [ ] **PR9** — FOUND-19 — connect requests → descriptor (+`composer dump-autoload -o`) — implemented · reviewed · deployed
- [ ] **PR10** — FOUND-21 — route registry — implemented · reviewed · deployed

---

## PR1 — FOUND-12 — extract `Aal2FreshnessGate` (AUTH-SENSITIVE)

**Goal.** Collapse the copy-pasted "fresh AAL2" freshness-check logic into one source-of-truth service so the MFA-method allowlist can never drift between consumers.

**Architecture.** A new stateless `App\Services\Auth\Aal2FreshnessGate::check(Request $request, int $maxAgeSeconds): Response` carries the verbatim scan-and-compare body (MFA-method allowlist → max-timestamp scan over `supabase_amr` → null⇒401 / age-compare⇒allow-or-401). Every call site keeps its own method signature and its own window default, and delegates the body to the gate via `app(Aal2FreshnessGate::class)`. No caller churn, no behavior change — pure extraction.

**Blast radius (4 files touched, 1 new test):**
- NEW `app/Services/Auth/Aal2FreshnessGate.php`
- `app/Policies/BasePolicy.php` — `requiresFreshAal2()` body → delegate
- `app/Http/Controllers/Api/User/Account/MfaController.php` — `requiresFreshAal2()` body → delegate
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php` — `requiresFreshAal2()` body → delegate  ← **third copy the audit/design-plan missed**
- NEW `tests/Unit/Auth/Aal2FreshnessGateTest.php`

**No migration.** (Matches the design-plan sequencing table row 1: "Migration? no".) No job touched (no `$backoff` concern). No new controller action (no `authorizeForUser` concern). The existing `authorizeForUser(...)` calls at all consumer sites are untouched.

**Premise grounding.**
- Audit/design-plan premise **"two copies, core byte-identical"** is CONFIRMED for `BasePolicy` + `MfaController` — but is **INCOMPLETE**. There is a **THIRD byte-identical copy**: `StaffUserController::requiresFreshAal2()` (lines 242–266), called by three high-risk staff endpoints (`updateStatus`, `bulkUpdateStatus`, `forceDestroy`). It is guarded by `StaffUserControllerFreshAal2Test` — the very test the design plan names as "the second consumer." The design plan calls that consumer a "staff *policy*"; it is actually a staff *controller* with its own inline copy. **This PR delegates all THREE call sites** (eliminating the drift the finding is about would be defeated by leaving the staff copy behind).
- All three bodies are byte-identical except: (a) `BasePolicy` and `StaffUserController` default a null `$maxAgeSeconds` from `config('partna.mfa.fresh_window_seconds', 300)`; `MfaController` requires the int and passes `config('partna.mfa.unenroll_fresh_window_seconds', 60)`; (b) `BasePolicy` reads the request via the `request()` helper while the two controllers receive an injected `$request`. Both differences stay at the call site — the gate is pure.
- The `$mfaMethods = ['totp', 'phone', 'webauthn']` allowlist is a local variable in all three copies; the gate keeps it as a local variable (NOT a const) so the extraction is byte-for-byte.

**Resolution-pattern decision (baked in — see DRIFT note 3).** `BasePolicy` **cannot** take constructor injection: it is instantiated via `new` in unit tests (`tests/Feature/Auth/BasePolicyAalHelpersTest.php` `new TestableBasePolicy`; `tests/Unit/Policies/UserSelfPolicyTest.php` + `UserStaffPolicyTest.php` `new UserSelfPolicy`). To keep ONE uniform resolution pattern across all three call sites (a single mental model for the security reviewer) and zero constructor churn, all three resolve the gate with `app(Aal2FreshnessGate::class)`. This is consistent with `BasePolicy` already using the `request()` global helper. (Alternative — constructor-inject the gate into the two controllers — is viable but mixes patterns and adds a constructor to `StaffUserController`; not chosen.)

**Golden-master requirement (AUTH-SENSITIVE).** Both the policy path and the `MfaController::destroy` path (and the three `StaffUserController` paths) MUST behave identically before and after. The existing consumer suites are the byte-for-byte regression net and MUST stay green unchanged:
- `tests/Feature/Auth/BasePolicyAalHelpersTest.php` — exercises `BasePolicy::requiresFreshAal2()` directly via `TestableBasePolicy` (fresh-allow, stale-deny-401, no-mfa-deny-401, max-scan order-independence).
- `tests/Feature/Account/UnenrollMfaFactorTest.php` — `MfaController::destroy` (aal1⇒401, 90s-stale⇒401, 30s-fresh⇒200 + Admin call + event row, 502 passthrough, **API-8 contract** `code = mfa_fresh_required` + `{message, code}` shape).
- `tests/Feature/Security/StaffUserControllerFreshAal2Test.php` — all three staff endpoints (stale⇒401+`mfa_fresh_required`, empty-amr⇒401, fresh⇒200/not-401, 0s⇒200/not-401).
- `tests/Unit/Policies/UserSelfPolicyTest.php`, `tests/Unit/Policies/UserStaffPolicyTest.php`, `tests/Feature/Security/PolicyEnforcement/UserSelfPolicyEnforcementTest.php` — confirm `new UserSelfPolicy` instantiation and the gated profile-update path still work.

No test file in this list is edited. Editing any of them would invalidate the golden master.

---

### COMPLETE CODE

#### 1. NEW `app/Services/Auth/Aal2FreshnessGate.php`

```php
<?php

namespace App\Services\Auth;

use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;

/**
 * Single source of truth for the "fresh AAL2" check — was the user's most recent
 * MFA verification inside $maxAgeSeconds?
 *
 * AAL stays sticky at aal2 for the life of a Supabase session (it is NOT
 * downgraded on token refresh), so session-level aal2 alone cannot answer
 * "verified recently". We inspect the amr timeline instead: scan every entry,
 * take the max MFA-method timestamp, and compare to now. The scan is
 * order-independent — correct whether Supabase emits amr oldest- or newest-first.
 *
 * SECURITY-SENSITIVE: this gate decides whether high-risk actions proceed — MFA
 * unenroll (MfaController), staff suspend / bulk-suspend / force-delete
 * (StaffUserController), and the flag-gated profile self-mutation (UserSelfPolicy
 * via BasePolicy). Those four call sites previously each carried a byte-identical
 * copy of this logic; they now all delegate here so the MFA-method allowlist can
 * never drift between them. Changing the allowlist or the comparison changes the
 * security posture of ALL consumers at once — by design.
 */
class Aal2FreshnessGate
{
    /**
     * @param  Request  $request  Carries the verified `supabase_amr` attribute:
     *                            an array of ['method' => string, 'timestamp' => int].
     * @param  int  $maxAgeSeconds  Freshness window. Each call site owns its own
     *                              default (staff/profile window vs the unenroll
     *                              window) and passes the resolved value here.
     */
    public function check(Request $request, int $maxAgeSeconds): Response
    {
        $amr = $request->attributes->get('supabase_amr', []);
        $mfaMethods = ['totp', 'phone', 'webauthn'];

        $mostRecentMfaTs = null;
        foreach ($amr as $entry) {
            $method = $entry['method'] ?? null;
            if (in_array($method, $mfaMethods, true)) {
                $ts = (int) ($entry['timestamp'] ?? 0);
                if ($mostRecentMfaTs === null || $ts > $mostRecentMfaTs) {
                    $mostRecentMfaTs = $ts;
                }
            }
        }

        if ($mostRecentMfaTs === null) {
            return Response::denyWithStatus(401, 'Recent MFA verification required');
        }

        return (time() - $mostRecentMfaTs) <= $maxAgeSeconds
            ? Response::allow()
            : Response::denyWithStatus(401, 'Recent MFA verification required');
    }
}
```

#### 2. `app/Policies/BasePolicy.php`

Add the import (after the existing `use Illuminate\Auth\Access\Response;`):

```php
use App\Services\Auth\Aal2FreshnessGate;
```

Replace the `requiresFreshAal2()` method body (current lines 69–93) in full with:

```php
    /**
     * "Fresh" AAL2 — was the user's most recent MFA verification inside
     * $maxAgeSeconds? Use for high-risk actions where AAL2 alone is too weak
     * (an attacker on an already-aal2 session could otherwise act freely).
     *
     * Delegates the amr scan to Aal2FreshnessGate (the single source of truth
     * shared with MfaController + StaffUserController) so the MFA-method allowlist
     * cannot drift. This signature stays put: TestableBasePolicy + UserSelfPolicy
     * call it, so the default-window resolution lives here, not in the gate.
     *
     * @param  int  $maxAgeSeconds  Window. Default in config('partna.mfa.fresh_window_seconds').
     */
    protected function requiresFreshAal2(?int $maxAgeSeconds = null): Response
    {
        $maxAgeSeconds ??= (int) config('partna.mfa.fresh_window_seconds', 300);

        return app(Aal2FreshnessGate::class)->check(request(), $maxAgeSeconds);
    }
```

(`use Illuminate\Auth\Access\Response;` stays — still the return type here and used by the other helpers.)

#### 3. `app/Http/Controllers/Api/User/Account/MfaController.php`

Add the import (alongside the existing `App\Services\Auth\*` imports):

```php
use App\Services\Auth\Aal2FreshnessGate;
```

`destroy()` is **unchanged** — it still calls `$this->requiresFreshAal2($request, $window)` where `$window = (int) config('partna.mfa.unenroll_fresh_window_seconds', 60)`, and the `mfa_fresh_required` error shape is preserved exactly.

Replace the private `requiresFreshAal2()` method body (current lines 77–107) in full with:

```php
    /**
     * Fresh-AAL2 gate for the unenroll endpoint. Kept as a local wrapper (not
     * delegated to a policy) because there is no model to authorize against — the
     * factor lives in Supabase, not our DB; see destroy(). The actual scan is the
     * shared Aal2FreshnessGate, so this path can no longer drift from BasePolicy.
     */
    private function requiresFreshAal2(Request $request, int $maxAgeSeconds): GateResponse
    {
        return app(Aal2FreshnessGate::class)->check($request, $maxAgeSeconds);
    }
```

(`use Illuminate\Auth\Access\Response as GateResponse;` stays — the gate returns the same class, so the `GateResponse` return type remains valid.)

#### 4. `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`

Add the import (alongside the existing `use` block, e.g. after `use App\Models\Core\User\User;`):

```php
use App\Services\Auth\Aal2FreshnessGate;
```

`updateStatus()`, `bulkUpdateStatus()`, and `forceDestroy()` are **unchanged** — they each still call `$this->requiresFreshAal2($request)` and return the same `{message, code: mfa_fresh_required}` body.

Replace the private `requiresFreshAal2()` method body (current lines 234–266) in full with:

```php
    /**
     * Fresh-AAL2 gate for high-risk staff actions (updateStatus / bulkUpdateStatus
     * / forceDestroy). Local wrapper (not a policy) following MfaController's
     * convention: these actions have no model to authorize against and a controller
     * cannot call a protected policy method. Uses the staff fresh window
     * (config('partna.mfa.fresh_window_seconds', 300)); the scan is the shared
     * Aal2FreshnessGate so this path can no longer drift from BasePolicy.
     */
    private function requiresFreshAal2(Request $request, ?int $maxAgeSeconds = null): GateResponse
    {
        $maxAgeSeconds ??= (int) config('partna.mfa.fresh_window_seconds', 300);

        return app(Aal2FreshnessGate::class)->check($request, $maxAgeSeconds);
    }
```

(`use Illuminate\Auth\Access\Response as GateResponse;` stays.)

---

### Postgres-only constraints note

Not applicable — this PR adds no migration and no DB constraint. No `tests/Pest.php` schema change, no DEV-SUPABASE verification step.

---

### TDD TASKS

#### Task 1 — new `Aal2FreshnessGate` unit test + the service (TDD)

**1a. Write the failing test** `tests/Unit/Auth/Aal2FreshnessGateTest.php` (service unit tests live under `tests/Unit/Auth/`, e.g. `SupabaseAdminServiceTest.php`):

```php
<?php

use App\Services\Auth\Aal2FreshnessGate;
use Illuminate\Http\Request;

/** Build a Request carrying the given amr timeline as the verified attribute. */
function aal2GateRequest(array $amr): Request
{
    $request = Request::create('/', 'GET');
    $request->attributes->set('supabase_amr', $amr);

    return $request;
}

it('allows when the most recent mfa entry is inside the window', function () {
    $request = aal2GateRequest([
        ['method' => 'totp', 'timestamp' => time() - 60],
    ]);

    expect((new Aal2FreshnessGate)->check($request, 300)->allowed())->toBeTrue();
});

it('denies with 401 + message when the most recent mfa entry is outside the window', function () {
    $request = aal2GateRequest([
        ['method' => 'totp', 'timestamp' => time() - 1000],
    ]);

    $response = (new Aal2FreshnessGate)->check($request, 300);

    expect($response->allowed())->toBeFalse();
    expect($response->status())->toBe(401);
    expect($response->message())->toBe('Recent MFA verification required');
});

it('denies with 401 + message when amr has no mfa entries', function () {
    $request = aal2GateRequest([
        ['method' => 'magiclink', 'timestamp' => time() - 5],
    ]);

    $response = (new Aal2FreshnessGate)->check($request, 300);

    expect($response->allowed())->toBeFalse();
    expect($response->status())->toBe(401);
    expect($response->message())->toBe('Recent MFA verification required');
});

it('denies with 401 when amr is empty', function () {
    $response = (new Aal2FreshnessGate)->check(aal2GateRequest([]), 300);

    expect($response->allowed())->toBeFalse();
    expect($response->status())->toBe(401);
});

it('scans for the max mfa timestamp, ignoring newer non-mfa entries', function () {
    // A newer non-mfa entry must NOT make the gate pass; the fresh mfa entry decides.
    $request = aal2GateRequest([
        ['method' => 'token_refresh', 'timestamp' => time() - 5],   // newest, not mfa
        ['method' => 'totp', 'timestamp' => time() - 60],            // mfa, fresh
        ['method' => 'magiclink', 'timestamp' => time() - 120],
    ]);

    expect((new Aal2FreshnessGate)->check($request, 300)->allowed())->toBeTrue();
});

it('takes the most recent mfa timestamp when multiple mfa entries exist (order-independent)', function () {
    // Stale totp listed AFTER a fresh webauthn — result must use the max (fresh) one.
    $request = aal2GateRequest([
        ['method' => 'totp', 'timestamp' => time() - 5000],    // stale
        ['method' => 'webauthn', 'timestamp' => time() - 30],  // fresh, max
    ]);

    expect((new Aal2FreshnessGate)->check($request, 300)->allowed())->toBeTrue();
});

it('counts phone and webauthn as valid mfa methods', function () {
    foreach (['phone', 'webauthn'] as $method) {
        $request = aal2GateRequest([
            ['method' => $method, 'timestamp' => time() - 10],
        ]);

        expect((new Aal2FreshnessGate)->check($request, 300)->allowed())
            ->toBeTrue("method {$method} should count as MFA");
    }
});
```

**1b. Run — expect FAIL:**

```bash
php artisan test tests/Unit/Auth/Aal2FreshnessGateTest.php
```

Expected: errors with `Error: Class "App\Services\Auth\Aal2FreshnessGate" not found` (class does not exist yet).

**1c. Create the service** — write `app/Services/Auth/Aal2FreshnessGate.php` exactly as in COMPLETE CODE §1.

**1d. Run — expect PASS:**

```bash
php artisan test tests/Unit/Auth/Aal2FreshnessGateTest.php
```

Expected: all 7 tests pass (8 assertions+).

**1e. Style + commit:**

```bash
php artisan pint --dirty
git add app/Services/Auth/Aal2FreshnessGate.php tests/Unit/Auth/Aal2FreshnessGateTest.php
git commit -m "feat(auth): add Aal2FreshnessGate single-source fresh-AAL2 gate (FOUND-12)"
```

#### Task 2 — delegate all THREE call sites (refactor under golden-master guard)

This step changes no behavior; the existing consumer suites are the regression net.

**2a. Establish the golden master — run BEFORE editing, expect PASS:**

```bash
php artisan test \
  tests/Feature/Auth/BasePolicyAalHelpersTest.php \
  tests/Feature/Account/UnenrollMfaFactorTest.php \
  tests/Feature/Security/StaffUserControllerFreshAal2Test.php \
  tests/Unit/Policies/UserSelfPolicyTest.php \
  tests/Unit/Policies/UserStaffPolicyTest.php \
  tests/Feature/Security/PolicyEnforcement/UserSelfPolicyEnforcementTest.php
```

Expected: all green. Record the totals (test + assertion counts) — they MUST be identical in 2c.

**2b. Apply the delegation edits** — COMPLETE CODE §2 (BasePolicy), §3 (MfaController), §4 (StaffUserController). Add each `use App\Services\Auth\Aal2FreshnessGate;` import; replace each `requiresFreshAal2()` body. Do NOT touch any caller (`destroy`, `update`, `updateStatus`, `bulkUpdateStatus`, `forceDestroy`) and do NOT touch any test file.

**2c. Run the same golden-master set — expect PASS with identical totals:**

```bash
php artisan test \
  tests/Feature/Auth/BasePolicyAalHelpersTest.php \
  tests/Feature/Account/UnenrollMfaFactorTest.php \
  tests/Feature/Security/StaffUserControllerFreshAal2Test.php \
  tests/Unit/Policies/UserSelfPolicyTest.php \
  tests/Unit/Policies/UserStaffPolicyTest.php \
  tests/Feature/Security/PolicyEnforcement/UserSelfPolicyEnforcementTest.php
```

Expected: all green, same test/assertion counts as 2a (proves byte-identical behavior across the policy path, the MfaController destroy path, and the three staff paths).

**2d. Style + commit:**

```bash
php artisan pint --dirty
git add app/Policies/BasePolicy.php \
        app/Http/Controllers/Api/User/Account/MfaController.php \
        app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php
git commit -m "refactor(auth): delegate 3 fresh-AAL2 copies to Aal2FreshnessGate (FOUND-12)"
```

#### Task 3 — SECURITY-REVIEW CHECKPOINT (mandatory extra pass)

This is the one AUTH-SENSITIVE Wave-2 finding. After Task 2's implement→review, a SEPARATE reviewer instance performs an explicit security review BEFORE the box is ticked. The reviewer confirms, with the diff in hand:

1. **Scan logic is byte-identical.** `Aal2FreshnessGate::check()` reproduces the original `$amr` read, the `$mfaMethods = ['totp','phone','webauthn']` allowlist (local variable, not narrowed/widened), the `in_array(..., true)` strict check, the `(int) ($entry['timestamp'] ?? 0)` coercion, the max-timestamp scan, the `null ⇒ denyWithStatus(401, 'Recent MFA verification required')` branch, and the `(time() - $ts) <= $maxAgeSeconds ? allow() : deny` comparison (operator `<=`, message string verbatim).
2. **Window defaults preserved per call site.** `MfaController` ⇒ `config('partna.mfa.unenroll_fresh_window_seconds', 60)`; `BasePolicy` + `StaffUserController` ⇒ `config('partna.mfa.fresh_window_seconds', 300)` when null. No window value moved into the gate.
3. **amr source preserved.** `BasePolicy` still reads via `request()`; the two controllers still pass the injected `$request`. The gate reads `supabase_amr` from whichever `Request` it is given.
4. **No acceptance widened.** No `aal`/`amr` method added or removed; no fallback that would accept a stale-but-aal2 session. The only behavioral consequence of the change is that future allowlist edits now propagate to all four consumers at once (the intended fix).
5. **Return-type compatibility.** The gate returns `Illuminate\Auth\Access\Response`, which is exactly the class aliased as `GateResponse` in the controllers and imported as `Response` in `BasePolicy` — every caller's `->allowed()` / `->message()` / `->status()` usage is unchanged.
6. **No new auth surface.** No route, middleware, or policy registration changed; `app(Aal2FreshnessGate::class)` resolves a stateless service (no container binding needed; auto-resolved).

Reviewer verdict must be PASS (security) AND the golden-master totals from 2c must match 2a before this checkbox is ticked.

#### Task 4 — FULL-SUITE GATE (a filtered subset is a false signal)

```bash
composer test
```

Expected: the entire Pest suite is green (Wave-1 hit a 9-test regression only the full suite caught — do not skip this). Only after a fully-green run is PR1 complete.

---

### Cross-PR / assembler notes

- **Independent — safe to do first.** PR1 touches `BasePolicy`, `MfaController`, `StaffUserController`, a new service, and a new test. No other Wave-2 PR in the sequencing table is listed as editing these files. Doing PR1 first clears the auth gate, as the design plan recommends.
- `UserSelfPolicy` is NOT edited (its `update()` calls the unchanged `BasePolicy::requiresFreshAal2()` signature). If any later PR edits `BasePolicy` or `UserSelfPolicy`, sequence after PR1 to avoid a merge conflict on the shared base class.


---

## PR2 — FOUND-2 + FOUND-6 — menu delivery cols + `menu_items.platforms` → child tables

**Goal:** Collapse the menu subsystem's two hardcoded-per-platform shapes — the six `uber_eats_*`/`doordash_*` columns on `site.menus` and the `menu_items.platforms` JSONB array — into two normalised child tables (`site.menu_platform_links`, `site.menu_item_platforms`), so adding a delivery platform is a row, not a schema-plus-five-code-edits remodel.

**Architecture:** `MenuFetchJob` is the single hinge that writes both shapes today, so both findings ship in **one PR with two migration files** (granular rollback). FOUND-2 lands first (menu-level per-platform sync state → `menu_platform_links`, one row per `(menu, platform)`), then FOUND-6 (per-item per-platform availability → `menu_item_platforms`, one row per `(menu_item, platform)`, **per-mode columns** `pickup_price`/`pickup_url`/`delivery_price`/`delivery_url`). `MenuFetchJob` reads/writes the link table via `updateOrCreate(['menu_id','platform'])` and bulk-inserts item-platform child rows keyed to the pre-generated item id. `MenuController::platforms()` maps the new relation into the byte-identical `{platform,pickupPrice,pickupUrl,deliveryPrice,deliveryUrl}` output. `MenuMerger` is unchanged (it still returns the in-memory `platforms` array per item — only the persist layer changes).

**Blast radius (exact files):**
- `app/Models/Core/Site/Menu.php` (drop 6 fillable + 2 casts, add `platformLinks()` hasMany)
- `app/Models/Core/Site/MenuItem.php` (drop `platforms` fillable + cast, add `platformLinks()` hasMany)
- `app/Models/Core/Site/MenuPlatformLink.php` (NEW)
- `app/Models/Core/Site/MenuItemPlatform.php` (NEW)
- `app/Jobs/Platforms/MenuFetchJob.php` (skip logic, pending-flip store_url write, status write, `platformSettled`, persist's item-platform write)
- `app/Console/Commands/RetryUnavailableMenusCommand.php` (`whereHas('platformLinks', …)`)
- `app/Http/Controllers/Api/Platforms/MenuController.php` (`platforms()` reads the relation; drop dead legacy branch; eager-load)
- `supabase/migrations/20260701000000_menu_platform_links.sql` (NEW)
- `supabase/migrations/20260701000100_menu_item_platforms_table.sql` (NEW)
- `tests/Pest.php` (`setupSitesTable()`: add 2 TEXT tables, remove 6 cols from `site.menus`, remove `platforms` from `site.menu_items`)
- `tests/Feature/Platforms/MenuTest.php` (`seedMenu` helper + 6 golden-master tests reseeded onto child rows)

**DECISION (confirm before implementing): per-mode child schema (recommended) vs the audit's lossy `(price, modes, url)`.** The live writer (`MenuMerger::platformEntry`, `app/Services/Platforms/MenuMerger.php:205-218`) and reader (`MenuController::platforms()`, `:149-181`) both use a per-mode shape — `{platform, pickupPrice, pickupUrl, deliveryPrice, deliveryUrl}` (TWO prices, TWO urls per platform). The audit's `(platform, price NUMERIC, modes JSONB, order_url TEXT)` cannot represent this without loss. This plan is written around the recommended per-mode schema. The `{price, modes, url}` branch in `MenuController::platforms()` (`:166-179`) is dead legacy back-compat (no pre-beta rows of that shape exist) — drop it.

**Sub-decision (low stakes — default = honor design plan):** `menu_platform_links.platform` carries a `CHECK IN ('uber-eats','doordash')`, mirroring the existing `content_source` / `pickup_platform` / `source_platform` CHECKs throughout the menu subsystem. The column-explosion this PR removes is gone; adding a 3rd delivery platform still needs a one-line CHECK widen — acceptable and consistent. (`menu_item_platforms.platform` is deliberately **un-CHECKed** so item availability can mirror any platform without a migration — that is the extensibility win.)

**Premise grounding:** Honored premise correction #1 (FOUND-6 per-mode child schema) — confirmed against the live merger and controller. Drift found vs the audit (NOT vs the design plan, which already caught these):
- Audit's FOUND-6 schema `(platform, price, modes, url)` is stale — replaced with per-mode columns.
- Audit's FOUND-2 "5 code locations" missed `RetryUnavailableMenusCommand` (a real consumer — the 15-min `menu:retry-unavailable` schedule). Confirmed it reads `uber_eats_status`/`doordash_status` at `:31-32`.
- Confirmed (grep, whole `app/`) the ONLY readers of the 6 menu columns are `Menu.php`, `MenuFetchJob.php`, `RetryUnavailableMenusCommand.php`; the ONLY readers of `menu_items.platforms` are `MenuItem.php`, `MenuFetchJob.php:217` (write), `MenuController.php:132/149/151/180` (read). `MenuController` does NOT read the 6 menu columns. No public controller reads either shape. `MenuMerger::platformEntry` returns the in-memory array and stays unchanged.
- No Resource class is involved — `MenuController` returns plain arrays via `$this->success([...])`; there is nothing to re-thread through a Resource.

**Migration timestamps:** latest existing migration is `20260630000000_drop_smart_links.sql`. This PR uses `20260701000000` (FOUND-2) then `20260701000100` (FOUND-6). *The executing session bumps these to timestamps later than the then-latest migration before applying.*

**Authorization / `$backoff` notes:** `MenuController`'s every query is already scoped to `$this->currentUser($request)->id` (read-only dashboard surface, not a per-resource policy action) — this PR preserves that scoping and adds no new authorization surface. `MenuFetchJob` already declares `public array $backoff = [30, 120];` (`:48`) and is the only `ShouldQueue` touched — **keep `$backoff` intact**; no new job is introduced.

**Expand/contract + queue-drain caveat:** dev Supabase serves prod traffic. The migrations below are written **collapsed** (CREATE + backfill + DROP COLUMN in one file each) — safe pre-beta (zero rows). **Before applying, drain the `scraping` queue** (`php artisan horizon:terminate` / let in-flight `MenuFetchJob`s finish) so an old worker doesn't hit a dropped column mid-run. If a zero-downtime path is ever wanted: split each file into (1) CREATE+backfill, (2) deploy code, (3) DROP COLUMN — but pre-beta the collapsed form is preferred.

---

### Migration file 1 — `supabase/migrations/20260701000000_menu_platform_links.sql`

```sql
-- =====================================================================
-- Menu per-platform sync state → site.menu_platform_links (FOUND-2)
-- =====================================================================
-- Replaces the six hardcoded columns on site.menus
--   (uber_eats_store_url / uber_eats_synced_at / uber_eats_status,
--    doordash_store_url / doordash_synced_at / doordash_status)
-- with one row per (menu, platform). Adding a third delivery platform is
-- now a row, not three new columns + five code edits. Each row tracks one
-- platform's scrape: the store URL targeted, when it last synced, and its
-- last per-platform status (independent of the merge outcome).
-- Default-privilege grant in the baseline (ALTER DEFAULT PRIVILEGES IN
-- SCHEMA site, baseline :2303) auto-covers this table, exactly as it did
-- for site.menu_categories / site.menu_items (added in 20260619050000
-- with no explicit GRANT). No RLS (matches the rest of the menu subsystem).

CREATE TABLE IF NOT EXISTS site.menu_platform_links (
    id         uuid PRIMARY KEY,
    menu_id    uuid NOT NULL REFERENCES site.menus (id) ON DELETE CASCADE,
    platform   text NOT NULL CHECK (platform IN ('uber-eats', 'doordash')),
    store_url  text,
    synced_at  timestamptz,
    status     text CHECK (status IN ('pending', 'ok', 'unavailable')),
    created_at timestamptz,
    updated_at timestamptz,
    UNIQUE (menu_id, platform)
);
CREATE INDEX IF NOT EXISTS idx_menu_platform_links_menu ON site.menu_platform_links (menu_id);

-- Backfill: one row per connected platform (gated on store_url present —
-- a platform with no store URL was never connected). No-op pre-beta (zero
-- rows); correct for prod-shape parity.
INSERT INTO site.menu_platform_links (id, menu_id, platform, store_url, synced_at, status, created_at, updated_at)
SELECT gen_random_uuid(), m.id, 'uber-eats', m.uber_eats_store_url, m.uber_eats_synced_at, m.uber_eats_status, now(), now()
FROM site.menus m
WHERE m.uber_eats_store_url IS NOT NULL;

INSERT INTO site.menu_platform_links (id, menu_id, platform, store_url, synced_at, status, created_at, updated_at)
SELECT gen_random_uuid(), m.id, 'doordash', m.doordash_store_url, m.doordash_synced_at, m.doordash_status, now(), now()
FROM site.menus m
WHERE m.doordash_store_url IS NOT NULL;

ALTER TABLE site.menus
    DROP COLUMN IF EXISTS uber_eats_store_url,
    DROP COLUMN IF EXISTS uber_eats_synced_at,
    DROP COLUMN IF EXISTS uber_eats_status,
    DROP COLUMN IF EXISTS doordash_store_url,
    DROP COLUMN IF EXISTS doordash_synced_at,
    DROP COLUMN IF EXISTS doordash_status;

-- ROLLBACK:
-- ALTER TABLE site.menus
--     ADD COLUMN IF NOT EXISTS uber_eats_store_url  text,
--     ADD COLUMN IF NOT EXISTS uber_eats_synced_at  timestamptz,
--     ADD COLUMN IF NOT EXISTS uber_eats_status     text
--         CHECK (uber_eats_status IN ('pending', 'ok', 'unavailable')),
--     ADD COLUMN IF NOT EXISTS doordash_store_url   text,
--     ADD COLUMN IF NOT EXISTS doordash_synced_at   timestamptz,
--     ADD COLUMN IF NOT EXISTS doordash_status      text
--         CHECK (doordash_status IN ('pending', 'ok', 'unavailable'));
-- UPDATE site.menus m SET
--     uber_eats_store_url = ue.store_url, uber_eats_synced_at = ue.synced_at, uber_eats_status = ue.status
--     FROM site.menu_platform_links ue WHERE ue.menu_id = m.id AND ue.platform = 'uber-eats';
-- UPDATE site.menus m SET
--     doordash_store_url = dd.store_url, doordash_synced_at = dd.synced_at, doordash_status = dd.status
--     FROM site.menu_platform_links dd WHERE dd.menu_id = m.id AND dd.platform = 'doordash';
-- DROP TABLE IF EXISTS site.menu_platform_links;
```

### Migration file 2 — `supabase/migrations/20260701000100_menu_item_platforms_table.sql`

```sql
-- =====================================================================
-- Menu item per-platform availability → site.menu_item_platforms (FOUND-6)
-- =====================================================================
-- Replaces the menu_items.platforms JSONB array with one row per
-- (menu_item, platform). PER-MODE shape (matches the live writer
-- MenuMerger::platformEntry and reader MenuController::platforms):
--   pickup_price + pickup_url, delivery_price + delivery_url.
-- A mode the store doesn't offer is NULL on both price and url. platform is
-- deliberately un-CHECKed so item availability mirrors any platform without
-- a migration. menu_item_id FK ON DELETE CASCADE so a wholesale item rebuild
-- (MenuFetchJob deletes items each scrape) auto-clears stale child rows.

CREATE TABLE IF NOT EXISTS site.menu_item_platforms (
    id             uuid PRIMARY KEY,
    menu_item_id   uuid NOT NULL REFERENCES site.menu_items (id) ON DELETE CASCADE,
    platform       text NOT NULL,
    pickup_price   numeric(10,2),
    pickup_url     text,
    delivery_price numeric(10,2),
    delivery_url   text,
    created_at     timestamptz,
    updated_at     timestamptz,
    UNIQUE (menu_item_id, platform)
);
CREATE INDEX IF NOT EXISTS idx_menu_item_platforms_item ON site.menu_item_platforms (menu_item_id);

-- Backfill from the JSONB array via jsonb_to_recordset (camelCase keys
-- quoted to preserve case). No-op pre-beta (zero rows). Only the per-mode
-- shape is backfilled; the dead {price,modes,url} legacy shape has no rows.
INSERT INTO site.menu_item_platforms (id, menu_item_id, platform, pickup_price, pickup_url, delivery_price, delivery_url, created_at, updated_at)
SELECT gen_random_uuid(), mi.id, p.platform, p."pickupPrice", p."pickupUrl", p."deliveryPrice", p."deliveryUrl", now(), now()
FROM site.menu_items mi
CROSS JOIN LATERAL jsonb_to_recordset(mi.platforms)
    AS p(platform text, "pickupPrice" numeric, "pickupUrl" text, "deliveryPrice" numeric, "deliveryUrl" text)
WHERE mi.platforms IS NOT NULL
  AND jsonb_typeof(mi.platforms) = 'array';

ALTER TABLE site.menu_items DROP COLUMN IF EXISTS platforms;

-- ROLLBACK:
-- ALTER TABLE site.menu_items ADD COLUMN IF NOT EXISTS platforms jsonb;
-- UPDATE site.menu_items mi SET platforms = sub.arr
--   FROM (
--     SELECT menu_item_id,
--            jsonb_agg(jsonb_build_object(
--              'platform', platform,
--              'pickupPrice', pickup_price,
--              'pickupUrl', pickup_url,
--              'deliveryPrice', delivery_price,
--              'deliveryUrl', delivery_url
--            )) AS arr
--     FROM site.menu_item_platforms GROUP BY menu_item_id
--   ) sub
--   WHERE sub.menu_item_id = mi.id;
-- DROP TABLE IF EXISTS site.menu_item_platforms;
```

---

### New model — `app/Models/Core/Site/MenuPlatformLink.php`

```php
<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One delivery platform's sync state for a menu — replaces the per-platform
// *_store_url / *_synced_at / *_status columns that used to live on site.menus.
// One row per (menu, platform). `status` is the last per-platform scrape outcome
// ('pending' | 'ok' | 'unavailable'), independent of the overall merge result.
class MenuPlatformLink extends BaseModel
{
    use HasUuids;

    protected $table = 'site.menu_platform_links';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'menu_id',
        'platform',
        'store_url',
        'synced_at',
        'status',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
```

### New model — `app/Models/Core/Site/MenuItemPlatform.php`

```php
<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One dish's availability on a single ordering platform — replaces the
// menu_items.platforms JSONB array. Per-mode: pickup_price + pickup_url,
// delivery_price + delivery_url. A mode the store doesn't offer is null on
// both. One row per (menu_item, platform); rebuilt wholesale every scrape.
class MenuItemPlatform extends BaseModel
{
    use HasUuids;

    protected $table = 'site.menu_item_platforms';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'menu_item_id',
        'platform',
        'pickup_price',
        'pickup_url',
        'delivery_price',
        'delivery_url',
    ];

    protected $casts = [
        'pickup_price' => 'float',
        'delivery_price' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}
```

---

### Edit — `app/Models/Core/Site/Menu.php`

Drop the 6 platform columns from `$fillable`, drop the 2 `*_synced_at` casts, add the `platformLinks()` relation. The class-doc block's reference to "per-platform *_store_url / *_synced_at / *_status columns" updates to point at the child table.

Replace the `$fillable` array (`:35-53`) with:

```php
    protected $fillable = [
        'user_id',
        'content_source',
        'store_name',
        'logo_url',
        'rating',
        'review_count',
        'currency',
        'pickup_platform',
        'delivery_platform',
        'fetch_status',
        'last_fetched_at',
    ];
```

Replace the `$casts` array (`:55-64`) with:

```php
    protected $casts = [
        'rating' => 'float',
        'review_count' => 'integer',
        'last_fetched_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
```

Add `use Illuminate\Database\Eloquent\Relations\HasMany;` is already imported (`:9`). Add the relation after `items()` (`:78-81`):

```php
    /** Per-platform sync state (one row per delivery platform). */
    public function platformLinks(): HasMany
    {
        return $this->hasMany(MenuPlatformLink::class, 'menu_id');
    }
```

Update the class doc-comment (`:20-21`) — replace:
```
// delivery_price from those platforms. The per-platform *_store_url / *_synced_at
// / *_status columns track each scrape independently. Per-item order LINKS are
```
with:
```
// delivery_price from those platforms. Each delivery platform's store URL,
// last-sync timestamp, and status live in site.menu_platform_links (one row per
// platform), tracking each scrape independently. Per-item order LINKS are
```

### Edit — `app/Models/Core/Site/MenuItem.php`

Drop `'platforms'` from `$fillable` (`:46`) and `'platforms' => 'array'` from `$casts` (`:53`); add the `platformLinks()` relation.

Add to the imports (after `:7`):
```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

Remove `'platforms',` from `$fillable`. Remove `'platforms' => 'array',` from `$casts`.

Add the relation after `menu()` (`:68-71`):

```php
    /** Per-platform availability (one row per ordering platform, per-mode prices/urls). */
    public function platformLinks(): HasMany
    {
        return $this->hasMany(MenuItemPlatform::class, 'menu_item_id');
    }
```

Update the class doc-comment (`:12-16`) — replace:
```
// across platforms (UE wins a field only where present). `platforms` records
// every platform the dish is available on — each with its price, the modes
// (pickup/delivery) that platform's store link supports, and that platform's
// order URL. `base_price` is the representative headline (min across platforms);
```
with:
```
// across platforms (UE wins a field only where present). Each platform the dish
// is available on is a site.menu_item_platforms row — per-mode pickup/delivery
// price + url for that platform's store link. `base_price` is the representative
// headline (min across platforms);
```

---

### Edit — `app/Jobs/Platforms/MenuFetchJob.php`

Add the two new models to the imports (after `:7`):

```php
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\Site\MenuPlatformLink;
```

Add `use Illuminate\Support\Collection;` (after `:17`, the `Carbon` import) for the `platformSettled` signature.

**Replace `handle()` (`:68-150`)** with the version below. Changes: eager-load `platformLinks` on `$existing`; read store_url/status from the keyed relation in the skip check; drop the 6 columns from the pending-flip `updateOrCreate`; upsert link rows (store_url) in the pending phase and (synced_at/status) in the status phase; delete a platform's link row when it disconnects.

```php
    public function handle(MenuSource $source, MenuApifyScraper $scraper, MenuMerger $merger): void
    {
        $plan = $source->resolveAll($this->userId);

        // No Uber Eats / DoorDash link → clear any existing menu.
        if ($plan === null) {
            Menu::query()->where('user_id', $this->userId)->delete();

            return;
        }

        $existing = Menu::query()->where('user_id', $this->userId)->with('platformLinks')->first();
        $existingLinks = $existing?->platformLinks->keyBy('platform') ?? collect();

        // Skip the scrape when both store URLs are unchanged, the last fetch
        // succeeded, EVERY connected platform last scraped 'ok', and this isn't a
        // forced refresh — links recompute at read time, so there's nothing to do.
        // A platform that's connected but last came back 'unavailable' (a flaky /
        // bot-blocked scrape) is NOT settled, so we re-scrape to recover it rather
        // than leaving the menu permanently single-platform.
        if (! $this->force
            && $existing
            && $existing->fetch_status === 'ok'
            && ($existingLinks->get('uber-eats')?->store_url) === $plan['ueUrl']
            && ($existingLinks->get('doordash')?->store_url) === $plan['ddUrl']
            && $this->platformSettled($existingLinks, 'uber-eats', $plan['ueUrl'])
            && $this->platformSettled($existingLinks, 'doordash', $plan['ddUrl'])) {
            return;
        }

        // Flip to pending (preserving any existing items) so the dashboard shows
        // a syncing state while the scrape runs.
        $menu = Menu::updateOrCreate(
            ['user_id' => $this->userId],
            [
                'content_source' => $plan['contentSource'],
                'pickup_platform' => $plan['pickupPlatform'],
                'delivery_platform' => $plan['deliveryPlatform'],
                'fetch_status' => 'pending',
            ],
        );

        // Upsert the per-platform store URLs; a disconnected platform (null URL)
        // drops its link row so the skip-comparison sees "not connected".
        foreach (['uber-eats' => $plan['ueUrl'], 'doordash' => $plan['ddUrl']] as $platform => $url) {
            if ($url === null) {
                $menu->platformLinks()->where('platform', $platform)->delete();

                continue;
            }
            MenuPlatformLink::updateOrCreate(
                ['menu_id' => $menu->id, 'platform' => $platform],
                ['store_url' => $url],
            );
        }

        // Per-platform consolidated store links (one store's pickup + delivery rows
        // already collapsed) — the scrape targets AND each item's modes/url source.
        $storeLinks = $source->storeLinks($this->userId);
        $ueLink = $storeLinks['uber-eats'] ?? null;
        $ddLink = $storeLinks['doordash'] ?? null;

        // Scrape every connected platform across BOTH modes CONCURRENTLY (one
        // Http::pool round inside fetchStores) and fuse per-mode prices per dish.
        $links = array_filter(['uber-eats' => $ueLink, 'doordash' => $ddLink]);
        $menus = $scraper->fetchStores($links, $this->userId, $plan['address']);
        $ueMenu = $menus['uber-eats'] ?? null;
        $ddMenu = $menus['doordash'] ?? null;

        $now = now();

        // Per-platform sync status, independent of the merge outcome — only for
        // connected platforms (those with a store link).
        $statuses = [
            'uber-eats' => ['link' => $ueLink, 'menu' => $ueMenu],
            'doordash' => ['link' => $ddLink, 'menu' => $ddMenu],
        ];
        foreach ($statuses as $platform => $r) {
            if ($r['link'] === null) {
                continue;
            }
            MenuPlatformLink::updateOrCreate(
                ['menu_id' => $menu->id, 'platform' => $platform],
                ['synced_at' => $now, 'status' => $r['menu'] ? 'ok' : 'unavailable'],
            );
        }

        // Nothing usable from EITHER platform — keep the last menu, mark
        // unavailable so the dashboard stops polling. A manual refresh retries.
        if ($ueMenu === null && $ddMenu === null) {
            $menu->forceFill(['fetch_status' => 'unavailable', 'last_fetched_at' => $now])->save();

            return;
        }

        // Canonical structure prefers Uber Eats, but falls back to whichever
        // platform actually returned a menu. The union appends the other
        // platform's items either way; a connected platform that returned nothing
        // is still attached to every dish as a ghost (see MenuMerger).
        $contentSource = $ueMenu !== null ? 'uber-eats' : 'doordash';
        $merged = $merger->merge(['uber-eats' => $ueMenu, 'doordash' => $ddMenu], $contentSource, $storeLinks);

        $this->persist($menu, $contentSource, $merged, $now);
    }
```

**Replace `platformSettled()` (`:152-165`)** with (reads the keyed relation collection):

```php
    /**
     * Whether a platform is "settled" for skip purposes: an unconnected platform
     * (no store URL) always is; a connected one only when its last scrape was 'ok'.
     * A connected-but-'unavailable' platform forces a re-scrape (recovery).
     *
     * @param  \Illuminate\Support\Collection<string, \App\Models\Core\Site\MenuPlatformLink>  $links
     */
    private function platformSettled(Collection $links, string $platform, ?string $url): bool
    {
        if ($url === null) {
            return true;
        }

        return $links->get($platform)?->status === 'ok';
    }
```

**Replace `persist()` (`:173-229`)** — drop the `'platforms' => json_encode(...)` field from the item row, pre-generate the item id so child rows can reference it, accumulate `$platformRows`, delete stale children + items explicitly (defensive — matches the existing explicit category/item delete; works on SQLite where FK cascade is off), and bulk-insert the child rows after all items exist:

```php
    /**
     * Replace the menu's categories + items + per-platform availability wholesale
     * within a transaction, and write the resolved store-level fields.
     *
     * @param  array{store:array<string,mixed>, categories:list<array<string,mixed>>}  $merged
     */
    private function persist(Menu $menu, string $contentSource, array $merged, Carbon $now): void
    {
        DB::connection('pgsql')->transaction(function () use ($menu, $contentSource, $merged, $now) {
            // Clear children first (FK cascade covers this on Postgres, but be
            // explicit so SQLite tests don't leak orphaned item-platform rows).
            $itemIds = MenuItem::query()->where('menu_id', $menu->id)->pluck('id');
            MenuItemPlatform::query()->whereIn('menu_item_id', $itemIds)->delete();
            MenuItem::query()->where('menu_id', $menu->id)->delete();
            MenuCategory::query()->where('menu_id', $menu->id)->delete();

            $store = $merged['store'];
            $menu->forceFill([
                'content_source' => $contentSource,
                'store_name' => $store['name'] ?? null,
                'logo_url' => $store['logo'] ?? null,
                'rating' => $store['rating'] ?? null,
                'review_count' => $store['reviewCount'] ?? null,
                'currency' => $store['currency'] ?? 'AUD',
                'fetch_status' => 'ok',
                'last_fetched_at' => $now,
            ])->save();

            // Accumulate item-platform child rows across all categories; insert
            // them once, after every menu_items row exists (FK menu_item_id).
            $platformRows = [];

            foreach ($merged['categories'] as $ci => $category) {
                $cat = MenuCategory::create([
                    'menu_id' => $menu->id,
                    'name' => $category['name'],
                    'position' => $ci,
                    'source_platform' => $category['sourcePlatform'],
                ]);

                $rows = [];
                foreach ($category['items'] as $ii => $item) {
                    $itemId = (string) Str::uuid();
                    $rows[] = [
                        'id' => $itemId,
                        'menu_id' => $menu->id,
                        'category_id' => $cat->id,
                        'position' => $ii,
                        'name' => $item['name'],
                        'description' => $item['description'] ?? null,
                        'image_url' => $item['imageUrl'] ?? null,
                        'rating' => $item['rating'] ?? null,
                        'rating_count' => $item['ratingCount'] ?? null,
                        'badges' => isset($item['badges']) ? json_encode($item['badges']) : null,
                        'base_price' => $item['basePrice'] ?? null,
                        'pickup_price' => $item['pickupPrice'] ?? null,
                        'pickup_source' => $item['pickupSource'] ?? null,
                        'delivery_price' => $item['deliveryPrice'] ?? null,
                        'delivery_source' => $item['deliverySource'] ?? null,
                        'dd_external_id' => $item['ddExternalId'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    foreach (($item['platforms'] ?? []) as $p) {
                        if (! is_array($p) || ! isset($p['platform'])) {
                            continue;
                        }
                        $platformRows[] = [
                            'id' => (string) Str::uuid(),
                            'menu_item_id' => $itemId,
                            'platform' => $p['platform'],
                            'pickup_price' => $p['pickupPrice'] ?? null,
                            'pickup_url' => $p['pickupUrl'] ?? null,
                            'delivery_price' => $p['deliveryPrice'] ?? null,
                            'delivery_url' => $p['deliveryUrl'] ?? null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                if ($rows !== []) {
                    // Bulk insert (bypasses casts — badges already JSON).
                    MenuItem::query()->insert($rows);
                }
            }

            if ($platformRows !== []) {
                MenuItemPlatform::query()->insert($platformRows);
            }
        });
    }
```

`failed()` (`:231-240`) is unchanged.

---

### Edit — `app/Console/Commands/RetryUnavailableMenusCommand.php`

Replace the inline `where(uber_eats_status/doordash_status)` (`:30-32`) with a `whereHas` over the new relation. The `last_fetched_at` window, ordering, limit, and dispatch loop are unchanged.

Replace the query (`:29-39`):

```php
        $menus = Menu::query()
            ->whereHas('platformLinks', fn ($q) => $q->where('status', 'unavailable'))
            // Bound the retry window so a permanently-dead store isn't re-billed
            // forever — last_fetched_at advances on every attempt, so a menu that
            // keeps failing eventually crosses the window and stops.
            ->where('last_fetched_at', '>=', $since)
            ->orderByRaw('last_fetched_at ASC NULLS FIRST')
            ->limit($limit)
            ->get();
```

(`orderByRaw('... NULLS FIRST')` is already in the current code and runs on the SQLite test DB — keep verbatim.)

---

### Edit — `app/Http/Controllers/Api/Platforms/MenuController.php`

Two changes: eager-load `platformLinks` on items, and rewrite `platforms()` to read the relation (dropping the dead legacy `{price,modes,url}` branch). Output stays byte-identical: `{platform, pickupPrice, pickupUrl, deliveryPrice, deliveryUrl}`.

Add the import (after `:9`):
```php
use App\Models\Core\Site\MenuItemPlatform;
```

In `menuFor()` (`:92-101`), add the child relation to the eager-load:

```php
    private function menuFor(User $user): ?Menu
    {
        return Menu::query()
            ->where('user_id', $user->id)
            ->with([
                'categories' => fn ($q) => $q->orderBy('position'),
                'categories.items' => fn ($q) => $q->orderBy('position'),
                'categories.items.platformLinks',
            ])
            ->first();
    }
```

**Replace `platforms()` (`:137-181`)** entirely:

```php
    /**
     * The item's per-platform availability list — one entry per ordering platform
     * the dish is on, each with its pickup price + url and delivery price + url (a
     * mode the store doesn't offer is null on both). Empty when the dish has no
     * platform rows.
     *
     * @return list<array{platform:string, pickupPrice:float|null, pickupUrl:string|null, deliveryPrice:float|null, deliveryUrl:string|null}>
     */
    private function platforms(MenuItem $item): array
    {
        return $item->platformLinks->map(fn (MenuItemPlatform $p) => [
            'platform' => $p->platform,
            'pickupPrice' => $this->numberOrNull($p->pickup_price),
            'pickupUrl' => $this->textOrNull($p->pickup_url),
            'deliveryPrice' => $this->numberOrNull($p->delivery_price),
            'deliveryUrl' => $this->textOrNull($p->delivery_url),
        ])->values()->all();
    }
```

`numberOrNull()` / `textOrNull()` (`:183-191`) are unchanged and still used. `categories()` (`:112-135`) is unchanged (it already calls `$this->platforms($item)`).

> **Ordering note:** the in-memory array preserved insertion order (`uber-eats`, then `doordash`); the relation returns rows in DB order. The golden-master test "returns the full menu…" asserts `platforms[0].platform === 'uber-eats'` and `platforms[1].platform === 'doordash'`. Child rows are written in merger order (UE first — see `MenuMerger`), and the seed helper inserts UE then DD, so default ordering is correct. **If the full-suite run shows order flakiness, add `->orderBy('platform')` is NOT correct** (`'doordash'` < `'uber-eats'` alphabetically would flip them) — instead pin order at write time by relying on insertion order (Postgres returns heap/PK order which for these sequential inserts is insertion order) or add a `position`/`sort_order` column. Per-mode tests below assert by index, so verify ordering holds in the full run; if it doesn't, the minimal fix is to seed/insert in the asserted order (already the case) — do **not** add an alphabetical `orderBy`.

---

### `tests/Pest.php` SQLite schema updates (inside `setupSitesTable()`)

The menu tables are built in `setupSitesTable()` (`:436-493`). Make three edits. SQLite enforces no CHECK / partial-unique / FK-cascade, so the new tables are plain TEXT/REAL columns matching the existing `menu_categories` / `menu_items` pattern (no constraints) — constraints are verified on dev Supabase (below).

**1. Remove the 6 columns from the `site.menus` block** (`:439-461`) — replace with:

```php
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menus (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        content_source TEXT NULL,
        store_name TEXT NULL,
        logo_url TEXT NULL,
        rating REAL NULL,
        review_count INTEGER NULL,
        currency TEXT NULL,
        pickup_platform TEXT NULL,
        delivery_platform TEXT NULL,
        fetch_status TEXT NULL,
        last_fetched_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');
```

**2. Remove `platforms` from the `site.menu_items` block** (`:473-493`) — replace with:

```php
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menu_items (
        id TEXT PRIMARY KEY,
        menu_id TEXT NULL,
        category_id TEXT NULL,
        position INTEGER NULL,
        name TEXT NULL,
        description TEXT NULL,
        image_url TEXT NULL,
        rating REAL NULL,
        rating_count INTEGER NULL,
        badges TEXT NULL,
        base_price REAL NULL,
        pickup_price REAL NULL,
        pickup_source TEXT NULL,
        delivery_price REAL NULL,
        delivery_source TEXT NULL,
        dd_external_id TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
```

**3. Add the two new tables** immediately after the `site.menu_items` statement (before the closing `}` of `setupSitesTable()` at `:494`):

```php
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menu_platform_links (
        id TEXT PRIMARY KEY,
        menu_id TEXT NULL,
        platform TEXT NULL,
        store_url TEXT NULL,
        synced_at TEXT NULL,
        status TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menu_item_platforms (
        id TEXT PRIMARY KEY,
        menu_item_id TEXT NULL,
        platform TEXT NULL,
        pickup_price REAL NULL,
        pickup_url TEXT NULL,
        delivery_price REAL NULL,
        delivery_url TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
```

Also update the block comment (`:436-438`) to mention the two new tables and migrations `20260701000000` + `20260701000100`.

---

### "Postgres-only constraints are invisible to SQLite" note

SQLite (the test DB) does NOT enforce: the `menu_platform_links.platform` CHECK, the `menu_platform_links.status` CHECK, either `UNIQUE(...)`, or the FK `ON DELETE CASCADE`. A migration that violates any of these passes CI green and only fails on real Postgres. Therefore every constraint below is verified directly on dev Supabase `glncumufgaqcmqhzwrxm` AFTER `apply_migration`, by attempting an invalid write via `execute_sql` and confirming rejection.

### DEV-SUPABASE VERIFICATION (ref `glncumufgaqcmqhzwrxm`)

Apply both migrations via MCP `apply_migration` (project `glncumufgaqcmqhzwrxm`), then run each check via `execute_sql`. Seed one throwaway menu + item first (under an existing user — see below), and clean up at the end.

**Seed (throwaway):** prefer reusing an EXISTING user — dev Supabase `glncumufgaqcmqhzwrxm` serves prod
traffic, so `core.users` is not actually empty, and a raw `core.users` insert is rejected three ways
(`account_type` only accepts `'partna'`/`'business'` after `20260612120000`; `first_name` is NOT NULL with
no default; `auth_user_id` FKs to `auth.users`). Grab one and substitute it for `<USER>`:
```sql
-- select id from core.users where deleted_at is null limit 1;   -- → use as <USER>
INSERT INTO site.menus (id, user_id, content_source, currency, fetch_status, created_at, updated_at)
VALUES ('00000000-0000-0000-0000-0000000000bb','<USER>','uber-eats','AUD','ok',now(),now());
INSERT INTO site.menu_categories (id, menu_id, name, position, source_platform, created_at, updated_at)
VALUES ('00000000-0000-0000-0000-0000000000cc','00000000-0000-0000-0000-0000000000bb','Mains',0,'uber-eats',now(),now());
INSERT INTO site.menu_items (id, menu_id, category_id, position, name, created_at, updated_at)
VALUES ('00000000-0000-0000-0000-0000000000dd','00000000-0000-0000-0000-0000000000bb','00000000-0000-0000-0000-0000000000cc',0,'Burrito',now(),now());
```

**Check 1 — `menu_platform_links.platform` CHECK** (expect ERROR `violates check constraint "menu_platform_links_platform_check"`):
```sql
INSERT INTO site.menu_platform_links (id, menu_id, platform, store_url, status, created_at, updated_at)
VALUES (gen_random_uuid(),'00000000-0000-0000-0000-0000000000bb','menulog','https://x',NULL,now(),now());
```

**Check 2 — `menu_platform_links.status` CHECK** (expect ERROR `violates check constraint "menu_platform_links_status_check"`):
```sql
INSERT INTO site.menu_platform_links (id, menu_id, platform, store_url, status, created_at, updated_at)
VALUES (gen_random_uuid(),'00000000-0000-0000-0000-0000000000bb','uber-eats','https://x','queued',now(),now());
```

**Check 3 — `UNIQUE(menu_id, platform)`** (insert a valid row, then a duplicate; expect ERROR `duplicate key value violates unique constraint`):
```sql
INSERT INTO site.menu_platform_links (id, menu_id, platform, store_url, status, created_at, updated_at)
VALUES (gen_random_uuid(),'00000000-0000-0000-0000-0000000000bb','uber-eats','https://x','ok',now(),now());
INSERT INTO site.menu_platform_links (id, menu_id, platform, store_url, status, created_at, updated_at)
VALUES (gen_random_uuid(),'00000000-0000-0000-0000-0000000000bb','uber-eats','https://y','ok',now(),now());
```

**Check 4 — `menu_platform_links` FK cascade** (hard-delete a throwaway menu and confirm the link row is gone). Use a second throwaway menu so we don't drop the seed mid-suite:
```sql
INSERT INTO site.menus (id, user_id, content_source, currency, fetch_status, created_at, updated_at)
VALUES ('00000000-0000-0000-0000-0000000000ee','<USER>','uber-eats','AUD','ok',now(),now());
INSERT INTO site.menu_platform_links (id, menu_id, platform, store_url, status, created_at, updated_at)
VALUES ('00000000-0000-0000-0000-0000000000ff','00000000-0000-0000-0000-0000000000ee','doordash','https://z','ok',now(),now());
DELETE FROM site.menus WHERE id = '00000000-0000-0000-0000-0000000000ee';
SELECT count(*) AS leftover FROM site.menu_platform_links WHERE id = '00000000-0000-0000-0000-0000000000ff';
-- EXPECT leftover = 0
```

**Check 5 — `menu_item_platforms` UNIQUE(menu_item_id, platform)** (expect ERROR `duplicate key value violates unique constraint`):
```sql
INSERT INTO site.menu_item_platforms (id, menu_item_id, platform, pickup_price, delivery_price, created_at, updated_at)
VALUES (gen_random_uuid(),'00000000-0000-0000-0000-0000000000dd','uber-eats',12.50,NULL,now(),now());
INSERT INTO site.menu_item_platforms (id, menu_item_id, platform, pickup_price, delivery_price, created_at, updated_at)
VALUES (gen_random_uuid(),'00000000-0000-0000-0000-0000000000dd','uber-eats',13.00,NULL,now(),now());
```

**Check 6 — `menu_item_platforms` FK cascade** (hard-delete the seed item, confirm child gone):
```sql
DELETE FROM site.menu_items WHERE id = '00000000-0000-0000-0000-0000000000dd';
SELECT count(*) AS leftover FROM site.menu_item_platforms WHERE menu_item_id = '00000000-0000-0000-0000-0000000000dd';
-- EXPECT leftover = 0
```

**Cleanup (remove all throwaway rows — the reused `<USER>` is left intact):**
```sql
DELETE FROM site.menus WHERE id IN ('00000000-0000-0000-0000-0000000000bb','00000000-0000-0000-0000-0000000000ee');
-- (No core.users delete: the seed reuses an existing user; deleting the menus above
--  cascades to their menu_categories / menu_items / menu_platform_links / menu_item_platforms.)
```

(The `menu_item_platforms.platform` column is intentionally un-CHECKed — no rejection test for it; that extensibility is the point of FOUND-6.)

---

### Golden-master guard (these must stay GREEN, byte-identical where user-visible)

In `tests/Feature/Platforms/MenuTest.php`:
- "scrapes and stores the relational menu on source change" (`:182`)
- "skips the paid scrape when the store url is unchanged" (`:207`)
- "re-scrapes when a connected platform last came back unavailable" (`:224`)
- "forces a re-scrape and replaces the menu even when the url is unchanged" (`:248`)
- "clears the menu when no ordering source remains" (`:270`)
- "reports menu status with item count and source" (`:285`)
- "returns the full menu with per-mode prices and computed order links" (`:328`) — **user-visible API contract**; assert the `categories.0.items.0.platforms` array is byte-identical to today (`platform`, `pickupPrice`, `pickupUrl`, `deliveryPrice`, `deliveryUrl`; UE index 0, DD index 1).
- "unions both platforms and persists a per-platform availability list" (`:419`)
- "menu:retry-unavailable re-dispatches forced fetches only for recently-unavailable menus" (`:387`)

These currently seed/assert the 6 menu columns and the `platforms` JSON cast directly — they MUST be migrated to the child tables (Tasks below). The MenuMerger tests (`tests/Feature/Platforms/MenuMergerTest.php`) and scraper tests are untouched (the merger is unchanged).

---

## Bite-sized TDD tasks

> Run from the **main checkout** (not a `.claude/worktrees/` worktree — feature tests are broken there). If `app/` edits "don't take effect", run `composer dump-autoload -o`.

### Task 0 — write both migration files + the `tests/Pest.php` SQLite schema edits

No test of its own (migrations don't run under SQLite). Create `supabase/migrations/20260701000000_menu_platform_links.sql` and `supabase/migrations/20260701000100_menu_item_platforms_table.sql` (full SQL above), and make the three `tests/Pest.php` edits (above). This is a prerequisite for every task below — the SQLite tables must exist before the model/job tests can pass.

- Run: `php artisan pint --dirty`
- Commit: `git commit -m "chore(menu): migrations + SQLite schema for menu_platform_links + menu_item_platforms (FOUND-2, FOUND-6)"`

### Task 1 (FOUND-2) — `MenuPlatformLink` model + `Menu` relation; reseed the skip/status tests

**Failing test:** edit `tests/Feature/Platforms/MenuTest.php` to seed the link table instead of the 6 columns. Replace the three affected setups:

- "skips the paid scrape when the store url is unchanged" (`:210-215`) — replace the `Menu::create([... 'uber_eats_store_url' => ..., 'uber_eats_status' => 'ok', ...])` with:
```php
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats', 'fetch_status' => 'ok',
    ]);
    MenuPlatformLink::create([
        'menu_id' => $menu->id, 'platform' => 'uber-eats',
        'store_url' => 'https://www.ubereats.com/store/x', 'status' => 'ok',
    ]);
```
- "re-scrapes when a connected platform last came back unavailable" (`:228-233`) — same pattern, `'status' => 'unavailable'`.
- "forces a re-scrape…" (`:251-254`) — `Menu::create` keeps only menu-level fields; add a `MenuPlatformLink::create([... 'store_url' => 'https://www.ubereats.com/store/x'])` (no status needed — `force` skips the settled check).
- "clears the menu when no ordering source remains" (`:272-275`) — `Menu::create` drops `'uber_eats_store_url'`; add a `MenuPlatformLink::create` if the test wants a pre-existing link (optional — the test only asserts the menu is gone).
- Update the `Menu` and `MenuPlatformLink` `use` imports at the top of the file (add `use App\Models\Core\Site\MenuPlatformLink;`).
- In "scrapes and stores…" (`:199`), replace `expect($menu->uber_eats_store_url)->toBe('https://www.ubereats.com/store/x')` with:
```php
    expect($menu->platformLinks->firstWhere('platform', 'uber-eats')?->store_url)->toBe('https://www.ubereats.com/store/x');
```
- In "re-scrapes…" (`:243-244`), replace `$menu->refresh(); expect($menu->uber_eats_status)->toBe('ok')` with:
```php
    $menu->load('platformLinks');
    expect($menu->platformLinks->firstWhere('platform', 'uber-eats')?->status)->toBe('ok');
```

- Run-fail: `php artisan test tests/Feature/Platforms/MenuTest.php`
  Expected failure: `Class "App\Models\Core\Site\MenuPlatformLink" not found` (model not created yet) and/or `Call to undefined method ...Menu::platformLinks()`.

**Minimal code:** create `app/Models/Core/Site/MenuPlatformLink.php` (full code above); edit `app/Models/Core/Site/Menu.php` (drop 6 fillable + 2 casts, add `platformLinks()`); edit `app/Jobs/Platforms/MenuFetchJob.php` `handle()` + `platformSettled()` (full code above — the `persist()` change is FOUND-6 Task 4, but `persist()` still references `$item['platforms']` via the OLD body until Task 4; for this task keep the OLD `persist()` body, which writes the `platforms` JSON column that still exists on SQLite — wait: Task 0 already removed `platforms` from the SQLite `menu_items` table, so the old `persist()` would fail. **Sequencing fix: do Task 4's `persist()` rewrite as part of this task's code change** — i.e. land the full `MenuFetchJob` rewrite here, and the `MenuItemPlatform` model in Task 4. To avoid a class-not-found on `MenuItemPlatform`, create BOTH new models in this task.) 

  → **Concretely for Task 1:** create BOTH `MenuPlatformLink` and `MenuItemPlatform` models, apply the FULL `MenuFetchJob` rewrite (handle + platformSettled + persist), and edit BOTH `Menu` and `MenuItem` models. (The two findings share one job hinge; splitting the job across two commits would leave an un-compilable intermediate. Keep Task 1 = "FOUND-2 + the job/model plumbing both findings need"; Task 2 = command; Task 3 = controller/FOUND-6 read path + its tests.)

- Run-pass: `php artisan test tests/Feature/Platforms/MenuTest.php`
  Expected: the scrape/skip/status/union tests pass (the controller read-path test "returns the full menu…" may still fail until Task 3 — that's expected; note it).
- `php artisan pint --dirty`
- Commit: `git commit -m "feat(menu): menu_platform_links + menu_item_platforms models, MenuFetchJob rewrite (FOUND-2, FOUND-6)"`

### Task 2 (FOUND-2) — `RetryUnavailableMenusCommand` → `whereHas('platformLinks')`

**Failing test:** in "menu:retry-unavailable…" (`:387-415`), the three `Menu::create([... 'uber_eats_status' => 'unavailable', 'doordash_status' => 'ok' ...])` seeds move to link rows. Replace:
```php
    // In-window
    $freshMenu = Menu::create(['user_id' => $fresh->id, 'content_source' => 'doordash', 'fetch_status' => 'ok', 'last_fetched_at' => now()]);
    MenuPlatformLink::create(['menu_id' => $freshMenu->id, 'platform' => 'uber-eats', 'status' => 'unavailable']);
    MenuPlatformLink::create(['menu_id' => $freshMenu->id, 'platform' => 'doordash', 'status' => 'ok']);
    // Out-of-window
    $staleMenu = Menu::create(['user_id' => $stale->id, 'content_source' => 'doordash', 'last_fetched_at' => now()->subHours(12)]);
    MenuPlatformLink::create(['menu_id' => $staleMenu->id, 'platform' => 'uber-eats', 'status' => 'unavailable']);
    // Healthy
    $okMenu = Menu::create(['user_id' => $ok->id, 'content_source' => 'uber-eats', 'fetch_status' => 'ok', 'last_fetched_at' => now()]);
    MenuPlatformLink::create(['menu_id' => $okMenu->id, 'platform' => 'uber-eats', 'status' => 'ok']);
```

- Run-fail: `php artisan test --filter="retry-unavailable" tests/Feature/Platforms/MenuTest.php`
  Expected failure: command still queries `where('uber_eats_status', ...)` → `SQLSTATE ... no such column: uber_eats_status` (column removed from SQLite in Task 0).

**Minimal code:** edit `app/Console/Commands/RetryUnavailableMenusCommand.php` (the `whereHas` query above).

- Run-pass: `php artisan test --filter="retry-unavailable" tests/Feature/Platforms/MenuTest.php`
- `php artisan pint --dirty`
- Commit: `git commit -m "fix(menu): RetryUnavailableMenusCommand reads platformLinks status (FOUND-2)"`

### Task 3 (FOUND-6) — `MenuController::platforms()` reads the relation; reseed the read tests

**Failing test:** in `seedMenu` (`:99-106`) and "returns the full menu with per-mode prices…" (`:328-368`), the inline `'platforms' => [ ... ]` array on the item moves to child rows. Update `seedMenu` so an item's `platforms` key (if present) is written as `MenuItemPlatform` rows:
```php
        foreach (($category['items'] ?? []) as $ii => $item) {
            $platforms = $item['platforms'] ?? [];
            unset($item['platforms']);
            $menuItem = MenuItem::create(array_merge([
                'menu_id' => $menu->id,
                'category_id' => $cat->id,
                'position' => $ii,
                'name' => 'Item',
            ], $item));
            foreach ($platforms as $p) {
                MenuItemPlatform::create([
                    'menu_item_id' => $menuItem->id,
                    'platform' => $p['platform'],
                    'pickup_price' => $p['pickupPrice'] ?? null,
                    'pickup_url' => $p['pickupUrl'] ?? null,
                    'delivery_price' => $p['deliveryPrice'] ?? null,
                    'delivery_url' => $p['deliveryUrl'] ?? null,
                ]);
            }
        }
```
Add `use App\Models\Core\Site\MenuItemPlatform;` to the test imports. The "returns the full menu…" test body's assertions on `$item['platforms'][0]['platform']` etc. stay byte-identical (the seed already passes the per-mode shape).

In "unions both platforms…" (`:457-465`), the assertions read `$burrito->platforms` (the old cast). Replace with the relation:
```php
    $burrito->load('platformLinks');
    expect($burrito->platformLinks)->toHaveCount(2);
    expect($burrito->platformLinks->pluck('platform')->all())->toBe(['uber-eats', 'doordash']);
    expect((float) $burrito->platformLinks->firstWhere('platform', 'uber-eats')->delivery_price)->toBe(17.0);
    expect($burrito->platformLinks->firstWhere('platform', 'uber-eats')->pickup_price)->toBeNull();
    expect((float) $burrito->platformLinks->firstWhere('platform', 'doordash')->pickup_price)->toBe(15.5);
    expect($burrito->platformLinks->firstWhere('platform', 'doordash')->delivery_price)->toBeNull();
    $churros->load('platformLinks');
    expect($churros->platformLinks)->toHaveCount(1);
    expect($churros->platformLinks->first()->platform)->toBe('doordash');
    expect((float) $churros->platformLinks->first()->pickup_price)->toBe(8.0);
    expect($churros->platformLinks->first()->delivery_price)->toBeNull();
```

- Run-fail: `php artisan test tests/Feature/Platforms/MenuTest.php`
  Expected failure (BEFORE the controller edit): "returns the full menu…" fails because `MenuController::platforms()` still reads `$item->platforms` (the dropped cast → null) → empty `platforms` array; and the union test fails on `$burrito->platforms` being undefined.

**Minimal code:** edit `app/Http/Controllers/Api/Platforms/MenuController.php` (`menuFor()` eager-load + `platforms()` rewrite, full code above).

- Run-pass: `php artisan test tests/Feature/Platforms/MenuTest.php`
  Expected: all MenuTest cases pass, with the public `platforms` payload byte-identical.
- `php artisan pint --dirty`
- Commit: `git commit -m "feat(menu): MenuController reads menu_item_platforms, drop dead legacy branch (FOUND-6)"`

### Task 4 — DEV-SUPABASE constraint verification

Apply both migrations to `glncumufgaqcmqhzwrxm` via `apply_migration` and run Checks 1-6 above via `execute_sql`. Confirm every invalid insert is rejected with the named error and both cascade checks return `leftover = 0`. Clean up the throwaway rows. (No code change / commit — this is the constraint-correctness gate the SQLite suite cannot provide.)

---

## Closing gate — FULL suite green

A filtered subset is a false signal (Wave-1 hit a 9-test regression only the full suite caught — and namespace/short-ref breakage is invisible to a filtered run). Run the **entire** suite from the main checkout:

```bash
composer test
```

Expected: all green. Pay attention to any test that constructs a `Menu`/`MenuItem` with the removed attributes or reads `->platforms` / `uber_eats_*` / `doordash_*` outside `MenuTest.php` — there should be none (grep confirmed the only consumers are the files this PR edits), but the full run is the proof. If `IntegrationContractGoldenMasterTest` or any platform route test is touched, that is unexpected — investigate before proceeding.


---

## PR3 — FOUND-4 + FOUND-5 — workplace + credentials/experience JSONB → tables (incl. write-path rewire)

**Goal:** Promote the workplace card (`site.sites.settings->'workplace'`) and the credentials/experience arrays (`core.users.about`) out of JSONB into three typed tables, rewiring every reader AND the `about` write path so dashboard edits stay visible and the public wire stays byte-identical.

**Architecture:** Three new tables — `site.workplaces` (1:1 with `site.sites`, PK = `site_id`), `core.user_credentials`, `core.user_experience` (both `user_id` FK, `sort_order`). All visibility predicates become portable Eloquent (no `whereRaw` / JSON arrows), so they finally run on SQLite. A new `App\Services\User\SyncUserAboutService` owns the credentials/experience write (delete-and-reinsert ≤5 rows, strip the two arrays from `about` before `fill`, then re-gate the section blocks), called by both the self-serve and staff user-update controllers. The dashboard `about` round-trip and the GDPR export rebuild the credentials/experience shape from the tables via a new `User::aboutPayload()`.

**Blast radius (files):**
- Migrations: `supabase/migrations/<ts>_create_workplaces.sql`, `supabase/migrations/<ts>_create_user_credentials_experience.sql`
- New models: `app/Models/Core/Site/Workplace.php`, `app/Models/Core/User/UserCredential.php`, `app/Models/Core/User/UserExperience.php`
- New service: `app/Services/User/SyncUserAboutService.php`
- Models edited: `app/Models/Core/Site/Site.php` (+`workplace()`), `app/Models/Core/User/User.php` (+`credentials()`/`experience()`/`aboutPayload()`)
- Services edited: `app/Services/User/SectionVisibilityService.php` (6 predicate sites), `app/Services/PublicSite/SitepageDataResolverService.php` (`getWorkplace`, `getBio`), `app/Services/Platforms/MenuSource.php` (`address`), `app/Services/Platforms/GoogleBusinessAutoSync.php` (`seedWorkplace`), `app/Services/User/DataExport/DataExportPayloadBuilder.php` (`profile`, `site`)
- Controllers edited: `app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php`, `app/Http/Controllers/Api/Staff/StaffSite/StaffWorkplaceController.php`, `app/Http/Controllers/Api/User/Account/UserSelfController.php`, `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`
- Resources edited: `app/Http/Resources/UserDashboardResource.php`, `app/Http/Resources/UserStaffResource.php`
- Tests: `tests/Pest.php` (3 new helpers + wiring), plus golden-master updates listed below.
- **Unchanged (verified consumers of the resolver, not the JSONB):** `IndividualProfilePayloadBuilder::buildWorkplace`/`buildBio`, `IndividualProfileResource`, `AnalyticsQueryService` (section-label map only).

### DECISIONs (confirm before implementing)

- **DECISION — CONFIRMED 2026-07-01: keep workplace visibility name-OR-address (NO behavior change).** The section goes live when the workplace has a non-empty name OR address — exactly today's behavior. The only change is mechanical: the predicate moves from the `settings->'workplace'` JSONB `whereRaw` to a portable null-safe table predicate on `site.workplaces` (name OR address). The visibility reason string is UNCHANGED: `"Workplace section requires a name or address before it can go live."` (both `resolveFromContext` and `checkWorkplaceRequirements`).
- **DECISION (confirm before implementing):** `site.workplaces.name` **NULLABLE** (recommended) — vs `NOT NULL` (design-plan/brief default). **This is a drift I found grounding against the tree:** two shipped write paths set workplace fields *without* a name — `UserWorkplaceController::setPreviousWebsite` (the PATCH `/site/workplace/previous-website` Settings card, explicitly "no name requirement") and `GoogleBusinessAutoSync::seedWorkplace` (fills only `previous_website`/`category`/`description`, comment: "Never seeds the identity fields"). With `NOT NULL name`, both would 500 / regress to update-existing-only. NULLABLE preserves the exact JSONB semantics (a name-less blob already renders as `null` everywhere). The visibility/resolver/normalizeProfile name-gate makes a null-name row harmless. If Josh prefers `NOT NULL`, those two methods must become update-existing-only and `setPreviousWebsite` silently no-ops before a workplace exists. **Plan is written for NULLABLE.**

**Premise grounding:**
- Honored premise correction #7 (FOUND-5): the read migration is incomplete without the JSON write rewire — `SyncUserAboutService` ships with the readers in this PR.
- FOUND-4 premise HOLDS: 14 named fields (`UpsertWorkplaceRequest`), `settings->'workplace'->>'name'` pgsql-only subquery in two places, `getWorkplace` emits 11 keys. The "extra consumers the audit missed" all confirmed: `MenuSource::address` (raw pgsql read), `GoogleBusinessAutoSync::seedWorkplace` (read-modify-write), `setPreviousWebsite`, `IndividualProfilePayloadBuilder::buildWorkplace`.
- FOUND-5 premise HOLDS: `ValidatesUserAbout` shapes confirmed — credentials `array:title,issuer,year` (NO description in validation, but the resolver reads one → always null), experience `array:role,place,start,end,description` (`start`/`end` are `Y-m` strings). Visibility uses `jsonb_array_length`/`jsonb_array_elements` in 4 places (2 credentials, 2 experience). `getBio`→`normaliseCredential`/`normaliseExperience` synthesises `period = "{start} – {end|Current}"`.
- **Naming flag (kept per design plan):** `core.user_experience.start_year`/`end_year` actually hold `Y-m` month strings, not years; `organisation` holds the validated `place` field; `core.user_credentials.year` is `text` (could be `integer`, but text avoids empty-string casts — kept text). Documented inline in the migration.
- **Drift caught — `Model::preventLazyLoading(!isProduction())` is ON in tests** (`AppServiceProvider:237`). Every new relation read (`getBio`, `getWorkplace`, `aboutPayload`, export) MUST use `loadMissing(...)`, never bare lazy access, or tests throw `LazyLoadingViolationException`.
- **Drift caught — the dashboard/staff resources rebuild from tables.** `UserDashboardResource:27` and `UserStaffResource:22` emit `(object)($this->about ?? [])`; after the strip, `about` JSON is empty, so they must emit `(object) $this->aboutPayload()` to round-trip credentials/experience. Any test seeding `about` JSON directly and asserting the resource must switch to seeding the tables.

---

### Migration files

> Both files use concrete timestamps after the current latest (`20260630000000_drop_smart_links.sql`). **The executing session bumps these to timestamps later than the then-latest migration before applying.** No explicit `GRANT`/RLS needed: the baseline `ALTER DEFAULT PRIVILEGES IN SCHEMA site/core … TO app_backend` (baseline `:2302-2303`) auto-covers new tables, and `app_backend` is `BYPASSRLS` — matching the `site.menu_*` tables convention (no RLS).

#### File 1 — `supabase/migrations/20260701000000_create_workplaces.sql` (FOUND-4)

```sql
-- =====================================================================
-- Workplace card — promote site.sites.settings->'workplace' JSONB → table
-- =====================================================================
-- The workplace card is a known, validated, typed set of 14 named fields
-- (UpsertWorkplaceRequest). Stored in the site.sites.settings JSONB it forced a
-- non-indexable `settings->'workplace'->>'name'` scan on every public-page
-- visibility check. Promote it to a 1:1 table keyed by site_id.
--
-- name is NULLABLE on purpose: setPreviousWebsite + GoogleBusinessAutoSync seed
-- previous_website/category/description with no name; a name-less row renders as
-- null everywhere (resolver + visibility gate on a non-empty name).

CREATE TABLE IF NOT EXISTS site.workplaces (
    site_id          uuid PRIMARY KEY REFERENCES site.sites (id) ON DELETE CASCADE,
    name             text,
    address          text,
    address_line1    text,
    city             text,
    state            text,
    postcode         text,
    country          text,
    latitude         double precision,
    longitude        double precision,
    phone            text,
    website          text,
    previous_website text,
    category         text,
    description      text,
    created_at       timestamptz,
    updated_at       timestamptz
);

-- Backfill every workplace object faithfully (name may be null). No-op pre-beta
-- (zero rows) but correct for prod-shape parity. NULLIF(...,'') keeps empty
-- strings out; numeric casts guard against '' → 0.0.
INSERT INTO site.workplaces (
    site_id, name, address, address_line1, city, state, postcode, country,
    latitude, longitude, phone, website, previous_website, category, description,
    created_at, updated_at
)
SELECT
    s.id,
    NULLIF(btrim(s.settings->'workplace'->>'name'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'address'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'address_line1'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'city'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'state'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'postcode'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'country'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'latitude'), '')::double precision,
    NULLIF(btrim(s.settings->'workplace'->>'longitude'), '')::double precision,
    NULLIF(btrim(s.settings->'workplace'->>'phone'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'website'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'previous_website'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'category'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'description'), ''),
    now(), now()
FROM site.sites s
WHERE s.settings ? 'workplace'
  AND jsonb_typeof(s.settings->'workplace') = 'object';

-- Strip the promoted key from settings.
UPDATE site.sites
SET settings = settings - 'workplace'
WHERE settings ? 'workplace';

-- ROLLBACK:
-- UPDATE site.sites s
-- SET settings = jsonb_set(
--         COALESCE(s.settings, '{}'::jsonb),
--         '{workplace}',
--         jsonb_strip_nulls(jsonb_build_object(
--             'name', w.name, 'address', w.address, 'address_line1', w.address_line1,
--             'city', w.city, 'state', w.state, 'postcode', w.postcode, 'country', w.country,
--             'latitude', w.latitude, 'longitude', w.longitude, 'phone', w.phone,
--             'website', w.website, 'previous_website', w.previous_website,
--             'category', w.category, 'description', w.description
--         ))
--     )
-- FROM site.workplaces w
-- WHERE w.site_id = s.id;
-- DROP TABLE IF EXISTS site.workplaces;
```

#### File 2 — `supabase/migrations/20260701000100_create_user_credentials_experience.sql` (FOUND-5)

```sql
-- =====================================================================
-- Credentials + experience — promote core.users.about arrays → child tables
-- =====================================================================
-- Visibility scanned jsonb_array_elements(about->'credentials'/'experience') in a
-- WHERE clause (no index possible). Promote each to a child table with sort_order.
--
-- NAMING: user_experience.start_year / end_year hold 'Y-m' month strings
-- ('2021-03'), NOT years (kept text). organisation holds the validated `place`
-- field. user_credentials.year is text (avoids empty-string int casts).

CREATE TABLE IF NOT EXISTS core.user_credentials (
    id          uuid PRIMARY KEY,
    user_id     uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
    title       text NOT NULL,
    issuer      text,
    year        text,
    description text,
    sort_order  integer NOT NULL DEFAULT 0,
    created_at  timestamptz,
    updated_at  timestamptz
);
CREATE INDEX IF NOT EXISTS idx_user_credentials_user
    ON core.user_credentials (user_id, sort_order);

CREATE TABLE IF NOT EXISTS core.user_experience (
    id           uuid PRIMARY KEY,
    user_id      uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
    role         text NOT NULL,
    organisation text,            -- validated `place` field
    start_year   text,            -- 'Y-m' month string, not a year
    end_year     text,            -- 'Y-m' month string; NULL = "Current"
    description  text,
    sort_order   integer NOT NULL DEFAULT 0,
    created_at   timestamptz,
    updated_at   timestamptz
);
CREATE INDEX IF NOT EXISTS idx_user_experience_user
    ON core.user_experience (user_id, sort_order);

-- Backfill credentials. ord-1 → sort_order (0-based, preserves original order).
-- Gated on a non-empty title (mirrors the resolver: no-title row has no identity).
INSERT INTO core.user_credentials
    (id, user_id, title, issuer, year, description, sort_order, created_at, updated_at)
SELECT
    gen_random_uuid(), u.id,
    btrim(c.title),
    NULLIF(btrim(c.issuer), ''),
    NULLIF(btrim(c.year), ''),
    NULLIF(btrim(c.description), ''),
    (c.ord - 1)::int,
    now(), now()
FROM core.users u
CROSS JOIN LATERAL jsonb_to_recordset(COALESCE(u.about->'credentials', '[]'::jsonb))
    WITH ORDINALITY AS c(title text, issuer text, year text, description text, ord bigint)
WHERE NULLIF(btrim(c.title), '') IS NOT NULL;

-- Backfill experience. "start"/"end" quoted (end is reserved; both match JSON keys).
INSERT INTO core.user_experience
    (id, user_id, role, organisation, start_year, end_year, description, sort_order, created_at, updated_at)
SELECT
    gen_random_uuid(), u.id,
    btrim(e.role),
    NULLIF(btrim(e.place), ''),
    NULLIF(btrim(e."start"), ''),
    NULLIF(btrim(e."end"), ''),
    NULLIF(btrim(e.description), ''),
    (e.ord - 1)::int,
    now(), now()
FROM core.users u
CROSS JOIN LATERAL jsonb_to_recordset(COALESCE(u.about->'experience', '[]'::jsonb))
    WITH ORDINALITY AS e("role" text, place text, "start" text, "end" text, description text, ord bigint)
WHERE NULLIF(btrim(e.role), '') IS NOT NULL;

-- Strip the promoted keys from about.
UPDATE core.users
SET about = (about - 'credentials') - 'experience'
WHERE about ? 'credentials' OR about ? 'experience';

-- ROLLBACK:
-- UPDATE core.users u
-- SET about = jsonb_set(COALESCE(u.about, '{}'::jsonb), '{credentials}', sub.arr)
-- FROM (
--     SELECT user_id, jsonb_agg(jsonb_strip_nulls(jsonb_build_object(
--                'title', title, 'issuer', issuer,
--                'year', NULLIF(year, '')::int)) ORDER BY sort_order) AS arr
--     FROM core.user_credentials GROUP BY user_id
-- ) sub
-- WHERE sub.user_id = u.id;
-- UPDATE core.users u
-- SET about = jsonb_set(COALESCE(u.about, '{}'::jsonb), '{experience}', sub.arr)
-- FROM (
--     SELECT user_id, jsonb_agg(jsonb_strip_nulls(jsonb_build_object(
--                'role', role, 'place', organisation, 'start', start_year,
--                'end', end_year, 'description', description)) ORDER BY sort_order) AS arr
--     FROM core.user_experience GROUP BY user_id
-- ) sub
-- WHERE sub.user_id = u.id;
-- DROP TABLE IF EXISTS core.user_credentials;
-- DROP TABLE IF EXISTS core.user_experience;
```

---

### `tests/Pest.php` — SQLite schema (Postgres CHECK/NOT NULL/FK-cascade NOT enforced here)

Add three helpers and wire them into the shared setups so every test that touches a user/site has the tables (the resources, resolver, export, and visibility all read them; `loadMissing` errors if a table is missing).

```php
/**
 * site.workplaces — 1:1 with site.sites (FOUND-4). Postgres FK-cascade and the
 * double-precision lat/lng are not enforced under SQLite (verify on dev Supabase).
 */
function setupWorkplacesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.workplaces (
        site_id TEXT PRIMARY KEY,
        name TEXT NULL,
        address TEXT NULL,
        address_line1 TEXT NULL,
        city TEXT NULL,
        state TEXT NULL,
        postcode TEXT NULL,
        country TEXT NULL,
        latitude REAL NULL,
        longitude REAL NULL,
        phone TEXT NULL,
        website TEXT NULL,
        previous_website TEXT NULL,
        category TEXT NULL,
        description TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * core.user_credentials (FOUND-5). NOT NULL title + FK-cascade unenforced under
 * SQLite — verify on dev Supabase.
 */
function setupUserCredentialsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.user_credentials (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        title TEXT NULL,
        issuer TEXT NULL,
        year TEXT NULL,
        description TEXT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * core.user_experience (FOUND-5). start_year/end_year hold 'Y-m' strings.
 */
function setupUserExperienceTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.user_experience (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        role TEXT NULL,
        organisation TEXT NULL,
        start_year TEXT NULL,
        end_year TEXT NULL,
        description TEXT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}
```

Wire-in (append to the existing helpers so coverage is automatic):
- At the end of `setupUsersTable()` (after the `CREATE TABLE … core.users …` statement): add
  ```php
      setupUserCredentialsTable();
      setupUserExperienceTable();
  ```
- At the end of `setupSitesTable()` (after the platform_connections block): add
  ```php
      setupWorkplacesTable();
  ```

> Note: these only create tables inside the already-attached `core`/`site` schemas — no new `ATTACH` (SQLITE_MAX_ATTACHED is already full per the testing-information_schema rule).

---

### Code — new models

`app/Models/Core/Site/Workplace.php`

```php
<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Workplace card — 1:1 with site.sites (PK = site_id). Promoted out of the
// site.sites.settings->'workplace' JSONB (FOUND-4). No HasUuids: the PK is the
// site_id FK, set explicitly, not a generated uuid.
class Workplace extends BaseModel
{
    protected $table = 'site.workplaces';

    protected $primaryKey = 'site_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'site_id', 'name', 'address', 'address_line1', 'city', 'state',
        'postcode', 'country', 'latitude', 'longitude', 'phone', 'website',
        'previous_website', 'category', 'description',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
```

`app/Models/Core/User/UserCredential.php`

```php
<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A single professional credential (FOUND-5). Child of core.users; written
// delete-and-reinsert by SyncUserAboutService with a 0-based sort_order.
class UserCredential extends BaseModel
{
    use HasUuids;

    protected $table = 'core.user_credentials';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'title', 'issuer', 'year', 'description', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

`app/Models/Core/User/UserExperience.php`

```php
<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A single professional experience entry (FOUND-5). organisation holds the
// validated `place` field; start_year/end_year hold 'Y-m' month strings.
class UserExperience extends BaseModel
{
    use HasUuids;

    protected $table = 'core.user_experience';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'role', 'organisation', 'start_year', 'end_year',
        'description', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

### Code — relations on existing models

`Site.php` — add `HasOne` import and the relation:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```
```php
    public function workplace(): HasOne
    {
        return $this->hasOne(Workplace::class, 'site_id');
    }
```

`User.php` — add the two relations and the round-trip builder (same namespace as `UserCredential`/`UserExperience`, so no imports needed; `HasMany` already imported):

```php
    public function credentials(): HasMany
    {
        return $this->hasMany(UserCredential::class, 'user_id')->orderBy('sort_order');
    }

    public function experience(): HasMany
    {
        return $this->hasMany(UserExperience::class, 'user_id')->orderBy('sort_order');
    }

    /**
     * Rebuild the dashboard-facing `about` payload from the child tables so the
     * edit form round-trips after FOUND-5 moved reads to tables. Mirrors the
     * legacy JSONB shape EXACTLY: credentials [{title, issuer, year}], experience
     * [{role, place, start, end, description}]. The public-render shape is built
     * separately by SitepageDataResolverService.
     *
     * @return array{credentials: list<array<string, mixed>>, experience: list<array<string, mixed>>}
     */
    public function aboutPayload(): array
    {
        $this->loadMissing(['credentials', 'experience']);

        return [
            'credentials' => $this->credentials->map(fn (UserCredential $c): array => [
                'title' => $c->title,
                'issuer' => $c->issuer,
                'year' => ($c->year !== null && $c->year !== '') ? (int) $c->year : null,
            ])->all(),
            'experience' => $this->experience->map(fn (UserExperience $e): array => [
                'role' => $e->role,
                'place' => $e->organisation,
                'start' => $e->start_year,
                'end' => $e->end_year,
                'description' => $e->description,
            ])->all(),
        ];
    }
```

### Code — new write service

`app/Services/User/SyncUserAboutService.php`

```php
<?php

namespace App\Services\User;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Models\Core\User\UserCredential;
use App\Models\Core\User\UserExperience;

// Persists the credentials + experience child tables from a validated `about`
// payload, then re-gates the credentials/experience section blocks. Delete-and-
// reinsert (≤5 rows each, capped by validation) preserves the client-sent order
// via sort_order. Replaces the old single-JSONB write so dashboard edits stay
// visible after FOUND-5 moved reads to the tables. Call INSIDE the same DB
// transaction as the user save.
class SyncUserAboutService
{
    public function __construct(
        private readonly SectionVisibilityService $visibility,
    ) {}

    /**
     * @param  array<string, mixed>  $about  The validated `about` payload.
     */
    public function sync(User $user, array $about): void
    {
        $this->syncCredentials($user, is_array($about['credentials'] ?? null) ? $about['credentials'] : []);
        $this->syncExperience($user, is_array($about['experience'] ?? null) ? $about['experience'] : []);

        // Re-gate the section blocks so the dashboard Live toggle reflects whether
        // the user now has at least one valid credential / experience entry.
        $siteId = (string) (Site::query()->where('user_id', $user->id)->value('id') ?? '');
        if ($siteId !== '') {
            $this->visibility->reevaluateEnabled((string) $user->id, $siteId, 'credentials');
            $this->visibility->reevaluateEnabled((string) $user->id, $siteId, 'experience');
        }
    }

    /** @param array<int, mixed> $rows */
    private function syncCredentials(User $user, array $rows): void
    {
        UserCredential::query()->where('user_id', $user->id)->delete();

        foreach (array_values($rows) as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = $this->trimOrNull($row['title'] ?? null);
            if ($title === null) {
                continue; // a credential without a title has no identity (mirrors the resolver)
            }
            UserCredential::query()->create([
                'user_id' => $user->id,
                'title' => $title,
                'issuer' => $this->trimOrNull($row['issuer'] ?? null),
                'year' => $this->stringOrNull($row['year'] ?? null),
                'description' => $this->trimOrNull($row['description'] ?? null),
                'sort_order' => $i,
            ]);
        }
    }

    /** @param array<int, mixed> $rows */
    private function syncExperience(User $user, array $rows): void
    {
        UserExperience::query()->where('user_id', $user->id)->delete();

        foreach (array_values($rows) as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $role = $this->trimOrNull($row['role'] ?? null);
            if ($role === null) {
                continue;
            }
            UserExperience::query()->create([
                'user_id' => $user->id,
                'role' => $role,
                'organisation' => $this->trimOrNull($row['place'] ?? null),
                'start_year' => $this->trimOrNull($row['start'] ?? null),
                'end_year' => $this->trimOrNull($row['end'] ?? null),
                'description' => $this->trimOrNull($row['description'] ?? null),
                'sort_order' => $i,
            ]);
        }
    }

    private function trimOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
```

> No `ShouldQueue` job is introduced (the sync runs synchronously inside the request transaction), so the `$backoff` rule does not apply to this PR.

### Code — SectionVisibilityService (6 predicate sites)

Add imports:
```php
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\UserCredential;
use App\Models\Core\User\UserExperience;
```

Replace the `has_credential` subquery (currently `:185-196`):
```php
        if ($needsCredentials) {
            $subqueries['has_credential'] = UserCredential::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->whereNotNull('title')
                ->where('title', '<>', '')
                ->getQuery();
        }
```

Replace the `has_experience` subquery (currently `:198-209`):
```php
        if ($needsExperience) {
            $subqueries['has_experience'] = UserExperience::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->whereNotNull('role')
                ->where('role', '<>', '')
                ->getQuery();
        }
```

Replace the `has_workplace` subquery (currently `:228-237`) — CONFIRMED **name-OR-address** (no behavior change; portable null-safe table predicate):
```php
        if ($needsWorkplace) {
            $subqueries['has_workplace'] = Workplace::query()
                ->select(DB::raw('1'))
                ->where('site_id', $siteId)
                ->where(function ($q) {
                    $q->where(fn ($n) => $n->whereNotNull('name')->where('name', '<>', ''))
                        ->orWhere(fn ($a) => $a->whereNotNull('address')->where('address', '<>', ''));
                })
                ->getQuery();
        }
```

Replace `professionalHasCredential` (currently `:518-529`):
```php
    private function professionalHasCredential(string $userId): bool
    {
        return UserCredential::query()
            ->where('user_id', $userId)
            ->whereNotNull('title')
            ->where('title', '<>', '')
            ->exists();
    }
```

Replace `professionalHasExperience` (currently `:531-542`):
```php
    private function professionalHasExperience(string $userId): bool
    {
        return UserExperience::query()
            ->where('user_id', $userId)
            ->whereNotNull('role')
            ->where('role', '<>', '')
            ->exists();
    }
```

Replace `checkWorkplaceRequirements` (currently `:680-699`) — CONFIRMED **name-OR-address** (behavior preserved; original reason string kept):
```php
    /**
     * Workplace section goes live once the workplace row has a non-empty name OR
     * address (FOUND-4 — behavior preserved; only moved from settings JSONB to the
     * site.workplaces table).
     */
    private function checkWorkplaceRequirements(string $siteId): array
    {
        $hasContent = Workplace::query()
            ->where('site_id', $siteId)
            ->where(function ($q) {
                $q->where(fn ($n) => $n->whereNotNull('name')->where('name', '<>', ''))
                    ->orWhere(fn ($a) => $a->whereNotNull('address')->where('address', '<>', ''));
            })
            ->exists();

        if (! $hasContent) {
            return [false, 'Workplace section requires a name or address before it can go live.'];
        }

        return [true, null];
    }
```

The batch-path reason string in `resolveFromContext` (currently `:314-316`) is UNCHANGED (name-OR-address preserved):
```php
            'workplace' => $context['has_workplace']
                ? [true, null]
                : [false, 'Workplace section requires a name or address before it can go live.'],
```

> The class comment at `:182-184` claiming the credential/experience clauses are "pgsql-specific" is now stale — update it to note the predicates are portable column comparisons.

### Code — SitepageDataResolverService

`getWorkplace` (currently `:411-441`) — read the relation, same 11 keys:
```php
    public function getWorkplace(?Site $site, Collection $sections): array
    {
        return $this->sectionEnvelope($sections, 'workplace', function () use ($site): ?array {
            if (! $site) {
                return null;
            }
            $site->loadMissing('workplace');
            $workplace = $site->workplace;
            if (! $workplace) {
                return null;
            }
            $name = trim((string) ($workplace->name ?? ''));
            if ($name === '') {
                return null;
            }

            return [
                'name' => $name,
                'address' => $this->trimToNull($workplace->address),
                'address_line1' => $this->trimToNull($workplace->address_line1),
                'city' => $this->trimToNull($workplace->city),
                'state' => $this->trimToNull($workplace->state),
                'postcode' => $this->trimToNull($workplace->postcode),
                'country' => $this->trimToNull($workplace->country),
                'latitude' => $workplace->latitude !== null ? (float) $workplace->latitude : null,
                'longitude' => $workplace->longitude !== null ? (float) $workplace->longitude : null,
                'phone' => $this->trimToNull($workplace->phone),
                'website' => $this->trimToNull($workplace->website),
            ];
        });
    }
```

`getBio` (currently `:371-401`) — source credentials/experience from the relations, then pass through the UNCHANGED `normaliseCredential`/`normaliseExperience` so the wire stays byte-identical:
```php
    public function getBio(User $pro, Collection $sections): array
    {
        return $this->sectionEnvelope($sections, 'bio', function () use ($pro): array {
            $pro->loadMissing(['credentials', 'experience']);

            // Map the child rows back to the array shape normaliseCredential /
            // normaliseExperience expect (the old JSONB row shape), so their
            // output — including the synthesised `period` — is unchanged.
            $credentials = $pro->credentials->map(fn ($c): array => [
                'title' => $c->title,
                'issuer' => $c->issuer,
                'year' => $c->year,
                'description' => $c->description,
            ])->all();
            $experience = $pro->experience->map(fn ($e): array => [
                'role' => $e->role,
                'place' => $e->organisation,
                'start' => $e->start_year,
                'end' => $e->end_year,
                'description' => $e->description,
            ])->all();

            $publicEmail = $this->trimToNull($pro->public_contact_email ?? null);
            $publicPhone = $this->trimToNull($pro->public_contact_number ?? null);
            $publicContact = ($publicEmail !== null || $publicPhone !== null)
                ? ['email' => $publicEmail, 'phone' => $publicPhone]
                : null;

            return [
                'text' => (string) ($pro->bio ?? ''),
                'credentials' => array_values(array_filter(array_map(
                    fn ($row) => $this->normaliseCredential($row),
                    $credentials,
                ))),
                'experience' => array_values(array_filter(array_map(
                    fn ($row) => $this->normaliseExperience($row),
                    $experience,
                ))),
                'public_contact' => $publicContact,
            ];
        });
    }
```

> `normaliseCredential`/`normaliseExperience`/`trimToNull` are UNCHANGED. `normaliseCredential`'s `(string) $row['year']` turns the text column back into the same `"2019"` string the old JSONB path produced. `normaliseExperience` re-synthesises `period` from `start`/`end` exactly as before.

### Code — UserWorkplaceController (full rewrite of the body)

Replace the `SETTINGS_KEY` const usage with the `Workplace` model. Add `use App\Models\Core\Site\Workplace;`. Keep the injected `SectionVisibilityService`.

```php
    public function show(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);

        return $this->success([
            'workplace' => $this->normalizeProfile(
                Workplace::query()->where('site_id', $site->id)->first()
            ),
        ]);
    }

    public function upsert(UpsertWorkplaceRequest $request): JsonResponse
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);
        $data = $request->validated();

        // updateOrCreate replaces all 14 fields (any field absent from the request
        // becomes null) — identical to the old "rebuild the whole blob" semantics.
        $workplace = Workplace::updateOrCreate(
            ['site_id' => $site->id],
            [
                'name' => (string) $data['name'],
                'address' => $this->trimOrNull($data['address'] ?? null),
                'address_line1' => $this->trimOrNull($data['address_line1'] ?? null),
                'city' => $this->trimOrNull($data['city'] ?? null),
                'state' => $this->trimOrNull($data['state'] ?? null),
                'postcode' => $this->trimOrNull($data['postcode'] ?? null),
                'country' => $this->trimOrNull($data['country'] ?? null),
                'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
                'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
                'phone' => $this->trimOrNull($data['phone'] ?? null),
                'website' => $this->trimOrNull($data['website'] ?? null),
                'previous_website' => $this->trimOrNull($data['previous_website'] ?? null),
                'category' => $this->trimOrNull($data['category'] ?? null),
                'description' => $this->trimOrNull($data['description'] ?? null),
            ],
        );

        $this->visibilityService->reevaluateEnabled(
            (string) $professional->id,
            (string) $site->id,
            'workplace',
        );

        return $this->success([
            'workplace' => $this->normalizeProfile($workplace),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);

        Workplace::query()->where('site_id', $site->id)->delete();

        $this->visibilityService->reevaluateEnabled(
            (string) $professional->id,
            (string) $site->id,
            'workplace',
        );

        return $this->success(['workplace' => null]);
    }

    public function showPreviousWebsite(Request $request): JsonResponse
    {
        $site = $this->currentSite($this->currentUser($request));
        $workplace = Workplace::query()->where('site_id', $site->id)->first();

        return $this->success([
            'previousWebsite' => $workplace ? $this->trimOrNull($workplace->previous_website) : null,
        ]);
    }

    public function setPreviousWebsite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'previous_website' => ['nullable', 'url', 'max:2048'],
        ]);

        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);

        // No name requirement here (the Settings card works before a workplace is
        // named) — updateOrCreate creates a name-null row if none exists, exactly
        // as the old settings.workplace merge did. (Relies on NULLABLE name.)
        $workplace = Workplace::updateOrCreate(
            ['site_id' => $site->id],
            ['previous_website' => $this->trimOrNull($validated['previous_website'] ?? null)],
        );

        return $this->success([
            'previousWebsite' => $workplace->previous_website,
        ]);
    }

    private function trimOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeProfile(?Workplace $workplace): ?array
    {
        if ($workplace === null) {
            return null;
        }
        // A row with no name has no identity — drop it. Every other field is optional.
        $name = $this->trimOrNull($workplace->name);
        if (! $name) {
            return null;
        }

        return [
            'name' => $name,
            'address' => $this->trimOrNull($workplace->address),
            'address_line1' => $this->trimOrNull($workplace->address_line1),
            'city' => $this->trimOrNull($workplace->city),
            'state' => $this->trimOrNull($workplace->state),
            'postcode' => $this->trimOrNull($workplace->postcode),
            'country' => $this->trimOrNull($workplace->country),
            'latitude' => $workplace->latitude !== null ? (float) $workplace->latitude : null,
            'longitude' => $workplace->longitude !== null ? (float) $workplace->longitude : null,
            'phone' => $this->trimOrNull($workplace->phone),
            'website' => $this->trimOrNull($workplace->website),
            'previous_website' => $this->trimOrNull($workplace->previous_website),
            'category' => $this->trimOrNull($workplace->category),
            'description' => $this->trimOrNull($workplace->description),
        ];
    }
```

Remove the now-unused `private const SETTINGS_KEY = 'workplace';`.

### Code — StaffWorkplaceController (read-only; keeps its 11-key shape)

Add `use App\Models\Core\Site\Workplace;`. Replace `show` body + `normalizeProfile`:

```php
    public function show(User $professional): JsonResponse
    {
        $site = Site::query()
            ->where('user_id', $professional->id)
            ->first();

        if (! $site) {
            return $this->error('Site not found for professional.', 404);
        }

        return $this->success([
            'workplace' => $this->normalizeProfile(
                Workplace::query()->where('site_id', $site->id)->first()
            ),
        ]);
    }

    private function normalizeProfile(?Workplace $workplace): ?array
    {
        if ($workplace === null) {
            return null;
        }
        $name = $this->trimOrNull($workplace->name);
        if (! $name) {
            return null;
        }

        return [
            'name' => $name,
            'address' => $this->trimOrNull($workplace->address),
            'address_line1' => $this->trimOrNull($workplace->address_line1),
            'city' => $this->trimOrNull($workplace->city),
            'state' => $this->trimOrNull($workplace->state),
            'postcode' => $this->trimOrNull($workplace->postcode),
            'country' => $this->trimOrNull($workplace->country),
            'latitude' => $workplace->latitude !== null ? (float) $workplace->latitude : null,
            'longitude' => $workplace->longitude !== null ? (float) $workplace->longitude : null,
            'phone' => $this->trimOrNull($workplace->phone),
            'website' => $this->trimOrNull($workplace->website),
        ];
    }
```

Remove the unused `private const SETTINGS_KEY = 'workplace';`. Keep `trimOrNull` (unchanged).

### Code — UserSelfController::update (write-path rewire)

Add imports + constructor injection:
```php
use App\Services\User\SyncUserAboutService;
```
```php
    public function __construct(
        private readonly SyncUserAboutService $aboutSync,
    ) {}
```
Replace `update`:
```php
    public function update(UpdateUserRequest $request)
    {
        $professional = $this->currentUser($request);
        $this->authorizeForUser($professional, 'update', $professional);

        $validated = $request->validated();

        // Credentials + experience now live in child tables (FOUND-5). Pull the
        // about sub-payload out, strip the two arrays from the JSONB that gets
        // filled, then sync the tables so dashboard edits round-trip.
        $about = (array_key_exists('about', $validated) && is_array($validated['about']))
            ? $validated['about']
            : null;
        if ($about !== null) {
            unset($validated['about']['credentials'], $validated['about']['experience']);
            if ($validated['about'] === []) {
                $validated['about'] = null;
            }
        }

        DB::transaction(function () use ($professional, $validated, $about): void {
            $professional->fill($validated);
            $professional->save();

            if ($about !== null) {
                $this->aboutSync->sync($professional, $about);
            }
        });

        return $this->success([
            'professional' => new UserDashboardResource($professional->fresh()),
        ]);
    }
```

### Code — StaffUserController::update (write-path rewire)

Add `use App\Services\User\SyncUserAboutService;` and a constructor (the class currently has none):
```php
    public function __construct(
        private readonly SyncUserAboutService $aboutSync,
    ) {}
```
Replace `update`:
```php
    public function update(
        StaffUpdateUserRequest $request,
        User $professional,
    ) {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $validated = $request->validated();

        $about = (array_key_exists('about', $validated) && is_array($validated['about']))
            ? $validated['about']
            : null;
        if ($about !== null) {
            unset($validated['about']['credentials'], $validated['about']['experience']);
            if ($validated['about'] === []) {
                $validated['about'] = null;
            }
        }

        DB::transaction(function () use ($professional, $validated, $about): void {
            $professional->fill($validated);
            $professional->save();

            if ($about !== null) {
                $this->aboutSync->sync($professional, $about);
            }
        });

        return $this->success([
            'professional' => new UserStaffResource($professional->fresh()),
        ]);
    }
```

> The `HandlesSearchQueries`/`NormalizesPerPage`/`ReturnsPaginatedResponse` traits carry no constructor, so adding one is safe.

### Code — resources (round-trip rebuild)

`UserDashboardResource.php:27`:
```php
            'about' => (object) $this->aboutPayload(),
```
`UserStaffResource.php:22`:
```php
            'about' => (object) $this->aboutPayload(),
```
(`$this->aboutPayload()` forwards through `JsonResource`'s `DelegatesToResource` to the model method.)

### Code — MenuSource::address (DoorDash locale; raw pgsql, matches existing style)

Replace the body (currently `:235-253`):
```php
    private function address(User|string $user): ?string
    {
        $userId = $user instanceof User ? $user->id : $user;

        // Workplace lives 1:1 on site.workplaces (PK = site_id); join through the
        // user's site to read the DoorDash consumer locale.
        $workplace = DB::connection('pgsql')
            ->table('site.workplaces')
            ->join('site.sites', 'site.sites.id', '=', 'site.workplaces.site_id')
            ->where('site.sites.user_id', $userId)
            ->select('site.workplaces.address', 'site.workplaces.city', 'site.workplaces.state')
            ->first();

        if ($workplace === null) {
            return null;
        }

        $address = $workplace->address;
        if (is_string($address) && trim($address) !== '') {
            return trim($address);
        }

        $parts = array_filter(
            [$workplace->city, $workplace->state],
            fn ($v) => is_string($v) && trim($v) !== '',
        );

        return $parts === [] ? null : implode(', ', $parts).', Australia';
    }
```

### Code — GoogleBusinessAutoSync::seedWorkplace (read-modify-write on the row)

Add `use App\Models\Core\Site\Workplace;`. Replace the body of the `try` after `$fields` is built (currently `:299-319`):
```php
            $site = Site::query()->where('user_id', $userId)->first();
            if ($site === null) {
                return;
            }

            // 1:1 workplace row; firstOrNew lets us seed the GB-sourced extras even
            // before the user has named a workplace (name stays null — the card is
            // hidden until named, exactly as the old JSONB blob behaved).
            $workplace = Workplace::query()->firstOrNew(['site_id' => $site->id]);

            $changed = false;
            foreach ($fields as $key => $value) {
                if ($this->blank($workplace->{$key} ?? null)) {
                    $workplace->{$key} = $value;
                    $changed = true;
                }
            }
            if (! $changed) {
                return;
            }

            $workplace->save();
```

### Code — DataExportPayloadBuilder (GDPR — read the new tables)

`profile()` (currently `:321-330`) — rebuild `about` from tables (byte-identical to the old JSONB shape):
```php
    private function profile(User $p): array
    {
        // Strip secrets — never let auth or tokens leak into an export.
        $row = $p->toArray();
        unset($row['auth_user_id'], $row['deletion_token_hash']);

        // Credentials + experience were promoted out of the about JSONB into child
        // tables (FOUND-5); rebuild the about payload so the export still discloses
        // them in the same shape.
        $row['about'] = $p->aboutPayload();

        return [
            'professional' => $row,
        ];
    }
```

`site()` (currently `:332-356`) — add the workplace row (additive sub-key; the workplace data used to live in `settings.workplace`, now stripped):
```php
    private function site(string $userId): array
    {
        $site = DB::connection('pgsql')
            ->table('site.sites')
            ->where('user_id', $userId)
            ->first();

        if (! $site) {
            return ['site' => null, 'blocks' => [], 'workplace' => null];
        }

        $blocks = $this->collect(
            $this->lazyRows(
                DB::connection('pgsql')
                    ->table('site.blocks')
                    ->where('site_id', $site->id)
                    ->orderBy('sort_order')
            )
        );

        $workplace = DB::connection('pgsql')
            ->table('site.workplaces')
            ->where('site_id', $site->id)
            ->first();

        return [
            'site' => (array) $site,
            'blocks' => $blocks,
            'workplace' => $workplace !== null ? (array) $workplace : null,
        ];
    }
```

> Update the `build()` return-shape docblock `site:` entry note if the reviewer wants it precise; the top-level key set is unchanged. The `stream()` path yields `'site'` as a single value via the same `site()` method, so it inherits the workplace automatically.

---

### DEV-SUPABASE VERIFICATION (Postgres CHECK / NOT NULL / FK-cascade are invisible to SQLite)

Apply both migrations to dev ref `glncumufgaqcmqhzwrxm` via `apply_migration`, then run these `execute_sql` probes (use a real seeded `site.sites` id `$SID` + `core.users` id `$UID`):

1. **NOT NULL title (user_credentials)** — expect rejection (`null value in column "title" violates not-null constraint`):
   ```sql
   INSERT INTO core.user_credentials (id, user_id, title) VALUES (gen_random_uuid(), '$UID', NULL);
   ```
2. **NOT NULL role (user_experience)** — expect rejection:
   ```sql
   INSERT INTO core.user_experience (id, user_id, role) VALUES (gen_random_uuid(), '$UID', NULL);
   ```
3. **FK cascade (workplaces)** — insert a workplace, delete its site, confirm the row is gone:
   ```sql
   INSERT INTO site.workplaces (site_id, name) VALUES ('$SID', 'Probe');
   DELETE FROM site.sites WHERE id = '$SID';
   SELECT count(*) FROM site.workplaces WHERE site_id = '$SID';  -- expect 0
   ```
4. **FK cascade (credentials/experience)** — insert one of each for `$UID`, delete the user, confirm both gone:
   ```sql
   INSERT INTO core.user_credentials (id, user_id, title) VALUES (gen_random_uuid(), '$UID', 'X');
   INSERT INTO core.user_experience  (id, user_id, role)  VALUES (gen_random_uuid(), '$UID', 'Y');
   DELETE FROM core.users WHERE id = '$UID';
   SELECT (SELECT count(*) FROM core.user_credentials WHERE user_id='$UID')
        + (SELECT count(*) FROM core.user_experience  WHERE user_id='$UID');  -- expect 0
   ```
   (Use a throwaway `$SID`/`$UID` or a transaction you `ROLLBACK` afterward — these probes are destructive.)
5. **Backfill fidelity** (zero rows pre-beta, but prove the SQL parses): run the two `INSERT … SELECT … jsonb_to_recordset … WITH ORDINALITY` statements and the `settings - 'workplace'` / `about - 'credentials'` strips against the live (empty) tables and confirm 0 rows affected, no syntax error.

> If Josh flips the NULLABLE-name DECISION to NOT NULL, add a probe: `INSERT INTO site.workplaces (site_id, name) VALUES ('$SID', NULL);` → expect rejection.

---

### Golden-master guard (tests that MUST stay green / be updated)

These tests currently seed the OLD JSONB paths — they must switch to seeding the new tables, asserting the SAME output (byte-identical wire):

- **`tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`** (the bio block test ~`:631-666` seeds `about => json_encode([credentials,experience])`): replace the `about` seed with inserts into `core.user_credentials` / `core.user_experience`; the assertions on `bio.credentials`/`bio.experience` (`toMatchArray`, `period`) must stay identical. **This is the public-wire byte-identical golden master.** Add a workplace section assertion seeding `site.workplaces` and asserting the 11-key `workplace` envelope.
- **`tests/Feature/User/UserAboutTest.php`**: the `exposes about through UserDashboardResource` test seeds `about` JSON then asserts the resource — switch to inserting credential/experience rows and assert `aboutPayload`-rebuilt shape (`credentials[0].title`, `year` as int, `experience[0].start`). The raw-cast test (`persists a full about payload …`) still passes (the `about` column is retained) but is no longer the source of truth — note that in the test.
- **`tests/Feature/Staff/StaffWorkplaceControllerTest.php`**: `makeStaffWorkplaceUser` seeds `settings => {workplace:{…}}` — switch to inserting a `site.workplaces` row; the three assertions (null when absent, 11-key normalised profile, 404 no-site) stay identical.
- **`tests/Unit/Requests/UpsertWorkplaceRequestTest.php`** and **`tests/Feature/Validation/UserAboutValidationTest.php`**: request validation is UNCHANGED — must stay green untouched (regression guard that the public input contract didn't drift).
- **`tests/Feature/FeatureFlags/SectionVisibility*`** (`SectionVisibilityTestCase`, `SectionVisibilityLinkOnlyTest`): any workplace/credentials/experience visibility seeding moves to the tables; the workplace reason-string assertions stay `"requires a name or address"` (name-OR-address preserved — NO behavior change). Add a case proving an address-only (name-less) workplace still reports live.
- **`tests/Feature/Platforms/*`** that touch workplace (`ReservationProvidersTest`, `GoogleBusinessApifyTest`/auto-sync): any `settings.workplace` seed/assert moves to `site.workplaces`.
- **`tests/Feature/User/DataExport/DataExportPayloadBuilderTest.php`**: add assertions that `profile.professional.about` rebuilds credentials/experience from tables and `site.workplace` is present; existing assertions stay green.
- **`tests/Feature/Site/BatchCheckQueryCountTest.php`** (the portability NET WIN): extend `$types` to include `'workplace'`, `'credentials'`, `'experience'`; the combined SELECT still issues exactly ONE query — keep `toHaveCount(1)`, bump `substr_count(... 'exists (')` assertion `toBeGreaterThanOrEqual(5)` → `(8)` (the 3 new portable subqueries now compile on SQLite). `createBrandTenant` already pulls the new tables in via `tenantHelpersEnsureTables`.

---

### Bite-sized TDD task list (FOUND-4 first, then FOUND-5; stacked commits)

> Commands below assume the main checkout (not a `.claude/worktrees/` copy — feature tests break there). Run `composer dump-autoload -o` after adding new classes if Eloquent can't resolve them.

**Task 1 — Migration files + Pest schema (FOUND-4 table).**
- Write `20260701000000_create_workplaces.sql`; add `setupWorkplacesTable()` and wire it into `setupSitesTable()`.
- Run `composer test -- --filter=BatchCheckQueryCountTest` → expect PASS (tables now exist; no behavior change yet).
- `php artisan pint --dirty`
- `git commit -m "feat(workplace): create site.workplaces table + SQLite schema (FOUND-4)"`

**Task 2 — Workplace model + Site relation, visibility + resolver + controllers (FOUND-4 read/write).**
- Failing test: in `tests/Feature/Staff/StaffWorkplaceControllerTest.php` switch the seed to `site.workplaces`. Run `composer test -- --filter=StaffWorkplaceControllerTest` → expect FAIL (`StaffWorkplaceController` still reads settings).
- Implement: `Workplace` model, `Site::workplace()`, rewire `SectionVisibilityService` (workplace 2 sites, name-OR-address predicate, reason string unchanged), `SitepageDataResolverService::getWorkplace`, `UserWorkplaceController`, `StaffWorkplaceController`, `MenuSource::address`, `GoogleBusinessAutoSync::seedWorkplace`.
- Update `IndividualProfileControllerTest` workplace seeding/assertion.
- Run `composer test -- --filter='Workplace|IndividualProfile|SectionVisibility|MenuFetch|GoogleBusiness'` → expect PASS.
- `php artisan pint --dirty`
- `git commit -m "feat(workplace): read/write site.workplaces; portable name-or-address visibility (FOUND-4)"`

**Task 3 — Credentials/experience migration + Pest schema (FOUND-5 tables).**
- Write `20260701000100_create_user_credentials_experience.sql`; add `setupUserCredentialsTable()`/`setupUserExperienceTable()` and wire into `setupUsersTable()`.
- Run `composer test -- --filter=UserAboutTest` → expect FAIL only on the resource-exposure test (drives Task 5) — others green.
- `php artisan pint --dirty`
- `git commit -m "feat(about): create user_credentials/user_experience tables + SQLite schema (FOUND-5)"`

**Task 4 — Models + relations + visibility + resolver read path (FOUND-5 reads).**
- Failing test: update `IndividualProfileControllerTest` bio block to seed the child tables. Run `--filter=IndividualProfileControllerTest` → expect FAIL (resolver still reads `about`).
- Implement: `UserCredential`/`UserExperience` models, `User::credentials()/experience()`, rewire `SectionVisibilityService` (4 credential/experience sites), `SitepageDataResolverService::getBio`.
- Run `composer test -- --filter='IndividualProfile|SectionVisibility'` → expect PASS.
- `php artisan pint --dirty`
- `git commit -m "feat(about): read credentials/experience from child tables (FOUND-5)"`

**Task 5 — `SyncUserAboutService` + write paths + resource round-trip (FOUND-5 writes — the biggest risk).**
- Failing test (NEW) `tests/Feature/User/UserAboutWritePathTest.php`:
  ```php
  it('PATCH /me writes credentials + experience to tables, strips JSON, flips is_enabled', function () {
      // seed user + site + a 'credentials' and 'experience' section block (is_enabled=false)
      // PATCH /api/me with about.credentials=[{title,issuer,year}], about.experience=[{role,place,start,end}]
      // assert: core.user_credentials has the row(s) with sort_order; core.user_experience too;
      //         core.users.about no longer contains 'credentials'/'experience';
      //         the credentials/experience section blocks' is_enabled flipped true;
      //         the response JSON 'about' round-trips {credentials:[…],experience:[…]} (year as int).
      // Then PATCH again with about.credentials=[] → rows deleted, block is_enabled flips false.
  });
  ```
  Run `--filter=UserAboutWritePath` → expect FAIL.
- Implement: `SyncUserAboutService`, `User::aboutPayload()`, `UserSelfController::update`, `StaffUserController::update`, `UserDashboardResource`, `UserStaffResource`, `DataExportPayloadBuilder` (profile+site).
- Update `tests/Feature/User/UserAboutTest.php` resource test + `DataExportPayloadBuilderTest`.
- Run `composer test -- --filter='UserAbout|DataExport|StaffUser|UserSelf'` → expect PASS.
- `php artisan pint --dirty`
- `git commit -m "feat(about): SyncUserAboutService write path + resource/export round-trip (FOUND-5)"`

**Task 6 — BatchCheck portability win + dev-Supabase verification.**
- Extend `BatchCheckQueryCountTest` `$types` with workplace/credentials/experience; bump the `exists (` assertion to `>= 8`. Run `--filter=BatchCheckQueryCountTest` → expect PASS.
- Apply both migrations to dev `glncumufgaqcmqhzwrxm`; run the 5 verification probes above; confirm rejections/cascades.
- `php artisan pint --dirty`
- `git commit -m "test(visibility): workplace/credentials/experience join the single-query batch check (FOUND-4/5)"`

**Task 7 — Full-suite gate.**
- Run the COMPLETE suite (a filtered subset is a false signal — Wave-1 hit a 9-test regression only the full run caught):
  ```bash
  composer test
  ```
  Expect: green. Pay attention to any test that previously seeded `settings.workplace` or `about->credentials/experience` and now errors with "no such table" or a shape mismatch — those need their seed switched to the tables.
- If any new `ShouldQueue` job were introduced it would need `public int $backoff` — none is in this PR (confirm `JobHygienePolicyTest` stays green regardless).

---

### Authorization / house-rule conformance
- `UserSelfController::update` keeps `authorizeForUser($professional, 'update', $professional)`; `StaffUserController::update` keeps `authorizeForUser($staff, 'staffManage', $professional)`. No inline `abort_unless`.
- No raw `Cache::` introduced. No Laravel migration files (raw SQL in `supabase/migrations/` only). All API responses still flow through Resource classes / the `success()` envelope.
- The `about` column on `core.users` is intentionally RETAINED (not dropped) to keep blast radius minimal; it is simply no longer the source of truth for credentials/experience.


---

## PR4 — FOUND-14 + FOUND-10 — `block_group`/`block_type` pair-CHECK + config map + SectionVisibility registry

**Goal:** Replace the two independent `site.blocks` CHECKs with one pair-CHECK (rejecting cross-group combinations like `('sections','link')`), make a canonical `config('partna.block_types')` map + `Block` group/type constants the single source for the magic strings, and collapse `SectionVisibilityService`'s three entangled per-type methods into a `SectionVisibilityRegistry` of one rule per section type.

**Architecture:** One CHECK-swap migration (`NOT VALID` → `VALIDATE`, no column promotion, so **both public-read DB views are untouched**). FOUND-14 introduces `config('partna.block_types')` (group → allowed types, cross-referenced to the new CHECK) plus `Block::GROUP_LINKS / GROUP_SECTIONS / TYPE_LINK` constants; the four block controllers read the constants instead of bare literals. FOUND-10 introduces a `SectionVisibilityContract` + `SectionVisibilityRegistry` (modelled on `PlatformRegistry`/`PlatformRegistryServiceProvider`), one impl per requirement-bearing section type, bound in a new `SectionVisibilityServiceProvider`; `SectionVisibilityService` becomes a thin orchestrator that loads the bundled single-round-trip `EXISTS` context and delegates the per-block decision to the matching rule.

**Blast radius:**
- Migration: `supabase/migrations/20260701000000_blocks_group_type_pair_check.sql` (new).
- `config/partna.php` (add `block_types`; derive `section_block_types` from it).
- `app/Models/Core/Site/Block.php` (3 constants).
- `app/Http/Controllers/Api/User/SiteManagement/UserLinkBlockController.php`, `.../UserSectionBlockController.php`, `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffLinkBlockManagementController.php`, `.../StaffSectionManagementController.php` (literal → constant).
- `app/Services/User/SectionVisibilityService.php` (rewrite to delegate).
- New: `app/Services/User/Visibility/SectionVisibilityContract.php`, `.../SectionVisibilityRegistry.php`, `.../Rules/{Gallery,Documents,Services,Booking,Credentials,Experience,PublicContact,Workplace,Countdown,Contact}Visibility.php`, `app/Providers/SectionVisibilityServiceProvider.php`.
- `bootstrap/providers.php` (register the provider).
- Tests: `tests/Feature/Database/CheckConstraintsTest.php` (constraint name), new config-guard + registry-coverage tests. **No `tests/Pest.php` schema change** (no new column; SQLite does not enforce CHECK).

**DECISIONS (confirm before implementing):**
- **DECISION (FOUND-10 contract shape):** `contextSubqueries(string $userId, string $siteId): array<string, \Illuminate\Database\Query\Builder>` (plural, keyed by alias) — vs the brief's literal singular `contextSubquery(...): ?Builder`. Recommended: **plural.** Booking needs **two** bundled subqueries (`has_active_service` + `has_booking_link_block`) inside the one SELECT; a singular return cannot express that without either breaking the single-round-trip invariant or special-casing booking outside the registry. The design plan (line 101) explicitly sanctions "its contract may return a keyed map." Plan is written around plural.
- **DECISION (FOUND-14 literal source):** use `Block` model constants (`GROUP_LINKS`, `GROUP_SECTIONS`, `TYPE_LINK`) for the **singular inline literals** in WHERE/skeleton creation (mirroring `Site::DEFAULT_SKELETON_ID`), and `config('partna.block_types')` as the **canonical group→types enumeration** that the DB CHECK is cross-referenced to and that `section_block_types` is derived from — vs forcing the singular literals through `config()` lookups (awkward: `config('partna.block_types.links')` is an array, not the string `'links'`). Recommended: **both, as described.** Existing `section_block_types` consumers and their `Config::set(...)` overrides are left untouched.

**Premise grounding (honored corrections + drift found vs the design plan):**
- **Honored FOUND-14 premise correction #6:** confirmed against the live tree — a `block_type` CHECK **and** a separate `block_group` CHECK already exist; only the pair-CHECK is missing.
  - `link_blocks_block_group_check CHECK (block_group = ANY (ARRAY['links','sections']))` — baseline `20260526000000_baseline_standalone_user.sql:756` (never renamed).
  - `blocks_block_type_check CHECK (block_type IN (...16 types...))` — re-added by `20260527040000_extend_block_type_check.sql:20-39` (16 types = `link` + the 15 section types). The baseline's original `blocks_block_type_check` (`:757-762`) had only 14; `20260527040000` extended it to add `public_contact` + `workplace`.
  - Today `('sections','link')` passes (both columns pass their independent CHECK). The pair-CHECK fixes this.
- **Drift — column name:** the design plan/audit show `professional_id`; the live column is **`user_id`** (renamed by `20260527030000_rename_professional_to_user.sql:40`). All code in this plan uses `user_id`.
- **Drift — config:** no `config('partna.block_types')` exists yet; only `config('partna.section_block_types')` (flat 15-item list at `config/partna.php:745`). The 15 section types in config exactly match the 15 non-`link` types in the `blocks_block_type_check` enum.
- **Drift — golden master:** `tests/Feature/Database/CheckConstraintsTest.php:52-56` asserts `blocks_block_type_check` exists+validated; this PR renames it, so that test must be updated to `blocks_group_type_check`. (Postgres-only; it `markTestSkipped`s on SQLite — see verification.)
- **No drift on the visibility service:** `checkVisibilityRequirements` match (`SectionVisibilityService.php:32-45`), `loadVisibilityContext` (`:101-273`), `resolveFromContext` (`:283-325`) confirmed exactly as the design plan describes. Requirement-bearing types: gallery, booking, services, documents, countdown, contact, public_contact, workplace, credentials, experience (10).

**CROSS-PR DEPENDENCY (PR3 ships BEFORE PR4 — read carefully):**
PR3 (FOUND-4/5) migrates **workplace → `site.workplaces`** table and **credentials/experience → `core.user_credentials` / `core.user_experience`** child tables, and **rewrites those exact subqueries** inside `SectionVisibilityService` from JSONB predicates to table predicates (workplace stays **name-OR-address** per FOUND-4 — no behavior change). Because PR3 lands first, the `Workplace`/`Credentials`/`Experience` rule impls in this PR are written against **PR3's table predicates**, NOT the JSONB predicates currently in the tree. See the impls below and the explicit assembler note at the end.

---

### Migration — `supabase/migrations/20260701000000_blocks_group_type_pair_check.sql`

> The executing session bumps this filename to a timestamp later than the then-latest migration before applying (latest today is `20260630000000_drop_smart_links.sql`).
>
> Before dropping, confirm the live constraint names on dev Supabase (`\d site.blocks` or `pg_constraint`) — the dev DB has had constraints applied directly in the past (migration-drift). Expected names: `link_blocks_block_group_check`, `blocks_block_type_check`.

```sql
-- ==========================================================================
-- site.blocks: replace the two independent column CHECKs with one pair-CHECK
-- (2026-07-01)
--
-- Today there are TWO independent constraints:
--   link_blocks_block_group_check  CHECK (block_group IN ('links','sections'))   [baseline]
--   blocks_block_type_check        CHECK (block_type IN (16 types))              [20260527040000]
-- Because they are independent, the invalid pair ('sections','link') passes:
-- 'sections' is a valid group AND 'link' is a valid type. Such a row is then
-- invisible to every list endpoint (group/type filters never match it).
--
-- Replace both with a single pair-CHECK enumerating valid (group,type) combos.
-- The 15 section types mirror config('partna.block_types.sections'); 'link' is
-- the sole 'links'-group type. Keep this list in sync with that config map.
--
-- Safe pattern (CONVENTIONS.md §2): DROP old constraints, ADD new one NOT VALID
-- (catalog-only, lock released immediately), then VALIDATE in a separate
-- transaction (SHARE UPDATE EXCLUSIVE — writes continue during validation).
-- Pre-beta the table is empty, so VALIDATE is a no-op scan; the pattern is kept
-- for prod-shape parity.
-- ==========================================================================

BEGIN;
ALTER TABLE site.blocks DROP CONSTRAINT link_blocks_block_group_check;
ALTER TABLE site.blocks DROP CONSTRAINT blocks_block_type_check;
ALTER TABLE site.blocks ADD CONSTRAINT blocks_group_type_check
    CHECK (
        (block_group = 'links' AND block_type = 'link')
        OR (block_group = 'sections' AND block_type IN (
            'gallery', 'services', 'booking', 'contacts_collection',
            'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter',
            'countdown', 'contact', 'public_contact', 'workplace',
            'credentials', 'experience', 'bio'
        ))
    ) NOT VALID;
COMMIT;

-- Validate in a separate transaction (weaker lock; CONVENTIONS.md §2).
BEGIN;
ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_group_type_check;
COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.blocks DROP CONSTRAINT blocks_group_type_check;
-- ALTER TABLE site.blocks ADD CONSTRAINT link_blocks_block_group_check
--     CHECK (block_group = ANY (ARRAY['links', 'sections'])) NOT VALID;
-- ALTER TABLE site.blocks ADD CONSTRAINT blocks_block_type_check
--     CHECK (block_type IN (
--         'link', 'gallery', 'services', 'booking', 'contacts_collection',
--         'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter',
--         'countdown', 'contact', 'public_contact', 'workplace',
--         'credentials', 'experience', 'bio'
--     )) NOT VALID;
-- COMMIT;
-- BEGIN;
-- ALTER TABLE site.blocks VALIDATE CONSTRAINT link_blocks_block_group_check;
-- ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_block_type_check;
-- COMMIT;
```

**Postgres-only constraints are invisible to SQLite.** The hand-built SQLite schema (`tests/Pest.php` `setupBlocksTable()`) does not enforce CHECKs, and this migration adds **no column**, so `tests/Pest.php` needs **no edit**. The constraint behaviour is unverifiable in CI — verify on dev Supabase.

**DEV-SUPABASE VERIFICATION (`glncumufgaqcmqhzwrxm`):**
1. Apply: `mcp apply_migration` with the SQL above (or `supabase db push`).
2. Pick a real site to satisfy the NOT NULL FKs (`user_id`, `site_id`):
   ```sql
   SELECT id AS site_id, user_id FROM site.sites LIMIT 1;
   ```
3. Invalid pair `('sections','link')` — expect rejection **SQLSTATE 23514** (`check constraint "blocks_group_type_check"`):
   ```sql
   BEGIN;
   INSERT INTO site.blocks (user_id, site_id, block_group, block_type)
   VALUES ('<user_id>', '<site_id>', 'sections', 'link');
   ROLLBACK;
   ```
4. Invalid pair `('links','gallery')` — expect rejection **23514**:
   ```sql
   BEGIN;
   INSERT INTO site.blocks (user_id, site_id, block_group, block_type)
   VALUES ('<user_id>', '<site_id>', 'links', 'gallery');
   ROLLBACK;
   ```
5. Valid pair `('links','link')` — expect **success** (then roll back):
   ```sql
   BEGIN;
   INSERT INTO site.blocks (user_id, site_id, block_group, block_type)
   VALUES ('<user_id>', '<site_id>', 'links', 'link');
   ROLLBACK;
   ```
6. Confirm constraint is validated:
   ```sql
   SELECT conname, convalidated FROM pg_constraint
   WHERE conname = 'blocks_group_type_check';   -- expect convalidated = t
   ```

---

### `config/partna.php`

Add a local `$blockTypes` variable immediately before `return [` (after the `use` imports) and reference it for both the new `block_types` map and the existing `section_block_types` alias, so there is **one** PHP source for the section-type list:

```php
// Canonical block-type registry — block_group => allowed block_types. Single
// source of truth for the section/link type split. The 'sections' list is
// cross-referenced to the DB CHECK `blocks_group_type_check`
// (supabase/migrations/20260701000000_blocks_group_type_pair_check.sql) and to
// the Block::GROUP_* / TYPE_* constants — keep all three in sync. The flat
// `section_block_types` key below is derived from this so the two never drift.
$blockTypes = [
    'links' => ['link'],
    'sections' => [
        'gallery', 'services', 'booking', 'contacts_collection', 'sitepage_analytics',
        'barbershop_info', 'documents', 'newsletter', 'countdown', 'contact',
        'public_contact', 'workplace', 'credentials', 'experience', 'bio',
    ],
];

return [
    // ... existing entries ...
```

Add the new key (place near the existing `section_block_types`, e.g. just above it):

```php
    'block_types' => $blockTypes,
```

Replace the existing flat literal at `config/partna.php:745`:

```php
// BEFORE:
'section_block_types' => ['gallery', 'services', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter', 'countdown', 'contact', 'public_contact', 'workplace', 'credentials', 'experience', 'bio'],

// AFTER:
// Flat alias of block_types['sections'] — many consumers + tests read this and
// override it via Config::set(). Derived so it can never drift from block_types.
'section_block_types' => $blockTypes['sections'],
```

The resolved value of `section_block_types` is byte-identical (same 15 items, same order), so every existing consumer (`UserSectionBlockController`, `UpsertSectionBlockRequest`, `TrackableBlockTypes`) and `Config::set('partna.section_block_types', ...)` override keeps working.

---

### `app/Models/Core/Site/Block.php`

Add three constants below the existing properties (after `$casts`, before `user()`):

```php
    /**
     * block_group values. Mirror config('partna.block_types') keys and the
     * blocks_group_type_check DB CHECK. There are exactly two groups.
     */
    public const GROUP_LINKS = 'links';

    public const GROUP_SECTIONS = 'sections';

    /** The sole block_type permitted in the 'links' group. */
    public const TYPE_LINK = 'link';
```

---

### Controllers — replace bare literals with constants (no behaviour change)

`Block` is already imported in all four. Edits (each `'links'`/`'sections'`/`'link'` magic string → constant):

**`app/Http/Controllers/Api/User/SiteManagement/UserLinkBlockController.php`**
- `:87` `->where('block_group', 'links')` → `->where('block_group', Block::GROUP_LINKS)`
- `:93-94` `'block_group' => 'links', 'block_type' => 'link',` → `'block_group' => Block::GROUP_LINKS, 'block_type' => Block::TYPE_LINK,`
- `:115` `abort_unless($linkBlock->block_group === 'links' && $linkBlock->block_type === 'link', 404);` → `abort_unless($linkBlock->block_group === Block::GROUP_LINKS && $linkBlock->block_type === Block::TYPE_LINK, 404);`
- `:165` identical edit to `:115`.
- `:188-189` `->where('block_group', 'links')->where('block_type', 'link')` → `->where('block_group', Block::GROUP_LINKS)->where('block_type', Block::TYPE_LINK)`

**`app/Http/Controllers/Api/Staff/UserSiteManagement/StaffLinkBlockManagementController.php`**
- `:45` `->where('block_group', 'links')` → `->where('block_group', Block::GROUP_LINKS)`
- `:51-52` skeleton `'block_group' => 'links', 'block_type' => 'link',` → constants
- `:78` `if ($linkBlock->block_group !== 'links' || $linkBlock->block_type !== 'link') {` → `if ($linkBlock->block_group !== Block::GROUP_LINKS || $linkBlock->block_type !== Block::TYPE_LINK) {`
- `:95` identical edit to `:78`.
- `:116-117` `->where('block_group', 'links')->where('block_type', 'link')` → constants

**`app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php`** — replace every bare `'sections'` group literal with `Block::GROUP_SECTIONS` (lines `:129`, `:169`, `:180`, `:251`, `:278`, `:328`, `:344`). The `block_type` values come from the validated `$blockType` variable (validated against `config('partna.section_block_types')` at `:35`/`:119`/`:268`) — leave those config reads as-is.

**`app/Http/Controllers/Api/Staff/UserSiteManagement/StaffSectionManagementController.php`** — replace every bare `'sections'` group literal with `Block::GROUP_SECTIONS` (lines `:33`, `:58`, `:69`, `:123`, `:144`). `block_type` from `$blockType` as above.

> Out of scope for this PR (flag as follow-ups, not in the finding's evidence): `PublicEnquiryController.php:76-77` (`'sections'`/`'contact'`) and `app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:125-126` (raw `whereRaw("LOWER(...) = 'links'/'link'")` — needs care, keep as-is).

---

### FOUND-10 — the visibility registry

#### `app/Services/User/Visibility/SectionVisibilityContract.php` (new)

```php
<?php

namespace App\Services\User\Visibility;

use App\Models\Core\Site\Block;
use Illuminate\Database\Query\Builder;

// One section type's visibility rule. Registered in SectionVisibilityRegistry,
// keyed by block_type. Modelled on the PlatformRegistry/PlatformDescriptor spine:
// adding a section type = one impl + one register() line, no edits to the
// orchestrating SectionVisibilityService.
interface SectionVisibilityContract
{
    /**
     * The block_type this rule governs (e.g. 'gallery'). Must be one of the
     * 'sections' entries in config('partna.block_types').
     */
    public function blockType(): string;

    /**
     * EXISTS subqueries this rule needs, keyed by context alias. Each Builder is
     * wrapped in `exists (...)` and bundled into ONE SELECT round-trip by the
     * service. Return [] for types whose requirement lives entirely in the
     * block's own settings (countdown, contact).
     *
     * @return array<string, Builder>
     */
    public function contextSubqueries(string $userId, string $siteId): array;

    /**
     * Resolve [canBeVisible, ?reason] for one block against the precomputed context.
     *
     * @param  array<string, bool|null>  $context  Resolved EXISTS results, keyed by alias.
     * @param  array<string, mixed>|null  $pendingSettings  Unsaved settings merged over the
     *                                                       block's stored settings (single-check
     *                                                       path only; null in the batch path).
     * @return array{0: bool, 1: ?string}
     */
    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array;
}
```

#### `app/Services/User/Visibility/SectionVisibilityRegistry.php` (new)

```php
<?php

namespace App\Services\User\Visibility;

// Single source of truth for which section types have a visibility rule. Bound as
// a singleton in SectionVisibilityServiceProvider. Mirrors PlatformRegistry.
class SectionVisibilityRegistry
{
    /** @var array<string, SectionVisibilityContract> */
    private array $rules = [];

    public function register(SectionVisibilityContract $rule): self
    {
        $this->rules[$rule->blockType()] = $rule;

        return $this;
    }

    public function get(string $blockType): ?SectionVisibilityContract
    {
        return $this->rules[$blockType] ?? null;
    }

    /** @return array<string, SectionVisibilityContract> */
    public function all(): array
    {
        return $this->rules;
    }
}
```

#### Rule impls — `app/Services/User/Visibility/Rules/*.php` (new, one file each)

**`GalleryVisibility.php`**
```php
<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\SiteMedia;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Gallery is publishable once the site has at least one active gallery image.
class GalleryVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'gallery';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_gallery_image' => SiteMedia::query()
                ->select(DB::raw('1'))
                ->where('site_id', $siteId)
                ->where('pool', SiteMedia::POOL_GALLERY)
                ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_gallery_image'] ?? false)
            ? [true, null]
            : [false, 'Gallery section requires at least 1 uploaded image.'];
    }
}
```

**`DocumentsVisibility.php`**
```php
<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\SiteMedia;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Documents is publishable once the site has at least one active document.
class DocumentsVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'documents';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_document' => SiteMedia::query()
                ->select(DB::raw('1'))
                ->where('site_id', $siteId)
                ->where('pool', SiteMedia::POOL_DOCUMENTS)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_document'] ?? false)
            ? [true, null]
            : [false, 'Documents section requires an uploaded document.'];
    }
}
```

**`ServicesVisibility.php`**
```php
<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\User\Service;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Services & Pricing is publishable once there is at least one active, non-deleted
// service with a title and a price > 0 — the "valid enough to show publicly" bar.
class ServicesVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'services';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_priced_service' => Service::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereNotNull('title')
                ->where('title', '!=', '')
                ->where('price_cents', '>', 0)
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_priced_service'] ?? false)
            ? [true, null]
            : [false, 'Services section requires at least 1 service with a title and price.'];
    }
}
```

**`BookingVisibility.php`** — the tricky one (two bundled subqueries + dropped-integration constant + legacy loaded-block fallback)
```php
<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\User\Service;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Booking is publishable when there is at least one active service AND a booking
// destination (a links-group block tagged category='booking', or a legacy
// booking_url stored on the booking section block itself). Smart-booking
// (Square/Fresha integration) was dropped — a platform integration never
// satisfies booking on its own.
class BookingVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'booking';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            // Gating requirement: at least one active service.
            'has_active_service' => Service::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->getQuery(),

            // Current "has a booking destination" path: a links-group block with
            // settings.category = 'booking'. Portable JSON arrow (pgsql + sqlite).
            'has_booking_link_block' => Block::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->where('block_group', Block::GROUP_LINKS)
                ->where('settings->category', 'booking')
                ->whereNull('deleted_at')
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        if (! ($context['has_active_service'] ?? false)) {
            return [false, 'Booking section requires at least 1 active service.'];
        }

        // Smart-booking (Square/Fresha) dropped → integration always false; the
        // only remaining pass conditions are a booking link block or a legacy url.
        if ($context['has_booking_link_block'] ?? false) {
            return [true, null];
        }

        // Legacy fallback: booking_url stored on the booking section block itself.
        // Read from the loaded block (no DB hit). Single-check path: the
        // orchestrator loads the live section row (or a transient skeleton with
        // empty settings); batch path: the already-loaded block.
        $settings = is_array($block->settings) ? $block->settings : [];
        $url = data_get($settings, 'booking_url');
        if (is_string($url) && trim($url) !== '') {
            return [true, null];
        }

        return [false, 'Booking section requires a booking link or booking integration.'];
    }
}
```

> Behaviour note: today the single-check path (`checkBookingRequirements`) ran a separate DB query (with a pgsql-only `BTRIM` whereRaw) to find a booking section block with a non-empty `booking_url`; the batch path read the loaded block. This impl unifies both on the **loaded block's** `settings.booking_url` (the orchestrator loads it — see `loadSectionBlock`). One site has at most one booking section block (partial unique index `blocks_sections_site_group_type_uq`), so the result is identical, with one fewer query and no driver-specific SQL. `pendingSettings` is intentionally ignored for booking (matching today — the single-check path never consulted pending settings for `booking_url`).

**`CredentialsVisibility.php`** — **PR3 table-based** (see cross-PR note)
```php
<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\User\UserCredential; // introduced by PR3 (FOUND-5)
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Credentials is publishable once there is at least one credential with a
// non-empty title. PR3 (FOUND-5) moved credentials from core.users.about JSONB to
// the core.user_credentials child table — this reads the table, not the JSONB.
class CredentialsVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'credentials';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_credential' => UserCredential::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->whereNotNull('title')
                ->where('title', '<>', '')
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_credential'] ?? false)
            ? [true, null]
            : [false, 'Credentials section requires at least 1 credential with a title.'];
    }
}
```

**`ExperienceVisibility.php`** — **PR3 table-based**
```php
<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\User\UserExperience; // introduced by PR3 (FOUND-5)
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Experience is publishable once there is at least one entry with a non-empty
// role. PR3 (FOUND-5) moved experience from core.users.about JSONB to the
// core.user_experience child table — this reads the table, not the JSONB.
class ExperienceVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'experience';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_experience' => UserExperience::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->whereNotNull('role')
                ->where('role', '<>', '')
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_experience'] ?? false)
            ? [true, null]
            : [false, 'Experience section requires at least 1 entry with a role.'];
    }
}
```

**`PublicContactVisibility.php`** — predicate unchanged (already portable; not touched by PR3)
```php
<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\User\User;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Public contact info goes live once at least one of the opt-in fields
// (public_contact_number / public_contact_email) is non-empty.
class PublicContactVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'public_contact';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_public_contact' => User::query()
                ->select(DB::raw('1'))
                ->where('id', $userId)
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->whereRaw("COALESCE(public_contact_number, '') <> ''")
                        ->orWhereRaw("COALESCE(public_contact_email, '') <> ''");
                })
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_public_contact'] ?? false)
            ? [true, null]
            : [false, 'Public contact info requires a phone number or email before it can go live.'];
    }
}
```

**`WorkplaceVisibility.php`** — **PR3 table-based + name-OR-address (FOUND-4, no behavior change)**
```php
<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Workplace; // introduced by PR3 (FOUND-4)
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Workplace goes live once the site's workplace card has a non-empty name OR
// address. PR3 (FOUND-4) moved the card from site.sites.settings.workplace JSONB
// to the site.workplaces table (1:1 with sites); the name-OR-address predicate is
// preserved (no behavior change).
class WorkplaceVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'workplace';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_workplace' => Workplace::query()
                ->select(DB::raw('1'))
                ->where('site_id', $siteId)
                ->where(function ($q) {
                    $q->where(fn ($n) => $n->whereNotNull('name')->where('name', '<>', ''))
                        ->orWhere(fn ($a) => $a->whereNotNull('address')->where('address', '<>', ''));
                })
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_workplace'] ?? false)
            ? [true, null]
            : [false, 'Workplace section requires a name or address before it can go live.'];
    }
}
```

> Message note: FOUND-4 is CONFIRMED as name-OR-address (no behavior change), so the predicate matches the existing user-facing message ("name or address") verbatim — golden master preserved, no reason-string change anywhere.

**`CountdownVisibility.php`** — settings-only (no subquery; uses `pendingSettings`)
```php
<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Services\User\Visibility\SectionVisibilityContract;
use Carbon\CarbonImmutable;

// Countdown is publishable when it has both a drop_time and an expiry_time, with
// expiry strictly after drop AND not already past. The requirement lives in the
// block's own settings — no DB lookup. The single-check (first-publish) path
// passes the incoming payload as $pendingSettings so timeline + live arriving in
// the same request see the pending values; the batch path passes null.
class CountdownVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'countdown';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        $stored = is_array($block->settings) ? $block->settings : [];
        $settings = $pendingSettings !== null
            ? array_replace_recursive($stored, $pendingSettings)
            : $stored;

        $drop = data_get($settings, 'timeline.drop_time');
        $expiry = data_get($settings, 'timeline.expiry_time');

        if (! is_string($drop) || $drop === '') {
            return [false, 'Countdown section requires a drop time before it can go live.'];
        }

        if (! is_string($expiry) || $expiry === '') {
            return [false, 'Countdown section requires an expiry time before it can go live.'];
        }

        try {
            $dropTs = CarbonImmutable::parse($drop);
            $expiryTs = CarbonImmutable::parse($expiry);
        } catch (\Throwable) {
            return [false, 'Countdown section has an invalid drop time or expiry time.'];
        }

        if ($expiryTs->lessThanOrEqualTo($dropTs)) {
            return [false, 'Countdown expiry time must be after the drop time.'];
        }

        if ($expiryTs->isPast()) {
            return [false, 'Countdown expiry time is already in the past.'];
        }

        return [true, null];
    }
}
```

**`ContactVisibility.php`** — settings-only (no subquery; uses `pendingSettings`)
```php
<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Services\User\Visibility\SectionVisibilityContract;

// Contact is publishable when it has a non-empty, valid notification_email in its
// settings. Like countdown, the requirement lives in the block's own payload — the
// single-check path passes the incoming settings as $pendingSettings to cover
// first-publish (config + live arrive together); the batch path passes null.
class ContactVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'contact';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        $stored = is_array($block->settings) ? $block->settings : [];
        $settings = $pendingSettings !== null
            ? array_replace_recursive($stored, $pendingSettings)
            : $stored;

        $email = data_get($settings, 'notification_email');
        $email = is_string($email) ? trim($email) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [false, 'Contact section requires a notification email before it can go live.'];
        }

        return [true, null];
    }
}
```

#### `app/Providers/SectionVisibilityServiceProvider.php` (new)

```php
<?php

namespace App\Providers;

use App\Services\User\Visibility\Rules\BookingVisibility;
use App\Services\User\Visibility\Rules\ContactVisibility;
use App\Services\User\Visibility\Rules\CountdownVisibility;
use App\Services\User\Visibility\Rules\CredentialsVisibility;
use App\Services\User\Visibility\Rules\DocumentsVisibility;
use App\Services\User\Visibility\Rules\ExperienceVisibility;
use App\Services\User\Visibility\Rules\GalleryVisibility;
use App\Services\User\Visibility\Rules\PublicContactVisibility;
use App\Services\User\Visibility\Rules\ServicesVisibility;
use App\Services\User\Visibility\Rules\WorkplaceVisibility;
use App\Services\User\Visibility\SectionVisibilityRegistry;
use Illuminate\Support\ServiceProvider;

// Binds the SectionVisibilityRegistry singleton and registers every section type's
// visibility rule. Single place a section visibility rule is declared. Mirrors
// PlatformRegistryServiceProvider. Section types with no data requirement
// (contacts_collection, sitepage_analytics, barbershop_info, newsletter, bio) get
// no rule and default to visible.
class SectionVisibilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SectionVisibilityRegistry::class, function () {
            $r = new SectionVisibilityRegistry;

            $r->register(new GalleryVisibility);
            $r->register(new DocumentsVisibility);
            $r->register(new ServicesVisibility);
            $r->register(new BookingVisibility);
            $r->register(new CredentialsVisibility);
            $r->register(new ExperienceVisibility);
            $r->register(new PublicContactVisibility);
            $r->register(new WorkplaceVisibility);
            $r->register(new CountdownVisibility);
            $r->register(new ContactVisibility);

            return $r;
        });
    }
}
```

#### `bootstrap/providers.php`

```php
<?php

use App\Providers\AppServiceProvider;
use App\Providers\BotProtectionServiceProvider;
use App\Providers\DatabaseServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\PlatformRegistryServiceProvider;
use App\Providers\SectionVisibilityServiceProvider;

return [
    AppServiceProvider::class,
    DatabaseServiceProvider::class,
    EventServiceProvider::class,
    BotProtectionServiceProvider::class,
    PlatformRegistryServiceProvider::class,
    SectionVisibilityServiceProvider::class,
];
```

#### `app/Services/User/SectionVisibilityService.php` — full rewrite (delegates to the registry)

Replace the entire file body with:

```php
<?php

namespace App\Services\User;

use App\Models\Core\Site\Block;
use App\Services\User\Visibility\SectionVisibilityRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Orchestrates section-visibility checks. Each section type's rule lives in a
// SectionVisibilityContract impl registered in SectionVisibilityRegistry; this
// service loads the shared EXISTS context in one round-trip and delegates the
// per-block decision to the matching rule. Adding a section type touches only the
// registry + a new rule impl, never this file.
class SectionVisibilityService
{
    public function __construct(
        private readonly SectionVisibilityRegistry $registry,
    ) {}

    /**
     * Check if a section type meets its visibility requirements (single-block path).
     *
     * @param  array<string, mixed>|null  $pendingSettings  Incoming-but-not-yet-persisted settings,
     *                                                       merged over stored for types whose
     *                                                       requirement lives in their own payload
     *                                                       (countdown, contact). Others ignore it.
     * @return array{0: bool, 1: ?string} [canBeVisible, reason]
     */
    public function checkVisibilityRequirements(
        string $userId,
        string $siteId,
        string $blockType,
        ?array $pendingSettings = null,
    ): array {
        $rule = $this->registry->get($blockType);
        if ($rule === null) {
            return [true, null];
        }

        $context = $this->buildContext($userId, $siteId, [$blockType]);
        $block = $this->loadSectionBlock($userId, $siteId, $blockType);

        return $rule->resolve($block, $context, $pendingSettings);
    }

    /**
     * Batch-evaluate visibility for a set of already-loaded section blocks.
     *
     * Loads each visibility data-source at most once (and only when at least one
     * section in the input requires it) in a single SELECT, then resolves every
     * block against that shared context.
     *
     * @param  iterable<Block>  $sectionBlocks  Already-loaded blocks; their stored settings are
     *                                          used for countdown/contact/booking (no DB call).
     * @return array<string, array{0: bool, 1: ?string}> Map of block_type → [canBeVisible, reason]
     */
    public function batchCheck(string $userId, string $siteId, iterable $sectionBlocks): array
    {
        $blocks = $sectionBlocks instanceof Collection
            ? $sectionBlocks
            : Collection::make($sectionBlocks);

        $types = $blocks->pluck('block_type')
            ->filter(fn ($t) => is_string($t))
            ->unique()
            ->values()
            ->all();

        $context = $this->buildContext($userId, $siteId, $types);

        $byType = [];
        foreach ($blocks as $block) {
            $type = (string) ($block->block_type ?? '');
            if ($type === '' || array_key_exists($type, $byType)) {
                continue;
            }

            $rule = $this->registry->get($type);
            $byType[$type] = $rule === null
                ? [true, null]
                : $rule->resolve($block, $context, null);
        }

        return $byType;
    }

    /**
     * Re-evaluate and persist is_enabled for a section block based on its requirements.
     * is_active (the professional's show/hide preference) is never touched.
     */
    public function reevaluateEnabled(string $userId, string $siteId, string $blockType): void
    {
        $block = Block::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->where('block_group', Block::GROUP_SECTIONS)
            ->where('block_type', $blockType)
            ->first();

        if (! $block) {
            return;
        }

        try {
            $rule = $this->registry->get($blockType);
            if ($rule === null) {
                $canBeEnabled = true;
            } else {
                // Reuse the already-loaded block; no pending settings (post-save).
                $context = $this->buildContext($userId, $siteId, [$blockType]);
                [$canBeEnabled] = $rule->resolve($block, $context, null);
            }

            if ((bool) $block->is_enabled !== $canBeEnabled) {
                $block->is_enabled = $canBeEnabled;
                $block->save();
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Section is_enabled reevaluation failed', [
                'user_id' => $userId,
                'site_id' => $siteId,
                'block_type' => $blockType,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the visibility data context for the present section types in a single
     * round-trip. Each rule contributes zero or more EXISTS subqueries (keyed by a
     * context alias); they are bundled into ONE SELECT, so we pay one network
     * round-trip instead of N. Rules whose requirement lives in the block's own
     * settings (countdown, contact) contribute no subquery. Bindings accumulate in
     * select-clause order, matching the placeholder order in the compiled SQL.
     *
     * @param  array<int, string>  $presentTypes
     * @return array<string, bool|null> alias => exists-result
     */
    private function buildContext(string $userId, string $siteId, array $presentTypes): array
    {
        $subqueries = [];
        foreach ($presentTypes as $type) {
            $rule = $this->registry->get($type);
            if ($rule === null) {
                continue;
            }
            foreach ($rule->contextSubqueries($userId, $siteId) as $alias => $builder) {
                $subqueries[$alias] = $builder;
            }
        }

        if (empty($subqueries)) {
            return [];
        }

        $query = DB::query();
        foreach ($subqueries as $alias => $sub) {
            $query->selectRaw('exists ('.$sub->toSql().') as '.$alias, $sub->getBindings());
        }

        $row = $query->first();
        $context = [];
        if ($row !== null) {
            foreach ($subqueries as $alias => $_) {
                $context[$alias] = isset($row->$alias) ? (bool) $row->$alias : null;
            }
        }

        return $context;
    }

    /**
     * Load the section block for (user, site, type), or a transient skeleton with
     * empty settings when none exists yet (first-publish path). Rules that read the
     * block's own settings (booking legacy url, countdown, contact) operate on this;
     * data-source rules ignore it.
     */
    private function loadSectionBlock(string $userId, string $siteId, string $blockType): Block
    {
        $block = Block::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->where('block_group', Block::GROUP_SECTIONS)
            ->where('block_type', $blockType)
            ->first();

        return $block ?? new Block([
            'user_id' => $userId,
            'site_id' => $siteId,
            'block_group' => Block::GROUP_SECTIONS,
            'block_type' => $blockType,
            'settings' => [],
        ]);
    }
}
```

**Behaviour-parity reasoning (why this is byte-equivalent to the old service):**
- `batchCheck`: identical bundled-SELECT construction (`DB::query()->selectRaw('exists (...) as alias', bindings)`), so query count and SQL shape are preserved. For the 6 requirement-bearing types in `BatchCheckQueryCountTest` (gallery, documents, services, booking, countdown, contact) the rules contribute 5 subqueries (gallery 1, documents 1, services 1, booking 2, countdown/contact 0) → exactly one SELECT, ≥5 `exists (` substrings — test stays green.
- Single-check `checkVisibilityRequirements`: produces the same boolean results as the old per-type helpers. For countdown/contact the orchestrator loads the block (or a skeleton with empty settings) and the rule merges `$pendingSettings` — exactly the old `checkCountdownRequirements`/`checkContactRequirements`. For booking, the loaded block carries `settings.booking_url`, replacing the old separate legacy query (same data, one site → one booking block).
- `reevaluateEnabled`: same public behaviour; reuses the loaded block instead of re-querying it inside the (now removed) `checkVisibilityRequirements` block-load → one fewer query, identical decision.
- No external caller signatures change; all callers resolve the service via the container (`app(SectionVisibilityService::class)` / constructor injection), so the new constructor dependency is auto-wired. Mocks (`mock(SectionVisibilityService::class)`, `Mockery::spy`) are unaffected.

**House-rule checks:** No `ShouldQueue` jobs added → `$backoff` rule N/A. No new controller authorization paths (literal swaps only; existing `abort_unless(... 404)` retained). No raw `Cache::` use. No Laravel migration (raw SQL only).

---

### TDD tasks

> Run all commands from the real checkout root (`/Users/joshuahunter/Herd/Side Street/backend`), not a worktree (feature tests are unreliable under `.claude/worktrees/`). After config changes, `php artisan config:clear` if a test reads stale config.

#### Task 1 — `block_types` config map + `Block` constants (config-guard test)

1. Add the failing test `tests/Feature/Site/BlockTypesConfigTest.php`:
```php
<?php

use App\Models\Core\Site\Block;

it('exposes block_types keyed by group, matching the CHECK enum', function () {
    $sections = [
        'gallery', 'services', 'booking', 'contacts_collection', 'sitepage_analytics',
        'barbershop_info', 'documents', 'newsletter', 'countdown', 'contact',
        'public_contact', 'workplace', 'credentials', 'experience', 'bio',
    ];

    expect(config('partna.block_types.links'))->toBe(['link']);
    expect(config('partna.block_types.sections'))->toBe($sections);
});

it('derives section_block_types from block_types so the two never drift', function () {
    expect(config('partna.section_block_types'))
        ->toBe(config('partna.block_types.sections'));
});

it('Block group/type constants match the config keys', function () {
    expect(Block::GROUP_LINKS)->toBe('links');
    expect(Block::GROUP_SECTIONS)->toBe('sections');
    expect(Block::TYPE_LINK)->toBe('link');
    expect(array_keys(config('partna.block_types')))
        ->toBe([Block::GROUP_LINKS, Block::GROUP_SECTIONS]);
});
```
2. Run-fail: `php artisan config:clear && ./vendor/bin/pest tests/Feature/Site/BlockTypesConfigTest.php` → fails (`config('partna.block_types')` is null; `Block::GROUP_LINKS` undefined → Error).
3. Implement: `config/partna.php` (`$blockTypes` + `block_types` + derive `section_block_types`); `Block` constants.
4. Run-pass: `php artisan config:clear && ./vendor/bin/pest tests/Feature/Site/BlockTypesConfigTest.php` → 3 passed.
5. `php artisan pint --dirty`
6. `git commit -m "feat(blocks): block_types config map + Block group/type constants (FOUND-14)"`

#### Task 2 — controllers read constants

1. (Guarded by existing controller tests; no new test needed.) Edit the four controllers per the table above.
2. Run-pass (existing golden masters): `./vendor/bin/pest tests/Feature/Site tests/Feature/Contact tests/Feature/Countdown tests/Feature/Documents tests/Feature/Newsletter` → all green.
3. `php artisan pint --dirty`
4. `git commit -m "refactor(blocks): controllers use Block group/type constants, not literals (FOUND-14)"`

#### Task 3 — pair-CHECK migration

1. Add `supabase/migrations/20260701000000_blocks_group_type_pair_check.sql` (full SQL above).
2. Update `tests/Feature/Database/CheckConstraintsTest.php:52-57`:
```php
it('blocks_group_type_check constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('site', 'blocks', 'blocks_group_type_check');
});
```
3. Run-pass (skips on SQLite, proves no regression): `./vendor/bin/pest tests/Feature/Database/CheckConstraintsTest.php` → green (block test skipped).
4. **DEV-SUPABASE VERIFICATION** (gated DB step — apply + the three invalid/valid inserts above; confirm 23514 rejections + `convalidated = t`).
5. `php artisan pint --dirty` (test file only)
6. `git commit -m "fix(blocks): replace two independent CHECKs with one group/type pair-CHECK (FOUND-14)"`

#### Task 4 — SectionVisibility registry + rules + provider (not yet wired into the service)

1. Add the failing test `tests/Feature/Site/SectionVisibilityRegistryTest.php`:
```php
<?php

use App\Services\User\Visibility\SectionVisibilityRegistry;

it('registers a rule for every requirement-bearing section type', function () {
    $registry = app(SectionVisibilityRegistry::class);

    $expected = [
        'gallery', 'documents', 'services', 'booking', 'credentials',
        'experience', 'public_contact', 'workplace', 'countdown', 'contact',
    ];
    foreach ($expected as $type) {
        expect($registry->get($type))->not->toBeNull("missing rule: {$type}")
            ->and($registry->get($type)->blockType())->toBe($type);
    }
});

it('returns null for requirement-free section types', function () {
    $registry = app(SectionVisibilityRegistry::class);

    foreach (['contacts_collection', 'sitepage_analytics', 'barbershop_info', 'newsletter', 'bio'] as $type) {
        expect($registry->get($type))->toBeNull();
    }
});
```
2. Run-fail: `./vendor/bin/pest tests/Feature/Site/SectionVisibilityRegistryTest.php` → fails (registry class / binding absent).
3. Implement: contract, registry, 10 rule impls, `SectionVisibilityServiceProvider`, register in `bootstrap/providers.php`.
   - **Premise check before writing `CredentialsVisibility`/`ExperienceVisibility`/`WorkplaceVisibility`:** confirm PR3 has landed and that `App\Models\Core\User\UserCredential`, `App\Models\Core\User\UserExperience`, `App\Models\Core\Site\Workplace` exist with the columns used (`user_id` + `title`/`role`; `site_id` + `name`). If PR3 has NOT yet merged, STOP and reconcile with the assembler (see cross-PR note) — do not fall back to the JSONB predicates, which PR3 is removing.
4. Run-pass: `./vendor/bin/pest tests/Feature/Site/SectionVisibilityRegistryTest.php` → 2 passed.
5. `php artisan pint --dirty`
6. `git commit -m "feat(visibility): SectionVisibilityContract + registry + per-type rules (FOUND-10)"`

#### Task 5 — collapse `SectionVisibilityService` onto the registry

1. (Guarded by existing golden masters — the parity test set below.) Rewrite `SectionVisibilityService` per the full code above.
2. Run-pass (the parity gate): 
```
./vendor/bin/pest \
  tests/Feature/Site/BatchCheckQueryCountTest.php \
  tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php \
  tests/Feature/Contact/ContactSectionBehaviorTest.php \
  tests/Feature/Countdown/CountdownSectionBehaviorTest.php \
  tests/Feature/Documents/DocumentConfigTest.php \
  tests/Feature/Site/SectionBlockUpsertSortOrderTest.php \
  tests/Feature/Core/ServiceObserverSingleSiteBustTest.php \
  tests/Feature/User/UserObserverHandleChangeTest.php
```
→ all green (especially `BatchCheckQueryCountTest`: exactly one query, ≥5 `exists (`).
3. `php artisan pint --dirty`
4. `git commit -m "refactor(visibility): SectionVisibilityService delegates to the registry (FOUND-10)"`

---

### Golden-master guard (must stay green)

- `tests/Feature/Site/BatchCheckQueryCountTest.php` — single bundled SELECT, ≥5 `exists (` (the core FOUND-10 perf invariant).
- `tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php` — the 4 booking single-check paths (reject via integration, allow via link block, allow via legacy `booking_url`).
- `tests/Feature/Contact/ContactSectionBehaviorTest.php` + `tests/Feature/Countdown/CountdownSectionBehaviorTest.php` — `pendingSettings` first-publish path for contact/countdown.
- `tests/Feature/Documents/DocumentConfigTest.php`, `tests/Feature/Site/SectionConfigCleanupTest.php`, `tests/Feature/Analytics/TopSectionsExpandedTypesTest.php`, `tests/Feature/Contact/ContactSectionConfigTest.php`, `tests/Feature/Countdown/CountdownSectionConfigTest.php`, `tests/Feature/Newsletter/NewsletterSectionConfigTest.php` — `section_block_types` resolved value unchanged.
- `tests/Feature/Site/SectionBlockInvalidTypeTest.php`, `tests/Feature/Site/SectionBlockUpsertSortOrderTest.php` — `Config::set('partna.section_block_types', ...)` overrides still honoured (literal swaps + derived alias don't break them).
- `tests/Feature/Core/ServiceObserverSingleSiteBustTest.php`, `tests/Feature/User/UserObserverHandleChangeTest.php` — observer `reevaluateEnabled` flows.
- `tests/Feature/Database/CheckConstraintsTest.php` — updated to the new constraint name (Postgres-only; the dev-Supabase verification is the real assertion).

### Final gate

Run the **full** suite from the real checkout (a filtered subset is a false signal — Wave-1 hit a 9-test regression only the full suite caught):
```
composer test
```
Must be fully green before the PR is considered done. Then the fix-flow's independent reviewer + auto-archive run.


---

## PR5 — FOUND-15 — promote `Block.settings` `live_check_enabled`/`category`/`platform` → columns (+ BOTH DB views)

**Goal:** Move the three query-predicate sub-keys (`live_check_enabled`, `category`, `platform`) out of `site.blocks.settings` JSONB into real, typed, indexed columns — `handle` and all display-hint keys stay in JSONB — so cap counts, the live-status poll, and link grouping run on typed/indexed columns instead of JSON scans.

**Architecture:** Delivered as a single PR in **two migration files (expand → contract)**, matching the Group-A precedent. **Expand (Migration 1 / Phase 1):** add the three columns, backfill from `settings`, add the `category` CHECK + a partial index on `live_check_enabled`, switch every *query-predicate* reader (the streaming job, the two cap counts, the cap-remediation command) to the columns, and **dual-write** (writes land on both the columns and the settings keys). No wire contract changes; the two public-read views, the `LiveStatusInjector`, the `LinkBlockResource`, and `SitepageDataResolverService::getLinks` keep reading `settings`, which is still populated — so the change is fully reversible and invisible to both frontends. **Contract (Migration 2 / Phase 2):** strip the three keys from `settings` (scoped `WHERE block_group = 'links'`), `CREATE OR REPLACE` **both** views to emit the three values as top-level block keys, flip the payload readers (injector, resolver, resource) and the write contract (Form Request rules + allowlist) to the columns, and stop dual-writing. Phase 2 is the only step that changes the public/dashboard wire and therefore is **gated on the frontend** shipping its top-level reads.

**Blast radius (files):**
- Migrations (new): `supabase/migrations/20260701000000_promote_block_settings_columns.sql`, `supabase/migrations/20260701000100_strip_block_settings_keys_and_views.sql`
- `app/Models/Core/Site/Block.php` (fillable + casts)
- `config/partna.php` (`link_block_settings_keys` — Phase 2)
- `app/Services/Site/LinkBlockFieldBuilder.php` (write path — both phases)
- `app/Http/Controllers/Api/User/SiteManagement/UserLinkBlockController.php` (custom-update hoist — both phases)
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffLinkBlockManagementController.php` (**DRIFT — not in the design plan; see Premise grounding**)
- `app/Http/Requests/Api/User/Site/StoreLinkBlockRequest.php` + `UpdateLinkBlockRequest.php` (cap queries Phase 1; rules + allowlist Phase 2). Staff subclasses `StaffStoreLinkRequest`/`StaffUpdateLinkRequest` inherit — no separate edit.
- `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php` (Phase 1 query + read)
- `app/Services/Streaming/LiveStatusInjector.php` (Phase 2)
- `app/Services/PublicSite/SitepageDataResolverService.php` — `getLinks` only, `:303-344` (Phase 2)
- `app/Http/Resources/LinkBlockResource.php` (Phase 2)
- `app/Http/Resources/Staff/StaffSiteResource.php` — `BLOCK_ALLOWLIST` (Phase 2; **DRIFT**)
- `app/Console/Commands/EnforcePlatformLinkCapCommand.php` (Phase 1; **DRIFT — not in the design plan**)
- `app/Console/Commands/BackfillSocialLinksCommand.php` (Phase 2 / retire; **DRIFT**)
- **CROSS-PR — PR4's booking `SectionVisibilityContract` impl** (the one emitting `has_booking_link_block`, which filters booking-category link blocks with `->where('settings->category', 'booking')`): Phase 2 promotes `category` to a column, so this predicate MUST switch to `->where('category', 'booking')` **in the same Phase-2 commit** — otherwise booking-section visibility silently breaks the moment `category` is stripped from `settings`. (PR4 ships before PR5; see reconciliation directive #3 in the header.)
- `tests/Pest.php` (`setupBlocksTable`, `createLinkBlockFor`) + the test files named in the test plan
- **Frontend (cross-repo, Phase 2 dependency):** `partna-pages` (public payload `links[].settings.{platform,category,live_check_enabled}` → `links[].{…}`) and `partna-frontend` dashboard (read `LinkBlockResource` top-level; send `live_check_enabled` top-level instead of `settings.live_check_enabled`).

**DECISION (confirm before implementing): dual-write-then-strip (RECOMMENDED — decouples the two-view lockstep + both frontends) vs strip-in-same-migration.** This plan is written around dual-write-then-strip (Phase 1 = expand, Phase 2 = contract). To take strip-in-same-migration instead: concatenate Migration 2 into Migration 1, fold the Phase-2 code edits into the Phase-1 commits, and ship the frontend in the same window. The strip path (Migration 2 + all Phase-2 code) is written out in full below either way — it just moves earlier.

**DECISION (confirm before implementing): add the `category` CHECK (RECOMMENDED) vs leave `category` un-CHECKed.** Stored category is always one of `config('partna.link_categories')` (the Form Request enum-validates it; `'custom'` is only a *read-time* fallback in `getLinks`, never written), so the CHECK is provably safe. `platform` is left **un-CHECKed** by design (large, frequently-extended `social_platforms` registry — a CHECK would force a migration per platform).

**Premise grounding:**
- **FOUND-15 premise HOLDS** against the live tree: expression index `idx_blocks_live_check_enabled` exists (baseline `:776-777`), and all four raw-JSON predicates are present — `CheckStreamingLiveStatusJob:71`, `StoreLinkBlockRequest:155` (category) + `:184` (live_check), `UpdateLinkBlockRequest:143`.
- **Column rename confirmed:** baseline declares `professional_id`; `20260527030000_rename_professional_to_user.sql:40` renamed it to `user_id`. The live `site.blocks` table has `user_id`. The expression index references `settings->>'live_check_enabled'` (no column ref) so it survived the rename untouched.
- **DRIFT — `settings.platform` on a *booking section* is a DIFFERENT field.** `SitepageDataResolverService::getBooking():635-636` reads `$settings['platform']` on `block_group='sections'`, `block_type='booking'` rows (the booking-platform tag, e.g. `fresha`). The promotion is **links-only**: the backfill AND the Phase-2 strip are both scoped `WHERE block_group = 'links'`, so the booking section's `settings.platform` is never touched and `getBooking` is **not** modified. Missing this scope would silently break booking links. (The design plan's `:304-339` reference correctly points at `getLinks`; `:635` is out of scope and must stay so.)
- **DRIFT — two consumers the design plan/audit missed:** `EnforcePlatformLinkCapCommand:92,104` reads `$b->settings['category']` (an *idempotent, re-runnable* remediation — must move to the column in Phase 1 or it silently filters nothing post-strip → cap never enforced), and `BackfillSocialLinksCommand:91-154` reads/writes `settings.platform`/`settings.category` (a one-shot, already-run backfill — retire or repoint in Phase 2).
- **DRIFT — the staff write path was not listed.** `StaffLinkBlockManagementController` persists blocks WITHOUT `LinkBlockFieldBuilder` (store `:50-59` writes `settings` directly; update `:82` does `fill($request->validated())`). It must populate the new columns too, or staff-created links get NULL `category`/`platform`/`live_check_enabled` and are missed by the cap counts (cap bypass) and the streaming job. Included below. Minor behavior improvement to flag: post-Phase-2 a staff custom link with a top-level `category` but no `settings.category` will render with its real category instead of `'custom'`.
- **DRIFT — effort.** The design plan rates FOUND-15 as **M**; with the staff controller, the two console commands, the two-phase expand/contract, and the cross-repo frontend coordination, the real effort is **M–L**.
- **Two read paths confirmed (the byte-identical nuance):**
  - *Path 1 — `public_site_payload` VIEW* → `SiteCacheService::getPublicSitePayload` → `PublicSiteController` → `LiveStatusInjector`. The `links[]` blocks carry raw `settings`. **Phase 2 relocates** `platform`/`category`/`live_check_enabled` from `links[].settings.*` to `links[].*` (top-level) — this is NOT byte-identical and **is** the frontend contract change. `is_live` continues to be injected at `settings.is_live` (unchanged).
  - *Path 2 — `SitepageDataResolverService::getLinks`* → `IndividualProfilePayloadBuilder::buildLinks:254-269` → `/api/public/profiles/{handle}`. Its output rows are **already** `{id,title,url,category,platform}` at top level, so Phase 2 (read column instead of `settings`) is **byte-identical** on the wire.

---

### House-rules checklist for this PR
- DB schema = raw SQL in `supabase/migrations/` only (never a Laravel migration). Filenames `20260701000000…` / `20260701000100…` — **the executing session bumps each to a timestamp later than the then-latest migration before applying** (today's latest is `20260630000000_drop_smart_links.sql`).
- Apply to **dev Supabase `glncumufgaqcmqhzwrxm`** via `supabase db push` / MCP `apply_migration` (the env's `migrate --force` is commented out).
- `CheckStreamingLiveStatusJob` already declares `public int $tries`, `public int $backoff`, `public int $timeout` (`:22-34`) — `JobHygienePolicyTest` stays satisfied; do not remove them.
- No new controller authz: existing `authorizeForUser` / `abort_unless(... 404)` guards are untouched. No raw `Cache::` introduced.
- SQLite enforces **no** CHECK / NOT NULL / partial index — every constraint gets a dev-Supabase verification step (below).

---

## Migration 1 (expand) — `supabase/migrations/20260701000000_promote_block_settings_columns.sql`

```sql
-- =====================================================================
-- FOUND-15 (expand) — promote site.blocks.settings query-predicate keys to columns
-- =====================================================================
-- Adds live_check_enabled / category / platform as real columns on
-- site.blocks, backfills them from the settings JSONB (links blocks only),
-- adds a category CHECK + a partial index on the live_check_enabled column.
--
-- This is the EXPAND half of an expand/contract pair. It does NOT strip the
-- settings keys and does NOT touch the public-read views — the keys remain in
-- JSONB so the views, LiveStatusInjector, LinkBlockResource and getLinks keep
-- working unchanged while code dual-writes. The contract half
-- (20260701000100_strip_block_settings_keys_and_views.sql) strips the keys and
-- rewrites both views once all readers + both frontends are on the columns.
--
-- Pre-beta: site.blocks has zero rows, so the backfill is a no-op now; it is
-- written for prod-shape parity (idempotent, links-scoped).
-- =====================================================================
BEGIN;

ALTER TABLE site.blocks
    ADD COLUMN live_check_enabled boolean NOT NULL DEFAULT false,
    ADD COLUMN category           text,
    ADD COLUMN platform           text;

-- Backfill from JSONB, LINKS ONLY. block_group='sections' rows (e.g. booking
-- sections) keep their own settings.platform untouched — that is a different
-- field, read by SitepageDataResolverService::getBooking.
UPDATE site.blocks
SET live_check_enabled = COALESCE(NULLIF(settings->>'live_check_enabled', '')::boolean, false),
    category           = NULLIF(settings->>'category', ''),
    platform           = NULLIF(settings->>'platform', '')
WHERE block_group = 'links';

-- category CHECK. Enum mirrors config('partna.link_categories'); keep the two
-- in lockstep (adding a category = update this CHECK + the config array).
-- NOT VALID -> VALIDATE so a non-empty prod table is checked without a long lock.
ALTER TABLE site.blocks
    ADD CONSTRAINT blocks_category_check
    CHECK (category IS NULL OR category IN
        ('social','booking','education','content','events','streaming','other'))
    NOT VALID;
ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_category_check;

-- New partial index replacing the role of the expression index. Indexes only
-- the live, enabled links (the exact rows CheckStreamingLiveStatusJob scans).
-- The old expression index idx_blocks_live_check_enabled is left in place here
-- and dropped in the contract migration (avoids a name clash; harmless meanwhile).
CREATE INDEX idx_blocks_live_check_enabled_active ON site.blocks (site_id)
    WHERE live_check_enabled AND block_group = 'links'
          AND deleted_at IS NULL AND is_active = true;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- DROP INDEX IF EXISTS site.idx_blocks_live_check_enabled_active;
-- ALTER TABLE site.blocks DROP CONSTRAINT IF EXISTS blocks_category_check;
-- ALTER TABLE site.blocks
--     DROP COLUMN IF EXISTS platform,
--     DROP COLUMN IF EXISTS category,
--     DROP COLUMN IF EXISTS live_check_enabled;
-- COMMIT;
```

## Migration 2 (contract) — `supabase/migrations/20260701000100_strip_block_settings_keys_and_views.sql`

> **GATING STEP.** The strip and BOTH `CREATE OR REPLACE VIEW` statements ship in this ONE migration. Stripping the keys without rewriting a view = silent NULL data-loss on the public payload (blank live status, lost platform/category) — no exception, only golden-master payload assertions catch it. `CREATE OR REPLACE VIEW` keeps each view's column list (`blocks` / `payload` stay `jsonb`), so grants are preserved (no re-grant needed); we only change the `jsonb_build_object` internals. The `sections` array and `all_site_data`'s booking-section settings are NOT modified. Ship Phase-2 code + both frontends in the same window as this migration.

```sql
-- =====================================================================
-- FOUND-15 (contract) — strip promoted keys from settings + rewrite both views
-- =====================================================================
-- Removes live_check_enabled / category / platform from site.blocks.settings
-- (LINKS ONLY), rewrites the two public-read views to emit them as top-level
-- block keys sourced from the new columns, and drops the now-dead expression
-- index. Run ONLY after every PHP reader and both frontends read the columns.
-- =====================================================================
BEGIN;

-- 1. Strip the three keys from settings — LINKS ONLY (booking sections keep
--    their own settings.platform).
UPDATE site.blocks
SET settings = (settings - 'live_check_enabled' - 'category' - 'platform')
WHERE block_group = 'links'
  AND (settings ? 'live_check_enabled' OR settings ? 'category' OR settings ? 'platform');

-- 2. Drop the dead expression index (replaced by idx_blocks_live_check_enabled_active).
DROP INDEX IF EXISTS site.idx_blocks_live_check_enabled;

-- 3a. all_site_data (staff ops view) — add platform/category/live_check_enabled
--     to each block. b.platform/b.category/b.live_check_enabled are NULL/false
--     for section rows, which is correct. Body is identical to the
--     20260527070000 definition except for the three added keys.
CREATE OR REPLACE VIEW site.all_site_data AS
SELECT
    s.id AS site_id,
    s.user_id,
    s.subdomain,
    s.is_published,
    s.skeleton_id,
    s.settings AS site_settings,
    s.created_at AS site_created_at,
    s.updated_at AS site_updated_at,
    p.handle,
    p.display_name,
    p.bio,
    p.location_street_address,
    p.location_city,
    p.location_state,
    p.location_postcode,
    p.location_country,
    COALESCE(
        jsonb_agg(
            jsonb_build_object(
                'id', b.id,
                'site_id', b.site_id,
                'user_id', b.user_id,
                'block_type', b.block_type,
                'block_group', b.block_group,
                'title', b.title,
                'url', b.url,
                'icon_key', b.icon_key,
                'sort_order', b.sort_order,
                'is_active', b.is_active,
                'settings', b.settings,
                'platform', b.platform,
                'category', b.category,
                'live_check_enabled', b.live_check_enabled,
                'created_at', b.created_at,
                'updated_at', b.updated_at
            )
            ORDER BY b.sort_order
        ) FILTER (WHERE b.id IS NOT NULL),
        '[]'::jsonb
    ) AS blocks,
    p.account_type
FROM site.sites s
    JOIN core.users p ON p.id = s.user_id
    LEFT JOIN site.blocks b ON b.site_id = s.id
GROUP BY s.id, p.id;

-- 3b. public_site_payload — add the three keys to the LINKS array only. The
--     sections array is unchanged (booking sections read settings.platform).
--     Everything else is byte-identical to the 20260527070000 definition.
CREATE OR REPLACE VIEW site.public_site_payload AS
SELECT
    s.id AS site_id,
    s.user_id,
    s.subdomain,
    jsonb_build_object(
        'site', jsonb_build_object(
            'id', s.id,
            'subdomain', s.subdomain,
            'settings', s.settings,
            'is_published', s.is_published,
            'skeleton_id', s.skeleton_id,
            'gallery', COALESCE((
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', sm.id,
                        'alt_text', sm.alt_text,
                        'caption', sm.caption,
                        'sort_order', sm.sort_order,
                        'variants', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'webp'::text
                        ), '{}'::jsonb)
                    )
                    ORDER BY sm.sort_order, sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'gallery'::text
                    AND sm.media_type::text = 'image'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
            ), '[]'::jsonb),
            'content_images', COALESCE((
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', sm.id,
                        'alt_text', sm.alt_text,
                        'caption', sm.caption,
                        'sort_order', sm.sort_order,
                        'variants', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'webp'::text
                        ), '{}'::jsonb)
                    )
                    ORDER BY sm.sort_order, sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'content'::text
                    AND sm.media_type::text = 'image'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
            ), '[]'::jsonb),
            'gallery_videos', COALESCE((
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', sm.id,
                        'alt_text', sm.alt_text,
                        'caption', sm.caption,
                        'sort_order', sm.sort_order,
                        'media_type', sm.media_type,
                        'processing_state', sm.processing_state,
                        'duration_ms', sm.duration_ms,
                        'poster', sm.poster_path,
                        'variants', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'mp4'::text
                        ), '{}'::jsonb),
                        'streams', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'hls_playlist'::text
                        ), '{}'::jsonb)
                    )
                    ORDER BY sm.sort_order, sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'gallery'::text
                    AND sm.media_type::text = 'video'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
            ), '[]'::jsonb),
            'content_videos', COALESCE((
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', sm.id,
                        'alt_text', sm.alt_text,
                        'caption', sm.caption,
                        'sort_order', sm.sort_order,
                        'media_type', sm.media_type,
                        'processing_state', sm.processing_state,
                        'duration_ms', sm.duration_ms,
                        'poster', sm.poster_path,
                        'variants', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'mp4'::text
                        ), '{}'::jsonb),
                        'streams', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'hls_playlist'::text
                        ), '{}'::jsonb)
                    )
                    ORDER BY sm.sort_order, sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'content'::text
                    AND sm.media_type::text = 'video'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
            ), '[]'::jsonb),
            'document', (
                SELECT jsonb_build_object(
                    'id', sm.id,
                    'title', sm.alt_text,
                    'caption', sm.caption,
                    'original_mime', sm.original_mime,
                    'original_size_bytes', sm.original_size_bytes,
                    'original_filename', sm.original_filename,
                    'preview_url', sm.path,
                    'created_at', sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'documents'::text
                    AND sm.media_type::text = 'document'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
                LIMIT 1
            )
        ),
        'professional', jsonb_build_object(
            'id', p.id,
            'handle', p.handle,
            'display_name', p.display_name,
            'bio', p.bio,
            'country_code', p.country_code,
            'timezone', p.timezone,
            'public_contact_number', p.public_contact_number,
            'public_contact_email', p.public_contact_email
        ),
        'skeleton_id', s.skeleton_id,
        'links', COALESCE((
            SELECT jsonb_agg(
                jsonb_build_object(
                    'id', b.id,
                    'block_type', b.block_type,
                    'title', b.title,
                    'url', b.url,
                    'icon_key', b.icon_key,
                    'sort_order', b.sort_order,
                    'settings', b.settings,
                    'platform', b.platform,
                    'category', b.category,
                    'live_check_enabled', b.live_check_enabled
                )
                ORDER BY b.sort_order, b.created_at
            )
            FROM site.blocks b
            WHERE b.site_id = s.id
                AND b.block_group = 'links'::text
                AND b.is_active = true
                AND b.deleted_at IS NULL
        ), '[]'::jsonb),
        'sections', COALESCE((
            SELECT jsonb_agg(
                jsonb_build_object(
                    'id', b.id,
                    'block_type', b.block_type,
                    'title', b.title,
                    'url', b.url,
                    'icon_key', b.icon_key,
                    'sort_order', b.sort_order,
                    'is_enabled', b.is_enabled,
                    'is_active', b.is_active,
                    'settings', b.settings
                )
                ORDER BY b.sort_order, b.created_at
            )
            FROM site.blocks b
            WHERE b.site_id = s.id
                AND b.block_group = 'sections'::text
                AND b.is_enabled = true
                AND b.is_active = true
                AND b.deleted_at IS NULL
        ), '[]'::jsonb),
        'services', COALESCE((
            SELECT jsonb_agg(
                jsonb_build_object(
                    'id', sv.id,
                    'title', sv.title,
                    'description', sv.description,
                    'price_cents', sv.price_cents,
                    'currency_code', sv.currency_code,
                    'duration_minutes', sv.duration_minutes,
                    'is_active', sv.is_active,
                    'sort_order', sv.sort_order,
                    'category', COALESCE(sc.title, 'Services'::text)
                )
                ORDER BY (COALESCE(sc.sort_order, 2147483647)),
                         (lower(COALESCE(sc.title, 'Services'::text))),
                         sv.sort_order,
                         sv.created_at
            )
            FROM site.services sv
                LEFT JOIN site.service_categories sc ON sc.id = sv.category_id AND sc.deleted_at IS NULL
            WHERE sv.user_id = p.id
                AND sv.is_active = true
                AND sv.deleted_at IS NULL
        ), '[]'::jsonb)
    ) AS payload
FROM site.sites s
    JOIN core.users p ON p.id = s.user_id
WHERE s.is_published = true
    AND p.status = 'active'::text
    AND p.deleted_at IS NULL;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- -- Re-inject the keys into settings from the columns (LINKS ONLY).
-- UPDATE site.blocks
-- SET settings = settings
--       || jsonb_build_object('live_check_enabled', live_check_enabled)
--       || (CASE WHEN category IS NOT NULL THEN jsonb_build_object('category', category) ELSE '{}'::jsonb END)
--       || (CASE WHEN platform IS NOT NULL THEN jsonb_build_object('platform', platform) ELSE '{}'::jsonb END)
-- WHERE block_group = 'links';
-- -- Recreate the expression index.
-- CREATE INDEX idx_blocks_live_check_enabled ON site.blocks ((settings->>'live_check_enabled'))
--     WHERE block_group = 'links' AND deleted_at IS NULL AND is_active = true;
-- -- Restore both views to their 20260527070000 bodies (without the 3 added keys).
-- -- Re-run the CREATE OR REPLACE VIEW statements from 20260527070000_skeleton_system_cleanup.sql verbatim.
-- COMMIT;
```

### DEV-SUPABASE VERIFICATION (Postgres-only constraints are invisible to SQLite)
SQLite (test schema) enforces neither the `NOT NULL DEFAULT false`, the `category` CHECK, nor the partial index — so verify directly on `glncumufgaqcmqhzwrxm`.

After applying **Migration 1** (`apply_migration`), run via `execute_sql`:

1. `category` CHECK rejects junk (expect ERROR `violates check constraint "blocks_category_check"`):
```sql
INSERT INTO site.blocks (id, user_id, site_id, block_group, block_type, settings, category)
SELECT gen_random_uuid(), s.user_id, s.id, 'links', 'link', '{}'::jsonb, 'bogus'
FROM site.sites s LIMIT 1;
```
2. `category` CHECK accepts a valid value (expect 1 row inserted; then `ROLLBACK`/`DELETE` it):
```sql
BEGIN;
INSERT INTO site.blocks (id, user_id, site_id, block_group, block_type, settings, category, live_check_enabled)
SELECT gen_random_uuid(), s.user_id, s.id, 'links', 'link', '{}'::jsonb, 'social', true
FROM site.sites s LIMIT 1;
ROLLBACK;
```
3. `live_check_enabled` NOT NULL holds (expect ERROR `null value in column "live_check_enabled"`):
```sql
INSERT INTO site.blocks (id, user_id, site_id, block_group, block_type, settings, live_check_enabled)
SELECT gen_random_uuid(), s.user_id, s.id, 'links', 'link', '{}'::jsonb, NULL
FROM site.sites s LIMIT 1;
```
4. Partial index exists: `SELECT indexdef FROM pg_indexes WHERE schemaname='site' AND indexname='idx_blocks_live_check_enabled_active';` (expect the partial-index definition).

After applying **Migration 2**, confirm both views emit the keys and the old index is gone:
```sql
SELECT jsonb_object_keys(payload->'links'->0) FROM site.public_site_payload LIMIT 5;  -- expect platform/category/live_check_enabled present
SELECT indexname FROM pg_indexes WHERE schemaname='site' AND indexname='idx_blocks_live_check_enabled';  -- expect 0 rows
```

---

## `tests/Pest.php` — SQLite schema + helper updates (Phase 1)

**`setupBlocksTable()` (`:622`)** — add the three columns to the in-memory table:
```php
function setupBlocksTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.blocks (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        site_id TEXT NULL,
        block_group TEXT NULL,
        block_type TEXT NULL,
        title TEXT NULL,
        url TEXT NULL,
        icon_key TEXT NULL,
        sort_order INTEGER NULL,
        is_active INTEGER NULL,
        is_enabled INTEGER NULL,
        settings TEXT NULL,
        live_check_enabled INTEGER NULL DEFAULT 0,
        category TEXT NULL,
        platform TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}
```
> Note: SQLite gets `INTEGER NULL DEFAULT 0` for `live_check_enabled` (no NOT NULL — SQLite would otherwise reject existing helper inserts that omit it; the real `NOT NULL` is verified on Postgres above). Tests that exercise the column set it explicitly.

**`createLinkBlockFor()` (`:1018`)** — default the three columns so column readers see consistent rows:
```php
    $row = array_merge([
        'id' => $id,
        'user_id' => $pro->id,
        'site_id' => $site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'title' => 'Test Link',
        'url' => 'https://example.com',
        'sort_order' => 0,
        'is_active' => 1,
        'live_check_enabled' => 0,
        'category' => null,
        'platform' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);
```

---

## PHASE 1 (expand) — code changes

### 1. `app/Models/Core/Site/Block.php` — fillable + casts
Add the three columns to `$fillable` (after `'settings'`) and a boolean cast:
```php
    protected $fillable = [
        'user_id',
        'site_id',
        'block_type',
        'block_group',
        'title',
        'url',
        'icon_key',
        'sort_order',
        'is_active',
        'is_enabled',
        'settings',
        'live_check_enabled',
        'category',
        'platform',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_enabled' => 'boolean',
        'live_check_enabled' => 'boolean',
        'settings' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
```

### 2. `app/Services/Site/LinkBlockFieldBuilder.php` — dual-write (Phase 1)
`build()` now returns `platform`/`category`/`live_check_enabled` as **column** fields AND keeps the platform/category soft-tags in `settings` (so the unchanged views/injector keep working). `handle` stays in `settings`. Full method:
```php
    public function build(array $data): array
    {
        $platform = $data['platform'] ?? null;
        $requestedCategory = $data['category'] ?? null;

        // Phase 1: live_check_enabled still arrives nested at settings.live_check_enabled
        // (the request contract changes to top-level in Phase 2). Read it here so both
        // write modes populate the new column.
        $liveCheckEnabled = (bool) ($data['settings']['live_check_enabled'] ?? false);

        if ($platform !== null && $platform !== '') {
            $normalized = $this->normalizer->normalize(
                $platform,
                $data['handle'] ?? null,
                $data['url'] ?? null
            );

            // settings keeps handle + the Phase-1 platform/category mirror.
            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            $settings['platform'] = $normalized['platform_key'];
            if ($normalized['handle'] !== null) {
                $settings['handle'] = $normalized['handle'];
            }
            $registry = config("partna.social_platforms.{$normalized['platform_key']}", []);
            $resolvedCategory = $requestedCategory ?: ($registry['default_category'] ?? 'other');
            $settings['category'] = $resolvedCategory;

            return [
                'title' => ($data['title'] ?? '') !== '' ? $data['title'] : $normalized['display_name'],
                'url' => $normalized['url'],
                'icon_key' => $normalized['icon_key'],
                'platform' => $normalized['platform_key'],
                'category' => $resolvedCategory,
                'live_check_enabled' => $liveCheckEnabled,
                'settings' => $settings,
            ];
        }

        // Custom mode: category required by the Form Request; defensive guard here.
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        if ($requestedCategory === null || $requestedCategory === '') {
            throw new InvalidArgumentException('A category is required for custom links.');
        }
        $settings['category'] = $requestedCategory;

        return [
            'title' => $data['title'] ?? null,
            'url' => $data['url'] ?? null,
            'icon_key' => $data['icon_key'] ?? null,
            'category' => $requestedCategory,
            'live_check_enabled' => $liveCheckEnabled,
            'settings' => $settings,
        ];
    }
```

### 3. `app/Http/Controllers/Api/User/SiteManagement/UserLinkBlockController.php` — custom-update dual-write (Phase 1)
`store()` and the social branch of `update()` need **no change** (they go through the field builder, which now returns the columns; `new Block(array_merge($blockFields, …))` and `fill($normalized)` set the columns because they are fillable). Only the **custom branch** of `update()` (`:137-151`) must also populate the columns. Replace that `else` block:
```php
        } else {
            // Strip the social-mode-only keys before fill — they're not Block columns.
            unset($data['platform'], $data['handle']);

            // category is now a column (top-level $data['category'] fills it directly).
            // Phase 1 dual-write: also mirror it into settings so the unchanged views
            // keep emitting it. Preserve the existing settings on a category-only edit.
            if (array_key_exists('category', $data)) {
                $existingSettings = is_array($linkBlock->settings) ? $linkBlock->settings : [];
                $existingSettings['category'] = $data['category'];
                $data['settings'] = array_merge($existingSettings, $data['settings'] ?? []);
            }

            // live_check_enabled arrives nested in settings (Phase 1) — mirror it onto
            // the column so the cap count and streaming job (now column-based) see it.
            if (array_key_exists('settings', $data) && is_array($data['settings'])
                && array_key_exists('live_check_enabled', $data['settings'])) {
                $data['live_check_enabled'] = (bool) $data['settings']['live_check_enabled'];
            }

            $linkBlock->fill($data);
        }
```

### 4. `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffLinkBlockManagementController.php` — dual-write (Phase 1, DRIFT)
**store()** — add the columns to `new Block([...])` (`:50-59`); platform stays null (staff does custom links only):
```php
            create: function (int $sortOrder) use ($professional, $site, $data) {
                $block = new Block([
                    'block_group' => 'links',
                    'block_type' => 'link',
                    'title' => $data['title'],
                    'url' => $data['url'],
                    'icon_key' => $data['icon_key'] ?? null,
                    'sort_order' => $sortOrder,
                    'is_active' => $data['is_active'] ?? true,
                    'category' => $data['category'] ?? ($data['settings']['category'] ?? null),
                    'live_check_enabled' => (bool) ($data['settings']['live_check_enabled'] ?? false),
                    'settings' => $data['settings'] ?? [],
                ]);
                $block->user_id = $professional->id;
                $block->site_id = $site->id;
                $block->save();

                return $block->fresh();
            },
```
**update()** — hoist `live_check_enabled` onto the column before fill (`fill()` already maps top-level `category`/`platform` to columns via fillable); replace `:82`:
```php
        $data = $request->validated();
        if (isset($data['settings']) && is_array($data['settings'])
            && array_key_exists('live_check_enabled', $data['settings'])) {
            $data['live_check_enabled'] = (bool) $data['settings']['live_check_enabled'];
        }
        $linkBlock->fill($data);
        $linkBlock->save();
```

### 5. `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php` — query + read the column (Phase 1)
`handle = settings->>'handle'` stays JSONB; `platform` + `live_check_enabled` move to columns. Replace the query block (`:69-88`):
```php
        // block_group='links' (NOT block_type='link') is the links/sections discriminator.
        // live_check_enabled + platform are promoted columns; handle stays in settings JSONB.
        Block::query()
            ->where('block_group', 'links')
            ->where('live_check_enabled', true)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->chunkById(500, function ($blocks) use (&$handlesByPlatform, $streamingPlatforms): void {
                foreach ($blocks as $block) {
                    $platform = $block->platform;
                    $settings = is_array($block->settings) ? $block->settings : [];
                    $handle = $settings['handle'] ?? null;

                    if (
                        $platform
                        && $handle
                        && in_array($platform, $streamingPlatforms, true)
                    ) {
                        $handlesByPlatform[$platform][] = $handle;
                    }
                }
            });
```

### 6. `app/Http/Requests/Api/User/Site/StoreLinkBlockRequest.php` — cap count queries → columns (Phase 1)
Category cap count (`:151-156`) → column predicate:
```php
                    $existing = Block::query()
                        ->where('user_id', $proId)
                        ->where('block_group', 'links')
                        ->whereNull('deleted_at')
                        ->whereIn('category', $cappedCategories)
                        ->count();
```
Live-check cap count (`:181-185`) → column predicate:
```php
                        $existing = Block::query()
                            ->where('site_id', $siteId)
                            ->where('block_group', 'links')
                            ->where('live_check_enabled', true)
                            ->count();
```
Drop the now-unused `use Illuminate\Support\Facades\DB;` import if nothing else in the file uses `DB::` (it does not after this change). The rules + allowlist + triggers are **unchanged in Phase 1** (live_check still validated nested under `settings.live_check_enabled`).

### 7. `app/Http/Requests/Api/User/Site/UpdateLinkBlockRequest.php` — cap count query → column (Phase 1)
Live-check cap count (`:139-144`):
```php
                    $existing = Block::query()
                        ->where('site_id', $siteId)
                        ->where('block_group', 'links')
                        ->when($currentBlockId, fn ($q) => $q->where('id', '!=', $currentBlockId))
                        ->where('live_check_enabled', true)
                        ->count();
```

### 8. `app/Console/Commands/EnforcePlatformLinkCapCommand.php` — read the column (Phase 1, DRIFT)
`category` is now a real column present on both SQLite and Postgres. Replace the two `$b->settings['category']` reads (`:92`, `:104`):
```php
                ->filter(fn (Block $b) => in_array($b->category, $cappedCategories, true))
```
```php
                $category = $block->category ?? 'unknown';
```

---

## PHASE 2 (contract) — code changes (ship in lockstep with Migration 2 + both frontends)

### 9. `config/partna.php` — drop the three keys from the allowlist (`:181-198`)
```php
    'link_block_settings_keys' => [
        'open_in_new_tab',
        'rel_nofollow',
        'rel_sponsored',
        'rel_ugc',
        'highlight',
        'note',
        // platform/handle social-link tagging. platform + category + live_check_enabled
        // are now promoted columns on site.blocks (see 20260701000000); only handle
        // remains in settings JSONB.
        'handle',
    ],
```

### 10. `app/Services/Site/LinkBlockFieldBuilder.php` — stop writing settings tags (Phase 2)
Read `live_check_enabled` from the **top-level** request field; remove platform/category/live_check_enabled from the returned `settings`. Full method:
```php
    public function build(array $data): array
    {
        $platform = $data['platform'] ?? null;
        $requestedCategory = $data['category'] ?? null;
        $liveCheckEnabled = (bool) ($data['live_check_enabled'] ?? false);

        if ($platform !== null && $platform !== '') {
            $normalized = $this->normalizer->normalize(
                $platform,
                $data['handle'] ?? null,
                $data['url'] ?? null
            );

            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            // platform/category/live_check_enabled are columns now — never in settings.
            unset($settings['platform'], $settings['category'], $settings['live_check_enabled']);
            if ($normalized['handle'] !== null) {
                $settings['handle'] = $normalized['handle'];
            }
            $registry = config("partna.social_platforms.{$normalized['platform_key']}", []);

            return [
                'title' => ($data['title'] ?? '') !== '' ? $data['title'] : $normalized['display_name'],
                'url' => $normalized['url'],
                'icon_key' => $normalized['icon_key'],
                'platform' => $normalized['platform_key'],
                'category' => $requestedCategory ?: ($registry['default_category'] ?? 'other'),
                'live_check_enabled' => $liveCheckEnabled,
                'settings' => $settings,
            ];
        }

        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        unset($settings['platform'], $settings['category'], $settings['live_check_enabled']);
        if ($requestedCategory === null || $requestedCategory === '') {
            throw new InvalidArgumentException('A category is required for custom links.');
        }

        return [
            'title' => $data['title'] ?? null,
            'url' => $data['url'] ?? null,
            'icon_key' => $data['icon_key'] ?? null,
            'category' => $requestedCategory,
            'live_check_enabled' => $liveCheckEnabled,
            'settings' => $settings,
        ];
    }
```

### 11. `app/Http/Controllers/Api/User/SiteManagement/UserLinkBlockController.php` — custom-update column-only (Phase 2)
Replace the custom `else` branch:
```php
        } else {
            // Strip the social-mode-only keys before fill — they're not Block columns.
            unset($data['platform'], $data['handle']);
            // category + live_check_enabled are top-level columns now (request sends
            // them top-level in Phase 2); fill() maps them directly. Any settings the
            // client sends no longer carries these keys (rejected by the allowlist).
            $linkBlock->fill($data);
        }
```

### 12. `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffLinkBlockManagementController.php` — column-only (Phase 2)
**store()** `new Block` — `category` from top-level only, settings no longer mirrors:
```php
                    'category' => $data['category'] ?? null,
                    'live_check_enabled' => (bool) ($data['live_check_enabled'] ?? false),
                    'settings' => $data['settings'] ?? [],
```
**update()** — `fill()` maps top-level `category`/`platform`/`live_check_enabled` to columns; the Phase-1 hoist is removed:
```php
        $linkBlock->fill($request->validated());
        $linkBlock->save();
```

### 13. `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php` — no further change
Already column-based from Phase 1. (`handle` still read from `settings`, which retains it.)

### 14. `app/Services/Streaming/LiveStatusInjector.php` — read top-level (Phase 2)
The view now emits `platform`/`live_check_enabled` at the top level of each block; `handle` stays in `settings`; `is_live` is still written under `settings.is_live`. Replace `injectIntoBlocks()`:
```php
    public function injectIntoBlocks(array $blocks): array
    {
        $streamingPlatforms = config('partna.streaming_platforms', []);

        return array_map(function ($block) use ($streamingPlatforms) {
            if (! is_array($block)) {
                return $block;
            }

            // platform + live_check_enabled are promoted columns, emitted as top-level
            // block keys by the public-site views. handle stays in the settings bag.
            $platform = $block['platform'] ?? null;
            $liveCheckEnabled = (bool) ($block['live_check_enabled'] ?? false);

            $settings = $block['settings'] ?? [];
            $handle = is_array($settings) ? ($settings['handle'] ?? null) : null;

            if (
                ! $liveCheckEnabled
                || ! $platform
                || ! $handle
                || ! in_array($platform, $streamingPlatforms, true)
            ) {
                return $block;
            }

            // is_live continues to live under settings.is_live (wire contract for the frontend).
            if (! is_array($block['settings'] ?? null)) {
                $block['settings'] = [];
            }
            $redisKey = self::LIVE_KEY_PREFIX."{$platform}:{$handle}";
            $block['settings']['is_live'] = Redis::get($redisKey) === '1';

            return $block;
        }, $blocks);
    }
```

### 15. `app/Services/PublicSite/SitepageDataResolverService.php` — `getLinks` reads columns (Phase 2)
Only the `->map()` inside `getLinks()` (`:303-340`) changes; `getBooking()` (`:629-654`) is **NOT** touched (its `settings.platform` is a booking-section field). Replace the map callback:
```php
            ->map(function (Block $block): array {
                $settings = is_array($block->settings) ? $block->settings : [];
                $platform = is_string($block->platform)
                    ? strtolower(trim($block->platform))
                    : null;
                $platform = ($platform !== null && $platform !== '') ? $platform : null;
                $category = is_string($block->category)
                    ? strtolower(trim($block->category))
                    : 'custom';

                $title = is_string($block->title) ? trim($block->title) : '';
                $url = is_string($block->url) ? trim($block->url) : '';

                // Older rows can have empty title/url at rest — platform (column)
                // + settings.handle are the source of truth there. Rebuild both
                // from the platform config so the row still renders.
                if (($title === '' || $url === '') && $platform !== null) {
                    $config = config("partna.social_platforms.{$platform}");
                    $handle = is_string($settings['handle'] ?? null)
                        ? trim((string) $settings['handle'])
                        : '';
                    if (is_array($config)) {
                        if ($title === '' && is_string($config['display_name'] ?? null)) {
                            $title = (string) $config['display_name'];
                        }
                        if ($url === '' && $handle !== '' && is_string($config['url_template'] ?? null)) {
                            $url = str_replace('{handle}', $handle, (string) $config['url_template']);
                        }
                    }
                }

                return [
                    'id' => (string) $block->id,
                    'title' => $title,
                    'url' => $url,
                    'category' => $category !== '' ? $category : 'custom',
                    'platform' => $platform,
                ];
            })
```

### 16. `app/Http/Resources/LinkBlockResource.php` — emit columns top-level (Phase 2)
```php
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => $this->user_id,
            'site_id' => $this->site_id,
            'block_type' => $this->block_type,
            'block_group' => $this->block_group,
            'title' => $this->title,
            'url' => $this->url,
            'icon_key' => $this->icon_key,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'is_enabled' => $this->is_enabled,
            'platform' => $this->platform,
            'category' => $this->category,
            'live_check_enabled' => (bool) $this->live_check_enabled,
            'settings' => (object) ($this->settings ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
```

### 17. `app/Http/Requests/Api/User/Site/StoreLinkBlockRequest.php` — request contract (Phase 2)
- Remove the `settings.live_check_enabled` and `settings.category` rules; add a top-level `live_check_enabled` rule:
```php
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.highlight' => ['sometimes', 'boolean'],
            'settings.note' => ['sometimes', 'string', 'max:140'],

            'category' => ['sometimes', 'nullable', 'string', Rule::in(config('partna.link_categories', []))],
            'live_check_enabled' => ['sometimes', 'boolean'],
```
- The live-check cap trigger now reads the top-level field. Replace `:168-169`:
```php
            $liveCheckRequested = (bool) $this->input('live_check_enabled');
            if ($liveCheckRequested) {
```
  …and inside that block, the error key stays `settings.live_check_enabled`? **No** — move it to the top-level key for contract consistency: `$validator->errors()->add('live_check_enabled', "You can enable live status checking on at most {$cap} link blocks per site.");`
- The settings-allowlist check (`:198-207`) is unchanged in code, but now rejects `platform`/`category`/`live_check_enabled` because they were dropped from `config('partna.link_block_settings_keys')`.

### 18. `app/Http/Requests/Api/User/Site/UpdateLinkBlockRequest.php` — request contract (Phase 2)
- Remove `settings.live_check_enabled` + `settings.category` rules; add top-level `live_check_enabled` rule (mirror Store).
- The live-check cap trigger (`:130`) reads `$this->input('live_check_enabled')`; the error key moves to `live_check_enabled` (mirror Store).

### 19. `app/Http/Resources/Staff/StaffSiteResource.php` — allowlist (Phase 2, DRIFT)
`all_site_data` now emits the three keys; surface them in staff output by extending `BLOCK_ALLOWLIST` (`:62-66`):
```php
    private const BLOCK_ALLOWLIST = [
        'id', 'site_id', 'user_id', 'block_type', 'block_group',
        'title', 'url', 'icon_key', 'sort_order', 'is_active',
        'settings', 'platform', 'category', 'live_check_enabled',
        'created_at', 'updated_at',
    ];
```
> Pre-existing note (do not "fix" here): `professional_id` in the current allowlist is already dead (the view emits `user_id`); this PR replaces it with `user_id` while adding the three keys.

### 20. `app/Console/Commands/BackfillSocialLinksCommand.php` — retire or repoint (Phase 2, DRIFT)
This one-shot backfill (already run; superseded by Migration 1's backfill) writes `settings.platform`/`settings.category`, which Phase 2 strips. **Recommended:** repoint its writes to the columns — `$block->platform = $platformKey; $block->category = …;` and keep `$block->settings['handle']` in JSONB — and update its idempotency reads (`$block->platform`/`$block->category`). Acceptable alternative: delete the command + its test (pre-beta, no rows left to backfill). Flag for Josh; low priority.

---

## TDD task sequence

Each task: write/adjust the failing test → run-fail → minimal code → run-pass → `php artisan pint --dirty` → commit. Run a single file with `php artisan test --filter` / `vendor/bin/pest <path>`.

### Phase 1

**T1 — Migration 1 file + Pest schema.**
- Add both `setupBlocksTable`/`createLinkBlockFor` edits and write Migration 1.
- Fail: `vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php` is unaffected; instead add a unit guard test `tests/Unit/Models/BlockColumnsTest.php` asserting `(new App\Models\Core\Site\Block)->getFillable()` contains `live_check_enabled`,`category`,`platform`. Run-fail: "Failed asserting that array contains 'live_check_enabled'".
- Code: Block fillable/casts (change #1).
- Run-pass: that file green.
- `git commit -m "FOUND-15: add live_check_enabled/category/platform columns (migration + model + test schema)"`

**T2 — streaming job reads the column.**
- Edit `tests/Unit/Streaming/CheckStreamingLiveStatusJobTest.php` `:62-70` seed → `'live_check_enabled' => 1, 'platform' => 'twitch', 'settings' => json_encode(['handle' => 'testuser'])`. Run-fail (job still queries `settings->>'live_check_enabled'`, finds nothing → poll not called → "method poll was not called").
- Code: change #5.
- Run-pass.
- `git commit -m "FOUND-15: CheckStreamingLiveStatusJob queries live_check_enabled column"`

**T3 — field builder dual-write.**
- Edit `tests/Unit/Controllers/BuildBlockFieldsCategoryTest.php`: keep the existing `$fields['settings']['category']`/`['platform']` assertions (dual-write preserves them) and ADD `expect($fields['category'])->toBe('other')` / `expect($fields['platform'])->toBe('calendly')` / `expect($fields['live_check_enabled'])->toBeFalse()`. Run-fail: "Undefined array key 'category'".
- Code: change #2.
- Run-pass.
- `git commit -m "FOUND-15: LinkBlockFieldBuilder dual-writes promoted columns"`

**T4 — cap counts on columns (user + staff inheritance).**
- `tests/Feature/Site/LinkBlockPlatformCapTest.php`: `seedCappedLinks` must set the `category` (+`platform`) columns: add `'category' => $category, 'platform' => 'instagram'` to the insert (`:39-51`). Run-fail (count query now reads `category` column, seed only had settings → cap not hit → "Failed asserting true").
- `tests/Feature/Api/UpdateLinkBlockLiveCheckTest.php`: the two cap-seed inserts (`:111`, and the over-cap setup) must set `'live_check_enabled' => 1` columns. Run-fail similarly.
- Code: changes #6, #7 (+ remove unused `DB` import in Store request).
- Run-pass both files.
- `git commit -m "FOUND-15: link cap counts use category/live_check_enabled columns"`

**T5 — controllers populate columns (user custom-update + staff).**
- Feature test (new) `tests/Feature/Site/LinkBlockColumnWriteTest.php`: PATCH a custom link with `{category:'events'}` → assert `Block::find($id)->category === 'events'` AND (Phase 1) `settings['category'] === 'events'`. PATCH `{settings:{live_check_enabled:true}}` → assert column `live_check_enabled === true`. A staff store with `{title,url,category:'social'}` → assert column `category === 'social'`. Run-fail.
- Code: changes #3, #4.
- Run-pass.
- `git commit -m "FOUND-15: user + staff link controllers populate promoted columns"`

**T6 — cap remediation command on the column.**
- `tests/Feature/Console/EnforcePlatformLinkCapCommandTest.php`: ensure its seeds set the `category` column (inspect + adjust). Run-fail if it currently seeds only settings.
- Code: change #8.
- Run-pass.
- `git commit -m "FOUND-15: EnforcePlatformLinkCapCommand filters on category column"`

**T7 — full suite + dev-Supabase verify (expand).**
- Apply Migration 1 to `glncumufgaqcmqhzwrxm`; run the four DEV-SUPABASE VERIFICATION inserts; confirm rejections.
- `composer test` (FULL suite — green).
- No new commit (verification only) or a docs note commit.

### Phase 2 (gated — confirm frontend is ready; see DECISION)

**T8 — public payload golden master (capture BEFORE flipping).**
- New `tests/Feature/PublicSite/PublicSitePayloadPromotionGoldenTest.php`:
  - Seed a published site + a live-check link block (`platform:'twitch'`, `settings.handle:'foo'`, `live_check_enabled:1`, `category:'streaming'`) and a custom link.
  - **Path 2 byte-identical:** snapshot `GET /api/public/profiles/{handle}` `links[]` JSON; assert byte-identical before vs after Phase-2 code (output already top-level `{id,title,url,category,platform}`).
  - **Path 1 relocation:** snapshot `GET` of the public-site payload; assert everything is byte-identical EXCEPT `links[].settings.{platform,category,live_check_enabled}` are now `links[].{platform,category,live_check_enabled}` with identical values, and `links[].settings.is_live` is still injected.

**T9 — flip readers + views + strip.**
- Write Migration 2 (strip + both `CREATE OR REPLACE VIEW` + drop expression index).
- Apply to dev Supabase; run the post-Migration-2 verification (`jsonb_object_keys` shows the keys; old index gone).
- Code: changes #9–#20.
- Update `tests/Unit/Streaming/LiveStatusInjectorTest.php` (`:21,39,57,74,104`) and `tests/Feature/Api/PublicSiteStreamingLiveStatusTest.php` (`:20,51`) to build blocks with TOP-LEVEL `platform`/`live_check_enabled` + `settings.handle`. Update `BuildBlockFieldsCategoryTest` to drop the `$fields['settings']['category']`/`['platform']` assertions (Phase 2 no longer mirrors). Update `BackfillLinkCategoriesTest` to assert `$block->category`/`$block->platform` if the command is repointed (or delete with the command).
- Run-pass the affected files, then `composer test` FULL.
- `git commit -m "FOUND-15: strip settings keys, rewrite both views, flip payload readers to columns"`

---

## Golden-master guard (tests that MUST stay green)
- **`tests/Feature/Site/LinkBlockPlatformCapTest.php`** (4 cases) — platform-link cap on the column.
- **`tests/Feature/Api/UpdateLinkBlockLiveCheckTest.php`** — per-site live-check cap on the column.
- **`tests/Unit/Streaming/CheckStreamingLiveStatusJobTest.php`**, **`tests/Unit/Streaming/LiveStatusInjectorTest.php`**, **`tests/Feature/Api/PublicSiteStreamingLiveStatusTest.php`** — streaming poll + live-status injection.
- **`tests/Unit/Controllers/BuildBlockFieldsCategoryTest.php`** — field-builder category/platform resolution.
- **`tests/Feature/PublicSite/IndividualProfileControllerTest.php`** + **`tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`** — Path 2 (`/api/public/profiles/{handle}`) must stay **byte-identical** (snapshot links[] before, assert equal after).
- **`tests/Feature/PublicSite/PublicSiteControllerShowTest.php`** / **`PublicSiteControllerShowByHeaderTest.php`** — Path 1 payload (assert relocation, not equality, per T8).
- **`tests/Feature/Site/LinkBlockCategoryValidationTest.php`**, **`LinkBlockSocialValidationTest.php`**, **`tests/Unit/Resources/LinkBlockResourceTest.php`**, **`tests/Feature/Console/EnforcePlatformLinkCapCommandTest.php`**, **`tests/Feature/Console/BackfillLinkCategoriesTest.php`**, **`tests/Unit/Config/LinkCategoriesConfigTest.php`** (this asserts `live_check_enabled` is in `link_block_settings_keys` — **must be updated/removed in Phase 2** when the key is dropped from the allowlist).

## Final gate
Run the **FULL** suite, not a filtered subset (Wave-1 hit a 9-test regression only the full run caught):
```bash
composer test
```
Confirm green before opening the PR. After applying each migration to `glncumufgaqcmqhzwrxm`, re-run the DEV-SUPABASE VERIFICATION block for that migration and confirm the expected rejections/keys.


---

## PR6 — FOUND-16 — promote 10 `site.sites.settings` sub-keys → typed columns (+ BOTH DB views)

**Goal:** Promote the 10 named, individually-validated `site.sites.settings` sub-keys to typed nullable columns on `site.sites` (with a `booking_mode` CHECK), making them queryable/indexable and DB-enforced, with zero change to the dashboard or partna-pages wire contracts.

**Architecture:** `UpdateSiteAction` is the single write hinge — it keeps accepting `settings.*` from the client (no frontend change) and hoists the 10 keys into columns before `fill()`. Columns become the source of truth; `SiteResource` and the public-profile resolver read columns and re-merge them into the emitted `settings` object so responses stay byte-identical. The two public-read VIEWs (`site.all_site_data`, `site.public_site_payload`) pass `s.settings` through as a whole blob, so the JSONB strip and the view re-inject must ship in lockstep (Phase 2).

**Blast radius (files):**
- `supabase/migrations/20260701000000_promote_site_settings_columns.sql` (NEW — Phase 1)
- `supabase/migrations/20260701000100_strip_site_settings_jsonb_keys.sql` (NEW — Phase 2, gating)
- `app/Models/Core/Site/Site.php`
- `app/Services/Site/UpdateSiteAction.php`
- `app/Http/Requests/Api/User/Site/UpdateSiteRequest.php`
- `app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php`
- `app/Http/Requests/Api/User/Site/UpdateBookingSettingsRequest.php`
- `app/Http/Resources/SiteResource.php`
- `app/Services/PublicSite/SitepageDataResolverService.php`
- `tests/Pest.php` (`setupSitesTable()`)
- Tests: `tests/Unit/Resources/SiteResourceTest.php` (golden master, kept green) + new tests.

**DECISION (confirm before implementing):** widen `booking_mode` validation to accept `'none'` to match the new `CHECK (booking_mode IS NULL OR booking_mode IN ('manual','none'))` — *recommended* — vs keep `'manual'`-only and narrow the CHECK to `('manual')`. The plan below is written for the **widen** option (single `Site::BOOKING_MODES = ['manual','none']` const referenced by all three requests + mirrored by the CHECK). To flip: set `BOOKING_MODES = ['manual']` and change the CHECK body to `booking_mode IN ('manual')`.

**DECISION (confirm before implementing):** **dual-write-then-strip** (recommended — decouples the two-view lockstep, eliminates silent-NULL-loss risk) vs strip-in-same-migration. The plan ships **Phase 1** (add columns + backfill + CHECK + dual-write code, no view change, no strip) as PR6, and specifies **Phase 2** (strip the 10 keys + `CREATE OR REPLACE` both views, atomic) in full as the immediate follow-up. To flip to strip-in-same-migration: concatenate migration B into migration A and apply the Phase-2 `unset($merged[$key])` one-liner in `UpdateSiteAction` from the start.

**Premise grounding (corrections honored + drift found):**
- **Honored premise A:** `StaffUpdateSiteRequest` validates only **9** keys (lines 76–88) — it is **missing `charlie_enabled`**. The user request `UpdateSiteRequest` has all **10** (lines 56–71). Promotion unifies them → this PR **adds `charlie_enabled` to the Staff request**.
- **Honored premise B:** `booking_mode` is `Rule::in(['manual'])` today in **both** `UpdateSiteRequest:66-70` and `StaffUpdateSiteRequest:83-87` — it does **not** accept `'none'`. Widened per the DECISION above.
- **DRIFT 1 (third validator):** there is a **THIRD** `booking_mode` validator the design plan did not name — `app/Http/Requests/Api/User/Site/UpdateBookingSettingsRequest.php` (`'booking_mode' => ['required','string', Rule::in(['manual'])]`), used by the dedicated `updateBookingSettings` endpoint, which writes through `UpdateSiteAction`. It must be widened too or `'none'` is accepted by the generic endpoint but rejected by the booking endpoint.
- **DRIFT 2 (path):** `UpdateSiteAction` lives at `app/Services/Site/UpdateSiteAction.php` (design plan implied `app/Services/User/`). The settings merge is at lines **51–66**; `$site->fill($data)` at **226**. FOUND-29's inline subdomain-rename block (lines 86–222) lives in the same transaction — **do not disturb it**; the hoist goes in the pre-transaction pure-PHP section.
- **DRIFT 3 (views emit whole blob, not keys):** BOTH views emit the **entire `s.settings` JSONB blob** (`s.settings AS site_settings` at `20260527070000_skeleton_system_cleanup.sql:102`; `'settings', s.settings` at `:160`) — **not** individual keys. So the brief's generic "emit the 10 columns as top-level keys" does **not** fit: emitting them top-level would change `payload.site.hero_title` vs today's `payload.site.settings.hero_title` and break partna-pages with no frontend change. The faithful lockstep is to **re-inject the 10 columns back INTO the emitted settings object** via `s.settings || jsonb_strip_nulls(jsonb_build_object(...))`, preserving `payload.site.settings.*` and `all_site_data.site_settings.*`. This is the gating step.
- **DRIFT 4 (only 2 of 10 keys have named PHP readers):** an app-wide grep shows only `booking_mode` and `manual_booking_url` are read by name (in `SiteResource`, `UpdateBookingSettingsRequest`→`UpdateSiteAction`, and `SitepageDataResolverService::buildServicesData:593-594`). The other 8 keys flow purely through `settings` passthrough (dashboard `SiteResource.settings`; partna-pages via the view; staff via `StaffSiteResource.site_settings`). `IndividualProfilePayloadBuilder::buildServices:274-300` **drops** bookingMode/manualBookingUrl from the public-profile wire — so the resolver edit is code-correctness only, not wire-visible.
- **No conflict** with the skeleton `settings.design` strip (disjoint keys); the existing `unset($merged['design'])` guard is preserved untouched.

The 10 keys and their column types (confirmed against `UpdateSiteRequest:56-71`):

| Key | SQL type | Cast |
|-----|----------|------|
| `hero_title` | `text` | — (max:100) |
| `hero_subtitle` | `text` | — (max:200) |
| `primary_button_text` | `text` | — (max:50) |
| `primary_button_url` | `text` | — (nullable url, max:2048) |
| `bio_text` | `text` | — (nullable, max:500) |
| `show_branding` | `boolean` | `boolean` |
| `charlie_enabled` | `boolean` | `boolean` |
| `services_auto_sync_enabled` | `boolean` | `boolean` |
| `booking_mode` | `text` (CHECK) | — |
| `manual_booking_url` | `text` | — (nullable url, max:2048) |

All columns NULLABLE (matches the "absent-when-unset" API semantics + the design-kit nullable convention; the audit's "NOT NULL on show_branding" is intentionally relaxed pre-beta to preserve key-absence).

---

### House rules in scope
- DB schema = raw SQL in `supabase/migrations/` only (Laravel-migration guard). No `ShouldQueue` job or new controller action is added by this PR, so the `$backoff` rule and authorize-in-controller rule have no new surface here (the existing `updateBookingSettings`/`update` actions already gate via `authorizeForUser($professional, 'update', $site)` — unchanged).
- **Migration timestamps:** latest existing migration is `20260630000000_drop_smart_links.sql`. This PR uses `20260701000000` (Phase 1) and `20260701000100` (Phase 2). *The executing session bumps these to a timestamp later than the then-latest migration before applying.*
- **SQLite test schema is hand-built** in `tests/Pest.php` and does NOT enforce the Postgres CHECK. The `booking_mode` CHECK must be verified directly on dev Supabase (see DEV-SUPABASE VERIFICATION). In SQLite both views are **seeded stand-in tables** (`site.public_site_payload` table at `tests/Pest.php:502`; `site.all_site_data` stand-in in `tests/Feature/Api/Staff/StaffSiteControllerTest.php:23`) — they do **not** recompute from columns, so the Phase-2 view re-inject is **prod-only** and is verified on dev Supabase.

---

## Phase 1 — migration A: add columns + backfill + CHECK (NO strip, NO view change)

### `supabase/migrations/20260701000000_promote_site_settings_columns.sql`

```sql
-- =====================================================================
-- FOUND-16 Phase 1 — promote 10 site.sites.settings sub-keys to columns
-- =====================================================================
-- Adds typed, nullable columns for the 10 named settings sub-keys, backfills
-- them from the existing JSONB, and constrains booking_mode. The JSONB keys are
-- NOT stripped here (dual-write): the two public-read views keep passing the
-- full settings blob through, so this migration cannot cause silent NULL loss.
-- The strip + view re-inject ship atomically in the Phase 2 migration.
--
-- Pre-beta: zero rows, so the backfill is a no-op now; it is written correct
-- for prod-shape parity.
--
-- All statements are transaction-safe (ADD COLUMN, UPDATE, ADD CONSTRAINT
-- NOT VALID, VALIDATE CONSTRAINT), so the whole sequence is wrapped so a
-- mid-migration failure rolls back to the pre-migration schema.
-- =====================================================================
BEGIN;

ALTER TABLE site.sites
  ADD COLUMN hero_title                 text,
  ADD COLUMN hero_subtitle             text,
  ADD COLUMN primary_button_text       text,
  ADD COLUMN primary_button_url        text,
  ADD COLUMN bio_text                  text,
  ADD COLUMN show_branding             boolean,
  ADD COLUMN charlie_enabled           boolean,
  ADD COLUMN services_auto_sync_enabled boolean,
  ADD COLUMN booking_mode             text,
  ADD COLUMN manual_booking_url        text;

-- Backfill from the existing JSONB. `settings->>'key'` is NULL when the key is
-- absent, so unset keys leave the column NULL. Boolean keys are JSON booleans
-- ('true'/'false' as text via ->>), cast to boolean; NULL casts to NULL.
UPDATE site.sites SET
  hero_title                 = settings->>'hero_title',
  hero_subtitle             = settings->>'hero_subtitle',
  primary_button_text       = settings->>'primary_button_text',
  primary_button_url        = settings->>'primary_button_url',
  bio_text                  = settings->>'bio_text',
  show_branding             = (settings->>'show_branding')::boolean,
  charlie_enabled           = (settings->>'charlie_enabled')::boolean,
  services_auto_sync_enabled = (settings->>'services_auto_sync_enabled')::boolean,
  booking_mode             = settings->>'booking_mode',
  manual_booking_url        = settings->>'manual_booking_url'
WHERE settings IS NOT NULL;

-- booking_mode enum guard. NOT VALID → VALIDATE keeps the ADD lock brief and
-- validates existing rows separately. VALIDATE fails if any backfilled row has
-- a booking_mode NOT IN ('manual','none') and NOT NULL — investigate the data
-- before forcing (do not widen the CHECK to hide bad data).
ALTER TABLE site.sites
  ADD CONSTRAINT sites_booking_mode_check
  CHECK (booking_mode IS NULL OR booking_mode IN ('manual','none')) NOT VALID;

ALTER TABLE site.sites VALIDATE CONSTRAINT sites_booking_mode_check;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_booking_mode_check;
-- ALTER TABLE site.sites
--   DROP COLUMN IF EXISTS hero_title,
--   DROP COLUMN IF EXISTS hero_subtitle,
--   DROP COLUMN IF EXISTS primary_button_text,
--   DROP COLUMN IF EXISTS primary_button_url,
--   DROP COLUMN IF EXISTS bio_text,
--   DROP COLUMN IF EXISTS show_branding,
--   DROP COLUMN IF EXISTS charlie_enabled,
--   DROP COLUMN IF EXISTS services_auto_sync_enabled,
--   DROP COLUMN IF EXISTS booking_mode,
--   DROP COLUMN IF EXISTS manual_booking_url;
-- COMMIT;
-- (settings JSONB is untouched by Phase 1, so no re-injection is needed on rollback.)
```

### DEV-SUPABASE VERIFICATION (Phase 1 — CHECK is invisible to SQLite)

After `apply_migration` of migration A to ref `glncumufgaqcmqhzwrxm`, verify the `booking_mode` CHECK via `execute_sql` (each statement rolled back so no data changes). These assume at least one `site.sites` row exists on dev; if zero rows, use the INSERT form noted below.

Reject `'calendly'`:
```sql
BEGIN;
UPDATE site.sites SET booking_mode = 'calendly' WHERE id = (SELECT id FROM site.sites LIMIT 1);
ROLLBACK;
```
**Expected:** `ERROR: 23514 ... new row for relation "sites" violates check constraint "sites_booking_mode_check"`.

Accept `'none'` and `'manual'`:
```sql
BEGIN;
UPDATE site.sites SET booking_mode = 'none'   WHERE id = (SELECT id FROM site.sites LIMIT 1);
UPDATE site.sites SET booking_mode = 'manual' WHERE id = (SELECT id FROM site.sites LIMIT 1);
ROLLBACK;
```
**Expected:** both `UPDATE 1`, no error.

If `site.sites` is empty on dev, substitute an INSERT inside the rolled-back transaction:
```sql
BEGIN;
INSERT INTO site.sites (id, user_id, subdomain, skeleton_id, is_published, settings, booking_mode)
VALUES (gen_random_uuid(), (SELECT id FROM core.users LIMIT 1), 'check-test-pr6', 'skeleton-1', false, '{}'::jsonb, 'calendly');
ROLLBACK;
```
**Expected:** rejected with `23514`.

---

## Phase 1 — code changes

### `app/Models/Core/Site/Site.php`

Add the 10 columns to `$fillable`, the 3 booleans to `$casts`, and a `BOOKING_MODES` const mirroring the DB CHECK.

Replace the existing `$fillable` (lines 35–42) and `$casts` (lines 44–53), and add the const next to `DEFAULT_SKELETON_ID` (line 27):

```php
    /** Default skeleton when none has been explicitly chosen. Must match the DB CHECK constraint. */
    public const DEFAULT_SKELETON_ID = 'skeleton-1';

    /**
     * Allowed booking modes — mirrors the sites_booking_mode_check DB CHECK
     * constraint. Referenced by UpdateSiteRequest / StaffUpdateSiteRequest /
     * UpdateBookingSettingsRequest so validation and the DB constraint share a
     * single source of truth. Adding a mode = add here + widen the CHECK.
     */
    public const BOOKING_MODES = ['manual', 'none'];

    protected $table = 'site.sites';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'subdomain',
        'skeleton_id',
        'is_published',
        'unpublished_at',
        'settings',
        'moderation_state',
        // FOUND-16: 10 promoted columns (were settings.* sub-keys). Columns are
        // the source of truth; UpdateSiteAction hoists them out of settings.
        'hero_title',
        'hero_subtitle',
        'primary_button_text',
        'primary_button_url',
        'bio_text',
        'show_branding',
        'charlie_enabled',
        'services_auto_sync_enabled',
        'booking_mode',
        'manual_booking_url',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'settings' => 'array',
        'subdomain_changed_at' => 'datetime',
        'unpublished_at' => 'datetime',
        'custom_domain_verified_at' => 'datetime',
        'custom_domain_primary' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        // FOUND-16 promoted booleans.
        'show_branding' => 'boolean',
        'charlie_enabled' => 'boolean',
        'services_auto_sync_enabled' => 'boolean',
    ];
```

### `app/Services/Site/UpdateSiteAction.php`

Hoist the 10 keys out of the merged settings into typed columns before the transaction. This goes inside the existing `if (array_key_exists('settings', $data))` block (lines 51–66). **Do not touch** the subdomain-rename block (FOUND-29) below it.

Replace lines 51–66 with:

```php
        if (array_key_exists('settings', $data)) {
            $existing = is_array($site->settings) ? $site->settings : [];
            $incoming = is_array($data['settings']) ? $data['settings'] : [];
            // Product selections are stored in commerce.affiliate_product_selections, not site settings JSON.
            unset($incoming['selected_products']);
            // Skeleton-system cleanup: settings.design.* is dead. Any
            // incoming `design` sub-key gets dropped on the floor.
            unset($incoming['design']);
            $merged = array_replace_recursive($existing, $incoming);
            // Same guard on the merged result — old design data on disk
            // was already stripped by the cleanup migration, but if any
            // straggler resurfaced via a write race it dies here.
            unset($merged['design']);

            // FOUND-16: hoist the 10 promoted keys out of settings JSONB into
            // typed columns. We extract from $merged (post-PATCH) so only keys
            // the client actually sent are written — columns the request didn't
            // touch keep their existing DB value. The client still SENDS these
            // under settings.* (no frontend change); columns are the source of
            // truth. Phase 1 (dual-write) keeps the keys in $merged so the
            // unchanged DB views still emit them; Phase 2 (strip) uncomments the
            // unset so new writes stop populating the JSONB mirror.
            foreach (Site::PROMOTED_SETTINGS_KEYS as $key) {
                if (array_key_exists($key, $merged)) {
                    $data[$key] = $merged[$key];
                    // Phase 2: uncomment when the strip migration + view
                    // re-inject (20260701000100) have landed:
                    // unset($merged[$key]);
                }
            }

            $data['settings'] = $merged;
        }
```

Add the key list as a const on the `Site` model (single source of truth, also used by `SiteResource`). In `app/Models/Core/Site/Site.php`, directly after `BOOKING_MODES`:

```php
    /**
     * settings JSONB sub-keys promoted to typed columns (FOUND-16). The column
     * name equals the settings key in every case. Used by UpdateSiteAction
     * (hoist) and SiteResource (re-merge for byte-identical responses).
     */
    public const PROMOTED_SETTINGS_KEYS = [
        'hero_title',
        'hero_subtitle',
        'primary_button_text',
        'primary_button_url',
        'bio_text',
        'show_branding',
        'charlie_enabled',
        'services_auto_sync_enabled',
        'booking_mode',
        'manual_booking_url',
    ];
```

`UpdateSiteAction` already imports `App\Models\Core\Site\Site` (line 6) — no new import needed.

### `app/Http/Resources/SiteResource.php`

Read columns (source of truth), re-merge them into the emitted `settings` object so the API shape is unchanged after the eventual strip. A promoted key appears only when its column is non-null (preserves the prior "absent when unset" behaviour the dashboard relies on — see `SiteResourceTest` "handles empty settings as {}"). Columns win over any residual JSONB value (during dual-write both agree).

Replace `toArray()` (lines 16–38):

```php
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];

        // FOUND-16: the 10 promoted columns are the source of truth. Re-merge
        // the non-null ones into the settings object so the response shape is
        // identical before/after the JSONB strip. !== null keeps booleans like
        // show_branding=false while omitting unset keys (absent, not null).
        $promoted = array_filter([
            'hero_title' => $this->hero_title,
            'hero_subtitle' => $this->hero_subtitle,
            'primary_button_text' => $this->primary_button_text,
            'primary_button_url' => $this->primary_button_url,
            'bio_text' => $this->bio_text,
            'show_branding' => $this->show_branding,
            'charlie_enabled' => $this->charlie_enabled,
            'services_auto_sync_enabled' => $this->services_auto_sync_enabled,
            'booking_mode' => $this->booking_mode,
            'manual_booking_url' => $this->manual_booking_url,
        ], static fn ($value): bool => $value !== null);

        $settings = array_merge($settings, $promoted);

        return array_merge([
            'id' => (string) $this->id,
            'user_id' => $this->user_id,
            'subdomain' => $this->subdomain,
            'skeleton_id' => $this->skeleton_id,
            'is_published' => $this->is_published,
            'subdomain_changed_at' => $this->subdomain_changed_at?->toIso8601String(),
            'unpublished_at' => $this->unpublished_at?->toIso8601String(),
            'settings' => (object) $settings,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ],
            // Booking settings surfaced at the top level for the dashboard's
            // booking editor — mirrors the dedicated updateBookingSettings endpoint.
            // Conditionally merged so the keys are absent (not null) when unset,
            // keeping the shape clean for clients that don't care about booking.
            array_key_exists('booking_mode', $settings) ? ['booking_mode' => $settings['booking_mode']] : [],
            array_key_exists('manual_booking_url', $settings) ? ['manual_booking_url' => $settings['manual_booking_url']] : []);
    }
```

Note: the existing `SiteResourceTest` constructs a `Site` with `settings.booking_mode` and no column → column is null, `$promoted` is empty, the JSONB value wins → `settings.booking_mode` + top-level `booking_mode` preserved. The test stays green unmodified. In real usage post-Phase-1 the column is populated by `UpdateSiteAction`, so the column wins.

### `app/Services/PublicSite/SitepageDataResolverService.php`

`buildServicesData` (lines 590–621) reads `settings['booking_mode']` / `settings['manual_booking_url']` (lines 592–594). Read columns first, fall back to settings (column-authoritative; identical during dual-write).

Replace lines 592–594:

```php
        $settings = is_array($site?->settings) ? $site->settings : [];
        // FOUND-16: booking_mode / manual_booking_url are promoted columns.
        // Column-first with a settings fallback (identical during dual-write,
        // column-authoritative after the strip).
        $bookingMode = strtolower((string) ($site?->booking_mode ?? $settings['booking_mode'] ?? 'manual'));
        $manualBookingUrl = trim((string) ($site?->manual_booking_url ?? $settings['manual_booking_url'] ?? ''));
```

(The rest of `buildServicesData` is unchanged. `IndividualProfilePayloadBuilder::buildServices` drops these from the wire, so this is code-correctness only.)

### Form Requests — widen `booking_mode`, add `charlie_enabled` to Staff

**`app/Http/Requests/Api/User/Site/UpdateSiteRequest.php`** — add the import and reference the const. Add after line 8 (`use Illuminate\Validation\Rule;`):

```php
use App\Models\Core\Site\Site;
```

Replace the `settings.booking_mode` rule (lines 66–70):

```php
            'settings.booking_mode' => [
                'sometimes',
                'string',
                Rule::in(Site::BOOKING_MODES),
            ],
```

**`app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php`** — add the import after line 9 (`use Illuminate\Validation\Rule;`):

```php
use App\Models\Core\Site\Site;
```

Add the missing `charlie_enabled` rule (honored premise A) immediately after `settings.show_branding` (line 81), and widen `booking_mode` (lines 83–87). The block becomes:

```php
            'settings.show_branding' => ['sometimes', 'boolean'],
            'settings.charlie_enabled' => ['sometimes', 'boolean'],
            'settings.services_auto_sync_enabled' => ['sometimes', 'boolean'],
            'settings.booking_mode' => [
                'sometimes',
                'string',
                Rule::in(Site::BOOKING_MODES),
            ],
            'settings.manual_booking_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
```

**`app/Http/Requests/Api/User/Site/UpdateBookingSettingsRequest.php`** (DRIFT 1) — add the import after the existing `use Illuminate\Validation\Rule;`:

```php
use App\Models\Core\Site\Site;
```

Replace `rules()`:

```php
    public function rules(): array
    {
        return [
            'booking_mode' => ['required', 'string', Rule::in(Site::BOOKING_MODES)],
            'manual_booking_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
```

### `tests/Pest.php` — `setupSitesTable()`

Add the 10 columns to the `CREATE TABLE IF NOT EXISTS site.sites (...)` (lines 370–388). Booleans use `INTEGER NULL` (SQLite, matching the `custom_domain_primary INTEGER` convention). Insert before `deleted_at TEXT NULL,`:

```php
        custom_domain_primary INTEGER NOT NULL DEFAULT 0,
        hero_title TEXT NULL,
        hero_subtitle TEXT NULL,
        primary_button_text TEXT NULL,
        primary_button_url TEXT NULL,
        bio_text TEXT NULL,
        show_branding INTEGER NULL,
        charlie_enabled INTEGER NULL,
        services_auto_sync_enabled INTEGER NULL,
        booking_mode TEXT NULL,
        manual_booking_url TEXT NULL,
        deleted_at TEXT NULL,
```

Add a defensive `ALTER ... ADD COLUMN IF NOT EXISTS` loop (mirrors the `moderation_state`/custom-domain pattern at lines 391–412) so a pre-existing test table picks the columns up. Insert after the custom-domain ALTER block (after line 412):

```php
    // FOUND-16 promoted columns — defensive ALTER for any pre-existing test table.
    $promotedCols = [
        'hero_title' => 'TEXT NULL',
        'hero_subtitle' => 'TEXT NULL',
        'primary_button_text' => 'TEXT NULL',
        'primary_button_url' => 'TEXT NULL',
        'bio_text' => 'TEXT NULL',
        'show_branding' => 'INTEGER NULL',
        'charlie_enabled' => 'INTEGER NULL',
        'services_auto_sync_enabled' => 'INTEGER NULL',
        'booking_mode' => 'TEXT NULL',
        'manual_booking_url' => 'TEXT NULL',
    ];
    foreach ($promotedCols as $col => $type) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE site.sites ADD COLUMN IF NOT EXISTS {$col} {$type}");
        } catch (Throwable $e) {
            // already exists / unsupported — ignore
        }
    }
```

(The SQLite `booking_mode` CHECK is NOT enforced — covered by the DEV-SUPABASE VERIFICATION above.)

---

## Phase 1 — TDD tasks

Each task: write failing test → run-fail → minimal code → run-pass → `php artisan pint --dirty` → commit.

### Task 1 — `tests/Pest.php` schema + Site model columns/casts/consts

This is enabling infrastructure; pair it with Task 2's test (a model-level test cannot fail meaningfully before the column exists).

1. Apply the `tests/Pest.php` `setupSitesTable()` edits (CREATE columns + defensive ALTER loop).
2. Apply the `Site.php` edits (`$fillable` +10, `$casts` +3 booleans, `BOOKING_MODES`, `PROMOTED_SETTINGS_KEYS`).
3. Run: `php artisan pint --dirty`
4. Commit: `git commit -m "FOUND-16: add 10 promoted site columns to Site model + SQLite schema"`

### Task 2 — `UpdateSiteAction` hoists settings.* into columns

Failing test (new file `tests/Feature/Services/UpdateSiteSettingsPromotionTest.php`):

```php
<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupCoreUsersTable();
    setupSitesTable();
});

it('hoists the 10 settings.* keys into typed columns', function () {
    $pro = makeProfessionalWithSite(); // helper: see note below

    $action = app(UpdateSiteAction::class);
    $action->execute($pro, [
        'settings' => [
            'hero_title' => 'Hi',
            'hero_subtitle' => 'Sub',
            'primary_button_text' => 'Book',
            'primary_button_url' => 'https://example.com/book',
            'bio_text' => 'About me',
            'show_branding' => false,
            'charlie_enabled' => true,
            'services_auto_sync_enabled' => true,
            'booking_mode' => 'none',
            'manual_booking_url' => 'https://example.com/manual',
        ],
    ]);

    $row = DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->first();

    expect($row->hero_title)->toBe('Hi')
        ->and($row->booking_mode)->toBe('none')
        ->and((int) $row->show_branding)->toBe(0)
        ->and((int) $row->charlie_enabled)->toBe(1)
        ->and($row->manual_booking_url)->toBe('https://example.com/manual');
});
```

> Use the existing site-setup helper from a neighbouring test (e.g. the factory/`setup*` pattern in `tests/Feature/Services/UpdateSiteActionTest.php`) for `makeProfessionalWithSite()` — reuse that test's exact setup rather than inventing a new one.

- Run-fail: `php artisan test tests/Feature/Services/UpdateSiteSettingsPromotionTest.php`
  Expected failure before code: columns are null / `Undefined column` — assertions on `$row->hero_title` fail.
- Apply the `UpdateSiteAction` hoist (Task code above).
- Run-pass: `php artisan test tests/Feature/Services/UpdateSiteSettingsPromotionTest.php` → green.
- `php artisan pint --dirty`
- Commit: `git commit -m "FOUND-16: hoist settings.* into typed columns in UpdateSiteAction"`

### Task 3 — `SiteResource` reads columns, stays byte-identical

Failing test (append to `tests/Unit/Resources/SiteResourceTest.php`):

```php
it('builds settings.booking_mode + top-level booking_mode from the promoted column', function () {
    $site = new Site([
        'subdomain' => 'example',
        'skeleton_id' => 'skeleton-1',
        'is_published' => true,
        'settings' => [],
    ]);
    $site->id = '22222222-2222-2222-2222-222222222222';
    // Column is the source of truth; settings JSONB is empty (post-strip shape).
    $site->booking_mode = 'none';
    $site->show_branding = false;

    $array = (new SiteResource($site))->resolve();

    expect($array['settings']->booking_mode)->toBe('none')
        ->and($array['settings']->show_branding)->toBeFalse()
        ->and($array['booking_mode'])->toBe('none')
        ->and($array)->not->toHaveKey('manual_booking_url');
});
```

- Run-fail: `php artisan test tests/Unit/Resources/SiteResourceTest.php`
  Expected failure before code: `$array['settings']` has no `booking_mode` (resource reads only the empty JSONB) → undefined property.
- Apply the `SiteResource::toArray` edit.
- Run-pass: `php artisan test tests/Unit/Resources/SiteResourceTest.php` → all 3 green (the two existing golden-master cases stay green unmodified).
- `php artisan pint --dirty`
- Commit: `git commit -m "FOUND-16: SiteResource re-merges promoted columns into settings (byte-identical)"`

### Task 4 — Form Request widening + Staff charlie_enabled

Failing test (new file `tests/Feature/Api/User/SiteManagement/BookingModeValidationTest.php`) — exercise the generic, staff, and booking endpoints. Mirror the request/route wiring from the existing `tests/Feature/Api/User/SiteManagement/UpdateSiteValidationTest.php` and `tests/Feature/Api/Staff/UserSiteManagement/StaffUpdateSiteValidationTest.php`:

```php
<?php

// Grounded on the real idioms: UpdateSiteValidationTest.php (user PATCH /api/site
// via actingAsUser + createTenant) and StaffUpdateSiteValidationTest.php (staff
// PATCH /api/staff/professionals/{id}/site via actingAsStaff + PartnaStaff factory).
// No `uses(TestCase::class)` line — Pest auto-binds the Feature dir (tests/Pest.php).

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // PATCH /api/site carries throttle:authenticated — disable so repeated runs in
    // one process don't trip the limiter; the subdomain closure queries the alias
    // tables; the staff case needs the staff table.
    config(['partna.throttle.enabled' => false]);
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
    setupPartnaStaffTable();
});

it('accepts booking_mode none on the user site-update endpoint and persists the column', function () {
    $pro = createTenant('booking-none');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['booking_mode' => 'none']])
        ->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('booking_mode'))
        ->toBe('none');
});

it('rejects an unknown booking_mode with a 422 on settings.booking_mode', function () {
    $pro = createTenant('booking-bad');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['booking_mode' => 'calendly']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.booking_mode']);
});

it('accepts and persists charlie_enabled on the staff site-update endpoint', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-charlie');

    actingAsStaff($staff)
        ->patchJson("/api/staff/professionals/{$pro->id}/site", ['settings' => ['charlie_enabled' => true]])
        ->assertOk();

    expect((bool) DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('charlie_enabled'))
        ->toBeTrue();
});
```

> The persisted-column assertions require `setupSitesTable()` in `tests/Pest.php` to already
> carry the `booking_mode` / `charlie_enabled` columns — that edit is Task 1 of this PR, so it
> lands before this test runs.

- Run-fail: `php artisan test tests/Feature/Api/User/SiteManagement/BookingModeValidationTest.php`
  Expected failure before code: `'none'` is rejected (422) by the current `Rule::in(['manual'])`; the staff `charlie_enabled` is silently dropped (not persisted) → column assertion fails.
- Apply the three Form Request edits.
- Run-pass: that file → green.
- `php artisan pint --dirty`
- Commit: `git commit -m "FOUND-16: widen booking_mode to manual|none + add charlie_enabled to staff request"`

### Task 5 — resolver reads the booking_mode column

Failing test: extend an existing resolver/public-profile test that exercises `buildServicesData` (search `tests/Feature/PublicSite/` for a services/booking assertion), or add a focused unit test constructing a `Site` with `booking_mode` column set and asserting `buildServicesData($site, $proId)['booking_mode']` equals the column even when `settings` is empty.

```php
it('reads booking_mode from the column not settings', function () {
    $site = new Site(['settings' => []]);
    $site->booking_mode = 'none';
    $site->manual_booking_url = 'https://example.com/x';

    $data = app(\App\Services\PublicSite\SitepageDataResolverService::class)
        ->buildServicesData($site, (string) \Illuminate\Support\Str::uuid());

    expect($data['booking_mode'])->toBe('none')
        ->and($data['manual_booking_url'])->toBe('https://example.com/x');
})->skip(/* requires services table setup — wire via setupServicesTable() if present */ false);
```

> If `buildServicesData` needs the services table, reuse the resolver test's existing `setup*` helpers. If that scaffolding is heavy, the column-read is already covered indirectly by Task 2 + the golden masters — in that case fold this assertion into an existing resolver test rather than a new file.

- Run-fail / run-pass: `php artisan test <that file>`.
- Apply the resolver edit.
- `php artisan pint --dirty`
- Commit: `git commit -m "FOUND-16: SitepageDataResolverService reads booking_mode column"`

### Golden-master guard (Phase 1 — must stay green, unmodified)
- `tests/Unit/Resources/SiteResourceTest.php` — both existing cases (settings passthrough + empty `{}`).
- `tests/Feature/Services/UpdateSiteActionTest.php` — subdomain/publish/settings-merge behaviour.
- `tests/Feature/Api/User/SiteManagement/UpdateSiteValidationTest.php`
- `tests/Feature/Api/Staff/UserSiteManagement/StaffUpdateSiteValidationTest.php`
- `tests/Feature/Api/Staff/StaffSiteControllerTest.php` — staff `site.settings` passthrough (line 82 asserts `['hero_title' => 'Hello']`; unchanged in Phase 1 since views are untouched).
- `tests/Feature/PublicSite/IndividualProfileControllerTest.php`, `tests/Feature/PublicSite/PublicSiteControllerShowTest.php` — public payload shape (booking keys dropped from wire; unaffected).

### Phase 1 final gate
Run the **FULL** suite (a filtered subset is a false signal — Wave-1 hit a 9-test regression only the full run caught):
```
composer test
```
Expected: all green. If `composer dump-autoload -o` is stale after the model edit, run it from the real checkout root.

---

## Phase 2 — migration B: strip JSONB keys + `CREATE OR REPLACE` both views (GATING)

**This is the gating step — silent NULL data-loss if the view re-inject is wrong or omitted.** Both views currently emit the whole `s.settings` blob, so the strip MUST be accompanied by re-injecting the 10 columns back into the emitted settings, **in the same migration**. Recommended sequencing: land + verify migration A in prod first, then ship migration B as a follow-up window (dual-write decouples them). Pre-beta zero rows make a single combined window safe too.

Code delta for Phase 2 (one line): in `UpdateSiteAction`, uncomment `unset($merged[$key]);` inside the hoist loop so new writes stop populating the JSONB mirror. No other code changes (readers are already column-first).

### `supabase/migrations/20260701000100_strip_site_settings_jsonb_keys.sql`

```sql
-- =====================================================================
-- FOUND-16 Phase 2 — strip promoted keys from settings JSONB + re-inject
--                     the columns into both public-read views (LOCKSTEP)
-- =====================================================================
-- GATING: both views emit the whole s.settings blob. Stripping the 10 keys
-- from settings WITHOUT re-injecting the columns into the views would silently
-- drop hero_title/booking_mode/etc. from the public-site payload, the staff
-- view, and the dashboard — a NULL, not an error. The strip and the view
-- CREATE OR REPLACE therefore ship in one atomic migration.
-- =====================================================================
BEGIN;

-- 1. Re-inject the 10 promoted columns into site.all_site_data.site_settings.
--    IMPORTANT (reconciliation directive #1): PR6 lands AFTER PR5, so this body is
--    authored against the POST-PR5 view — PR5 (FOUND-15) already added
--    'platform'/'category'/'live_check_enabled' to the blocks[] objects; they are
--    carried forward VERBATIM here. When writing the real migration, author it
--    against the THEN-CURRENT view (run `\sf site.all_site_data` first) so a
--    concurrent block-key change is never silently dropped. Change vs the post-PR5
--    body: s.settings AS site_settings  ->  the settings merge below.
--    jsonb_strip_nulls drops NULL columns so unset keys stay absent (matching
--    SiteResource's !== null re-merge); booleans (incl. false) are kept.
CREATE OR REPLACE VIEW site.all_site_data AS
SELECT
    s.id AS site_id,
    s.user_id,
    s.subdomain,
    s.is_published,
    s.skeleton_id,
    (s.settings || jsonb_strip_nulls(jsonb_build_object(
        'hero_title', s.hero_title,
        'hero_subtitle', s.hero_subtitle,
        'primary_button_text', s.primary_button_text,
        'primary_button_url', s.primary_button_url,
        'bio_text', s.bio_text,
        'show_branding', s.show_branding,
        'charlie_enabled', s.charlie_enabled,
        'services_auto_sync_enabled', s.services_auto_sync_enabled,
        'booking_mode', s.booking_mode,
        'manual_booking_url', s.manual_booking_url
    ))) AS site_settings,
    s.created_at AS site_created_at,
    s.updated_at AS site_updated_at,
    p.handle,
    p.display_name,
    p.bio,
    p.location_street_address,
    p.location_city,
    p.location_state,
    p.location_postcode,
    p.location_country,
    COALESCE(
        jsonb_agg(
            jsonb_build_object(
                'id', b.id,
                'site_id', b.site_id,
                'user_id', b.user_id,
                'block_type', b.block_type,
                'block_group', b.block_group,
                'title', b.title,
                'url', b.url,
                'icon_key', b.icon_key,
                'sort_order', b.sort_order,
                'is_active', b.is_active,
                'settings', b.settings,
                'platform', b.platform,
                'category', b.category,
                'live_check_enabled', b.live_check_enabled,
                'created_at', b.created_at,
                'updated_at', b.updated_at
            )
            ORDER BY b.sort_order
        ) FILTER (WHERE b.id IS NOT NULL),
        '[]'::jsonb
    ) AS blocks,
    p.account_type
FROM site.sites s
    JOIN core.users p ON p.id = s.user_id
    LEFT JOIN site.blocks b ON b.site_id = s.id
GROUP BY s.id, p.id;

-- 2. Re-inject into site.public_site_payload (payload.site.settings).
--    Authored against the POST-PR5 body (PR5 added the three block keys to the
--    links[] objects — carried forward below). Change vs the post-PR5 body:
--    'settings', s.settings  ->  the settings merge.
CREATE OR REPLACE VIEW site.public_site_payload AS
SELECT
    s.id AS site_id,
    s.user_id,
    s.subdomain,
    jsonb_build_object(
        'site', jsonb_build_object(
            'id', s.id,
            'subdomain', s.subdomain,
            'settings', (s.settings || jsonb_strip_nulls(jsonb_build_object(
                'hero_title', s.hero_title,
                'hero_subtitle', s.hero_subtitle,
                'primary_button_text', s.primary_button_text,
                'primary_button_url', s.primary_button_url,
                'bio_text', s.bio_text,
                'show_branding', s.show_branding,
                'charlie_enabled', s.charlie_enabled,
                'services_auto_sync_enabled', s.services_auto_sync_enabled,
                'booking_mode', s.booking_mode,
                'manual_booking_url', s.manual_booking_url
            ))),
            'is_published', s.is_published,
            'skeleton_id', s.skeleton_id,
            'gallery', COALESCE((
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', sm.id,
                        'alt_text', sm.alt_text,
                        'caption', sm.caption,
                        'sort_order', sm.sort_order,
                        'variants', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'webp'::text
                        ), '{}'::jsonb)
                    )
                    ORDER BY sm.sort_order, sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'gallery'::text
                    AND sm.media_type::text = 'image'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
            ), '[]'::jsonb),
            'content_images', COALESCE((
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', sm.id,
                        'alt_text', sm.alt_text,
                        'caption', sm.caption,
                        'sort_order', sm.sort_order,
                        'variants', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'webp'::text
                        ), '{}'::jsonb)
                    )
                    ORDER BY sm.sort_order, sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'content'::text
                    AND sm.media_type::text = 'image'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
            ), '[]'::jsonb),
            'gallery_videos', COALESCE((
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', sm.id,
                        'alt_text', sm.alt_text,
                        'caption', sm.caption,
                        'sort_order', sm.sort_order,
                        'media_type', sm.media_type,
                        'processing_state', sm.processing_state,
                        'duration_ms', sm.duration_ms,
                        'poster', sm.poster_path,
                        'variants', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'mp4'::text
                        ), '{}'::jsonb),
                        'streams', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'hls_playlist'::text
                        ), '{}'::jsonb)
                    )
                    ORDER BY sm.sort_order, sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'gallery'::text
                    AND sm.media_type::text = 'video'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
            ), '[]'::jsonb),
            'content_videos', COALESCE((
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', sm.id,
                        'alt_text', sm.alt_text,
                        'caption', sm.caption,
                        'sort_order', sm.sort_order,
                        'media_type', sm.media_type,
                        'processing_state', sm.processing_state,
                        'duration_ms', sm.duration_ms,
                        'poster', sm.poster_path,
                        'variants', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'mp4'::text
                        ), '{}'::jsonb),
                        'streams', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'hls_playlist'::text
                        ), '{}'::jsonb)
                    )
                    ORDER BY sm.sort_order, sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'content'::text
                    AND sm.media_type::text = 'video'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
            ), '[]'::jsonb),
            'document', (
                SELECT jsonb_build_object(
                    'id', sm.id,
                    'title', sm.alt_text,
                    'caption', sm.caption,
                    'original_mime', sm.original_mime,
                    'original_size_bytes', sm.original_size_bytes,
                    'original_filename', sm.original_filename,
                    'preview_url', sm.path,
                    'created_at', sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'documents'::text
                    AND sm.media_type::text = 'document'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
                LIMIT 1
            )
        ),
        'professional', jsonb_build_object(
            'id', p.id,
            'handle', p.handle,
            'display_name', p.display_name,
            'bio', p.bio,
            'country_code', p.country_code,
            'timezone', p.timezone,
            'public_contact_number', p.public_contact_number,
            'public_contact_email', p.public_contact_email
        ),
        'skeleton_id', s.skeleton_id,
        'links', COALESCE((
            SELECT jsonb_agg(
                jsonb_build_object(
                    'id', b.id,
                    'block_type', b.block_type,
                    'title', b.title,
                    'url', b.url,
                    'icon_key', b.icon_key,
                    'sort_order', b.sort_order,
                    'settings', b.settings,
                    'platform', b.platform,
                    'category', b.category,
                    'live_check_enabled', b.live_check_enabled
                )
                ORDER BY b.sort_order, b.created_at
            )
            FROM site.blocks b
            WHERE b.site_id = s.id
                AND b.block_group = 'links'::text
                AND b.is_active = true
                AND b.deleted_at IS NULL
        ), '[]'::jsonb),
        'sections', COALESCE((
            SELECT jsonb_agg(
                jsonb_build_object(
                    'id', b.id,
                    'block_type', b.block_type,
                    'title', b.title,
                    'url', b.url,
                    'icon_key', b.icon_key,
                    'sort_order', b.sort_order,
                    'is_enabled', b.is_enabled,
                    'is_active', b.is_active,
                    'settings', b.settings
                )
                ORDER BY b.sort_order, b.created_at
            )
            FROM site.blocks b
            WHERE b.site_id = s.id
                AND b.block_group = 'sections'::text
                AND b.is_enabled = true
                AND b.is_active = true
                AND b.deleted_at IS NULL
        ), '[]'::jsonb),
        'services', COALESCE((
            SELECT jsonb_agg(
                jsonb_build_object(
                    'id', sv.id,
                    'title', sv.title,
                    'description', sv.description,
                    'price_cents', sv.price_cents,
                    'currency_code', sv.currency_code,
                    'duration_minutes', sv.duration_minutes,
                    'is_active', sv.is_active,
                    'sort_order', sv.sort_order,
                    'category', COALESCE(sc.title, 'Services'::text)
                )
                ORDER BY (COALESCE(sc.sort_order, 2147483647)),
                         (lower(COALESCE(sc.title, 'Services'::text))),
                         sv.sort_order,
                         sv.created_at
            )
            FROM site.services sv
                LEFT JOIN site.service_categories sc ON sc.id = sv.category_id AND sc.deleted_at IS NULL
            WHERE sv.user_id = p.id
                AND sv.is_active = true
                AND sv.deleted_at IS NULL
        ), '[]'::jsonb)
    ) AS payload
FROM site.sites s
    JOIN core.users p ON p.id = s.user_id
WHERE s.is_published = true
    AND p.status = 'active'::text
    AND p.deleted_at IS NULL;

-- CREATE OR REPLACE VIEW preserves existing grants (unlike DROP+CREATE), so no
-- re-GRANT block is required here. (The 20260527070000 migration re-granted
-- only because it DROPped the views first.)

-- 3. Strip the 10 promoted keys from settings JSONB. Now redundant with the
--    columns; the views re-inject them so consumers see no change.
UPDATE site.sites SET settings = settings
    - 'hero_title'
    - 'hero_subtitle'
    - 'primary_button_text'
    - 'primary_button_url'
    - 'bio_text'
    - 'show_branding'
    - 'charlie_enabled'
    - 'services_auto_sync_enabled'
    - 'booking_mode'
    - 'manual_booking_url'
WHERE settings IS NOT NULL;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- -- 1. Re-inject the 10 keys back into settings JSONB from the columns.
-- UPDATE site.sites SET settings = settings || jsonb_strip_nulls(jsonb_build_object(
--     'hero_title', hero_title,
--     'hero_subtitle', hero_subtitle,
--     'primary_button_text', primary_button_text,
--     'primary_button_url', primary_button_url,
--     'bio_text', bio_text,
--     'show_branding', show_branding,
--     'charlie_enabled', charlie_enabled,
--     'services_auto_sync_enabled', services_auto_sync_enabled,
--     'booking_mode', booking_mode,
--     'manual_booking_url', manual_booking_url
-- )) WHERE settings IS NOT NULL;
-- -- 2. Restore both views to the plain s.settings passthrough but KEEP PR5's three
-- --    block keys (platform/category/live_check_enabled) in blocks[]/links[] — i.e.
-- --    the POST-PR5 view bodies, NOT the original 20260527070000 bodies
-- --    (site_settings = s.settings, payload.site.settings = s.settings).
-- --    CREATE OR REPLACE with those bodies.
-- COMMIT;
```

### DEV-SUPABASE VERIFICATION (Phase 2 — the view lockstep; cannot be tested on SQLite)

The SQLite views are seeded stand-in tables, so the re-inject correctness is **only** observable on real Postgres. After applying migration B to `glncumufgaqcmqhzwrxm`, confirm the 10 keys survive in both views for a published, active site.

Pick a published/active site (or seed one in a rolled-back txn), set a column, and read the view:
```sql
-- Seed a column value, read the public payload, then read the staff view.
BEGIN;
UPDATE site.sites SET hero_title = 'PR6 check', booking_mode = 'none'
    WHERE id = (SELECT s.id FROM site.sites s JOIN core.users p ON p.id = s.user_id
                WHERE s.is_published = true AND p.status = 'active' AND p.deleted_at IS NULL LIMIT 1);

SELECT payload->'site'->'settings'->>'hero_title'  AS hero,
       payload->'site'->'settings'->>'booking_mode' AS mode
FROM site.public_site_payload
WHERE site_id = (SELECT s.id FROM site.sites s JOIN core.users p ON p.id = s.user_id
                 WHERE s.is_published = true AND p.status = 'active' AND p.deleted_at IS NULL LIMIT 1);
-- Expected: hero = 'PR6 check', mode = 'none'

SELECT site_settings->>'hero_title' AS hero
FROM site.all_site_data
WHERE site_id = (SELECT s.id FROM site.sites s JOIN core.users p ON p.id = s.user_id LIMIT 1);
-- Expected: hero = 'PR6 check' (for that same site)
ROLLBACK;
```
Also confirm a NULL column does **not** add a key (absent, not null):
```sql
SELECT (payload->'site'->'settings') ? 'manual_booking_url' AS has_key
FROM site.public_site_payload LIMIT 1;
-- Expected: false  (manual_booking_url column is NULL on that row)
```
Finally — reconciliation directive #1 cross-check — confirm PR5's block keys **survived** this
view rewrite (PR6 must NOT revert PR5):
```sql
SELECT (payload->'links'->0) ? 'platform'           AS links_has_platform,
       (payload->'links'->0) ? 'category'           AS links_has_category,
       (payload->'links'->0) ? 'live_check_enabled' AS links_has_live_check
FROM site.public_site_payload
WHERE jsonb_array_length(payload->'links') > 0 LIMIT 1;
-- Expected: all three true. If ANY is false, this migration dropped a PR5 key —
-- re-author both view bodies against the post-PR5 definition before proceeding.
```

### Phase 2 golden masters
- `tests/Feature/Api/Staff/StaffSiteControllerTest.php` (line 82, `site.settings` shape) stays green on SQLite (stand-in table seeds `site_settings` directly — the prod re-inject is verified above on dev Supabase).
- `tests/Feature/PublicSite/PublicSiteControllerShowTest.php` / `IndividualProfileControllerTest.php` stay green (SQLite stand-in `public_site_payload`; prod view verified above).
- Apply the `UpdateSiteAction` `unset($merged[$key])` one-liner; re-run Task 2's promotion test — it must still pass (columns populated) and, if you assert it, `settings` no longer carries the 10 keys.

### Phase 2 final gate
```
composer test
```
Full suite green, plus the DEV-SUPABASE VERIFICATION queries above confirmed on `glncumufgaqcmqhzwrxm`.

---

## Closing gate (whole PR)
- FULL `composer test` green (Phase 1 always; Phase 2 if bundled).
- `booking_mode` CHECK verified on dev Supabase (rejects `'calendly'`, accepts `'manual'`/`'none'`/NULL).
- If Phase 2 lands: both views verified on dev Supabase to still emit the 10 keys under `payload.site.settings` / `site_settings`, with NULL columns absent.
- `php artisan pint --dirty` clean on every commit.


---

## PR7 — FOUND-3 — `SiteMedia` cover purposes → registry-derived convention (index collapse)

### Goal
Make adding a per-platform cover-image slot a one-line registry flag (no new PHP const, no new list
entry, no new migration) by deriving the design-singleton purpose allowlist from the platform registry
and collapsing the five per-purpose partial-unique cover indexes into one composite index.

### Architecture
A new `coverable` flag on `PlatformDescriptor` (mirrors the existing `refreshable` flag) marks the exactly-4
cover-capable platforms (`youtube`, `apple-music`, `apple-podcast`, `eventbrite`). `SiteMedia` drops its
five `PURPOSE_COVER_*` consts and replaces the `DESIGN_SINGLETON_PURPOSES` **const** with a static
**method** `designSingletonPurposes()` that returns the two logo purposes plus `cover_` + the
underscore-normalized registry key for each coverable platform (a const can't call the runtime registry —
this is the load-bearing change). The DB-level singleton guard collapses from five per-purpose partial
unique indexes to one composite `(site_id, purpose) WHERE pool='design' AND deleted_at IS NULL`, which is
strictly stronger and needs no edit per new platform.

### Blast radius (files)
- `app/Models/Core/Site/SiteMedia.php` — delete 5 `PURPOSE_COVER_*` consts + the `DESIGN_SINGLETON_PURPOSES` const; add `designSingletonPurposes()` static method.
- `app/Services/Platforms/Registry/PlatformDescriptor.php` — add `coverable` flag (property + fluent setter + `isCoverable()` getter).
- `app/Services/Platforms/Registry/PlatformRegistry.php` — add `coverable()` filter.
- `app/Providers/PlatformRegistryServiceProvider.php` — add `->coverable()` to the 4 descriptors.
- `app/Http/Requests/Api/User/Uploads/UploadDesignMediaRequest.php` — const→method.
- `app/Http/Controllers/Api/User/Uploads/UserDesignMediaController.php` — const→method (2 sites).
- `app/Services/PublicSite/SitepageDataResolverService.php` — const→method (1 site).
- `supabase/migrations/20260701000000_collapse_cover_singleton_indexes.sql` — NEW (index-only).
- `tests/Feature/Platforms/Registry/RegistryCoverageTest.php` — new `coverable()` pin.
- `tests/Feature/Media/DesignSingletonMediaTest.php` — drop dead `cover_shopify`, add method-pin + rejection tests.

NOT touched (verified): `app/Services/Media/MediaUploadService.php` (purpose-agnostic for covers — only special-cases the LOGO purposes, which stay; it remains the app-side singleton replace). `tests/Pest.php` (index-only migration adds no column; `site.site_media.purpose` already present).

### DECISION (confirm before implementing)
**Drop the two baseline logo partial-unique indexes (`site_media_design_logo_full_uq`,
`site_media_design_logo_square_uq`) as part of the collapse — recommended.** They are strictly subsumed by
the new composite `(site_id, purpose)` index (the composite restricted to a single purpose IS the logo
index), so keeping them is pure redundancy. Alternative: leave them in place (harmless, but defeats the
"index collapse" intent and leaves two dead indexes). The plan below drops them; the rollback recreates
them verbatim from the baseline.

### Premise grounding (honored corrections + drift found)
- **HONORED (1):** `cover_shopify` is a DEAD slot — there is no `shopify` platform in the registry (the
  registered Shopify-adjacent key is `shop`, a multi-brand resource, NOT a coverable platform). It is
  DROPPED, never migrated. Confirmed: `grep PURPOSE_COVER` shows `cover_shopify` is referenced ONLY inside
  `SiteMedia.php` + two test assertions; no production reader depends on it.
- **HONORED (2):** registry keys are hyphenated (`apple-music`) while cover purposes are underscored
  (`cover_apple_music`). Confirmed the wire-key derivation in `IndividualProfilePayloadBuilder.php:232` is
  `lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $purpose))))` — it treats `_` as the word
  boundary. A naive `cover_apple-music` would become `coverApple-music` (hyphen survives), breaking the
  partna-pages `siteImages` contract. The convention MUST be `cover_` + `str_replace('-','_',$key)`.
  (Minor wording drift vs the audit/design-plan, which say "splits on `_`": the actual transform is an
  `ucwords` over underscore-as-space, not a literal `explode('_')` — same boundary, identical effect on
  the convention. Noted for the assembler; no code consequence.)
- **HONORED (3):** no `coverable` flag exists on `PlatformDescriptor` today — introduced here. The
  cover-capable set is EXACTLY 4: `youtube` (provider line 148), `apple-music` (184), `apple-podcast` (190),
  `eventbrite` (205).
- **Index drift confirmed:** the live per-purpose cover indexes on dev are five — `cover_youtube`,
  `cover_apple_music`, `cover_apple_podcast`, `cover_eventbrite` (`20260604000001`) and `cover_shopify`
  (`20260604000002`, which DROPPED the earlier `cover_fresha`). There is NO live `cover_fresha` index. The
  collapse drops these five; the rollback recreates only the FOUR live covers (not `shopify`, not the
  already-dead `fresha`).
- **Placeholder caveat confirmed:** the composite `(site_id, purpose)` predicate covers ALL design
  purposes, so it adds a NEW (currently dormant) one-`placeholder`-per-site guard. No code creates
  design-pool `placeholder` rows, and `designSingletonPurposes()` does not include `placeholder`, so this
  is inert pre-beta. The baseline `site_media_design_placeholder_sort_uq` index (`(site_id, sort_order)
  WHERE … purpose='placeholder' … is_active=true`) is a DIFFERENT shape and is NOT subsumed — it is LEFT
  in place.

---

### Premise-verification commands (run first, expect the cited output)

```bash
# 1) cover_shopify has no production reader (only SiteMedia.php consts + tests):
grep -rn "cover_shopify\|PURPOSE_COVER_SHOPIFY" app tests --include="*.php"
#   → SiteMedia.php const lines + DesignSingletonMediaTest.php:78,176 only.

# 2) DESIGN_SINGLETON_PURPOSES const has exactly 4 call sites (request / controller×2 / resolver):
grep -rn "DESIGN_SINGLETON_PURPOSES" app tests --include="*.php"
#   → UploadDesignMediaRequest:23, UserDesignMediaController:44/51, SitepageDataResolverService:257.

# 3) the LOGO consts (which STAY) are used elsewhere — do NOT delete them:
grep -rn "PURPOSE_LOGO_FULL\|PURPOSE_LOGO_SQUARE" app tests --include="*.php"
#   → SiteMedia.php + ProEmailBrandResolver.php:87/91 + MediaUploadService.php:438 + ProEmailBrandResolverTest:74.

# 4) latest migration timestamp (your file must sort AFTER this):
ls supabase/migrations/ | sort | tail -3
#   → … 20260630000000_drop_smart_links.sql
```

---

### Task 1 — `coverable` flag on the registry (descriptor + filter + 4 descriptors)

**1a. Failing test** — add to `tests/Feature/Platforms/Registry/RegistryCoverageTest.php` (after the
`refreshable` pin, ~line 51):

```php
it('marks exactly the cover-capable platforms as coverable', function () {
    $registry = app(PlatformRegistry::class);
    $coverable = array_keys($registry->coverable());
    sort($coverable);

    // The 4 platforms with a per-integration cover-image slot (SiteMedia design singletons).
    $expected = ['apple-music', 'apple-podcast', 'eventbrite', 'youtube'];
    sort($expected);

    expect($coverable)->toBe($expected);
});
```

**Run-fail:** `php artisan test tests/Feature/Platforms/Registry/RegistryCoverageTest.php`
**Expected failure:** `Error: Call to undefined method App\Services\Platforms\Registry\PlatformRegistry::coverable()`.

**1b. Minimal code.**

`app/Services/Platforms/Registry/PlatformDescriptor.php` — add the property next to `$refreshable`
(after line 28 `private bool $refreshable = false;`):

```php
    private bool $coverable = false;
```

Add the fluent setter immediately after the `refreshable()` method (after line 92):

```php
    /**
     * Cover-image capability — whether this platform has a design-pool cover-image
     * singleton slot (`cover_<key>`). Drives SiteMedia::designSingletonPurposes() so
     * adding a cover for a new platform is this one flag, not a new const + list +
     * migration. Mirrors refreshable(): identity metadata, no behaviour attached.
     */
    public function coverable(bool $coverable = true): self
    {
        $this->coverable = $coverable;

        return $this;
    }
```

Add the getter immediately after `isRefreshable()` (after line 130):

```php
    public function isCoverable(): bool
    {
        return $this->coverable;
    }
```

`app/Services/Platforms/Registry/PlatformRegistry.php` — add the filter immediately after `refreshable()`
(after line 47):

```php
    /**
     * Platforms that own a design-pool cover-image singleton slot. Read by
     * SiteMedia::designSingletonPurposes() to build the `cover_<key>` allowlist.
     *
     * @return array<string, PlatformDescriptor>
     */
    public function coverable(): array
    {
        return array_filter($this->descriptors, fn (PlatformDescriptor $d) => $d->isCoverable());
    }
```

`app/Providers/PlatformRegistryServiceProvider.php` — add `->coverable()` to the 4 descriptors. Insert it
right after `->refreshable()` on each:

- youtube (line 148):
  ```php
              $r->register(PD::make('youtube')->label('YouTube')->category(Cat::Content)->resource(YoutubeConnectionResource::class)->refreshable()->coverable()
                  ->payload(FeedPayload::class));
  ```
- apple-music (line 184):
  ```php
              $r->register(PD::make('apple-music')->label('Apple Music')->category(Cat::Music)->resource(AppleMusicConnectionResource::class)->refreshable()->coverable()
                  ->payload(FeedPayload::class));
  ```
- apple-podcast (line 190):
  ```php
              $r->register(PD::make('apple-podcast')->label('Apple Podcasts')->category(Cat::Content)->resource(ApplePodcastConnectionResource::class)->refreshable()->coverable()
                  ->payload(FeedPayload::class));
  ```
- eventbrite (line 205):
  ```php
              $r->register(PD::make('eventbrite')->label('Eventbrite')->category(Cat::Events)->resource(EventbriteConnectionResource::class)->refreshable()->coverable()->payload(EventsAccountPayload::class));
  ```

**Run-pass:** `php artisan test tests/Feature/Platforms/Registry/RegistryCoverageTest.php`
**Expected:** all green (the existing key-set + refreshable pins unchanged; the new coverable pin passes).

**Style + commit:**
```bash
php artisan pint --dirty
git commit -am "feat(platforms): add coverable flag to PlatformDescriptor + registry (FOUND-3)"
```

---

### Task 2 — `SiteMedia::designSingletonPurposes()` method + swap the 4 call sites

**2a. Failing tests.**

Add to `tests/Feature/Media/DesignSingletonMediaTest.php` (new test, anywhere in the validation block):

```php
it('derives the design singleton purposes from the registry (2 logos + 4 covers, no dead shopify)', function () {
    $purposes = SiteMedia::designSingletonPurposes();
    sort($purposes);

    $expected = [
        'logo_full', 'logo_square',
        'cover_youtube', 'cover_apple_music', 'cover_apple_podcast', 'cover_eventbrite',
    ];
    sort($expected);

    expect($purposes)->toBe($expected);
    expect($purposes)->not->toContain('cover_shopify'); // retired dead slot — no shopify platform exists
});
```

Edit the existing "accepts every integration cover purpose" test (line 77-86) — drop the dead
`cover_shopify`:

```php
it('accepts every integration cover purpose', function () {
    foreach (['cover_youtube', 'cover_apple_music', 'cover_apple_podcast', 'cover_eventbrite'] as $purpose) {
        $result = validateDesignMediaRequest(
            ['purpose' => $purpose],
            ['image' => UploadedFile::fake()->image('cover.png', 400, 200)],
        );

        expect($result['errors'] ?? [])->not->toHaveKey('purpose');
    }
});
```

Add a rejection test for the retired slot (after the above):

```php
it('rejects the retired cover_shopify slot', function () {
    $result = validateDesignMediaRequest(
        ['purpose' => 'cover_shopify'],
        ['image' => UploadedFile::fake()->image('x.png')],
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('purpose');
});
```

Edit the "reads back current design singletons by purpose" test (lines 161-177) — `cover_shopify` is no
longer an enumerated slot, so assert a still-live empty cover and assert the dead key is absent. Replace
line 176 and add one line:

```php
    expect($data['images']['logo_square'])->toBeNull();
    expect($data['images']['cover_eventbrite'])->toBeNull(); // a still-live cover slot, empty
    expect($data['images'])->not->toHaveKey('cover_shopify'); // retired — not enumerated anymore
```

**Run-fail:** `php artisan test tests/Feature/Media/DesignSingletonMediaTest.php`
**Expected failure:** `Error: Call to undefined method App\Models\Core\Site\SiteMedia::designSingletonPurposes()`.

**2b. Minimal code.**

`app/Models/Core/Site/SiteMedia.php`:

Add the import near the top (after line 6 `use App\Models\Core\MediaVariant;`):

```php
use App\Services\Platforms\Registry\PlatformRegistry;
```

DELETE the five cover consts (lines 47-56, the `// Per-integration cover images…` block through
`PURPOSE_COVER_EVENTBRITE`). Keep `PURPOSE_LOGO_FULL`, `PURPOSE_LOGO_SQUARE`, `PURPOSE_PLACEHOLDER`.

DELETE the `DESIGN_SINGLETON_PURPOSES` const (the docblock at lines 58-66 plus the `const … = [ … ];` array
at lines 67-75). Replace the whole block with this static method:

```php
    /**
     * Design-pool singleton purposes — one row per (site, purpose): the two brand
     * logos plus one cover image per cover-capable platform. The cover slots are
     * DERIVED from the platform registry (PlatformDescriptor::isCoverable) so adding
     * a cover for a new platform is a one-line descriptor flag, not a new const +
     * list entry + migration.
     *
     * Registry keys are hyphenated (`apple-music`) but media purposes are underscored
     * (`cover_apple_music`): IndividualProfilePayloadBuilder derives the camelCase
     * `siteImages` wire key by treating `_` as the word boundary, so a hyphen would
     * leak through (`coverApple-music`) and break the partna-pages contract. Hence the
     * convention is `cover_` + the registry key with hyphens normalized to underscores.
     *
     * A const can't call the runtime registry — this is a method by necessity.
     * Enforced at the DB by the composite partial unique index
     * site_media_design_singleton_purpose_uq and the app-side replace in
     * MediaUploadService::uploadSingleton. UploadDesignMediaRequest validates the
     * incoming purpose against this allowlist.
     *
     * @return list<string>
     */
    public static function designSingletonPurposes(): array
    {
        $covers = array_map(
            static fn (string $key): string => 'cover_'.str_replace('-', '_', $key),
            array_keys(app(PlatformRegistry::class)->coverable()),
        );

        return array_merge([self::PURPOSE_LOGO_FULL, self::PURPOSE_LOGO_SQUARE], array_values($covers));
    }
```

Swap the four call sites const→method:

`app/Http/Requests/Api/User/Uploads/UploadDesignMediaRequest.php:23`:
```php
            'purpose' => ['required', 'string', Rule::in(SiteMedia::designSingletonPurposes())],
```

`app/Http/Controllers/Api/User/Uploads/UserDesignMediaController.php` line 44:
```php
            ->whereIn('purpose', SiteMedia::designSingletonPurposes())
```
and line 51:
```php
        foreach (SiteMedia::designSingletonPurposes() as $purpose) {
```

`app/Services/PublicSite/SitepageDataResolverService.php:257`:
```php
            ->whereIn('purpose', SiteMedia::designSingletonPurposes())
```

(Optional cosmetic: update the stale `cover_shopify` mention in the `UserDesignMediaController` class
docblock line 17 and the `SitepageDataResolverService` docblock line 238 to drop `cover_shopify` — comment
only, no behaviour.)

**Run-pass:** `php artisan test tests/Feature/Media/DesignSingletonMediaTest.php`
**Expected:** all green — including the unchanged camelCase assertions
(`siteImages.coverAppleMusic`, `siteImages.logoFull`), proving the underscore-normalization preserved the
wire contract.

**Style + commit:**
```bash
php artisan pint --dirty
git commit -am "refactor(media): derive SiteMedia design singletons from registry; drop dead cover_shopify (FOUND-3)"
```

---

### Task 3 — collapse the cover/logo partial-unique indexes into one composite

> **NOT a Laravel migration.** Raw SQL in `supabase/migrations/` only (the composer guard rejects Laravel
> migration files). **Index-only → `CONCURRENTLY`, NO transaction**, matching the existing cover-index
> migrations `20260604000001` / `20260604000002`.

**File:** `supabase/migrations/20260701000000_collapse_cover_singleton_indexes.sql`
*(executing session bumps this to a timestamp later than the then-latest migration before applying —
latest today is `20260630000000_drop_smart_links.sql`.)*

```sql
-- FOUND-3: collapse the per-purpose design-singleton partial unique indexes into
-- ONE composite. The five per-cover indexes (20260604000001 + 20260604000002) and
-- the two baseline logo indexes each enforce the same intent — "one row per (site,
-- purpose)" — for a single hard-coded purpose. A composite (site_id, purpose) index
-- enforces it for EVERY design purpose at once, so a new platform cover slot needs
-- zero DB changes (the app-side allowlist now derives from the platform registry:
-- SiteMedia::designSingletonPurposes()). The dead `cover_shopify` slot is dropped,
-- not migrated (no `shopify` platform exists).
--
-- Strictly stronger: the composite rejects a 2nd live row for ANY (site, purpose)
-- pair, which is a superset of every index it replaces. It also subsumes the two
-- baseline logo indexes (logo_full / logo_square), which are dropped here as
-- redundant. It additionally introduces a (currently dormant) one-`placeholder`-
-- per-site guard — no code creates design-pool placeholders, and the baseline
-- `site_media_design_placeholder_sort_uq` (a different shape) is intentionally LEFT
-- in place. `purpose` is free-text (no CHECK); the design pool is already permitted
-- by site_media_pool_check, so this is index-only.
--
-- Index-only migration: CONCURRENTLY, no transaction (CONVENTIONS.md §1).

-- Drop the five per-purpose cover indexes (incl. the dead cover_shopify).
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_cover_youtube_uq;
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_cover_apple_music_uq;
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_cover_apple_podcast_uq;
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_cover_eventbrite_uq;
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_cover_shopify_uq;

-- Drop the two baseline logo indexes (subsumed by the composite below).
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_logo_full_uq;
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_logo_square_uq;

-- One composite singleton guard over every design purpose.
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_singleton_purpose_uq
    ON site.site_media (site_id, purpose)
    WHERE pool = 'design' AND deleted_at IS NULL;

-- ROLLBACK:
-- DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_singleton_purpose_uq;
-- -- recreate the two baseline logo indexes (verbatim from 20260526000000_baseline_standalone_user.sql):
-- CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_logo_full_uq ON site.site_media (site_id)
--     WHERE pool = 'design' AND purpose = 'logo_full' AND deleted_at IS NULL;
-- CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_logo_square_uq ON site.site_media (site_id)
--     WHERE pool = 'design' AND purpose = 'logo_square' AND deleted_at IS NULL;
-- -- recreate the FOUR live cover indexes (NOT cover_shopify — dead; NOT cover_fresha — already dropped pre-collapse):
-- CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_youtube_uq ON site.site_media (site_id)
--     WHERE pool = 'design' AND purpose = 'cover_youtube' AND deleted_at IS NULL;
-- CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_apple_music_uq ON site.site_media (site_id)
--     WHERE pool = 'design' AND purpose = 'cover_apple_music' AND deleted_at IS NULL;
-- CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_apple_podcast_uq ON site.site_media (site_id)
--     WHERE pool = 'design' AND purpose = 'cover_apple_podcast' AND deleted_at IS NULL;
-- CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_eventbrite_uq ON site.site_media (site_id)
--     WHERE pool = 'design' AND purpose = 'cover_eventbrite' AND deleted_at IS NULL;
```

No backfill: index-only, zero data transform. Pre-beta zero design-pool rows, so the composite cannot fail
on pre-existing duplicates; correct for prod-shape parity regardless.

**`tests/Pest.php`:** NO change. This migration adds no column (the `purpose` column already exists in the
`setupMediaTables()` helper, line 540: `purpose TEXT NULL`). SQLite cannot enforce partial unique indexes
anyway, so there is nothing to mirror.

#### Postgres-only constraints are invisible to SQLite
The composite partial unique index is NOT enforced by the SQLite test schema — a duplicate `(site_id,
purpose)` design row passes CI green and would only be rejected by real Postgres. The DB index is a
backstop; the primary singleton guard is `MediaUploadService::uploadSingleton` (app-side replace). The
constraint therefore MUST be verified directly on dev Supabase.

#### DEV-SUPABASE VERIFICATION (ref `glncumufgaqcmqhzwrxm`)
`CREATE INDEX CONCURRENTLY` cannot run inside a transaction block, so apply this file via
`supabase db push` (how `20260604000001/2` were applied). If you instead drive it through the MCP, run each
DROP/CREATE statement individually via `execute_sql` (autocommit) — NOT `apply_migration`, which wraps in a
transaction and would error `CREATE INDEX CONCURRENTLY cannot run inside a transaction block`.

Then prove the composite rejects a duplicate and allows distinct purposes. Pick any existing site id
(`select id from site.sites limit 1;`) and substitute it for `<SITE>`:

```sql
-- Two rows, SAME (site, purpose) → must be REJECTED by site_media_design_singleton_purpose_uq.
-- NB: `path` is NOT NULL with no default (the test helper seedReadyDesignSingleton supplies it),
-- so it MUST be in the column list or the INSERT fails on not-null BEFORE the unique index is tested.
insert into site.site_media (id, site_id, pool, purpose, path, media_type, processing_state, is_active, sort_order, created_at, updated_at)
values (gen_random_uuid(), '<SITE>', 'design', 'cover_youtube', 'design/verify-1.jpg', 'image', 'ready', true, 0, now(), now()),
       (gen_random_uuid(), '<SITE>', 'design', 'cover_youtube', 'design/verify-2.jpg', 'image', 'ready', true, 0, now(), now());
-- EXPECTED: ERROR: duplicate key value violates unique constraint "site_media_design_singleton_purpose_uq"
```

```sql
-- Two rows, SAME site, DIFFERENT purposes → must be ALLOWED.
insert into site.site_media (id, site_id, pool, purpose, path, media_type, processing_state, is_active, sort_order, created_at, updated_at)
values (gen_random_uuid(), '<SITE>', 'design', 'cover_youtube', 'design/verify-1.jpg', 'image', 'ready', true, 0, now(), now()),
       (gen_random_uuid(), '<SITE>', 'design', 'cover_apple_music', 'design/verify-2.jpg', 'image', 'ready', true, 0, now(), now());
-- EXPECTED: INSERT 0 2 (succeeds)
-- CLEANUP: delete from site.site_media where site_id = '<SITE>' and pool = 'design' and purpose in ('cover_youtube','cover_apple_music');
```

Run the first insert, confirm the rejection, then run the second (the first leaves no row because it
aborts atomically), confirm success, then run the cleanup delete.

**Commit (after dev verification passes):**
```bash
git add supabase/migrations/20260701000000_collapse_cover_singleton_indexes.sql
git commit -m "migration(media): collapse design-singleton cover/logo indexes into one composite (FOUND-3)"
```

---

### Golden-master guards (must stay green)
- `tests/Feature/Media/DesignSingletonMediaTest.php` — the camelCase wire-key assertions
  (`siteImages.coverAppleMusic`, `siteImages.logoFull`) are the partna-pages contract; they prove the
  underscore-normalization convention preserved the public `siteImages` shape. Byte-identical for the 4
  live covers; only the dead `cover_shopify` slot changes (now rejected / not enumerated).
- `tests/Feature/Platforms/Registry/RegistryCoverageTest.php` — the existing key-set + `refreshable()`
  pins must stay green (adding `coverable` touches neither). The new `coverable()` pin freezes the 4-set.
- `tests/Unit/Platforms/Registry/PlatformRegistryTest.php` — unaffected (no new behaviour on the base
  registry beyond the additive `coverable()` filter).
- `tests/Feature/Media/LogoBackgroundRemovalTest.php` — uses the `cover_youtube` literal (still a valid
  coverable purpose); must stay green, no edit.
- `tests/Feature/Notifications/ProEmailBrandResolverTest.php` — uses `PURPOSE_LOGO_FULL` (a kept const);
  must stay green, no edit.

No `ShouldQueue` job and no new controller action are added in this PR, so the `$backoff` and
authorize-in-controller rules have no new surface here (the touched `UserDesignMediaController` already
authorizes via `authorizeForUser($pro, 'create', $skeleton)` — unchanged).

### Final gate — FULL suite
```bash
composer test
```
Must be entirely green. A filtered subset is a false signal (a const→registry change has a wide reference
graph: the registry singleton resolves for every test that boots the container). Only a full green
`composer test` closes this PR.


---

## PR8 — FOUND-18 — `IntegrationConnection` `apify_status` (promote) + `place_id` (indexed mirror)

**Goal.** Lift the Google Business async enrichment state machine out of the catch-all `payload` JSONB: fully promote `apifyStatus` to a real `apify_status` column, and add an indexed `place_id` column that *mirrors* (does not replace) the payload key, so the enrich-job reconnect guard becomes an indexed lookup instead of a PHP-side JSON compare.

**Architecture.** `site.platform_connections` gains two nullable text columns. `apify_status` carries a CHECK (`pending`/`ok`/`unavailable`) and is the sole source of truth (stripped from payload, re-injected into the `/selection` + `/connect` resource arrays). `place_id` is written alongside the verbatim payload (payload keeps `placeId` — it is a first-class selection identifier read by the connect contract, the Maps deep-link, the public resource, and the refresh fetch strategy); only `GoogleBusinessEnrichJob::connection()` switches to the indexed column. The `GoogleBusinessConnectionResource` is **unchanged** — re-injection happens by putting `apifyStatus` into the array the resource is built from, in the controller.

**Blast radius.**
- `supabase/migrations/` — 3 new files (DDL+CHECK, backfill, CONCURRENTLY index — split per CONVENTIONS.md §1/§5).
- `app/Models/Core/Site/IntegrationConnection.php` — `$fillable` (+2).
- `app/Jobs/Platforms/GoogleBusinessEnrichJob.php` — column writes in success + `mark()`; `connection()` indexed query.
- `app/Http/Controllers/Api/Platforms/GoogleBusinessController.php` — `connect()` picker branch writes columns + echoes via injection; **new `selection()` override**.
- `app/Services/Platforms/Payloads/GoogleBusinessPayload.php` — remove dead `apifyStatus()`.
- `tests/Pest.php` — add 2 columns to the `site.platform_connections` SQLite table.
- Tests: `GoogleBusinessApifyTest.php` (seed helper + assertions + 1 new test), `GoogleBusinessSelectionContractTest.php`, `tests/Unit/Platforms/Payloads/GoogleBusinessPayloadTest.php`.

**DECISION (confirm before implementing):** `place_id` **indexed mirror (recommended)** — write `place_id` to a column for the indexed reconnect guard but keep `placeId` in payload (payload readers unchanged) — vs **full payload-purity re-thread** (remove `placeId` from payload and re-thread all 5 readers: connect request contract, Maps deep-link, `GoogleBusinessConnectionResource`, `GoogleBusinessFetch`, the public allowlist) — a larger, separate task. The plan below is written around the mirror option.

**DECISION (confirm before implementing):** `apifyFetchedAt` **stays in payload (recommended)** — keeps the migration tight; `GoogleBusinessApifyTest:190` (`expect($p)->toHaveKey('apifyFetchedAt')`) and `GoogleBusinessSelectionContractTest` continue to assert it as a payload key — vs promote it to a column for symmetry (bigger surface, no functional gain). The plan keeps it in payload.

**Premise grounding (honored corrections + drift).**
- Honored premise correction #3 (FOUND-18): **asymmetric** treatment — `apify_status` fully promoted, `place_id` mirrored. The CHECK intentionally adds `pending` and omits `error` (it is NOT the `last_refresh_status` enum).
- Drift vs audit: the audit (lines 606-607) says "Remove `apifyStatus` **and `placeId`** from payload; read from columns" and that `place_id` is hidden state. **Both wrong** — `placeId` is read verbatim from payload by `GoogleBusinessFetch:23`, the public allowlist (`PublicIntegrationConnectionResource:66-68`, where it is deliberately *stripped*), and the dashboard resource (`GoogleBusinessConnectionResource` ENRICHMENT_KEYS). Removing it from payload would silently drop it everywhere (resources are built from `new $resource($payloadArray)`). We mirror, not move.
- Drift vs design plan: the job already declares `public array $backoff = [30, 120];` (line 38-39) and the `failed()` handler already logs `'place_id' => $this->placeId` (line 113 — a log key, **not** a column write). The audit's cited line numbers (149-156 / 185-188) have drifted; the real writes are lines 96-105 + 141-150 and the guard is lines 127-139.
- Minor judgment call (flagged): after the `connection()` switch, `GoogleBusinessPayload::placeId()` has no remaining *app* caller. We **keep** it (place_id remains a first-class payload key; the unit test pins it) and remove only `apifyStatus()` per the brief.

---

### House rules applied

- **`$backoff`:** `GoogleBusinessEnrichJob` already has `public array $backoff = [30, 120];` — preserved unchanged (JobHygienePolicyTest green).
- **Authorization:** the picker `connect()` writes through the trait's `writeConnection()`, which runs the create/update policy abilities (`$this->authorizeForUser`) — preserved. The new `selection()` override is a pure read scoped to `currentUser`'s own connections via `accountRows()` (identical pattern to the base `selection()` it overrides — no inline 403, no policy needed for a relationship-scoped read).
- **No raw `Cache::`:** the column-only follow-up write uses `saveQuietly()` (the payload write already fired the IntegrationConnectionObserver cache purge; the columns have no public exposure) — no cache facade touched.
- **DB schema = raw SQL in `supabase/migrations/` only.** No Laravel migration files.

---

### Migration files

Per CONVENTIONS.md the work splits into **3 files** (one PR): DDL+CHECK in a transaction (§2), backfill outside any transaction (§5), index `CONCURRENTLY` outside any transaction (§1). Timestamps shown are placeholders — **the executing session bumps these to timestamps later than the then-latest migration** (currently `20260630000000_drop_smart_links.sql`) before applying, preserving the `…000000 / …000100 / …000200` ordering.

#### File 1 — `supabase/migrations/20260701000000_promote_gb_apify_status_placeid.sql`

```sql
-- FOUND-18: promote the Google Business async enrichment state out of the
-- site.platform_connections.payload JSONB.
--   • apify_status — fully promoted (stripped from payload in file 2, re-injected
--     into the dashboard resource in app code). CHECK adds 'pending' and omits
--     'error' vs last_refresh_status — intentional (separate state machine).
--   • place_id — an INDEXED MIRROR. placeId STAYS in payload (first-class
--     selection identifier); the column exists only to index the enrich-job
--     reconnect guard.
-- ADD COLUMN is metadata-only; the CHECK uses NOT VALID -> VALIDATE (CONVENTIONS
-- §2). All existing rows have NULL in the new column, so VALIDATE is instant.
BEGIN;

SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS apify_status text;
ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS place_id text;

ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_apify_status_check
    CHECK (apify_status IS NULL OR apify_status IN ('pending', 'ok', 'unavailable')) NOT VALID;
ALTER TABLE site.platform_connections VALIDATE CONSTRAINT platform_connections_apify_status_check;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.platform_connections DROP CONSTRAINT IF EXISTS platform_connections_apify_status_check;
-- ALTER TABLE site.platform_connections DROP COLUMN IF EXISTS apify_status;
-- ALTER TABLE site.platform_connections DROP COLUMN IF EXISTS place_id;
-- COMMIT;
-- (Run the file-2 re-inject below BEFORE dropping apify_status if any rows exist.)
```

#### File 2 — `supabase/migrations/20260701000100_backfill_gb_apify_status_placeid.sql`

```sql
-- Backfill the promoted columns from payload, then strip ONLY apifyStatus from
-- payload (placeId STAYS — first-class identifier). No transaction (CONVENTIONS
-- §5); each UPDATE auto-commits. Pre-beta = zero rows, so this is a no-op now —
-- written for prod-shape parity. apify_status values are constrained by the CHECK
-- added in file 1 (any non-enum value fails closed, which is correct).
UPDATE site.platform_connections
SET apify_status = payload->>'apifyStatus'
WHERE platform = 'google-business'
  AND payload ? 'apifyStatus'
  AND apify_status IS NULL;

UPDATE site.platform_connections
SET place_id = payload->>'placeId'
WHERE platform = 'google-business'
  AND payload ? 'placeId'
  AND place_id IS NULL;

UPDATE site.platform_connections
SET payload = payload - 'apifyStatus'
WHERE platform = 'google-business'
  AND payload ? 'apifyStatus';

-- ROLLBACK:
-- Re-inject apifyStatus back into payload from the column (placeId already in payload):
-- UPDATE site.platform_connections
-- SET payload = jsonb_set(payload, '{apifyStatus}', to_jsonb(apify_status))
-- WHERE platform = 'google-business' AND apify_status IS NOT NULL;
```

#### File 3 — `supabase/migrations/20260701000200_gb_place_id_idx.sql`

```sql
-- Indexed reconnect guard: GoogleBusinessEnrichJob::connection() looks up the
-- user's connection by (user_id, place_id). CONCURRENTLY, outside any
-- transaction (CONVENTIONS §1). Partial WHERE deleted_at IS NULL matches the
-- model's soft-delete scope.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_platform_connections_user_place_id
    ON site.platform_connections (user_id, place_id)
    WHERE deleted_at IS NULL;

-- ROLLBACK:
-- DROP INDEX CONCURRENTLY IF EXISTS site.idx_platform_connections_user_place_id;
```

#### Postgres-only constraints are invisible to SQLite

The CHECK (file 1) and the partial index (file 3) are **not enforced by the SQLite test schema** — a write that violates the CHECK passes CI green and only 23514s on real Postgres (the Instagram-500 lesson). Therefore every constraint must be verified directly on dev Supabase.

#### DEV-SUPABASE VERIFICATION (ref `glncumufgaqcmqhzwrxm`)

After `apply_migration` of file 1 (and file 3), run via `execute_sql`:

```sql
-- (A) CHECK rejects a non-enum value — EXPECT: ERROR 23514 check_violation
--     on "platform_connections_apify_status_check". Throwaway user + ROLLBACK so
--     nothing persists (user_id FK -> core.users must be satisfied).
BEGIN;
WITH u AS (
    INSERT INTO core.users (id, handle, handle_lc, display_name, account_type, auth_user_id, primary_email)
    VALUES (gen_random_uuid(), 'gbcheckprobe', 'gbcheckprobe', 'Probe', 'partna', gen_random_uuid(), 'gbprobe@example.test')
    RETURNING id
)
INSERT INTO site.platform_connections (id, user_id, platform, resource_id, payload, apify_status)
SELECT gen_random_uuid(), u.id, 'google-business', 'google-business', '{}'::jsonb, 'queued' FROM u;
ROLLBACK;
-- EXPECTED: ERROR: new row for relation "platform_connections" violates check
--           constraint "platform_connections_apify_status_check" (SQLSTATE 23514)

-- (B) Positive control — a valid enum value inserts cleanly, then ROLLBACK.
BEGIN;
WITH u AS (
    INSERT INTO core.users (id, handle, handle_lc, display_name, account_type, auth_user_id, primary_email)
    VALUES (gen_random_uuid(), 'gbcheckok', 'gbcheckok', 'Probe', 'partna', gen_random_uuid(), 'gbok@example.test')
    RETURNING id
)
INSERT INTO site.platform_connections (id, user_id, platform, resource_id, payload, apify_status, place_id)
SELECT gen_random_uuid(), u.id, 'google-business', 'google-business', '{}'::jsonb, 'pending', 'ChIJtest' FROM u;
ROLLBACK;  -- EXPECTED: INSERT 0 1 (accepted)

-- (C) Confirm the index exists.
SELECT indexname FROM pg_indexes
WHERE schemaname = 'site' AND indexname = 'idx_platform_connections_user_place_id';
-- EXPECTED: one row.
```

---

### `tests/Pest.php` SQLite schema update

The hand-built `site.platform_connections` table (currently lines ~418-434) has neither column. Add both as `TEXT NULL` (the CHECK and partial index are intentionally unenforced on SQLite — verified on Postgres above). Edit the `CREATE TABLE` block:

```php
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.platform_connections (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        platform TEXT NULL,
        resource_id TEXT NULL,
        payload TEXT NULL,
        sort_order INTEGER NULL DEFAULT 0,
        is_active INTEGER NULL DEFAULT 1,
        last_visited_at TEXT NULL,
        last_refreshed_at TEXT NULL,
        last_refresh_status TEXT NULL,
        last_refresh_error TEXT NULL,
        consecutive_failures INTEGER NULL DEFAULT 0,
        apify_status TEXT NULL,
        place_id TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');
```

---

### Application code (complete)

#### `app/Models/Core/Site/IntegrationConnection.php` — `$fillable`

Add `'apify_status'` and `'place_id'` (the test seed helpers mass-assign via `create()`; the controller/job use `forceFill` which bypasses fillable, but fillable keeps the contract honest). No `$casts` entry needed — both are plain strings.

```php
    protected $fillable = [
        'user_id',
        'platform',
        'resource_id',
        'payload',
        'sort_order',
        'is_active',
        'last_visited_at',
        'last_refreshed_at',
        'last_refresh_status',
        'last_refresh_error',
        'consecutive_failures',
        'apify_status',
        'place_id',
    ];
```

#### `app/Jobs/Platforms/GoogleBusinessEnrichJob.php`

Three edits. The class header/`$backoff`/`uniqueId`/`failed()` are unchanged.

**(a) success write (lines 96-105 today) — write the column, drop apifyStatus from payload, keep apifyFetchedAt + syncFindings:**

```php
        // Write back business-info only: strip the enrichment keys (stale ones from
        // a pre-cleanup connect included) and record apifyFetchedAt + this run's
        // findings. apifyStatus is now a real column, not a payload key. The GB
        // payload has no public change, so saveQuietly — the seeded rows above
        // fired their own sitepage cache purges.
        $connection->forceFill([
            'payload' => [
                ...Arr::except($this->payloadOf($connection), ['menu', 'reservation', 'order', 'booking', 'socials']),
                'apifyFetchedAt' => now()->toIso8601String(),
                // What THIS scrape produced — drives the connect modal's "found
                // platforms" list (only this run's, with live status + Change-to).
                'syncFindings' => $findings,
            ],
            'apify_status' => 'ok',
        ])->saveQuietly();
```

**(b) `mark()` (lines 141-150 today) — promote status to the column:**

```php
    private function mark(IntegrationConnection $connection, string $status): void
    {
        $connection->forceFill([
            'payload' => [
                ...$this->payloadOf($connection),
                'apifyFetchedAt' => now()->toIso8601String(),
            ],
            'apify_status' => $status,
        ])->saveQuietly();
    }
```

**(c) `connection()` (lines 127-139 today) — indexed lookup on the place_id column:**

```php
    // The user's single google-business connection, matched on the indexed
    // place_id column — guards against clobbering after the user reconnected a
    // DIFFERENT place while this job was queued. The model's soft-delete scope
    // adds deleted_at IS NULL, matching the partial index.
    private function connection(): ?IntegrationConnection
    {
        return IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', 'google-business')
            ->where('place_id', $this->placeId)
            ->first();
    }
```

(`use App\Services\Platforms\Payloads\GoogleBusinessPayload;` is still needed — the success write at line 84-90 uses `GoogleBusinessPayload::fromArray()->name()` / `->toArray()` and `payloadOf()` uses `->toArray()`. Do not remove the import.)

#### `app/Http/Controllers/Api/Platforms/GoogleBusinessController.php`

**(a) Add a `selection()` override** (place it just after `resourceClass()`). It re-injects `apify_status` (the column) as the `apifyStatus` key into the array the resource is built from, and defensively strips any stale payload copy so the column is the single source of truth:

```php
    // GET /api/platforms/google-business/selection
    // Overrides the base (payload-only) selection so apifyStatus is sourced from
    // the promoted apify_status column, not the payload. The resource is built
    // from the payload ARRAY, so we splice the column value into that array
    // (the resource itself is unchanged — apifyStatus stays in ENRICHMENT_KEYS).
    public function selection(Request $request): JsonResponse
    {
        $row = $this->accountRows($this->currentUser($request))->first();
        if ($row === null) {
            return $this->success(['selection' => null]);
        }

        $payload = $row->payload ?? [];
        // Column is the source of truth: drop any legacy payload copy, then add
        // the column value back as apifyStatus when set (null = never enriched).
        unset($payload['apifyStatus']);
        if ($row->apify_status !== null) {
            $payload['apifyStatus'] = $row->apify_status;
        }

        $resource = $this->resourceClass();

        return $this->success(['selection' => (new $resource($payload))->resolve()]);
    }
```

**(b) Rewrite the picker branch of `connect()`** so the payload keeps `placeId` but NOT `apifyStatus`, the columns are written, and the echo re-injects `apifyStatus` (full method shown; the legacy link-paste path below the branch is unchanged):

```php
    // POST /api/platforms/google-business/connect
    public function connect(ConnectGoogleBusinessRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $data = $request->validated();

        // Places-picker payload (canonical): the user searched + picked their
        // own business in the dashboard. Store the canonical place deep link
        // for the "open in Maps" / directions actions.
        if (isset($data['placeId'])) {
            $selection = [
                'url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($data['name']).'&query_place_id='.rawurlencode($data['placeId']),
                'placeId' => $data['placeId'],   // KEPT in payload — first-class identifier
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'lat' => (float) $data['lat'],
                'lng' => (float) $data['lng'],
            ];

            // Place Details enrichment (rating, reviews, hours, phone, …) —
            // best-effort: a missing server key or failed fetch keeps the
            // picker fields and the refresh cron retries within a week.
            // mapDetails() drops absent keys, so the spread never overwrites
            // a picker value with null.
            $details = $this->service->fetchPlaceDetails($data['placeId']);
            $merged = [...$selection, ...($details ?? [])];

            // Apify enrichment (menu / reservation / order / booking / socials)
            // is too slow to block connect, so it runs in a background job while
            // we return the instant Place Details card. apify_status='pending'
            // (a real column now, NOT a payload key) drives the dashboard's poll;
            // the job flips it to ok/unavailable. Gated on the token so a missing
            // key is a clean no-op.
            $enrich = (bool) config('services.apify.token');

            // Business accounts adopt the Google Business name as their display name.
            $this->maybeAdoptGoogleName($user, $data['name'] ?? null);

            // writeConnection owns the create/update authorization + payload upsert;
            // the promoted columns are a GB-specific follow-up. apify_status lives
            // ONLY in the column; place_id mirrors the payload value for the indexed
            // reconnect guard. saveQuietly — no public change beyond the payload
            // write writeConnection already purged.
            $row = $this->writeConnection($user, $merged);
            $row->forceFill([
                'place_id' => $data['placeId'],
                'apify_status' => $enrich ? 'pending' : null,
            ])->saveQuietly();

            // Echo: re-inject apifyStatus from the column so the connect response
            // keeps the key the dashboard polls on (resource is built from the array).
            $resource = $this->resourceClass();
            $echo = $merged;
            if ($enrich) {
                $echo['apifyStatus'] = 'pending';
            }
            $response = $this->success((new $resource($echo))->resolve());

            if ($enrich) {
                GoogleBusinessEnrichJob::dispatch((string) $user->id, $data['placeId']);
            }

            return $response;
        }

        $place = $this->service->resolve($data['url']);
        if ($place === null) {
            return $this->error('Paste your Google Maps link — open your business on Google Maps, hit Share, and copy the link.', 422);
        }

        $this->maybeAdoptGoogleName($user, $place['name'] ?? null);

        return $this->connected($user, $place);
    }
```

(`JsonResponse` and `Request` are already imported; `accountRows()` comes from the `ManagesIntegrationConnection` trait used by the base controller.)

#### `app/Services/Platforms/Payloads/GoogleBusinessPayload.php`

Remove the now-dead `apifyStatus()` accessor (apify_status no longer lives in payload). Keep `placeId()` (place_id stays a first-class payload key; the unit test pins it). Update the class doc line that mentions `apifyStatus()`.

Delete:
```php
    public function apifyStatus(): ?string
    {
        return is_string($this->raw['apifyStatus'] ?? null) ? $this->raw['apifyStatus'] : null;
    }
```

And in the class docblock, change `name() + placeId() (the enrich job's reconnect guard + name adoption), apifyStatus(),` to drop `apifyStatus()` (e.g. `name() + placeId() (name adoption + verbatim identifier),`).

---

### TDD task sequence

Each task: write/adjust the failing test → run-fail → minimal code → run-pass → `php artisan pint --dirty` → commit. Run from the **main checkout** (feature tests fail inside `.claude/worktrees/`).

#### Task 1 — SQLite schema + model fillable (enables every later test)

This is the structural prerequisite; verify via an existing test that touches the table.

- Apply the `tests/Pest.php` edit (add `apify_status`, `place_id`) and the model `$fillable` edit (above).
- Run: `./vendor/bin/pest tests/Feature/Platforms/GoogleBusinessApifyTest.php`
  - Expected at this point: existing assertions still pass except the ones we change in later tasks; importantly NO "no such column: place_id" error once Task 4's job query lands. (If run before code changes, the suite is still green — the columns are simply unused.)
- `php artisan pint --dirty`
- Commit: `git commit -m "fix(audit): FOUND-18 — add apify_status + place_id columns to test schema and model fillable"`

#### Task 2 — promote apify_status + mirror place_id on picker connect (new test, RED → GREEN)

Add this test to `GoogleBusinessApifyTest.php` (after the existing "marks apify pending …" test):

```php
it('promotes apify_status to a column and mirrors place_id on a picker connect', function () {
    config(['services.google_maps.server_api_key' => 'server-key', 'services.apify.token' => 'apify-token']);
    Bus::fake([GoogleBusinessEnrichJob::class]);
    Http::fake([
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response(['status' => 'ZERO_RESULTS']),
        'places.googleapis.com/*' => Http::response([
            'id' => 'ChIJtest', 'displayName' => ['text' => 'Fade Lab'], 'location' => ['latitude' => -37.8, 'longitude' => 144.96],
        ]),
    ]);
    $user = gbApifyUser('gbcol');

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJtest', 'name' => 'Fade Lab', 'lat' => -37.0, 'lng' => 144.0,
    ])->assertOk()->assertJsonPath('apifyStatus', 'pending');

    $conn = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'google-business')->firstOrFail();
    expect($conn->apify_status)->toBe('pending');          // promoted to column
    expect($conn->place_id)->toBe('ChIJtest');             // indexed mirror
    expect($conn->payload)->not->toHaveKey('apifyStatus');  // stripped from payload
    expect($conn->payload['placeId'])->toBe('ChIJtest');    // KEPT in payload (first-class)
});
```

- Run-fail: `./vendor/bin/pest --filter="promotes apify_status to a column"`
  Expected failure: `apify_status` is null / payload still has `apifyStatus` (old `connect()` writes apifyStatus into payload).
- Minimal code: apply the `GoogleBusinessController::connect()` picker-branch rewrite (above).
- Run-pass: `./vendor/bin/pest --filter="promotes apify_status to a column"` → green.
- `php artisan pint --dirty`
- Commit: `git commit -m "fix(audit): FOUND-18 — connect() promotes apify_status + mirrors place_id columns"`

#### Task 3 — `/selection` emits apifyStatus from the column (override)

Update the contract golden master so the enriched row stores `apify_status` in the **column** (not payload), and add a column-source assertion to the apify selection test.

In `GoogleBusinessSelectionContractTest.php`, the "freezes the full enriched shape" test (gbsel2): remove `'apifyStatus' => 'ok',` from the `payload` array, and add `'apify_status' => 'ok',` as a top-level key on the `IntegrationConnection::create([...])` call. The `toEqual` assertion at the bottom keeps `'apifyStatus' => 'ok'` (now sourced from the column via the override; `toEqual` is order-insensitive for assoc arrays). The minimal "5-key link-parse shape" test (gbsel1) is unchanged — its column is null, so no `apifyStatus` appears (still exactly 5 keys).

- Run-fail: `./vendor/bin/pest tests/Feature/Platforms/GoogleBusinessSelectionContractTest.php`
  Expected failure (before the override): the enriched selection no longer contains `apifyStatus` (payload key removed, base `selection()` doesn't read the column) → `toEqual` mismatch.
- Minimal code: add the `GoogleBusinessController::selection()` override (above).
- Run-pass: `./vendor/bin/pest tests/Feature/Platforms/GoogleBusinessSelectionContractTest.php` → green.
- `php artisan pint --dirty`
- Commit: `git commit -m "fix(audit): FOUND-18 — selection() override emits apifyStatus from the column"`

#### Task 4 — enrich job writes the column + indexed reconnect guard

Update `GoogleBusinessApifyTest.php`:

1. **Seed helper `gbApifyConnection`** — move `apifyStatus` from payload to the column, add the `place_id` column, keep `placeId` in payload:

```php
function gbApifyConnection(User $user, string $placeId = 'ChIJtest'): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'url' => 'https://maps.google.com/?cid=1',
            'placeId' => $placeId,            // KEPT in payload (first-class)
            'name' => 'Fade Lab Barbers',
            'rating' => 4.8,
        ],
        'place_id' => $placeId,               // indexed mirror column
        'apify_status' => 'pending',          // promoted column (was payload.apifyStatus)
        'last_refreshed_at' => now(),
    ]);
}
```

2. **"seeds reservation, ordering and social connections"** test (~line 187-190): replace `expect($p['apifyStatus'])->toBe('ok');` with `expect($conn->apify_status)->toBe('ok');` and add `expect($p)->not->toHaveKey('apifyStatus');`. Keep `expect($p)->toHaveKey('apifyFetchedAt');` (apifyFetchedAt stays in payload).

3. **"marks apify unavailable …"** test (~line 440): `expect($conn->payload['apifyStatus'])->toBe('unavailable');` → `expect($conn->apify_status)->toBe('unavailable');` (line 439 already `$conn->refresh()`).

4. **"skips enrichment when the stored place no longer matches"** test (~line 455): `expect($conn->payload['apifyStatus'])->toBe('pending');` → `expect($conn->apify_status)->toBe('pending');`. (The seed now sets `place_id='ChIJdifferent'`; the job's indexed `connection()` finds no row for `'ChIJtest'`, returns early, row untouched.)

5. The **"keeps the Google Business selection business-info-only after enrichment"** test (~line 381, `->assertJsonPath('selection.apifyStatus', 'ok')`) needs NO change — the `selection()` override (Task 3) now serves it from the column.

- Run-fail: `./vendor/bin/pest tests/Feature/Platforms/GoogleBusinessApifyTest.php`
  Expected failure (before job edit): success/`mark()` still write `apifyStatus` to payload (column stays the seeded `'pending'`), and `connection()` still matches via the payload DTO so the reconnect-guard seed (`place_id='ChIJdifferent'`, payload.placeId='ChIJdifferent') no longer aligns with the column query expectations.
- Minimal code: apply the three `GoogleBusinessEnrichJob` edits (success write, `mark()`, `connection()`).
- Run-pass: `./vendor/bin/pest tests/Feature/Platforms/GoogleBusinessApifyTest.php` → green.
- `php artisan pint --dirty`
- Commit: `git commit -m "fix(audit): FOUND-18 — enrich job writes apify_status column + indexed place_id reconnect guard"`

#### Task 5 — remove dead `GoogleBusinessPayload::apifyStatus()`

Update the unit test `tests/Unit/Platforms/Payloads/GoogleBusinessPayloadTest.php`, "exposes typed accessors" case:
- Line 20 fixture → `$dto = GoogleBusinessPayload::fromArray(['name' => 'Fade Lab', 'placeId' => 'ChIJ']);`
- Remove line 23 `expect($dto->apifyStatus())->toBe('pending');`
- Keep line 22 `expect($dto->placeId())->toBe('ChIJ');` and `syncFindings()` / `name()` assertions.
- The "lossless toArray" case (line 9-17) and the resource round-trip case (line 37-48) are unchanged — they exercise verbatim payload preservation + the resource (which still carries `apifyStatus` in ENRICHMENT_KEYS), not the removed accessor.

- Run-fail: `./vendor/bin/pest tests/Unit/Platforms/Payloads/GoogleBusinessPayloadTest.php` (after deleting the method) → `Call to undefined method …apifyStatus()` until the test line is removed; remove the test line first to get a clean RED→GREEN, or delete method + test line together and run for green.
- Minimal code: delete the `apifyStatus()` method + update its docblock mention.
- Run-pass: `./vendor/bin/pest tests/Unit/Platforms/Payloads/GoogleBusinessPayloadTest.php` → green.
- `php artisan pint --dirty`
- Commit: `git commit -m "fix(audit): FOUND-18 — drop dead GoogleBusinessPayload::apifyStatus()"`

---

### Golden-master guards (must stay byte-identical / green)

- **`GoogleBusinessSelectionContractTest`** — the highest drift/leak-risk dashboard resource. Both the minimal 5-key shape and the full enriched shape (`toEqual`) must match after the change; `apifyStatus` now flows from the column but the *emitted shape is identical*.
- **`GoogleBusinessApifyTest` "keeps apify enrichment off the public endpoint"** (~line 461) — the public payload (`data.platforms.google-business.0.payload`) must remain byte-identical: `apify_status`/`place_id` are columns (never serialized by `PublicIntegrationConnectionResource`, which emits only the allowlisted `payload`), and `placeId` stays stripped by the google-business allowlist (`PublicIntegrationConnectionResource:66-68`). No change needed; it must stay green.
- **`IntegrationsV3ConnectionTest` "google business connect accepts a places-picker payload"** (line 256) — the connect response `name`/`address`/`url` must be unchanged (the `url` deep-link construction is preserved verbatim; reconnect of the same place still 200s).
- **`GoogleBusinessDetailsTest`** (`missing_place_id` path, line 247) — `GoogleBusinessFetch` still reads `payload['placeId']` (unchanged); must stay green.
- Unrelated: `StaffWorkplaceControllerTest:70` (`not->toHaveKey('place_id')`) is about a **workplace profile JSON** (FOUND-4 territory), not this column — confirm untouched.

### Final gate

Run the **full** suite (a filtered subset is a false signal — Wave-1 hit a 9-test regression only the full suite caught):

```
composer test
```

Expected: all green. Then verify the migration constraints on dev Supabase per the DEV-SUPABASE VERIFICATION block before considering the unit done.


---

# Foundational audit — Wave 2 — PR9 + PR10 (Platform HTTP layer, CODE-ONLY, no migration)

> Two sections in one file because PR10 strictly depends on PR9's descriptor/route seam.
> Execute PR9 to completion (merge/green) BEFORE starting PR10.

---

## PR9 — FOUND-19 — connect Form Requests → descriptor-driven

### Goal
Replace the 21 near-identical per-platform connect Form Requests with ONE descriptor-driven
`PlatformConnectRequest`, so adding a platform is one descriptor line (`->connectInput(...)`) instead of a
new file — while keeping every field name / max / regex / 422 message byte-identical.

### Architecture
`PlatformDescriptor` already carries connect *parsing* metadata (`connect()`/`connectStrategy()`/
`connectErrorMessage()`). PR9 adds connect *validation* metadata (`connectInput($field, $rules, $messages, $normalizeUrlish)`
→ `connectField()`/`connectRules()`/`connectMessages()`/`connectNormalizesUrlish()`), populated in
`PlatformRegistryServiceProvider`. A new `PlatformConnectRequest` (using a `ResolvesConnectRules` trait)
resolves the descriptor from the `platform` route default (404 fail-closed) and returns
`[$descriptor->connectField() => $descriptor->connectRules()]`. Every thin connect route gains
`->defaults('platform', '<slug>')` so the shared request can resolve; each bespoke `connect()` swaps its
`ConnectXRequest` type-hint for `PlatformConnectRequest` (its body is unchanged — it still reads
`$request->validated()[<field>]`). GoogleBusiness (multi-field) stays standalone.

### Blast radius
- `app/Services/Platforms/Registry/PlatformDescriptor.php` (add 5 methods + 4 properties)
- `app/Providers/PlatformRegistryServiceProvider.php` (add `->connectInput(...)` to 26 descriptors)
- NEW `app/Http/Requests/Platforms/Concerns/ResolvesConnectRules.php`
- NEW `app/Http/Requests/Platforms/PlatformConnectRequest.php`
- `app/Http/Controllers/Api/Platforms/GenericPlatformController.php` (type-hint + `connectField()` read)
- 18 bespoke controllers / 19 connect methods (type-hint swap only)
- `routes/api/integrations.php` (add `->defaults('platform', $slug)` to every thin connect route)
- DELETE 21 reducible `Connect*Request.php` (keep `ConnectGoogleBusinessRequest.php`)
- NEW `tests/Feature/Platforms/Registry/RegistryConnectCoverageTest.php`
- No migration. No `tests/Pest.php` schema change (code-only).

### DECISION (confirm before implementing)
**FOUND-19:** a single `PlatformConnectRequest` for ALL reducible platforms (recommended) — vs the audit's
ByUrl + ByUsername split. Once the field name is descriptor metadata the url/username distinction is
cosmetic, so one request is strictly simpler. GoogleBusiness stays standalone either way.

### Premise grounding (honored corrections + drift found)
- Honored: **22 connect requests, not 24** (SmartLinks gone). Confirmed by Glob:
  `ls app/Http/Requests/Platforms/Connect*Request.php | wc -l` → **22**.
- Honored: **YouTube is a plain `channel` field** (`ConnectYoutubeRequest` → `['channel' => ['required','string','max:200']]`),
  NOT a video-id outlier. Honored: **only GoogleBusiness is irreducible** (6-field placeId/name/address/lat/lng/url).
- Honored: the descriptor already carries connect metadata (`connect`/`connectStrategy`/`connectErrorMessage`
  at `PlatformDescriptor:132-154`) but NOT validation rules.
- **DRIFT vs the audit's "17 share max:500" claim:** the live `max:` values are NOT uniform — they have
  already drifted (exactly the problem FOUND-19 calls out). Verbatim per-platform values are in the table
  below; the descriptor MUST reproduce each one (a single shared `max:500` would silently widen Twitch
  from 120 and tighten NowBookit from 2048 — an API contract change). This is the load-bearing detail.
- **DRIFT:** `ConnectSocialLinkRequest` is shared by **6** platforms (x, linkedin, threads, reddit,
  tiktok, facebook), so "21 reducible requests" → **26 descriptor `connectInput()` calls** (17 url + 3
  single-field + 6 socials). All 6 socials already route through `GenericPlatformController`.
- **DRIFT:** Fresha + Square carry a `prepareForValidation()` that runs `PlatformInput::urlish()` on `url`
  BEFORE the regex — reproduced via the `$normalizeUrlish` flag (only these two set it).
- **DRIFT:** Square is the ONLY reducible request with a custom `messages()`
  (`'url.regex' => 'Enter a valid Square booking link (a squareup.com or square.site URL).'`). Fresha's
  regex uses Laravel's default message → its `connectMessages()` is `[]`.

### The verbatim connect contract (reproduce byte-for-byte)

| Platform (descriptor key) | field | rules (exact) | messages | urlish |
|---|---|---|---|---|
| bandcamp | `url` | `['required','string','max:500']` | — | no |
| deezer | `url` | `['required','string','max:300']` | — | no |
| eventbrite | `url` | `['required','string','max:500']` | — | no |
| fresha | `url` | `['required','string','max:500','regex:#^https?://(www\.)?fresha\.com/(?:[a-z]{2,3}(-[a-z]{2})?/)?a/[a-z0-9-]+/?$#i']` | — | **yes** |
| humanitix | `url` | `['required','string','max:500']` | — | no |
| nowbookit | `url` | `['required','string','max:2048']` | — | no |
| opentable | `url` | `['required','string','max:2048']` | — | no |
| pinterest | `url` | `['required','string','max:200']` | — | no |
| resdiary | `url` | `['required','string','max:2048']` | — | no |
| skool | `url` | `['required','string','max:500']` | — | no |
| soundcloud | `url` | `['required','string','max:500']` | — | no |
| spotify | `url` | `['required','string','max:500']` | — | no |
| square | `url` | `['required','string','max:1000','regex:#^https?://([a-z0-9-]+\.)*(squareup\.com|square\.site)(/[^\s]*)?$#i']` | `['url.regex' => 'Enter a valid Square booking link (a squareup.com or square.site URL).']` | **yes** |
| strava | `url` | `['required','string','max:300']` | — | no |
| twitch | `url` | `['required','string','max:120']` | — | no |
| vimeo | `url` | `['required','string','max:300']` | — | no |
| youtube-music | `url` | `['required','string','max:300']` | — | no |
| apple-music | `artist` | `['required','string','max:200']` | — | no |
| apple-podcast | `show` | `['required','string','max:200']` | — | no |
| youtube | `channel` | `['required','string','max:200']` | — | no |
| x / linkedin / threads / reddit / tiktok / facebook | `username` | `['required','string','max:200']` | — | no |

`google-business` is NOT in this table — it keeps `ConnectGoogleBusinessRequest` (irreducible multi-field).

### Authorize-in-controller rule
Unchanged. Each connect body already authorizes through `ManagesIntegrationConnection::writeConnection`/
`writeAccountConnection`/`connected` (`authorizeForUser($user, 'create'|'update', …)` at
`ManagesIntegrationConnection.php:76/85`). PR9 only changes the *validation* source; bodies are untouched.
Every Connect request's `authorize()` is `return true` today — the shared request preserves that.

---

### Complete code

#### 1. `PlatformDescriptor` additions

Add 4 properties (next to the existing `$connectStrategy`/`$connectErrorMessage`):

```php
    private ?string $connectField = null;

    /** @var array<int, mixed> */
    private array $connectRules = [];

    /** @var array<string, string> */
    private array $connectMessages = [];

    private bool $connectNormalizesUrlish = false;
```

Add these methods (place them right after `connectErrorMessage()` at line 154):

```php
    /**
     * Declare this platform's connect-request validation contract — the single
     * input field, its Laravel rules (incl. regex), any custom 422 messages, and
     * whether the field is run through PlatformInput::urlish() before validation
     * (scheme-less pastes that the regex anchors on https?:// would otherwise miss).
     * Read by the shared PlatformConnectRequest. The field name + rules + messages
     * are part of the frozen API contract, so each platform reproduces its exact set.
     *
     * @param  array<int, mixed>      $rules
     * @param  array<string, string>  $messages
     */
    public function connectInput(string $field, array $rules, array $messages = [], bool $normalizeUrlish = false): self
    {
        $this->connectField = $field;
        $this->connectRules = $rules;
        $this->connectMessages = $messages;
        $this->connectNormalizesUrlish = $normalizeUrlish;

        return $this;
    }

    /** The connect-request input field name, or null when this platform isn't shared-request driven. */
    public function connectField(): ?string
    {
        return $this->connectField;
    }

    /** @return array<int, mixed> */
    public function connectRules(): array
    {
        return $this->connectRules;
    }

    /** @return array<string, string> */
    public function connectMessages(): array
    {
        return $this->connectMessages;
    }

    public function connectNormalizesUrlish(): bool
    {
        return $this->connectNormalizesUrlish;
    }
```

#### 2. `PlatformRegistryServiceProvider` — populate all 26 reducible descriptors

Add a block inside the `singleton(PlatformRegistry::class, …)` closure, **after** the descriptors are
registered (i.e. after the smart-detect / shop block, just before `return $r;` at line 241). Grouping it in
one place keeps the connect contract auditable in a single read.

```php
            // ── Connect-request validation contract (FOUND-19) ──────────────────
            // The single source of truth for each reducible platform's connect input
            // shape. Read by the shared PlatformConnectRequest via the route's
            // 'platform' default. Field names / maxes / regex / 422 messages are the
            // frozen API contract — reproduce verbatim. GoogleBusiness is irreducible
            // (multi-field) and keeps ConnectGoogleBusinessRequest.

            // url-shaped (17). The max differs per platform — these are NOT uniform.
            $r->get('bandcamp')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('deezer')->connectInput('url', ['required', 'string', 'max:300']);
            $r->get('eventbrite')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('fresha')->connectInput('url', ['required', 'string', 'max:500', 'regex:#^https?://(www\.)?fresha\.com/(?:[a-z]{2,3}(-[a-z]{2})?/)?a/[a-z0-9-]+/?$#i'], [], true);
            $r->get('humanitix')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('nowbookit')->connectInput('url', ['required', 'string', 'max:2048']);
            $r->get('opentable')->connectInput('url', ['required', 'string', 'max:2048']);
            $r->get('pinterest')->connectInput('url', ['required', 'string', 'max:200']);
            $r->get('resdiary')->connectInput('url', ['required', 'string', 'max:2048']);
            $r->get('skool')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('soundcloud')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('spotify')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('square')->connectInput('url', ['required', 'string', 'max:1000', 'regex:#^https?://([a-z0-9-]+\.)*(squareup\.com|square\.site)(/[^\s]*)?$#i'], ['url.regex' => 'Enter a valid Square booking link (a squareup.com or square.site URL).'], true);
            $r->get('strava')->connectInput('url', ['required', 'string', 'max:300']);
            $r->get('twitch')->connectInput('url', ['required', 'string', 'max:120']);
            $r->get('vimeo')->connectInput('url', ['required', 'string', 'max:300']);
            $r->get('youtube-music')->connectInput('url', ['required', 'string', 'max:300']);

            // single-named-field (3 distinct + 6 socials share 'username').
            $r->get('apple-music')->connectInput('artist', ['required', 'string', 'max:200']);
            $r->get('apple-podcast')->connectInput('show', ['required', 'string', 'max:200']);
            $r->get('youtube')->connectInput('channel', ['required', 'string', 'max:200']);
            foreach (['x', 'linkedin', 'threads', 'reddit', 'tiktok', 'facebook'] as $social) {
                $r->get($social)->connectInput('username', ['required', 'string', 'max:200']);
            }
```

Add the import at the top of the provider (used by the trait/request, but referenced for clarity in the
populated block — no new import is strictly required here since only string keys are used; `PlatformInput`
is imported in the request, not the provider).

#### 3. NEW `ResolvesConnectRules` trait

`app/Http/Requests/Platforms/Concerns/ResolvesConnectRules.php`:

```php
<?php

namespace App\Http\Requests\Platforms\Concerns;

use App\Services\Platforms\PlatformInput;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRegistry;

// Shared connect-request behaviour: resolve the platform descriptor from the
// route's 'platform' default (set by every thin connect route) and drive
// validation entirely from its connectInput() metadata. 404 fail-closed when the
// platform is unknown or declares no connect contract.
trait ResolvesConnectRules
{
    private ?PlatformDescriptor $resolvedDescriptor = null;

    protected function connectDescriptor(): PlatformDescriptor
    {
        if ($this->resolvedDescriptor !== null) {
            return $this->resolvedDescriptor;
        }

        $platform = $this->route('platform');
        abort_if(! is_string($platform) || $platform === '', 404);

        $descriptor = app(PlatformRegistry::class)->get($platform);
        // Must exist AND declare a connect contract; a platform without
        // connectInput() (e.g. google-business) is not shared-request driven.
        abort_if($descriptor === null || $descriptor->connectField() === null, 404);

        return $this->resolvedDescriptor = $descriptor;
    }

    // Authorization is enforced at the trait chokepoint in the controller
    // (ManagesIntegrationConnection write/forget call authorizeForUser), matching
    // every per-platform request this replaces.
    public function authorize(): bool
    {
        return true;
    }

    // Normalise scheme-less pastes for platforms whose regex anchors on https?://
    // (fresha, square) before the rule runs — mirrors their old prepareForValidation.
    protected function prepareForValidation(): void
    {
        $descriptor = $this->connectDescriptor();
        if (! $descriptor->connectNormalizesUrlish()) {
            return;
        }

        $field = $descriptor->connectField();
        if (is_string($this->input($field))) {
            $this->merge([$field => PlatformInput::urlish((string) $this->input($field))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $descriptor = $this->connectDescriptor();

        return [$descriptor->connectField() => $descriptor->connectRules()];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->connectDescriptor()->connectMessages();
    }
}
```

#### 4. NEW `PlatformConnectRequest`

`app/Http/Requests/Platforms/PlatformConnectRequest.php`:

```php
<?php

namespace App\Http\Requests\Platforms;

use App\Http\Requests\Platforms\Concerns\ResolvesConnectRules;
use Illuminate\Foundation\Http\FormRequest;

// The single connect request for every reducible platform. Its field, rules,
// custom 422 messages, and pre-validation normalisation all come from the
// platform descriptor resolved off the route's 'platform' default. Adding a
// platform = one ->connectInput(...) line in PlatformRegistryServiceProvider, not
// a new request class. GoogleBusiness (multi-field) keeps ConnectGoogleBusinessRequest.
class PlatformConnectRequest extends FormRequest
{
    use ResolvesConnectRules;
}
```

#### 5. `GenericPlatformController::connect` change

Swap the import and the type-hint + the hardcoded `['username']` read.

- Replace `use App\Http\Requests\Platforms\ConnectSocialLinkRequest;` with
  `use App\Http\Requests\Platforms\PlatformConnectRequest;`
- Change the method:

```php
    // POST /api/platforms/{platform}/connect — parse the input via the descriptor's
    // connect strategy, store the canonical {username,url}, echo it.
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $descriptor = $this->descriptor();

        // Capability checkpoint (spec §9) — true for everyone today; the gate
        // exists so future paid-tier/account-type rules are a per-descriptor flag.
        abort_unless($descriptor->availableFor($user), 403);

        $strategy = $descriptor->connectStrategy();
        abort_if($strategy === null, 404);

        $selection = $strategy->normalize($request->validated()[$descriptor->connectField()]);
        if ($selection === null) {
            return $this->error($descriptor->connectErrorMessage() ?? 'Enter a valid link.', 422);
        }

        // Round-trip through the typed boundary, then store the canonical shape.
        $payload = LinkPayload::fromArray($selection)->toArray();
        $this->writeConnection($user, $payload);

        $resourceClass = $descriptor->resourceClass();

        return $this->success((new $resourceClass($payload))->resolve());
    }
```

#### 6. Bespoke controller type-hint swaps (body unchanged)

For each controller below: replace the `use App\Http\Requests\Platforms\ConnectXRequest;` import with
`use App\Http\Requests\Platforms\PlatformConnectRequest;` and change the connect-method parameter type from
`ConnectXRequest $request` to `PlatformConnectRequest $request`. **Do not touch the method body** — each
already reads `$request->validated()[<field>]` with the matching field (verified):

| Controller | method(s) | reads |
|---|---|---|
| `FreshaController` | `connect` | `$validated['url']` |
| `SquareController` | `connect` | `$request->validated()['url']` |
| `YoutubeController` | `connect` | `$validated['channel']` |
| `AppleController` | `connectMusic`, `connectPodcast` | `$validated['artist']` / `$validated['show']` (via `connectFor`→`$cfg['inputField']`) |
| `BandcampController` | `connect` | `$validated['url']` |
| `VimeoController` | `connect` | `$request->validated()['url']` |
| `YoutubeMusicController` | `connect` | `$request->validated()['url']` |
| `EventbriteController` | `connect` | `$request->validated()['url']` |
| `HumanitixController` | `connect` | `$request->validated()['url']` |
| `SkoolController` | `connect` | `$validated['url']` |
| `StravaController` | `connect` | `$request->validated()['url']` |
| `SpotifyController` | `connect` | `$validated['url']` |
| `SoundcloudController` | `connect` | `$validated['url']` |
| `DeezerController` | `connect` | `$request->validated()['url']` |
| `TwitchController` | `connect` | `$request->validated()['url']` |
| `PinterestController` | `connect` | `$request->validated()['url']` |
| `OpenTableController` | `connect` | `$request->validated()['url']` |
| `ResDiaryController` | `connect` | `$request->validated()['url']` |
| `NowBookitController` | `connect` | `$request->validated()['url']` |

> `AppleController` imports BOTH `ConnectAppleMusicRequest` and `ConnectApplePodcastRequest` — remove both
> imports, add one `PlatformConnectRequest` import, and type-hint both `connectMusic`/`connectPodcast`.
> The `addEvent` methods on `EventbriteController`/`HumanitixController` use `AddPlatformEventRequest` —
> do NOT touch those.

#### 7. `routes/api/integrations.php` — add `->defaults('platform', $slug)` to every thin connect route

The shared request resolves the descriptor off this default. Edits (only the connect routes change):

- Fresha group: `Route::post('/connect', [FreshaController::class, 'connect'])->defaults('platform', 'fresha');`
- Square group: `Route::post('/connect', [SquareController::class, 'connect'])->defaults('platform', 'square');`
- YouTube group: `Route::post('/connect', [YoutubeController::class, 'connect'])->defaults('platform', 'youtube');`
- Apple group:
  - `Route::post('/music/connect', [AppleController::class, 'connectMusic'])->defaults('platform', 'apple-music');`
  - `Route::post('/podcast/connect', [AppleController::class, 'connectPodcast'])->defaults('platform', 'apple-podcast');`
- Bandcamp group: `Route::post('/connect', [BandcampController::class, 'connect'])->defaults('platform', 'bandcamp');`
- Vimeo group: `Route::post('/connect', [VimeoController::class, 'connect'])->defaults('platform', 'vimeo');`
- YouTube Music group: `Route::post('/connect', [YoutubeMusicController::class, 'connect'])->defaults('platform', 'youtube-music');`
- Events foreach: `Route::post('/connect', [$controller, 'connect'])->defaults('platform', $slug);`
  **⚠ The events group closure at `routes/api/integrations.php:189` is `->group(function () use ($controller) {` — it does NOT capture `$slug`. Change it to `->group(function () use ($controller, $slug) {` or `->defaults('platform', $slug)` throws "Undefined variable $slug" at route registration (fatal for EVERY route). The `$singleSelection` and `$migratedReads` loop closures already capture `$slug`.**
- `$singleSelection` loop: `Route::post('/connect', [$controller, 'connect'])->defaults('platform', $slug);`
  (this also stamps `google-business`; harmless — `GoogleBusinessController::connect` keeps
  `ConnectGoogleBusinessRequest` and ignores the route param via its hardcoded `platform()`).
- `$migratedReads` loop: `Route::post('/connect', [$cfg['controller'], 'connect'])->defaults('platform', $slug);`
- Link-only socials loop: already has `->defaults('platform', $slug)` — no change.

> `php artisan route:list` ACTION/URI columns do NOT show route defaults, so adding these does NOT change
> the route:list diff or the golden-master `toBe(52)`.

#### 8. DELETE the 21 reducible request classes (keep `ConnectGoogleBusinessRequest.php`)

```
git rm app/Http/Requests/Platforms/ConnectAppleMusicRequest.php \
       app/Http/Requests/Platforms/ConnectApplePodcastRequest.php \
       app/Http/Requests/Platforms/ConnectBandcampRequest.php \
       app/Http/Requests/Platforms/ConnectDeezerRequest.php \
       app/Http/Requests/Platforms/ConnectEventbriteRequest.php \
       app/Http/Requests/Platforms/ConnectFreshaRequest.php \
       app/Http/Requests/Platforms/ConnectHumanitixRequest.php \
       app/Http/Requests/Platforms/ConnectNowBookitRequest.php \
       app/Http/Requests/Platforms/ConnectOpenTableRequest.php \
       app/Http/Requests/Platforms/ConnectPinterestRequest.php \
       app/Http/Requests/Platforms/ConnectResDiaryRequest.php \
       app/Http/Requests/Platforms/ConnectSkoolRequest.php \
       app/Http/Requests/Platforms/ConnectSocialLinkRequest.php \
       app/Http/Requests/Platforms/ConnectSoundcloudRequest.php \
       app/Http/Requests/Platforms/ConnectSpotifyRequest.php \
       app/Http/Requests/Platforms/ConnectSquareRequest.php \
       app/Http/Requests/Platforms/ConnectStravaRequest.php \
       app/Http/Requests/Platforms/ConnectTwitchRequest.php \
       app/Http/Requests/Platforms/ConnectVimeoRequest.php \
       app/Http/Requests/Platforms/ConnectYoutubeMusicRequest.php \
       app/Http/Requests/Platforms/ConnectYoutubeRequest.php
```

Two stale **comment-only** references remain — update them so they don't name deleted classes:
- `ConnectNowBookitRequest.php` and `ConnectResDiaryRequest.php` are gone, but
  `ConnectOpenTableRequest` is referenced in *their now-deleted* headers — N/A after deletion.
- `app/Providers/PlatformRegistryServiceProvider.php:220` comment "(mirrors ConnectFreshaRequest)" →
  change to "(mirrors the fresha connect regex)".

(Confirmed by grep: the ONLY non-comment references to these 21 classes are the controllers swapped in
step 6 and `GenericPlatformController` in step 5.)

---

### Tests that MUST stay green (golden master)
- `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php` — `toBe(52)` GET-read-route
  count + the sorted URI snapshot (unchanged by PR9; PR9 touches only POST connect routes + request classes).
- `tests/Feature/Platforms/GenericLinkControllerTest.php` — the 6 socials' connect happy-path + exact
  `connectErrorMessage()` 422s (these prove the descriptor-driven `connectField()` read works).
- `tests/Feature/Platforms/SquareConnectionTest.php` — rejects non-Square URL (regex 422), happy path,
  XOR 409. The custom regex message survives via `connectMessages()`.
- `tests/Feature/Platforms/OpenTableConnectionTest.php`, `ReservationProvidersTest.php`,
  `ScraperPlatformsConnectionTest.php`, `LinkPlatformsConnectionTest.php`, `FreshaPayloadTest.php`,
  `EventsCatalogTest.php`, `IntegrationsV2/V3/V4*Test.php` — all per-platform connect behaviour.
- `tests/Feature/Platforms/Registry/RegistryCoverageTest.php` — registry key/refreshable sets (unchanged).

### NEW test (pins the connect contract against drift)
`tests/Feature/Platforms/Registry/RegistryConnectCoverageTest.php` — assert every reducible descriptor's
`connectField()`/`connectRules()`/`connectMessages()`/`connectNormalizesUrlish()` equals the verbatim
table above, and that `google-business` has a null `connectField()`. This is the future drift guard that
FOUND-19's whole point is to enable.

```php
<?php

use App\Services\Platforms\Registry\PlatformRegistry;

it('pins the descriptor-driven connect contract for every reducible platform', function () {
    $registry = app(PlatformRegistry::class);

    $expected = [
        'bandcamp' => ['url', ['required', 'string', 'max:500'], [], false],
        'deezer' => ['url', ['required', 'string', 'max:300'], [], false],
        'eventbrite' => ['url', ['required', 'string', 'max:500'], [], false],
        'fresha' => ['url', ['required', 'string', 'max:500', 'regex:#^https?://(www\.)?fresha\.com/(?:[a-z]{2,3}(-[a-z]{2})?/)?a/[a-z0-9-]+/?$#i'], [], true],
        'humanitix' => ['url', ['required', 'string', 'max:500'], [], false],
        'nowbookit' => ['url', ['required', 'string', 'max:2048'], [], false],
        'opentable' => ['url', ['required', 'string', 'max:2048'], [], false],
        'pinterest' => ['url', ['required', 'string', 'max:200'], [], false],
        'resdiary' => ['url', ['required', 'string', 'max:2048'], [], false],
        'skool' => ['url', ['required', 'string', 'max:500'], [], false],
        'soundcloud' => ['url', ['required', 'string', 'max:500'], [], false],
        'spotify' => ['url', ['required', 'string', 'max:500'], [], false],
        'square' => ['url', ['required', 'string', 'max:1000', 'regex:#^https?://([a-z0-9-]+\.)*(squareup\.com|square\.site)(/[^\s]*)?$#i'], ['url.regex' => 'Enter a valid Square booking link (a squareup.com or square.site URL).'], true],
        'strava' => ['url', ['required', 'string', 'max:300'], [], false],
        'twitch' => ['url', ['required', 'string', 'max:120'], [], false],
        'vimeo' => ['url', ['required', 'string', 'max:300'], [], false],
        'youtube-music' => ['url', ['required', 'string', 'max:300'], [], false],
        'apple-music' => ['artist', ['required', 'string', 'max:200'], [], false],
        'apple-podcast' => ['show', ['required', 'string', 'max:200'], [], false],
        'youtube' => ['channel', ['required', 'string', 'max:200'], [], false],
        'x' => ['username', ['required', 'string', 'max:200'], [], false],
        'linkedin' => ['username', ['required', 'string', 'max:200'], [], false],
        'threads' => ['username', ['required', 'string', 'max:200'], [], false],
        'reddit' => ['username', ['required', 'string', 'max:200'], [], false],
        'tiktok' => ['username', ['required', 'string', 'max:200'], [], false],
        'facebook' => ['username', ['required', 'string', 'max:200'], [], false],
    ];

    foreach ($expected as $key => [$field, $rules, $messages, $urlish]) {
        $d = $registry->get($key);
        expect($d)->not->toBeNull("missing descriptor: {$key}");
        expect($d->connectField())->toBe($field, "field drift: {$key}");
        expect($d->connectRules())->toBe($rules, "rules drift: {$key}");
        expect($d->connectMessages())->toBe($messages, "messages drift: {$key}");
        expect($d->connectNormalizesUrlish())->toBe($urlish, "urlish drift: {$key}");
    }

    // GoogleBusiness is irreducible — not shared-request driven.
    expect($registry->get('google-business')->connectField())->toBeNull();
});
```

### TDD task list (PR9)

Branch already exists (`audit-fix/foundational-2026-06-30`); work per fix-flow.

**Task 9.1 — descriptor metadata + provider population (failing test first).**
1. Write `RegistryConnectCoverageTest.php` (above).
2. Run-fail: `php artisan test tests/Feature/Platforms/Registry/RegistryConnectCoverageTest.php`
   → expect `Error: Call to undefined method …PlatformDescriptor::connectField()`.
3. Add the 4 properties + 5 methods to `PlatformDescriptor` (code §1); add the populate block to the
   provider (code §2); fix the `:220` comment.
4. Run-pass: same command → green (27 assertions).
5. `php artisan pint --dirty`
6. Commit: `git commit -m "feat(platforms): descriptor-driven connect validation contract (FOUND-19)"`

**Task 9.2 — shared request + GenericPlatformController (socials path).**
1. Run-fail anchor: `php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php` MUST be green
   BEFORE the change (baseline). Then create `ResolvesConnectRules` (§3) + `PlatformConnectRequest` (§4);
   swap `GenericPlatformController::connect` (§5).
2. Run-pass: `php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php` → green (still uses
   the existing socials `->defaults('platform',$slug)`; now validated by the shared request).
3. `php artisan pint --dirty`
4. Commit: `git commit -m "feat(platforms): PlatformConnectRequest + ResolvesConnectRules; socials use it (FOUND-19)"`

**Task 9.3 — swap all bespoke connect controllers + add route defaults.**
1. Apply §6 (18 controllers / 19 methods) + §7 (route defaults on every thin connect route).
2. Run-pass (the connect behaviour suites):
   `php artisan test tests/Feature/Platforms/` → green (SquareConnectionTest regex 422 + custom message,
   OpenTable/ReservationProviders, Scraper/LinkPlatforms, Fresha, IntegrationsV2/V3/V4, EventsCatalog).
3. `php artisan pint --dirty`
4. Commit: `git commit -m "refactor(platforms): bespoke connect controllers consume PlatformConnectRequest (FOUND-19)"`

**Task 9.4 — delete the 21 reducible request classes.**
1. `git rm` the 21 files (§8).
2. Run-fail guard first: `composer dump-autoload -o` then
   `php artisan test tests/Feature/Platforms/` → if any "class not found" surfaces, a controller swap was
   missed — fix it. Expect green.
3. `php artisan pint --dirty`
4. Commit: `git commit -m "refactor(platforms): remove 21 redundant connect Form Requests (FOUND-19)"`

**Task 9.5 — golden-master + full-suite gate.**
1. `php artisan test tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`
   → `toBe(52)` + URI snapshot green.
2. Capture a `route:list` baseline BEFORE Task 9.1 (see PR10's normalized-diff recipe) and confirm the
   normalized POST-connect actions are unchanged (only defaults added — invisible to route:list).
3. **Full suite:** `composer test` → green. (A filtered subset is a false signal — Wave-1 hit a 9-test
   regression only the full suite caught. Run the whole thing.)

---
---

## PR10 — FOUND-21 — route registration over the registry (PURE refactor, same 52 routes)

> **Do not start until PR9 is merged/green.** PR10 depends on PR9's `->defaults('platform', $slug)` on every
> thin connect route and on the shared request seam.

### Goal
Collapse the three hand-maintained route loops (`$singleSelection`, `$migratedReads`, link-only socials)
in `routes/api/integrations.php` into ONE loop over the `PlatformRegistry`, driven by a per-descriptor
`routeShape`, so adding a simple platform is a descriptor flag — with byte-identical routes.

### Architecture
Add a `PlatformRouteShape` enum (`LinkOnly`/`SingleSelection`/`MultiAccount`/`Bespoke`) + a
`connectController(?string)` + a `multiAccount(bool)` to `PlatformDescriptor`. Populate them in the provider
for the 17 loop platforms (the rest default to `Bespoke` and keep their standalone groups untouched). The
route file resolves `app(PlatformRegistry::class)`, iterates `all()`, skips `Bespoke`, and emits the exact
connect/selection/forget(/accounts) endpoints each shape currently has. The 9 controllers used ONLY by the
former loops move their imports out of the route file into the provider; the route file keeps imports for
the standalone groups + the one-off routes.

### Blast radius
- NEW `app/Services/Platforms/Registry/PlatformRouteShape.php`
- `app/Services/Platforms/Registry/PlatformDescriptor.php` (add enum + connectController + multiAccount)
- `app/Providers/PlatformRegistryServiceProvider.php` (set shape/controller/multi on 17 descriptors;
  add 11 controller imports for the `connectController` FQNs)
- `routes/api/integrations.php` (replace 3 loops with 1; remove 9 now-unused controller imports)
- No new app behaviour, no migration, no `tests/Pest.php` change.

### Premise grounding (verified + naming note)
- Verified: `routes/api/integrations.php` statically imports **30** controllers (lines 3-32) and has the
  3 partial loops: `$singleSelection` (`:271`), `$migratedReads` (`:299`), link-only socials (`:337`).
- **The three loops have DIFFERENT controller wiring** (this is the binding constraint for "route:list diff
  EMPTY" — the action column must match):
  - `$singleSelection` (skool, strava, google-business): connect + selection + DELETE **all on the bespoke
    controller**. No `/accounts`.
  - `$migratedReads` (spotify, soundcloud, deezer, twitch = `multi:true`; pinterest, opentable, resdiary,
    nowbookit = `multi:false`): connect on the **bespoke** controller; selection + DELETE (+ accounts when
    `multi`) on **`GenericPlatformController`** via the `platform` default.
  - link-only socials (x, linkedin, threads, reddit, tiktok, facebook): connect + selection + DELETE **all
    on `GenericPlatformController`**.
- **NAMING NOTE (deviation flagged):** the brief's enum case `MultiAccount` is used here for the whole
  `$migratedReads` archetype (bespoke connect + generic reads), and the separate `multiAccount(bool)` gates
  the optional `/accounts` pair — so single-account members (pinterest/opentable/resdiary/nowbookit) carry
  `routeShape=MultiAccount, multiAccount=false`. The case name is slightly broader than "multi" but keeps
  the brief's 4 names and mirrors the existing `$migratedReads['multi']` flag exactly. If the reviewer
  prefers a non-overloaded name, rename the case to `MigratedReads` (behaviour identical).
- Verified: `google-business` and `opentable` controllers are ALSO used by one-off routes
  (`/google-business/synced`, `/google-business/synced/apply`, `/opentable/suggestion`), so their imports
  STAY in the route file even though they're loop platforms.

### The route-shape mapping (exact)

| Platform | routeShape | connectController | multiAccount | connect | selection / DELETE | /accounts |
|---|---|---|---|---|---|---|
| x, linkedin, threads, reddit, tiktok, facebook | LinkOnly | (Generic) | false | Generic | Generic | no |
| skool, strava, google-business | SingleSelection | own controller | false | own | own | no |
| spotify, soundcloud, deezer, twitch | MultiAccount | own controller | true | own | Generic | Generic |
| pinterest, opentable, resdiary, nowbookit | MultiAccount | own controller | false | own | Generic | no |
| everything else (youtube, youtube-music, vimeo, bandcamp, apple-music, apple-podcast, instagram, eventbrite, humanitix, events-custom, fresha, square, shop, custom, booking, reservations, online-ordering, mixcloud, tidal) | Bespoke | — | — | (standalone groups, unchanged) | | |

---

### Complete code

#### 1. NEW `PlatformRouteShape` enum

`app/Services/Platforms/Registry/PlatformRouteShape.php`:

```php
<?php

namespace App\Services\Platforms\Registry;

// How the registry-driven route loop in routes/api/integrations.php emits a
// platform's per-user endpoints. Bespoke platforms are skipped by the loop and
// keep their hand-written standalone groups.
enum PlatformRouteShape
{
    // connect + selection + DELETE all on GenericPlatformController (link-only socials).
    case LinkOnly;

    // connect + selection + DELETE all on the platform's own controller; no /accounts
    // (the former $singleSelection group: skool, strava, google-business).
    case SingleSelection;

    // connect on the platform's own controller; selection + DELETE (and /accounts when
    // multiAccount()) on GenericPlatformController (the former $migratedReads group).
    case MultiAccount;

    // Not emitted by the loop — the platform keeps its standalone route group. Default.
    case Bespoke;
}
```

#### 2. `PlatformDescriptor` additions (PR10)

Add properties:

```php
    private PlatformRouteShape $routeShape = PlatformRouteShape::Bespoke;

    private ?string $connectController = null;

    private bool $multiAccount = false;
```

Add `use App\Services\Platforms\Registry\PlatformRouteShape;` is unnecessary (same namespace). Add methods
(after the connect contract methods from PR9):

```php
    /**
     * Declare the route archetype the registry-driven loop should emit for this
     * platform. $connectController is the controller class serving the bespoke
     * connect (and, for SingleSelection, selection/DELETE too); null for LinkOnly
     * (served by GenericPlatformController). $multiAccount gates the /accounts pair
     * for the MultiAccount shape.
     */
    public function routes(PlatformRouteShape $shape, ?string $connectController = null, bool $multiAccount = false): self
    {
        $this->routeShape = $shape;
        $this->connectController = $connectController;
        $this->multiAccount = $multiAccount;

        return $this;
    }

    public function routeShape(): PlatformRouteShape
    {
        return $this->routeShape;
    }

    public function connectController(): ?string
    {
        return $this->connectController;
    }

    public function multiAccount(): bool
    {
        return $this->multiAccount;
    }
```

#### 3. `PlatformRegistryServiceProvider` — set route shapes + add controller imports

Add controller imports at the top of the provider (the 11 `connectController` FQNs):

```php
use App\Http\Controllers\Api\Platforms\DeezerController;
use App\Http\Controllers\Api\Platforms\GoogleBusinessController;
use App\Http\Controllers\Api\Platforms\NowBookitController;
use App\Http\Controllers\Api\Platforms\OpenTableController;
use App\Http\Controllers\Api\Platforms\PinterestController;
use App\Http\Controllers\Api\Platforms\ResDiaryController;
use App\Http\Controllers\Api\Platforms\SkoolController;
use App\Http\Controllers\Api\Platforms\SoundcloudController;
use App\Http\Controllers\Api\Platforms\SpotifyController;
use App\Http\Controllers\Api\Platforms\StravaController;
use App\Http\Controllers\Api\Platforms\TwitchController;
use App\Services\Platforms\Registry\PlatformRouteShape;
```

Add a route-shape block inside the singleton closure, after the PR9 connect-contract block, before
`return $r;`:

```php
            // ── Route archetypes (FOUND-21) ─────────────────────────────────────
            // Drives the single registry loop in routes/api/integrations.php. Bespoke
            // platforms (the default) keep their standalone groups and are skipped.

            // Link-only socials: connect/selection/forget all via GenericPlatformController.
            foreach (['x', 'linkedin', 'threads', 'reddit', 'tiktok', 'facebook'] as $social) {
                $r->get($social)->routes(PlatformRouteShape::LinkOnly);
            }

            // Single-selection (connect/selection/forget all on the bespoke controller).
            $r->get('skool')->routes(PlatformRouteShape::SingleSelection, SkoolController::class);
            $r->get('strava')->routes(PlatformRouteShape::SingleSelection, StravaController::class);
            $r->get('google-business')->routes(PlatformRouteShape::SingleSelection, GoogleBusinessController::class);

            // Migrated reads: bespoke connect + generic reads. multiAccount gates /accounts.
            $r->get('spotify')->routes(PlatformRouteShape::MultiAccount, SpotifyController::class, true);
            $r->get('soundcloud')->routes(PlatformRouteShape::MultiAccount, SoundcloudController::class, true);
            $r->get('deezer')->routes(PlatformRouteShape::MultiAccount, DeezerController::class, true);
            $r->get('twitch')->routes(PlatformRouteShape::MultiAccount, TwitchController::class, true);
            $r->get('pinterest')->routes(PlatformRouteShape::MultiAccount, PinterestController::class, false);
            $r->get('opentable')->routes(PlatformRouteShape::MultiAccount, OpenTableController::class, false);
            $r->get('resdiary')->routes(PlatformRouteShape::MultiAccount, ResDiaryController::class, false);
            $r->get('nowbookit')->routes(PlatformRouteShape::MultiAccount, NowBookitController::class, false);
```

#### 4. `routes/api/integrations.php` — replace the 3 loops with 1

**(a) Remove these 9 imports** (now referenced only via the provider's `connectController`):
`SkoolController`, `StravaController`, `SpotifyController`, `SoundcloudController`, `DeezerController`,
`TwitchController`, `PinterestController`, `ResDiaryController`, `NowBookitController`.

**Keep** `GoogleBusinessController` (used by `/google-business/synced` + `/synced/apply`) and
`OpenTableController` (used by `/opentable/suggestion`) imports, and `GenericPlatformController`,
`RefreshController`, plus every standalone-group controller. Add
`use App\Services\Platforms\Registry\PlatformRegistry;` and
`use App\Services\Platforms\Registry\PlatformRouteShape;`.

**(b) Delete** the three blocks: `$singleSelection`/`$multiAccount` + its `foreach` (lines ~271-293), the
`$migratedReads` + its `foreach` (lines ~295-329), and the link-only socials `foreach` (lines ~331-345).

**(c) Insert** this single loop in their place (keep it where the link-only socials loop was — after the
`menu` group and before the `opentable/suggestion` one-off, so the standalone groups above are untouched):

```php
    // ── Registry-driven simple-archetype routes (FOUND-21) ───────────────────
    // One loop replaces the former $singleSelection, $migratedReads, and link-only
    // social loops. Each descriptor declares its routeShape (in
    // PlatformRegistryServiceProvider); this loop emits the matching
    // connect / selection / forget (/accounts) endpoints with byte-identical wiring.
    // Bespoke platforms keep their standalone groups above. Adding a simple platform
    // = one ->routes(...) descriptor line, no edit here.
    foreach (app(PlatformRegistry::class)->all() as $slug => $descriptor) {
        $shape = $descriptor->routeShape();
        if ($shape === PlatformRouteShape::Bespoke) {
            continue;
        }

        Route::prefix("{$base}/{$slug}")
            ->middleware($middleware)
            ->group(function () use ($descriptor, $slug, $shape) {
                // connect: link-only via the generic controller; everything else via
                // the platform's own controller (carrying the platform default for the
                // shared PlatformConnectRequest).
                $connectController = $shape === PlatformRouteShape::LinkOnly
                    ? GenericPlatformController::class
                    : $descriptor->connectController();
                Route::post('/connect', [$connectController, 'connect'])->defaults('platform', $slug);

                if ($shape === PlatformRouteShape::SingleSelection) {
                    // selection + DELETE stay on the bespoke controller.
                    $controller = $descriptor->connectController();
                    Route::get('/selection', [$controller, 'selection']);
                    Route::delete('/', [$controller, 'forget']);

                    return;
                }

                // LinkOnly + MultiAccount: reads served by the registry-driven
                // GenericPlatformController via the platform route default.
                Route::get('/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', $slug);
                Route::delete('/', [GenericPlatformController::class, 'forget'])->defaults('platform', $slug);

                if ($descriptor->multiAccount()) {
                    Route::get('/accounts', [GenericPlatformController::class, 'accounts'])->defaults('platform', $slug);
                    Route::delete('/accounts/{id}', [GenericPlatformController::class, 'removeAccount'])
                        ->where('id', '[A-Za-z0-9._-]+')->defaults('platform', $slug);
                }
            });
    }
```

> The loop registers in registry order, which differs from the old source order, so the route TABLE order
> changes — but the route SET (uri + method + action + middleware) is identical. Assert via a normalized
> diff (below), not a raw line diff.
>
> Resolving `app(PlatformRegistry::class)` during route registration (boot) is safe: the singleton is bound
> in the provider's `register()`, which runs before route files load, and the registry it builds only
> `make()`s simple services already resolvable at boot (it's the same build that happens lazily today).

---

### Golden master (PR10 — the gating assertion)

PR10 is a pure refactor: the route set must be **byte-identical** before/after.

**(a) Existing test stays green** —
`tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`: `toBe(52)` + the sorted
URI snapshot (lines 582-636). This alone catches any GET-read-route add/remove/swap.

**(b) Existing `RegistryCoverageTest.php` stays green** — incl. "does not register routes for the dormant
mixcloud/tidal embeds" (they're `Bespoke`, skipped by the loop).

**(c) Normalized `route:list` diff MUST be empty.** Capture before the first PR10 commit and after the last:

```bash
php artisan route:list --json \
  | jq -S '[ .[] | select(.uri | startswith("api/platforms/") or startswith("api/integrations/"))
             | {uri, method, action, middleware} ] | sort_by(.uri, .method)' \
  > /tmp/routes-before.json     # run on the PR9-merged baseline (stash the PR10 diff)
# …apply PR10…
php artisan route:list --json \
  | jq -S '[ .[] | select(.uri | startswith("api/platforms/") or startswith("api/integrations/"))
             | {uri, method, action, middleware} ] | sort_by(.uri, .method)' \
  > /tmp/routes-after.json
diff /tmp/routes-before.json /tmp/routes-after.json   # MUST be empty
```

This compares the SET of (uri, method, action, middleware) tuples normalized + sorted, so registration
order is irrelevant and a controller/method swap WOULD show. Empty diff = pure refactor proven.

> Baseline count for sanity: `php artisan route:list | grep -cE "api/(platforms|integrations)/"` = **356**
> on the current tree (both prefixes, all verbs). This total must be unchanged after PR10.

### NEW test (pins the route-shape mapping against drift)
`tests/Feature/Platforms/Registry/RegistryRouteShapeTest.php` — assert each platform's
`routeShape()`/`connectController()`/`multiAccount()` equals the mapping table, and that the Bespoke set
(youtube, youtube-music, vimeo, bandcamp, apple-music, apple-podcast, instagram, eventbrite, humanitix,
events-custom, fresha, square, shop, custom, booking, reservations, online-ordering, mixcloud, tidal) all
report `PlatformRouteShape::Bespoke`. This makes "I changed a shape but not the routes" a test failure.

```php
<?php

use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;

it('pins the registry-driven route shapes', function () {
    $registry = app(PlatformRegistry::class);

    $linkOnly = ['x', 'linkedin', 'threads', 'reddit', 'tiktok', 'facebook'];
    foreach ($linkOnly as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::LinkOnly, $key);
        expect($registry->get($key)->connectController())->toBeNull($key);
    }

    $single = ['skool', 'strava', 'google-business'];
    foreach ($single as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::SingleSelection, $key);
        expect($registry->get($key)->connectController())->not->toBeNull($key);
        expect($registry->get($key)->multiAccount())->toBeFalse($key);
    }

    $multiTrue = ['spotify', 'soundcloud', 'deezer', 'twitch'];
    foreach ($multiTrue as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::MultiAccount, $key);
        expect($registry->get($key)->multiAccount())->toBeTrue($key);
    }

    $multiFalse = ['pinterest', 'opentable', 'resdiary', 'nowbookit'];
    foreach ($multiFalse as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::MultiAccount, $key);
        expect($registry->get($key)->multiAccount())->toBeFalse($key);
    }

    $bespoke = ['youtube', 'youtube-music', 'vimeo', 'bandcamp', 'apple-music', 'apple-podcast',
        'instagram', 'eventbrite', 'humanitix', 'events-custom', 'fresha', 'square', 'shop',
        'custom', 'booking', 'reservations', 'online-ordering', 'mixcloud', 'tidal'];
    foreach ($bespoke as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::Bespoke, $key);
    }
});
```

### TDD task list (PR10)

**Task 10.1 — enum + descriptor + provider (failing test first).**
1. Write `RegistryRouteShapeTest.php` (above).
2. Run-fail: `php artisan test tests/Feature/Platforms/Registry/RegistryRouteShapeTest.php`
   → `Error: undefined method …PlatformDescriptor::routeShape()`.
3. Add `PlatformRouteShape` enum (§1); add descriptor methods (§2); add provider shape block + imports (§3).
4. Run-pass: same command → green.
5. `php artisan pint --dirty`
6. Commit: `git commit -m "feat(platforms): PlatformRouteShape descriptor metadata (FOUND-21)"`

**Task 10.2 — collapse the route loops (the refactor).**
1. Capture `route:list` normalized baseline → `/tmp/routes-before.json` (recipe above) on the pre-edit tree.
2. Apply §4 (remove 9 imports, delete 3 loops, insert the 1 loop, add the 2 registry imports).
3. `php artisan route:list` — confirm it boots with no resolution error.
4. Capture `/tmp/routes-after.json`; `diff` MUST be empty.
5. Run the golden master + route-shape tests:
   `php artisan test tests/Feature/Platforms/GoldenMaster tests/Feature/Platforms/Registry` → green.
6. `php artisan pint --dirty`
7. Commit: `git commit -m "refactor(platforms): registry-driven route registration (FOUND-21)"`

**Task 10.3 — full-suite gate.**
1. `composer test` → green. (Full suite, not a subset — a filtered green is a false signal.)
2. Re-confirm the normalized `route:list` diff is still empty after `pint`.

---

### Cross-PR notes
- PR10 depends on PR9's `->defaults('platform', $slug)` on every thin connect route (the loop reproduces
  them; without PR9 those defaults wouldn't exist and the shared request would 404).
- Both PRs are CODE-ONLY: no `supabase/migrations/` file, no `tests/Pest.php` schema edit.
- `composer dump-autoload -o` after the PR9 file deletions (and once in PR10 after moving imports) to avoid
  a stale optimized classmap masking a missed reference.


---

