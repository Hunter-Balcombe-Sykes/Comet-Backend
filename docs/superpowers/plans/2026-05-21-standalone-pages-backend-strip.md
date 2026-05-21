# Standalone Pages Backend Strip — Implementation Plan (v7)

> **v7 — ledger-driven; inline file lists removed.** Every file reference in this
> plan is now a pointer into `audits/standalone-pages/STRIP-LEDGER.md`. The ledger
> is the authoritative file list; this plan is the task graph — ordering, gates,
> and safety. v1–v6 carried inline "known-gap" lists because no ledger existed;
> they are deleted here. Where v6 enumerated files, v7 says "per `STRIP-LEDGER.md`
> Section N".

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Strip the Partna backend and database down to the minimum needed to launch standalone **user** site pages (signup → dashboard sitepage editor → public `<handle>.partna.au` page), removing everything brand/affiliate/partner/Shopify/Square/Fresha/Stripe/commerce/orders/payouts/integration-booking — without losing any security, caching, or scalability hardening on the user/site/public path.

**Architecture:** Strict reference order — **sever every reference from surviving code first (Task 2), then delete the orphaned files (Task 3).** The classifications are settled: `STRIP-LEDGER.md` was built by mechanical `git ls-files` enumeration, adversarially reviewed (`LEDGER-REVIEW-RESULT.md`, verdict *ready with listed corrections*), and all corrections folded in 2026-05-22. This plan does not re-derive any DELETE/EDIT/KEEP/DB call — it sequences them safely behind per-task verification gates. The DB re-baseline (Task 7) is viable (pre-beta, no customers) but is not a clean drop; Task 7 enumerates every cross-cut item via ledger Section 5.

**Tech Stack:** PHP 8.2 / Laravel 12, Supabase Postgres, Redis/Horizon, Pest 4 on `:memory:` SQLite, Supabase CLI, Cloudflare Workers.

---

## Ground rules

- **The ledger is the file list. This plan is not.** Every "which files" question is answered by `STRIP-LEDGER.md`. If a step and the ledger disagree, the ledger wins — STOP and re-plan.
- **Reference order is law.** Never delete a file while any *surviving* file references it. Task 2 severs; Task 3 deletes. Within Task 2, the boot-path severs (Steps 1–6) complete and pass a gate **before any Task 3 deletion**.
- **Three coupling channels, not one.** Chase all three: (a) PHP `use`/DI/static calls; (b) soft links — `site.settings` JSONB keys (`brand_partner`, `additional_brand_partners`), Horizon queue-name declarations, config-key lookups; (c) DB foreign keys, triggers, RLS policies, GRANTs. The ledger's sever specs (Section 2) and DB section (Section 5) already account for all three.
- **PRESERVE the hardening.** `STRIP-LEDGER.md` Section 1 (PRESERVE manifest) is binding. If a step seems to require gutting protective logic (SWR/single-flight cache core, MFA/AAL2, notification idempotency, GDPR deletion/export, the inline-403 CI guard), STOP and re-plan.
- **Verification gate after every task:** `composer test` + `php artisan route:list` + `php artisan about`, **and** for Tasks 2–5 a real request-path smoke (signup, a section edit, a public profile fetch, an image upload) — because the worst bugs here (silent 403 from a `false` config lookup, default-deny RLS, missing GRANT) pass boot and the SQLite test suite.
- **Migrations are append-only until Task 7.** No `supabase/migrations/` edits before Task 7. Commit per task / sub-task.
- **Never run `php artisan route:cache` during the strip** — it eagerly resolves a not-yet-cleaned middleware alias or controller class-string and will fail mid-strip.

---

## Task 0: Safety net and working branch

*(Identical to v6 — unchanged.)*

- [ ] **Step 1:** `git fetch && git pull && composer test` — confirm the green baseline.
- [ ] **Step 2:** `git tag brand-capable-2026-05-21 && git push origin brand-capable-2026-05-21`
- [ ] **Step 3:** `git branch archive/brand-capable-2026-05-21 development && git push origin archive/brand-capable-2026-05-21`
- [ ] **Step 4:** `git checkout -b strip/standalone-user-only development`

