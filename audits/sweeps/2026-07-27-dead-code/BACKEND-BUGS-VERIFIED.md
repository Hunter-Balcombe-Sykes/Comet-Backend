# Backend bugs — verified & consolidated for execution

**Verified 2026-07-27** against live code in this repo (`Comet-Backend`).

## Scope

Source: the merged `FULL-CODEBASE-BLOAT-INHERITANCE-AUDIT-PLAN.md` in this folder — specifically its
"Comet-Backend — Bloat & fixes" → **"Fixes — real bugs, not just cleanup"** section (its items 1–6).
Backend only, as requested; the source doc's `Partna-Frontend` (6 items, its lines 320-375) and
`partna-monorepo` (4 items, its lines 819-825) bug lists were **not** examined — see the appendix.

**Not produced by `scripts/audit/audit.sh`** — the source was a manual sweep. This file is a
verification pass over its bug claims, reformatted to the `execute audit` contract
(`scripts/audit/fix-flow.md`).

**Method:** every claim re-derived from live code, not taken on trust — traced the DDL in
`supabase/migrations/`, read the route table with its middleware groups, read each policy's actual
abilities *and return values*, read the write paths end to end. Where a verdict turned on runtime
behaviour, the test was **run** and the mechanism **proved by controlled experiment** (see #FFLAG-1
Evidence). Nothing has been fixed.

**Files verified:**
- app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php
- app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffAccountDeletionController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php
- app/Http/Requests/Api/Staff/UserSite/Services/{StaffStoreServiceRequest,StaffUpdateServiceRequest}.php
- app/Http/Requests/Api/User/Services/{StoreServiceRequest,UpdateServiceCategoryAssignmentRequest}.php
- app/Policies/{FeatureFlagPolicy,NotificationPolicy,UserSelfPolicy}.php
- app/Models/Core/FeatureFlagOverride.php, app/Models/Core/User/Service.php
- app/Http/Resources/FeatureFlagOverrideResource.php
- routes/api/staff.php, config/partna.php, config/database.php
- supabase/migrations/20260526000000_baseline_standalone_user.sql (adjacent, pulled for verification)
- supabase/migrations/20260527030000_rename_professional_to_user.sql (adjacent)
- supabase/migrations/20260721180000_service_multi_category.sql (adjacent)
- tests/Feature/FeatureFlags/{FeatureFlagTestCase,StaffFeatureFlagsControllerTest}.php, tests/Pest.php

---

## Findings at a glance

**1 of the source doc's 6 bug claims is a real defect.** Two are real-but-much-smaller than
described, two are documented deliberate design the sweep misread as omissions, and one is
cross-repo with no backend work in it. The single real bug is worse than stated, and one prescribed
fix (source item 2) would **break working endpoints** if applied as written.

| Tier | Count | IDs |
|---|---|---|
| P0 — blockers | 1 | #FFLAG-1 |
| P1 — high     | 1 | #TESTFX-1 |
| P2 — medium   | 0 | — |
| P3 — low      | 3 | #NOTIF-1, #SVC-1, #FFLAG-2 |
| **Total actionable** | **5** | |
| Decisions (no code fix) | 1 | #PRIV-D1 |
| Rejected / verified not-a-bug | 4 | see final section |

### Traceability to the source doc

| Source item | Outcome here |
|---|---|
| 1 — Live crash: override controller queries a dropped column | ✅ **CONFIRMED, understated** → #FFLAG-1 + #TESTFX-1 |
| 2 — Two Staff controllers "missing authorization entirely" | ⚠️ Partially real, **prescription is wrong** → #FFLAG-2 (rescoped) |
| 3 — Four methods missing authorization | ⚠️ 3 of 4 documented deliberate → #NOTIF-1 + #PRIV-D1; 1 rejected |
| 4 — Staff can't multi-category a service | ⚠️ Core claim **factually wrong** → #SVC-1 (narrow residual) |
| 5 — `typography.weight` dropped on validation | ⛔ Out of scope (monorepo) — zero backend work |
| 6 — Charlie notification categories | ✅ Backend ground truth confirmed — zero backend work |

---

## Execution policy  (how `execute audit` runs this file)

- **Plan:**       Opus 5
- **Implement:**  Sonnet 5
- **Review:**     Sonnet 5  — a *separate, independent* instance (never the implementer)
- **Combine plan+impl:** YES for S/XS effort · NO for P0/P1 or L/XL (those plan first, then implement)
- **Per-item override:** escalate to Opus for gnarly logic or risky blast radius. #FFLAG-2 and
  #NOTIF-1 touch authorization — plan first regardless of their XS/S size.
- **Trigger:** say `execute audit <path to this file>` to run plan → implement → independent review
  per bundle/item. Blockers (P0 · auth · money · DB/migration · L/XL) pause for sign-off.
  Full runbook: `scripts/audit/fix-flow.md`.

> **Verification constraint specific to this file:** #FFLAG-1's failure mode is **Postgres-only** —
> a SQLite test structurally cannot catch a `42703`. Its regression test belongs in the real
> Postgres CI lane (`postgres-tests`, added in `688aee43`), not the default suite. A green
> `composer test` is **not** sufficient evidence for #FFLAG-1 or #TESTFX-1.

---

## Progress