---

## Task 1: Ledger gate (HARD GATE — already satisfied)

In v1–v6 this task built the ledger. **That work is done.** `audits/standalone-pages/STRIP-LEDGER.md` exists, was built mechanically (`git ls-files` per subtree, complete by construction — see `LEDGER-BUILD-PROMPT.md`), was adversarially reviewed (`LEDGER-REVIEW-RESULT.md`, verdict *ready with listed corrections*), and every correction was folded in 2026-05-22 (markers read `(review correction 2026-05-22)`). It is the authoritative artifact. Section 0's 18 product decisions are all resolved with Josh; the propagation overrides are applied.

- [ ] **Step 1:** Confirm `audits/standalone-pages/STRIP-LEDGER.md` is present and that Section 0's resolution table shows all 18 decisions resolved. If so, **proceed to Task 2.** There is no ledger-review treadmill — the ledger is settled.

---

## Task 2: Sever all references from surviving code

**Goal:** after this task no *surviving* file references any brand-only class, config key, queue, or DB object. Steps 1–6 are the **boot path** and must pass the boot-path gate (Step 6) before Task 3 begins. Steps 7–16 are the remaining severs.

**Intermediate gates:** run `composer test` after Step 7 (the wide `SiteCacheService` edit), after Step 8 (the GDPR path), and after Step 11 (controllers) — so a regression surfaces early rather than at the Step 17 gate.

### Phase A — boot path (ledger-fatal if a DELETE'd class is referenced at parse time)