- P0 Blockers: 1 of 1 complete
- P1 High: 1 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 2 of 3 complete

**Bundle 1 (#FFLAG-1 + #TESTFX-1) shipped 2026-07-27** on `audit-fix/dead-code-backend-bugs-2026-07-27`.
Verified against **real Postgres 16**, not just SQLite: the new
`tests/Postgres/StaffFeatureFlagOverrideEndpointTest.php` was run pre-fix and reproduced the exact
production failure (`SQLSTATE[42703]: Undefined column … "brand_id" does not exist`), then post-fix
returns 201. That discriminate check is the evidence this file's Execution policy demanded.

Two corrections to this audit's own claims were recorded inline above (#FFLAG-1's mechanism, and
#TESTFX-1's prescribed guard file), plus one live bug this audit missed entirely
(`CreateFeatureFlagRequest.php` — `POST /api/staff/feature-flags` 500'd on every call).

---

## P0 — Blockers

- [x] **#FFLAG-1** · P0 — `POST /staff/feature-flags/{key}/overrides` 500s on every call: the response re-read filters on `brand_id`, a column dropped from `core.feature_flag_overrides` two months ago

    > **⚠ CORRECTION — recorded during execution 2026-07-27. This finding's mechanism was only half right.**
    > The endpoint was broken by **two independent bugs**, and this finding saw only the second one.
    > `CreateOverrideRequest.php:17` carried `exists:core.users,id`. Laravel's
    > `ValidatesAttributes::parseTable()` (`vendor/…/ValidatesAttributes.php:1152`) does
    > `explode('.', $table, 2)` and treats segment one as a **connection name, not a schema
    > qualifier** — so that rule meant *connection `core`*, which is not configured in
    > `config/database.php`. Proved by execution: it throws
    > `InvalidArgumentException: Database connection [core] not configured.`
    > FormRequest validation runs **before the controller body**, so:
    > - ❌ *"the write at :47 has already committed before the failing re-read"* — **false.** No write ever ran.
    > - ❌ *"the override is silently applied while the staff member sees a server error"* — **false.**
    >   There was never any silent partial state, and no double-apply on retry. Good news operationally.
    > - ❌ Deleting `->whereNull('brand_id')` alone would **not** have fixed the endpoint — it would
    >   still 500, one layer earlier.
    >
    > Why nobody caught it: **every existing test stubs `CreateOverrideRequest::validated()` directly**,
    > so this rule had never once been executed. It surfaced only when the #TESTFX-1 work added a real
    > HTTP-level Postgres test — which is precisely the coverage gap #TESTFX-1 is about.
    >
    > The rest of the repo already had this right: **15 call sites** use `Rule::exists('pgsql.core.users', 'id')`.
    > The two feature-flag Requests were the only ones missing the `pgsql.` prefix. Both are now fixed
    > (`CreateOverrideRequest.php`, and the sibling `CreateFeatureFlagRequest.php` whose
    > `unique:core.feature_flags,key` broke `POST /api/staff/feature-flags` the same way — **a third live
    > bug this audit did not report**, proved broken by the same method and fixed here).
    >
    > The audit's separate warning **not** to "fix" `exists:service_categories,id` (no dot → connection
    > `null` → resolved via `search_path`) is **correct and was honoured** — those rules hit a different
    > branch of the same function and are untouched.
    - **Where:** app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php:58-61 (the `->whereNull('brand_id')` at :60); tests/Feature/FeatureFlags/FeatureFlagTestCase.php:65 (the phantom fixture column that masks it)
    - **Affects:** Every staff use of the per-user feature-flag override endpoint. Broken since **2026-05-22** (`8e97b901`, the standalone strip-down) — roughly two months. Worse than a plain 500: the write at :47 has **already committed** before the failing re-read, so the override is silently applied while the staff member sees a server error and has no signal that it worked. Any staff retry double-applies against a cache that was already invalidated.
    - **Effort:** XS (~15 min for the fix; the regression test is the real work — see #TESTFX-1)
    - **What to do:**
        - Delete `->whereNull('brand_id')` at `StaffFeatureFlagOverrideController.php:60`. The remaining `flag_key` + `user_id` predicates already match the unique index `feature_flag_overrides_pro_unique` exactly, so the read stays deterministic — no other change needed to keep it correct.
        - Delete `brand_id TEXT,` at `tests/Feature/FeatureFlags/FeatureFlagTestCase.php:65`. **Do both together.** Fixing only the controller leaves the fixture certifying a schema that doesn't exist; fixing only the fixture turns the masked 500 into a red test.
        - Harden the response path: `FeatureFlagOverrideResource::toArray()` (`:10`) dereferences `$this->id` with no null guard, which is why the masked failure surfaces as `Attempt to read property "id" on null`. Prefer removing the re-read entirely — have `FeatureFlagService::setOverride()` return the upserted row — which eliminates this whole failure class rather than patching one predicate.
        - Add the regression test **in the Postgres lane** (see the Execution policy note). Also worth adding the missing HTTP-level test: current coverage calls the controller method directly with a mocked FormRequest, so the route + middleware + Resource path is never exercised end to end.
    - **Technical:** `core.feature_flag_overrides` has no `brand_id` column and never has under the standalone schema. The baseline migration documents the removal in its own header at `supabase/migrations/20260526000000_baseline_standalone_user.sql:571` — *"EDITS vs the original (20260518010000): brand_id column + the scope_xor constraint + 2 brand indexes dropped"* — and the resulting DDL (`:575-590`) declares exactly nine columns: `id, flag_key, professional_id, enabled, reason, expires_at, created_by, created_at, updated_at`. `professional_id` was later renamed to `user_id` by `20260527030000_rename_professional_to_user.sql:35`. No later migration re-adds `brand_id`; a repo-wide grep confirms the only surviving `brand_id` columns belong to `site.shop_brands` / `site.shop_products`, unrelated tables from `20260704160000`. `FeatureFlagOverride::$fillable` (`app/Models/Core/FeatureFlagOverride.php:19-22`) omits it, as does the sibling `FeatureFlagService`. On Postgres the `->first()` raises `SQLSTATE 42703 — column "brand_id" does not exist`. The route is live and reachable at `routes/api/staff.php:320`, inside the `staff.admin` group (`:184-188`: `supabase.jwt, require.email_verified, staff, require.aal2, staff.admin, throttle:staff, staff.audit`).
    - **Plain English:** There's a staff admin endpoint for switching a feature flag on or off for one specific user. It performs the write correctly, then — purely to build the response it sends back — re-reads the row it just wrote. That re-read filters on a column called `brand_id` that was deleted from the database when the platform dropped its brand/agency concept. The real database doesn't have that column, so it rejects the query and the request dies with a server error *after the change has already been saved*. The override quietly takes effect, but the staff member sees a failure and has no way to tell it actually worked. The reason nobody noticed for two months: the test that covers this endpoint **invented the missing column**. The lightweight practice database the test suite builds for itself has a `brand_id` column the real database never had, so the test passes and CI reports the endpoint as healthy.
    - **Evidence:**
        ```php
        // StaffFeatureFlagOverrideController.php:57-64 — the write at :47 already committed
        // Fetch the upserted row to return in the response.
        $created = FeatureFlagOverride::where('flag_key', $key)
            ->where('user_id', $scope->userId)
            ->whereNull('brand_id')      // ← :60 — column does not exist in Postgres
            ->first();

        $response = (new FeatureFlagOverrideResource($created))->response()->setStatusCode(201);
        ```
        ```php
        // tests/Feature/FeatureFlags/FeatureFlagTestCase.php:61-72 — the fixture invents the column
        $conn->statement('CREATE TABLE IF NOT EXISTS core.feature_flag_overrides (
            id TEXT PRIMARY KEY,
            flag_key TEXT,
            user_id TEXT,
            brand_id TEXT,               // ← :65 — exists ONLY here, never in Postgres
            enabled INTEGER DEFAULT 0,
        ```
        **Proved, not inferred — two experiments run 2026-07-27:**
        1. SQLite's unknown-quoted-identifier behaviour checked directly: `select count(*) from t where "zzz" is null` returns **0** (the double-quoted-string misfeature makes the predicate constant-false rather than an error). So the fixture column is genuinely load-bearing for the pass — without it the query matches nothing.
        2. `brand_id TEXT,` was temporarily removed from `FeatureFlagTestCase.php:65` and `it('store override creates a professional override')` re-run. It fails immediately:
        ```
        FAILED  Tests\Feature\FeatureFlags\StaffFeatureFlagsControllerTest   ErrorException
        Attempt to read property "id" on null
          at vendor/laravel/framework/src/Illuminate/Http/Resources/DelegatesToResource.php:139
          1  app/Http/Resources/FeatureFlagOverrideResource.php:10
          8  app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php:63
        ```
        The fixture was reverted immediately via `git checkout`; the working tree is clean.

---

## P1 — Fix before pilot launch

- [x] **#TESTFX-1** · P1 — Two hand-maintained SQLite stand-ins for the same table have silently diverged, and the one the tests use is the wrong one — while its docblock claims they mirror

    > **⚠ CORRECTION — recorded during execution 2026-07-27.** This finding's prescribed fix named the
    > wrong file. `tests/Feature/Database/DataExportSchemaParityTest.php` does **not** "diff fixture
    > columns against `supabase/migrations/`" — it never reads the migrations dir and never touches a
    > fixture. It reflects over `DataExportPayloadBuilder` against a live Postgres, and is pgsql-gated
    > at `:39` while `phpunit.pg.xml` includes only `tests/Postgres` — **so it has never executed in
    > either CI lane.** Generalising it would have produced a guard that also never runs. (That is the
    > same false-assurance shape as #FFLAG-1: a green signal from machinery that structurally cannot fail.)
    >
    > The real migration-replay machinery is **`MigrationColumnReplay`**, which was declared inside
    > `tests/Feature/Security/DataExportCoverageTest.php:283`. It has been extracted to
    > `tests/Support/SchemaDrift/MigrationColumnReplay.php` (pure move, verified byte-for-byte) because a
    > class declared in a test file may not be loaded in another worker under `--filter`/`--parallel`.
    > New guard: `tests/Feature/Database/FixtureSchemaParityTest.php`, which runs in the **default SQLite
    > lane** and was verified to go red on a phantom column *and* on an allowlisted table it never
    > actually compared.
    >
    > On first run the new guard immediately found five more phantom columns in the canonical
    > `setupUsersTable()` fixture — `bio` (dropped by `20260705120002:22`) and
    > `icon_bucket`/`icon_path`/`headshot_bucket`/`headshot_path` (documented as dropped at
    > `baseline:313-314`, no migration re-adds them). All five removed.
    - **Where:** tests/Feature/FeatureFlags/FeatureFlagTestCase.php:61-72 vs tests/Pest.php:1554-1564 (docblock claim at tests/Pest.php:1537)
    - **Affects:** All feature-flag tests, and the credibility of the fixture-parity guard generally. This is the mechanism that hid #FFLAG-1 for two months, so it will hide the next one too. Any test booting via `FeatureFlagTestCase` validates against a schema Postgres doesn't have; any test booting via `setupFeatureFlagsTable()` validates against the correct one. Same table, two answers, no guard.
    - **Effort:** S (~2–4h)
    - **What to do:**
        - Deduplicate: have `FeatureFlagTestCase::boot()` and `tests/Pest.php`'s `setupFeatureFlagsTable()` share one definition rather than two hand-copied ones. Whichever survives must match the baseline DDL's nine columns exactly.
        - Fix the stale docblock at `tests/Pest.php:1537` (*"Schema mirrors tests/Feature/FeatureFlags/FeatureFlagTestCase"*) — it asserts a parity that does not hold.
        - Extend the existing parity mechanism rather than building new tooling: `tests/Feature/Database/DataExportSchemaParityTest.php` already diffs fixture columns against `supabase/migrations/` for the export/deletion fixtures. Generalise it to cover the feature-flag stand-ins (ideally all hand-written `CREATE TABLE` stubs), so a fixture column with no migration backing fails the build.
        - Add the #FFLAG-1 regression test to the **Postgres lane** (`688aee43`), not the SQLite suite — a fixture-parity guard catches *extra* columns, but only real Postgres catches the `42703` itself. Both layers are needed.
    - **Technical:** `tests/Pest.php:1554-1564` builds `core.feature_flag_overrides` with the nine real columns and no `brand_id`; `FeatureFlagTestCase.php:61-72` builds the same table with ten, including `brand_id TEXT`. `tests/Feature/FeatureFlags/StaffFeatureFlagsControllerTest.php:22-24` boots via `FeatureFlagTestCase::boot()`, i.e. the incorrect one. Because SQLite resolves an unknown double-quoted identifier to a string literal instead of raising, a *missing* fixture column silently returns zero rows and an *extra* fixture column silently satisfies a predicate Postgres would reject — the failure is invisible in both directions. This is the same root cause already recorded for the GDPR export incident, which is why `DataExportSchemaParityTest.php` exists; the guard simply never covered these two stubs.
    - **Plain English:** The test suite builds its own lightweight copy of the database by hand. For this one table, that copy has been written out twice in two different files — and the two versions don't match. One is correct; the other has an extra column that the real database doesn't have. The tests use the wrong one. A comment in the correct file even claims the two are identical, which is no longer true. This mismatch is exactly what hid the bug above for two months, so it will hide the next one unless something automatically checks the practice database against the real schema. A guard like this already exists for the GDPR export tests — it just was never pointed at these files.
    - **Evidence:**
        ```php
        // tests/Pest.php:1535-1564 — CORRECT (no brand_id), but its docblock claims parity that's false
        /*
         * core.feature_flags + core.feature_flag_overrides — needed by any test that
         * ...
         * Schema mirrors tests/Feature/FeatureFlags/FeatureFlagTestCase.   // ← :1537 — no longer true
         */
        $conn->statement('CREATE TABLE IF NOT EXISTS core.feature_flag_overrides (
            id TEXT PRIMARY KEY, flag_key TEXT, user_id TEXT, enabled INTEGER DEFAULT 0,
            reason TEXT, expires_at TEXT, created_by TEXT, created_at TEXT, updated_at TEXT
        )');
        ```

---

## P3 — Nice to have

- [x] **#NOTIF-1** · P3 — `markReadForProfessional()`/`dismissForProfessional()` skip the `staffManage` defence-in-depth check that their sibling `store()` applies

    > **Shipped 2026-07-27.** Premise verified: `NotificationPolicy::staffManage` (`:67`) is already correctly
    > `PartnaStaff`-typed — this was NOT the #FFLAG-2 type-mismatch problem, so the seam just needed wiring.
    > The actor check is placed **before** `assertVisibleTo()` so a non-admin cannot distinguish 404 from 403 and
    > enumerate notification ids. `assertVisibleTo()` itself is byte-identical — it does the row-level job, which is
    > orthogonal to the actor check.
    >
    > **This finding's "XS, 2 lines" estimate was wrong.** Adding the check turns four existing assertions red:
    > `StaffNotificationOnBehalfTest.php` called both methods directly with a bare `Request::create('/', 'POST')`
    > carrying no `partna_staff` attribute (`:137`, `:153`, `:170`, `:181`) — including a 404 test that would have
    > failed for an entirely unrelated reason. All four now use the file's existing admin-request helper. Third
    > appearance of this same anti-pattern in one audit.
    >
    > 11 passed / 23 assertions (from a 9/19 baseline); `--filter=Notification` → 204 passed.
    >
    > ⚠️ **New production bug found while doing this — filed separately, NOT fixed here.** The optional HTTP smoke
    > test could not be written because both routes are broken: `Route::withoutScopedBindings()`
    > (`routes/api/staff.php:254`) does not take effect inside the parent group's `->scopeBindings()` (`:186`) —
    > the live routes report `enforcesScopedBindings=true`, `preventsScopedBindings=false`. Scoped binding resolves
    > `{notification}` via `$professional->notifications()`, which is Laravel's `HasDatabaseNotifications` relation
    > to `Illuminate\Notifications\DatabaseNotification` (table `notifications`) rather than
    > `App\Models\Core\Notifications\Notification` (table `notifications.notifications`). Affects Postgres as well
    > as SQLite. See the note at the foot of `tests/Feature/Staff/StaffNotificationOnBehalfTest.php`.
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:204, :217 (sibling that does it correctly: :39)
    - **Affects:** Nothing today — **zero privilege change**. `NotificationPolicy::staffManage` is `return $actor->isAdmin()` and both routes already sit in the `staff.admin` middleware group, so the policy call would be pure redundancy. It matters only as the defence-in-depth seam the codebase deliberately built: if support staff are ever granted access to the admin route group, these two writes are the ones that won't stop them.
    - **Effort:** XS (~10 min — 2 lines)
    - **What to do:** Add `$this->authorizeForUser($staff, 'staffManage', Notification::class);` to both methods, matching `store()` at `:39` exactly (resolve `$staff` from `$request->attributes->get('partna_staff')` the same way). Leave `assertVisibleTo()` in place — it does a different job (row-level visibility, 404) and is correctly documented.
    - **Technical:** Both methods are already guarded by `assertVisibleTo()` (`:232-237`), which 404s unless the notification is global or targeted at that professional, and the method carries an explicit docblock explaining why the policy can't be used for the *row* check: *"Staff context can't use NotificationPolicy (the policy's actor is the resource owner, not the staff member acting on their behalf). Replicate the ownership check inline: 404 if the notification is neither global nor targeted at this professional."* That reasoning is sound and should stand. What's missing is the separate **actor** check. `NotificationPolicy:60-66` describes `staffManage` as *"admin only, mirroring the `staff.admin` route middleware for **defence-in-depth parity** with the other staff write controllers"* — parity these two writes don't have. Routes: `routes/api/staff.php:255,257`, inside the admin group at `:184-188`.
    - **Plain English:** Two staff actions — marking a user's notification read, and dismissing it — don't re-check that the staff member is an admin. In practice it makes no difference right now, because the route itself already requires an admin to get that far. But the codebase deliberately double-checks these things one layer deeper, on the reasoning that if support staff are ever let into the admin area, the second check is what stops them. These two actions are the ones missing that second check while the near-identical action next to them has it.

- [x] **#SVC-1** · P3 — Staff can attach only one service category per call; professionals can pass a list and have a dedicated re-assignment endpoint

    > **Shipped 2026-07-27.** Premise re-verified before implementing — this finding's own correction of the source
    > doc holds: the staff write path *was* already migrated (pivot `attach`/`sync`, `unset($data['category_id'])`),
    > so there was no dropped-column write and nothing to repair. Only the narrow ergonomics residual was real.
    >
    > `category_ids` added to both staff Requests with rules byte-identical to `StoreServiceRequest.php:22-23`;
    > `store()`/`update()` accept the array form via a `requestedCategoryIds()` helper copied verbatim from
    > `UserServiceController::requestedCategoryIds()` (`:636-644`) so staff and professional precedence rules cannot
    > drift. Ownership is asserted over **every** supplied id before any DB write — the two negative tests place the
    > foreign id **second** in the array specifically so a first-only check would fail them.
    >
    > The optional staff re-assignment route was **deliberately not added** — array support folded into the existing
    > `update()` covers the parity gap without new routes. The unqualified `exists:service_categories,id` rules were
    > left untouched as this finding instructs (no dot → default connection → resolved by `search_path`).
    >
    > 6 new **route-level** tests (real FormRequests through the real routes, not the controller-mocking pattern that
    > hid #FFLAG-1). `--filter=Service` → 569 passed. Independent review: PASS, no defects.
    - **Where:** app/Http/Requests/Api/Staff/UserSite/Services/StaffStoreServiceRequest.php:14, StaffUpdateServiceRequest.php:14 (professional-facing equivalent: app/Http/Requests/Api/User/Services/StoreServiceRequest.php:21-24)
    - **Affects:** Staff service management ergonomics only. **No crash, no dropped-column write, no data loss** — see Technical; the source doc's central claim on this item is factually wrong. Practical impact: staff assisting a user cannot reproduce a multi-category service the user could create themselves, and there is no staff equivalent of the re-assignment endpoint.
    - **Effort:** S (~2–3h)
    - **What to do:**
        - Add `category_ids` to both staff Requests mirroring `StoreServiceRequest.php:22-23` (`['sometimes','nullable','array','max:50']` + `category_ids.*` => `['uuid','distinct']`), keeping the existing `category_id` as the legacy single-value alias exactly as the professional-facing Request does.
        - Extend `StaffServiceManagementController::store()` (`:130` `attach`) and `::update()` (`:181` `sync`) to accept the array form, and run `assertCategoryBelongsToProfessional()` over every supplied id, not just one.
        - Optional: a staff route mirroring the professional re-assignment endpoint (`UpdateServiceCategoryAssignmentRequest`).
        - **Do not** "fix" the unqualified `exists:service_categories,id` rule in these two files — verified correct, see Technical.
    - **Technical:** The source doc claims the staff-facing Requests "were not updated" after `20260721180000_service_multi_category.sql` dropped the column. The column drop is real — `:313` `ALTER TABLE site.services DROP COLUMN IF EXISTS category_id;` (and `:314` drops `category`), after backfilling `site.service_category_assignments` at `:47-50` and rebuilding `site.public_site_payload` first so the drop isn't blocked by the view dependency. But the staff **write path was migrated**: `StaffServiceManagementController::store()` (`:102`) never passes `category_id` to `create()`, attaching via the pivot at `:130` (`$service->categories()->attach($data['category_id'])`); `::update()` (`:161`) carries an explicit comment — *"Multi-category: the legacy single `category_id` REPLACES the membership set (`[]` for null)"* — syncs the pivot at `:181` and calls `unset($data['category_id'])` at `:190` before `fill()`/`save()`. `Service::$fillable` (`app/Models/Core/User/Service.php:52-63`) omits the dropped column too, so even a missed `unset` would be silently discarded rather than throwing. So `category_id` in these Requests is a deliberately retained legacy input alias — the same pattern the professional-facing Request uses. Authorization here is exemplary and contradicts the source doc's other items: `store`/`update`/`show`/`destroy` all call `authorizeForUser($staff, 'staffManage'|'staffView', $professional)` with `#SEC-2` comments. **Separately checked and NOT a bug:** these two Requests hold the only unqualified `exists:` rules in the entire `app/Http/Requests/` tree (`exists:service_categories,id`, no schema prefix) — it resolves because `config/database.php:98` sets `search_path` to `public,core,site,notifications,analytics`. Stylistic outlier, functionally correct.
    - **Plain English:** A recent database change let a service belong to several categories at once instead of just one. The audit says the staff side of this was never updated and is still writing to the deleted column. That isn't right — the staff code *was* updated: it saves categories through the new join table and explicitly strips the old field before saving, with a comment saying exactly that. Nothing crashes and nothing is lost. What's actually left is much smaller: a staff member can only attach one category at a time, while a user can send a whole list, and there's no staff version of the "reassign categories" action. That's a convenience gap, not a bug.

- [ ] **#FFLAG-2** · P3 — Feature-flag staff controllers have no in-controller authorization seam, because `FeatureFlagPolicy` never grew the staff abilities its sibling policies did
    - **Where:** app/Policies/FeatureFlagPolicy.php (whole file); app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php (all 4 methods), StaffFeatureFlagOverrideController.php (all 3 methods). Sibling policies that did grow the seam: app/Policies/NotificationPolicy.php:67,:81 and app/Policies/UserSelfPolicy.php:102,:116
    - **Affects:** Nothing exploitable — both controllers sit behind the `staff.admin` middleware group. Consistency/auditability only. ⚠️ **The source doc's prescribed fix would break both endpoints if applied as written** — read Technical before planning.
    - **Effort:** S (~2–3h) · **touches authorization → plan first and get sign-off regardless of size**
    - **What to do:** Two valid outcomes; pick one deliberately rather than defaulting.
        - **(a) Accept as-is.** `FeatureFlagPolicy` is a documented deliberate deny-all shield and the middleware is the stated gate. Close this as intended design and add a short note to the policy docblock recording that the absent staff seam is a conscious choice, so the next sweep doesn't re-raise it.
        - **(b) Add the seam properly, in this order.** First add `staffManage(PartnaStaff $actor)` and `staffView(PartnaStaff $actor)` to `FeatureFlagPolicy`, mirroring `NotificationPolicy:67,:81` (`return $actor->isAdmin()` / `return true`). **Leave the three existing `User`-typed deny-all methods untouched** — they are the shield, not an oversight. *Then* wire `authorizeForUser($staff, 'staffManage'|'staffView', ...)` into all 7 methods. Also add the missing null-actor `abort_if(..., 401, ...)` guard to `StaffFeatureFlagController`, which unlike its sibling has none.
        - **Never** call the existing `manage`/`view`/`viewAny` abilities from these controllers — that is the source doc's suggestion and it fails, see Technical.
    - **Technical:** `FeatureFlagPolicy` is registered for both models (`app/Providers/AppServiceProvider.php:176-177`) and its docblock is explicit: *"Defensive deny-all policy for FeatureFlag and FeatureFlagOverride. Real auth: the EnsurePartnaStaff middleware on the staff route group (supabase.jwt + staff + staff.admin + throttle:staff). All methods here return false so that a misconfigured non-staff route cannot grant access to a Professional actor via `Gate::forUser($pro)`.* ***PartnaStaff actors bypass this policy entirely — the middleware is the gate.***" All three abilities `return false`, and all three are typed `User $pro` — but the resolved staff actor is a `PartnaStaff`, so `authorizeForUser($staff, 'manage', $flag)` wouldn't merely 403, it would hit a type mismatch. The policies that *did* grow staff seams declare `PartnaStaff`-typed abilities: `NotificationPolicy:67/:81`, `UserSelfPolicy:102/:116`. `FeatureFlagPolicy` declares neither, which is the whole of the real finding. **Correction to a second source-doc claim:** it calls the override controller's `abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated')` *"the exact inline-check pattern the doctrine forbids."* It isn't — CLAUDE.md forbids inline **403 authorization** aborts; these are **401 authentication** guards against a null actor, and they're the defensive shape `StaffFeatureFlagController` is missing entirely. Routes: `routes/api/staff.php:310-323`, all inside the admin group at `:184-188`.
    - **Plain English:** The audit says these two staff controllers should call the standard permission check "like every other staff controller does." They can't, as written. The permission rules for feature flags are a deliberate locked door — every rule returns "no" on purpose, and a comment right at the top explains why: staff are meant to bypass it entirely and the real gate is the route's own security middleware. Calling that permission check would deny the request or fail outright, not authorize it. There *is* a smaller real point underneath: other parts of the codebase later added staff-specific permission rules so every staff action has an explicit, recorded authorization point. Feature flags never got those. That's a consistency gap, not a security hole — and closing it means writing the new rules first, not calling ones that don't exist.

---

## Needs a decision — not a mechanical fix

**#PRIV-D1 — Any staff tier can page and search every subscriber's email + full name; only admins can export the same list.**

> ### ✅ DECIDED 2026-07-27 — option (a): any-staff read is INTENDED. Recorded; do not re-raise.
>
> The asymmetry below (any staff tier may *read* subscriber email + full name at 200 rows/page with free-text
> search; only admins may *export*) is **deliberate and accepted**. Support staff need subscriber visibility to
> answer customer questions without elevated privileges — the same rationale already documented for
> `StaffAccountDeletionController::show()` (see Rejected #1).
>
> **No code change.** Specifically, do **not** "implement" this by adding
> `authorizeForUser($staff, 'staffView', $professional)` to `index()`: `UserSelfPolicy::staffView` (`:116`) returns
> `true` for every staff role, so it would grant exactly what is granted today while creating the *appearance* of a
> control. That is strictly worse than the honest absence — which is why this was never a code unit.
>
> Not adopted: option (c)'s `UserStaffResource`-style PII masking for non-admins. Revisit only if the staff tiers
> themselves are re-scoped, or if a privacy review before pilot launch reaches a different conclusion.
*(This is the substantive part of the source doc's item 3, which buried it under a "missing `authorizeForUser`" framing that is itself a false positive — see the Rejected section.)*

- **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php — `index()` at `:29` vs `export()` at `:86-91`
- **The asymmetry:** `export()` gates on `staffManage`, which is `return $actor->isAdmin()` (`UserSelfPolicy:102`) — **admin only**. `index()` deliberately does not: its docblock at `:27` reads *"**Any-staff.** Same query + paging shape as the brand sees on `/api/email-subscribers`."* Both routes are in the any-staff group (`routes/api/staff.php:137,139`), so the *only* control separating them is that policy call. `index()` returns `email` and `full_name`, is free-text searchable across both (`:58-60`), and runs at an intentionally elevated page size — default **50**, cap **200** (`:43`) — with a comment justifying the elevation over the standard 25/100 because "staff need higher page density."
- **So the effective posture is:** admin to *download* the subscriber list; any staff tier to *read* it 200 rows at a time, with search.
- **Why this isn't a code fix:** adding `authorizeForUser($staff, 'staffView', $professional)` would change nothing — `UserSelfPolicy::staffView` (`:116`) returns `true` for every staff role. Doing it would create the *appearance* of a control while granting exactly what's granted now, which is worse than the honest absence.
- **The call for you:** either (a) confirm any-staff read of subscriber PII is intended and record it, or (b) raise `index()` to `staffManage` for parity with `export()` and accept that support staff lose subscriber visibility, or (c) keep any-staff read but drop/mask `email` for non-admins in the Resource, mirroring how `UserStaffResource` already gates PII behind an admin-only `$showPii` flag (`UserSelfPolicy:107-113` documents that pattern). Option (c) is the one that matches existing house precedent.
- **Related, lower stakes:** the same "adding the call changes nothing" logic applies to `StaffAccountDeletionController::show()` — see Rejected #3-a. If you adopt a rule that *every* staff read carries an explicit ability call purely for auditability (which `UserSelfPolicy:107-113`'s own docblock argues for), both become one-line additions. That's a doctrine decision, not a bug fix, so it isn't ticked here.

---

## Rejected — verified false positives, recorded so the next sweep doesn't rediscover them

No checkboxes: there is nothing to execute. Each was checked against live code and found to be
correct as written.

1. **Source item 3-a — `StaffAccountDeletionController::show()` "missing authorization."** Deliberate and documented. Its docblock at `:89-94`: *"Returns current deletion state + recent audit entries. **Available to all staff (not just admin) so support can answer 'where is my erasure request' questions without elevated privileges.**"* Route is in the any-staff group (`routes/api/staff.php:130`); the siblings `initiate()`/`cancel()` authorize because they are admin-group **writes**. `UserSelfPolicy::staffView` (`:116`) returns `true` for all staff, so adding it is a no-op on access. The method already limits its own exposure — `:105-111` selects non-PII audit columns only, with a comment saying so. Auditability-only residue folded into #PRIV-D1.

2. **Source item 4's central claim — "the staff-facing Requests were not updated" after the multi-category migration.** Factually wrong. The staff write path was migrated: pivot `attach` at `StaffServiceManagementController.php:130`, pivot `sync` at `:181`, `unset($data['category_id'])` at `:190`, plus an explicit multi-category comment. No dropped-column write, no crash. Narrow residual tracked as #SVC-1.

3. **Unqualified `exists:service_categories,id`** in the two staff service Requests (`:14` each) — the only unqualified `exists:` rules in `app/Http/Requests/`. Resolves correctly: `config/database.php:98` sets `search_path` to `public,core,site,notifications,analytics`. Checked precisely because it looked like a latent Postgres bug; it isn't. Do not "fix."

4. **Source item 6 — Charlie's notification categories.** Not a backend bug at all; the source doc used the backend as ground truth for a frontend fix, and those facts **check out exactly**. `config/partna.php:1688-1704` is the single registry (`'mailables'`, and its docblock says so), holding precisely the nine categories claimed: `feature_announcement`, `incident`, `inbox`, `policy_update`, `profile_tasks`, `achievement`, `platform_connection`, `content_scrape`, `analytics_weekly`. `achievement` (`:1700`, in-app only — "milestones / first-enquiry") and `analytics_weekly` (`:1703`) are real; `analytics_milestones` exists nowhere; `subscriptions` is **not** a category — its only appearances in the file are unrelated (`:118` a content-moderation word list containing `'subscription', 'subscriptions'`, and `:1720` `subscription_list_keys`, mailing-list keys). `NotificationPublisher.php:30` derives the valid set from `array_keys()` of this config, so it is genuinely authoritative; `'mandatory_categories'` (`:1715-1717`) holds only `policy_update`. **No backend change.** The frontend fix is: drop `subscriptions`, map `analytics_milestones` → `achievement`.

---

## Suggested Bundled Sessions

- **Bundle 1 — Feature-flag override crash + the fixture that hid it:** #FFLAG-1, #TESTFX-1
    - **Why grouped:** They are causally locked together — fixing the controller alone leaves the fixture certifying a phantom schema, and fixing the fixture alone converts a masked production 500 into a red test. The `brand_id` fixture column must be deleted in the same commit as the controller predicate. #TESTFX-1's parity guard and Postgres-lane regression test are the only real verification #FFLAG-1 can have.
    - **⚠ Blocker gate:** contains a **P0** → plan first, present blast radius, wait for explicit sign-off before implementing.
    - **Verification:** `composer test` is **not** sufficient — the regression test must run in the `postgres-tests` lane. A green SQLite suite is precisely the signal that failed here for two months.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Staff service multi-category parity:** #SVC-1
    - **Why grouped:** Single, self-contained concern across two Requests and their one controller. No dependency on the other findings, no auth surface, no schema change. Combine plan+impl (S).
    - **Model:** Plan+Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#FFLAG-2 — Feature-flag policy staff seam** · reason: touches **authorization** doctrine, and the decision is *whether to act at all* (option (a) accept vs (b) add the seam) rather than how. Needs its own plan and sign-off. Critically, it must **not** be bundled with #FFLAG-1 despite touching the same two controllers — #FFLAG-1 is a P0 production fix that should ship without waiting on a doctrine debate, and mixing them risks an implementer applying the source doc's broken prescription (calling the deny-all `manage` ability) while "in the area."

- **#NOTIF-1 — Notification staff-write defence-in-depth** · reason: touches **authorization**. Two lines, but the blocker gate applies regardless of size, and the reviewer must confirm the existing `assertVisibleTo()` row-check was left intact — it does a different job from the actor check being added, and collapsing the two would be a real regression.

- **#PRIV-D1 — Subscriber PII read tier** · reason: **not a code unit.** A product/privacy decision on who may read subscriber emails. Do not let an implementer "fix" it by adding a `staffView` call that grants exactly what's already granted. Resolve the decision first; only then does it become a code unit (and option (c) would land in `UserStaffResource`-style PII gating, not in the controller).

---

## Appendix — deliberately not examined

Per the backend-only scope, the source doc's other two bug lists were **not** verified, and nothing
above should be read as covering them:

- **`Partna-Frontend`** — 6 items at source-doc lines 320-375: stale font-tool schema, Charlie's
  notification categories, `business_update_info` HTTP verb, wrong pricing-tier comparisons, 6 stale
  docs pages, stale settings copy.
  Two of these are partly answerable from this repo if you want them settled before touching
  frontend code: **Rejected #4 above supplies the verified backend ground truth** their
  notification-category fix needs, and their item 3's `POST` vs `PUT` question against
  `/site/workplace` is decidable from this repo's route table.
- **`partna-monorepo`** — 4 items at source-doc lines 819-825: `typography.weight` missing from the
  Zod schema (`packages/design-system/src/design-kit/validate.ts:103-112`, which the source doc also
  lists as backend item 5 — there is **no backend work in it**), the phantom `glassShineDuration`
  docstring, the over-permissive `responsiveSpaceSchema`, and the `check-no-framework.sh` regex gap.