- [ ] **Step 1: `bootstrap/app.php`.** Apply the ledger Section 2.1 sever spec for `bootstrap/app.php`. This file is parsed on every request and every artisan command — deleting a referenced class before this edit bricks `php artisan`. **KEEP** line 100 `'require.aal2'` and the `VerifySupabaseJwt` pin (l.67–70). *(Ledger Section 6 #1, #8.)*

- [ ] **Step 2: `app/Providers/AppServiceProvider.php`.** Apply the ledger Section 2.1 sever spec for `AppServiceProvider`. Note the review correction: the `WalletCurrencySwitchAudit` `Gate::policy` reg (l.100) and the `GdprRequest` `Gate::policy` reg (l.108) **must both be removed** — v6 missed them. **KEEP** the `DataExportAudit` reg (l.109) — `GdprPolicy` survives (decision 12 / Section 6 #10). KEEP the JWKS/JWT/Nightwatch guards and the 11 kept rate limiters. *(Ledger Section 6 #8.)*

- [ ] **Step 3: `app/Providers/EventServiceProvider.php`.** Apply the ledger Section 2.1 sever spec for `EventServiceProvider`. Heed the review correction: the listener-import range is **not** l.26–34 — `BlockObserver` (l.28) and `CustomerObserver` (l.34) are survivors; do not remove them. KEEP Professional/Site/Block/SiteMedia/Service/ServiceCategory/Customer `observe()` regs.

- [ ] **Step 4: The four route files.** For `routes/api.php`, `routes/api/professional.php`, `routes/api/publicSite.php`, `routes/api/staff.php`, apply the ledger Section 2.7 routes spec: remove every `use`-import and `Route::` binding for any controller classified DELETE in `STRIP-LEDGER.md` Section 3 (groups 3a–3g). A DELETE'd controller left referenced fatals the route file at parse. **KEEP** `EnvCheckController`, `SupabaseEmailHookController`, the MFA-verification webhook routes, and the staff group-level `require.aal2`. *(Ledger Section 6 #8.)*

- [ ] **Step 5: `routes/console.php`.** Apply the ledger Section 2.7 `routes/console.php` spec: remove every `Schedule::job()`/`Schedule::command()` entry for a DELETE'd class, and delete the inline `partna:normalize-professional-types` closure (l.13–61 — it reads the dropped `professional_type` column). Leaving any of these bricks `schedule:run`. *(Ledger Section 6 #8.)*

- [ ] **Step 6: BOOT-PATH GATE.** Run `php artisan about` and `php artisan route:list`. Both must succeed — this proves the boot path is coherent. Do **not** run `composer test` yet (surviving services/controllers still reference brand code; Steps 7–16 clear those). Do **not** run `php artisan route:cache`. Commit: `git commit -m "strip: sever boot path (bootstrap, providers, routes, schedule)"`. **Task 3 may not begin until this gate is green.**

### Phase B — remaining severs

- [ ] **Step 7: Shared services.** Apply the ledger Section 2.4 sever specs (all rows except `AccountDeletionService` and `DataExportPayloadBuilder` — those are Step 8). The `SiteCacheService` row is the widest edit: remove the brand methods **and every call-site** named in the ledger, but **leave the SWR / single-flight / `rememberLocked` / stale-key core untouched** (Section 1 PRESERVE). For `AccountCapabilitySet`, retain every property as a user-constant value — dynamic readers access them by name and would fatal otherwise. **Run `composer test`.**

- [ ] **Step 8: GDPR path (legal-obligation critical).** Apply the ledger Section 2.4 sever specs for `Services/Professional/AccountDeletionService` and `Services/Professional/DataExport/DataExportPayloadBuilder`. These carry **unguarded** raw queries on tables Task 7 drops (`commerce.commission_payouts` in `checkObligations()`, the `brand.brand_profiles` write in `pseudonymiseAccountPii()`, the commission/booking/billing streams) — left in place they 500 every account deletion and every data export, breaking the GDPR erasure/portability right. This is correctness-critical, not cosmetic. After editing, verify deletion **and** export end-to-end. **Run `composer test`.** *(Ledger Section 6 #7.)*

- [ ] **Step 9: Shared jobs.** Apply the ledger Section 2.5 sever specs. Heed Section 6 #6: `SyncSubdomainToKvJob`, `CloudflareCachePurgeJob`, and `RetireSubdomainFromKvJob` must have `onQueue('integrations')` reassigned to `'default'` (the `integrations` supervisor is removed in Step 14). After editing, `rg "onQueue\('integrations'\)" app/Jobs/` over surviving jobs must return zero.

- [ ] **Step 10: Shared observers, models, policies.** Apply the ledger Section 2.6 sever specs. For the `Professional` model: **KEEP** `account_type` in `$fillable` and keep its `AccountType` cast (decision 10); remove `professional_type`, all `stripe_*`, `payout_method`, the `has_historical_partner_links` cast, the brand predicates, and every brand relationship method named in the ledger. `IntegrationPolicy` and `ProfessionalIntegrationObserver` are **DELETE** (decision 4, group 3g) — not edits.

- [ ] **Step 11: Shared controllers.** Apply the ledger Section 2.2 sever specs (19 controllers). Note `ProfessionalAnalyticsController` is **EDIT, not KEEP** — sever `commerceAggregates()`/`commerceCharts()`/`topProducts()`/`shopSummary()`/`checkoutSessions()` and the `GET /analytics/shop` route, keep the site-visit/link-click path (Section 6 #2). `BootstrapController` collapses to individual-only signup. `IndividualProfileController` needs **no change** beyond confirmation — the `AccountType::Individual` case survives the stub (Task 4). **Run `composer test`.**

- [ ] **Step 12: Middleware, requests, resources.** Apply the ledger Section 2.3 sever specs (15 files). These include the v6-misclassified-KEEP corrections — `FeatureFlagOverrideResource` etc. are now EDIT (Section 6 #11). KEEP `account_type` on all resources; KEEP the `booking_mode`/`manual_booking_url` validation (decision 3).

- [ ] **Step 13: Console command edits.** Apply the ledger Section 2.7 spec for `Console/Commands/PurgeSoftDeleted` (remove `BrandPartnerLink` from `PURGE_EXEMPT` + its import). `BackfillIndividualKvEntries` is renamed in Task 4, not here.

- [ ] **Step 14: Config edits.** Apply the ledger Section 2.7 specs for `config/partna.php`, `config/horizon.php`, `config/services.php`, `config/sidest.php`, `config/auth.php`. These are Task-2 edits because deleting a mailable class before its `notifications.mailables` config key is removed throws `Class not found` on dispatch (boot-unsafe). **Order:** do the `config/horizon.php` supervisor removal *after* Step 9 reassigned the jobs. After the horizon edit, confirm a surviving supervisor serves the `default` queue (the 3 Cloudflare jobs now depend on it) — `php artisan horizon:status` / inspect `config/horizon.php`. *(Ledger Section 6 #6.)*

- [ ] **Step 15: Test scaffolding & shared tests.** Apply the ledger Section 2.9 sever specs. In `tests/Pest.php`: remove the brand/affiliate tenant helpers and the brand `setup*Table()` helpers; strip Stripe columns from the professionals DDL. **KEEP** the `account_type` column in `setupProfessionalsTable()` (decision 10), and **keep** `professional_type` in the DDL for now — it is dropped atomically with the `professionals`→`users` rename in Task 5. Apply the 7 test reclassifications listed in the Section 2.9 review-correction block. **Run `composer test`.**

- [ ] **Step 16: Factories, emails, worker text.** Apply the ledger Section 2.8 specs. Note `cloudflare-worker/src/index.js` here is **comment/description text only** — its functional change (delete the `affiliate` branch, add the `{type:"alias"}` 301 branch) is Task 3h.

- [ ] **Step 17: Verify and commit.** `composer test` + `php artisan about` + `php artisan route:list` + request-path smoke (signup, section edit, public profile fetch, image upload). `git commit -m "strip: sever all brand/commerce references from shared code"`.

---

## Task 3: Bulk-delete the orphaned brand-only files

Order-insensitive (the boot path is already severed). Delete per domain per `STRIP-LEDGER.md` Section 3 — **no inline file lists; the ledger group is the list.** Commit per domain. **Each sub-task also trims the deleted models from `PolicyCoverageTest`'s sweep + `POLICY_EXEMPT`** (the 8 entries are named in ledger Section 2.9) — do not defer this; the test breaks the moment a `POLICY_EXEMPT`-listed model is deleted.

> **GdprPolicy is KEEP.** Decision 12 deletes only the `GdprRequest` model; `GdprPolicy` still serves `DataExportAudit` and stays in the Section 1 PRESERVE manifest. The `GdprRequest` `Gate::policy` reg was already severed in Task 2 Step 2. Do not delete `GdprPolicy`. *(Ledger Section 6 #10.)*

- [ ] **3a — Shopify:** Delete per `STRIP-LEDGER.md` Section 3 group 3a. Verify (`php artisan about` + `route:list` + `composer test`), commit.
- [ ] **3b — Square:** Delete per ledger Section 3 group 3b. Verify, commit.
- [ ] **3c — Fresha / integration-booking:** Delete per ledger Section 3 group 3c. (The manual booking-URL link survives — decision 3 — only Square/Fresha smart-booking is removed.) Verify, commit.
- [ ] **3d — Stripe / Billing:** Delete per ledger Section 3 group 3d. Confirm no surviving route uses a plan gate. Verify, commit.
- [ ] **3e — Commerce / orders / payouts / exports:** Delete per ledger Section 3 group 3e. Verify, commit.
- [ ] **3f — Affiliate / partner / invites:** Delete per ledger Section 3 group 3f. Verify, commit.
- [ ] **3g — Brand dashboard / brand notifications / integrations:** Delete per ledger Section 3 group 3g. Note `NudgeStuckOnboardingJob` is **DELETE** here, not an edit — its query joins `brand.brand_profiles` (Section 6 #3). `StaffBrandDesignController` is DELETE; `StaffGoogleBusinessProfileController` is KEEP (decision 15) — confirm GBP is not `isBrand()`-gated before signing off. `ProfessionalIntegration` + `IntegrationPolicy` + `ProfessionalIntegrationObserver` are DELETE here (decision 4). Verify, commit.
- [ ] **3-account-type — Account-type machinery:** Delete per ledger Section 3 group "3-account-type": `AccountTypeTransitionEvent`, the **5** `Listeners/Accounts/*` listeners (count confirmed — Section 6 #4), `AccountTypeTransitionService`, `AccountTypeDefaultsService`, `InvalidAccountTypeTransition`, and the `NormalizesProfessionalType` trait (now orphaned — Section 6 #12). **Do NOT delete `Enums/AccountType`** — it is reduced to a stub in Task 4. Verify, commit.
- [ ] **3h — Cloudflare worker:** Apply the ledger Section 2.8 functional spec for `cloudflare-worker/src/index.js`: delete the `affiliate` redirect branch, remove the stale `// type === "brand"` comment, and **add the missing `{type:"alias"}` 301 branch** before the brand fallthrough (alias KV entries currently fall through to origin instead of 301-ing — hard functional requirement). KV-miss 404 + `individual` branch KEEP. The worker has no test suite — verify manually against a local `wrangler dev` for user, alias, and miss cases. Commit.
- [ ] **Tests (DELETE):** Delete the brand/commerce test directories and scattered files per ledger Section 3 "Tests (DELETE)". Verify (`composer test`), commit.

---

## Task 4: Reduce the account-type system to a user-only stub

The transition machinery, listeners, and `NormalizesProfessionalType` trait are already deleted (Task 3 group 3-account-type); the `Professional` model brand relationships are already severed (Task 2 Step 10). This task finishes the collapse.

- [ ] **Step 1: Stub the `AccountType` enum.** Apply the ledger Section 3 "3-account-type" `Enums/AccountType` correction: **reduce the enum to the `Individual` case only** (drop the `Brand` and `Partner` cases; keep the file). It is NOT deleted — `Professional` casts the kept `account_type` column to it (decision 10) and resources emit it; deleting it fatals the cast. By this point all `AccountType::Brand`/`Partner` readers are severed (Task 2) or deleted (Task 3), so the surviving readers (`AccountCapabilities`, `IndividualProfileController`, `BootstrapController`, the model cast) resolve cleanly. *(Ledger Section 6 #9.)*
- [ ] **Step 2: Rename the KV backfill command.** Per ledger Section 2.7: rename `Console/Commands/BackfillIndividualKvEntries` → `BackfillUserKvEntries`, sever its `BrandPartnerLink` import + affiliate-cohort query, and collapse the account-type dual-read to the user shape.
- [ ] **Step 3: Verify and commit.** `composer test` + boot + request smoke. `git commit -m "strip: collapse account-type system to user-only stub"`.

---

## Task 5: Rename Professional → User (atomic)

> **Tasks 5 + 7 are one unit** — after Step 3 the model expects table `users` but the DB still has `professionals`. Do not deploy or point at a live DB between them.

> **This rename is ATOMIC** (ledger Section 2.9 sequencing note): the model, the factory, the `tests/Pest.php` DDL (both `account_type` DDL blocks), and **every** tenant helper / `INSERT INTO professionals` caller are renamed in **one pass**. Do not split it — a half-renamed `tests/Pest.php` leaves the suite unrunnable.

- [ ] **Step 1:** `rg -l -w "Professional" app/ routes/ config/ tests/ database/ | sort` — record the count for the Step 7 zero-check.
- [ ] **Step 2:** Delete `database/factories/UserFactory.php` (a Laravel default-auth stub, zero column overlap) and replace it with the renamed `database/factories/ProfessionalFactory.php` content. Do not "fold".
- [ ] **Step 3:** Rename `app/Models/Core/Professional/Professional.php` → `User.php`, class `Professional`→`User`, set `protected $table = 'users'`.
- [ ] **Step 3b: FK-column decision — do NOT rename columns.** Keep every `professional_id` FK column named `professional_id` (~552 columns). Each Eloquent relationship must declare the column explicitly: `belongsTo(User::class, 'professional_id')`, `hasMany(..., 'professional_id')`. A blind `professional`→`user` rename would imply a wrong `user_id` column. Renaming 552 columns is out of scope and high-risk; the column name is internal.
- [ ] **Step 4: Atomic mechanical rename.** In one pass, rename `Professional`→`User`, `professional`→`user`, `professionals`→`users` across identifiers, class names, route names, resources, factories, variables, `app/Policies/*` (`BasePolicy` type-hints `Professional`), **and** `tests/Pest.php` — both `account_type` DDL blocks, every `createTenant`/`createBrandTenant`/`createAffiliateTenant`-style caller, and every `INSERT INTO professionals`. Drop `professional_type` from the DDL in this same pass (it was kept until now per Task 2 Step 15). Do NOT rename `professional_id` columns (Step 3b). Review each hunk.
- [ ] **Step 5:** Update `config/auth.php` — `App\Models\User::class` now resolves to a real model; point the auth provider correctly or comment it out (the app authenticates via Supabase JWT — confirm intent). Update any `require` path if `professional.php` route file is renamed.
- [ ] **Step 6:** Update `Gate::policy()` / `observe()` regs in both providers to the renamed class.
- [ ] **Step 7:** `rg -w "Professional" app/ routes/ config/ tests/` → zero. `composer dump-autoload -o && composer test && php artisan about`. Commit.

---

## Task 6: Strip dead dependencies and CI

Config files were already edited in Task 2 Step 14 (boot-safety). This task removes package dependencies and CI guards that are now vacuous.

- [ ] **Step 1:** Remove `stripe/stripe-php` (and any Shopify/Square SDK) from `composer.json`; `composer update --lock`.
- [ ] **Step 2:** From `.github/workflows/ci.yml`: remove brand test jobs and **remove the `CAPABILITY_PATTERN` guard** — once `BrandAccessService` is gone it passes vacuously and protects nothing. Keep `guard:no-laravel-migrations`, `guard:no-unsafe-migrations`, `guard:no-cache-memo`, the inline-403 guard, `PolicyCoverageTest`.
- [ ] **Step 3:** Cross-check `rg "config\('sidest\.|config\('partna\.|config\('services\."` for dangling reads of removed config keys → zero.
- [ ] **Step 4:** `composer test`, commit.

---

## Task 7: Database re-baseline

The cut is *not* clean. Build the single baseline migration from `STRIP-LEDGER.md` Section 5 — every cross-cut item is enumerated there. The steps below name the items the v6 plan got wrong (ledger Section 6 #5, #14).

- [ ] **Step 1: Target tables.** Build the KEEP table set per ledger Section 5.1. Apply every EDIT in 5.1: drop `professional_type` / all `stripe_*` / `payout_method` from `core.professionals` (KEEP the `account_type` column — decision 10); drop `brand_id` + the `scope_xor` constraint from `core.feature_flag_overrides`; drop the `square_*`/`fresha_*` columns from `site.services`. Apply the 5.1 review corrections: `core.professionals.qr_slug` was already dropped (`20260508600000`) — no action; `core.feature_flag_overrides.created_by` FK must target `core.partna_staff` per `20260519010001`, **not** the v2-baseline `core.professionals` target. *(Ledger Section 6 #14.)*
- [ ] **Step 2: RLS — port ALL three sources, fix the stale staff name.** Per ledger Section 5.2: RLS on KEEP tables spans **three** migrations — `20260403000000_v2_baseline.sql`, **`20260420200000_add_rls_to_remaining_tables.sql`** (an RLS source v6 omitted — Section 6 #5), and `20260525000000_rls_policy_sweep.sql`. ~32 policies reference the stale staff table name `core.comet_staff` / `core.sidest_staff` — rewrite every one to `core.partna_staff`. The rename is **not** policy-bodies-only: `core.data_export_audit` (KEEP) has an FK `triggered_by_staff_id REFERENCES core.sidest_staff(id)` that must also be rewritten to `core.partna_staff`, else the baseline fails on FK resolution. *(Ledger Section 6 #5, #14.)*
- [ ] **Step 3: GRANTs.** Port every late `app_backend` GRANT on KEEP tables per ledger Section 5.3 — including the two v6 missed: `GRANT SELECT, INSERT ON core.staff_audit_log` (`20260517300000`) and the `billing.webhook_events` GRANT (`20260407000000`) — both KEEP tables; omitting either leaves `app_backend` unable to write them. Preserve the intentional `REVOKE UPDATE,DELETE` on the append-only tables. Skip GRANTs on dropped tables. *(Ledger Section 6 #14.)*
- [ ] **Step 4: Triggers / functions — rewrite, don't copy.** Per ledger Section 5.4: rewrite `core.trg_professional_handle_change()` (drop the `trg_recompute_affiliate_path` call); rewrite `site.trg_recompute_partna_url()` to derive the URL from `site.sites` only; carry the **fixed** (`core.partna_staff`) body of `core.prevent_staff_escalation()` from `20260524000000` — it fires on every staff UPDATE, a stale body gives `42P01`; `DROP` the `site.trg_recompute_affiliate_path()` function, the `core.professionals_account_type_dual_write` trigger, and `core.validate_brand_team_membership()`. Carry forward all timestamp / append-only / `enforce_site_gallery_max6` triggers on KEEP tables. *(Ledger Section 6 #5, #14.)*
- [ ] **Step 5: FK drop order & views.** Per ledger Section 5.7: drop `core.feature_flag_overrides.brand_id` before dropping `brand.brand_profiles`; drop the `ON DELETE RESTRICT` commerce tables before any professional purge; follow the full 32-step DROP ordering (brand → schemas last). Per ledger Section 5.5: rebuild `site.all_site_data` **dropping `professional_type` from the SELECT list** (keep `account_type`) — a verbatim copy fails `db push`; `site.public_site_payload` ports as-is. *(Ledger Section 6 #5.)*
- [ ] **Step 6: BYPASSRLS decision.** Per ledger Section 5.6 / decision 16: keep `ALTER ROLE app_backend BYPASSRLS` in the baseline. Revoking it would require an explicit `app_backend` policy on every KEEP RLS table and several have none — omitting both leaves feature-flag reads default-deny. *(Ledger Section 6 #5.)*
- [ ] **Step 7: Write the single baseline.** Create `supabase/migrations/<ts>_baseline_standalone_user.sql` — schemas, KEEP tables, the full ported RLS + GRANTs, the rewritten triggers, the `app_backend` `NOLOGIN` role, the `all_site_data` / `public_site_payload` views. Confirm it passes `guard:no-unsafe-migrations` / `guard:no-cache-memo`.
- [ ] **Step 8: Archive old migrations:**
```bash
mkdir -p supabase/migrations-archive
git mv supabase/migrations/*.sql supabase/migrations-archive/
git mv supabase/migrations-archive/<ts>_baseline_standalone_user.sql supabase/migrations/
```
- [ ] **Step 9:** Provision a fresh dev Supabase project; `supabase link` → `db push --dry-run` → `db push`; run `ALTER ROLE app_backend WITH LOGIN PASSWORD …`. Provision a fresh `SUBDOMAIN_KV` namespace and run `BackfillUserKvEntries`.
- [ ] **Step 10:** Update the `CLAUDE.md` Environments table + `.env.example`. `composer test` (passes on SQLite — it does NOT exercise the new schema). Then run a **real request smoke against the new DB**: a `POST /bootstrap` (catches missing `INSERT` GRANTs — a read-only fetch will not), a public-profile fetch (catches default-deny RLS), a section edit, a handle rename. Commit.

---

## Task 8: Dead-code sweep, docs, and final verification

- [ ] **Step 1:** `composer dump-autoload -o`, delete empty dirs, `php artisan pint`. `rg "use App\\\\.*(Brand|Commerce|Shopify|Stripe|Square|Fresha|Billing)"` → zero. `rg -w "professional_type"` → zero. `rg -w "Professional"` (the class) → zero.
- [ ] **Step 2:** Update `AI_CONTEXT.md`, `docs/api.md`, `CLAUDE.md`; delete brand/commerce-only docs.
- [ ] **Step 3:** `composer dev`; walk the full journey — signup → user + site → dashboard edits (section, link, service, image upload, document upload, theme) → public `<handle>.partna.au` renders with cache/ETag/secure headers → analytics beacon → enquiry submit + notification → KV-sync + cache-purge on edit → handle rename + 301.
- [ ] **Step 4:** `composer test && php artisan route:list && php artisan about`. Commit, open PR `strip/standalone-user-only` → `development`.
- [ ] **Step 5:** Rewrite `audits/standalone-pages/AUDIT-SCOPE.md` to "the whole backend"; run `scripts/audit/audit.sh`.

---

## Cross-team callout (not a backend task)

The frontend repo couples to account types in **four** places, all broken by this strip — coordinate the merge:
1. Signup posts `professional_type` — removed from `BootstrapRequest` (Task 2 Step 11).
2–3. `ProfessionalResource`/`ProfessionalDashboardResource` stop emitting `professional_type` and `stripe_connect_status` (Task 2 Step 12). **`account_type` is still emitted** — `lib/account-capabilities.ts` routes off it, and it survives as the constant `individual`.
4. `professional_type` likewise removed from all responses.
Route-name renames are NOT a break (the frontend calls URLs, not named routes — verified).

---

## Self-review notes (v7)

- **v7 is ledger-driven.** Every v6 inline file enumeration is removed; the plan now points exclusively into `STRIP-LEDGER.md`. The architecture (sever-then-delete, reference-order law, three coupling channels, per-task verification gate) is unchanged from v6 — those were right.
- **Section 6 corrections, each a concrete step:** #1 → Task 2 Step 1; #2 → Task 2 Step 11; #3 → Task 3 group 3g; #4 → Task 3 group 3-account-type (5-listener count); #5 + #14 → Task 7 Steps 1–6; #6 → Task 2 Steps 9 & 14; #7 → Task 2 Step 8; #8 → Task 2 Steps 1–5; #9 → Task 4 Step 1; #10 → Task 3 header note; #11 → Task 2 Steps 11–12 (ledger 2.2/2.3 carry the reclassifications); #12 → Task 3 group 3-account-type; #13 → resolved internally by the ledger (Sections 2–5 now match Section 0).
- **Boot path ordered first.** Task 2 Steps 1–6 sever `bootstrap/app.php`, both providers, the four route files, and `routes/console.php`, then gate on `php artisan about` + `route:list`. Task 3 cannot start until that gate is green — a DELETE'd class referenced at parse time bricks `php artisan`.
- **`AccountType` is a stub, not a deletion.** Task 4 Step 1 reduces it to the `Individual` case; Task 3 explicitly excludes it from the 3-account-type deletion group.
- **Task 5 is atomic.** The `Professional`→`User` / `professionals`→`users` rename — model, factory, both `tests/Pest.php` DDL blocks, every tenant helper — happens in one pass (Task 5 Step 4); FK columns stay `professional_id` (Step 3b).
- **Risk concentration:** Tasks 2, 5, 7. Reversibility: tag `brand-capable-2026-05-21` + `archive/brand-capable-2026-05-21` + `supabase/migrations-archive/`.
- **Stopping rule:** the ledger is settled and reviewed; this is the v7 that gets executed behind the per-task gates. No further plan-review pass.
