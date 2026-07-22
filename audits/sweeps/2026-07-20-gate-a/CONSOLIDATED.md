# Gate A — pre-pilot audit set (CONSOLIDATED)

Merged from **23 audit runs** executed sequentially 2026-07-19 → 2026-07-20 per
`scripts/audit/campaigns.md` § *Running Gate A end to end*. Each finding keeps a pointer to
its source file under `sources/`, which holds the full **Where / Technical / Plain English /
Evidence** for that finding. This file is the orchestration layer: tiers, effort, bundles,
and cross-run root causes.

**Scope** — ranks 1–7 of the Gate A table: Security T1 (8 runs) · Concurrency T1 (3) ·
Security T2a (3) · Lifecycle T1 (2) · Cutover T1 (3) · Cutover T2 (2) · Data & privacy T1 (2).
Lenses: `security`, `schema-rls`, `configuration-hygiene`, `edge-worker`, `privacy-compliance`,
`caching-gold-standard`, `webhook-idempotency`, `transaction-boundaries`,
`lifecycle-correctness`, `migration-safety`, `test-prod-parity`.

## Findings at a glance

| Tier | Count |
|---|---|
| P0 — blocker | 2 |
| P1 — high | 13 |
| P2 — medium | 62 |
| P3 — low | 51 |
| **Total** | **128** |

Two runs (`parity-models`, `parity-services`) returned **zero findings**; both were verified
as genuine — payloads of 226 KB and 315 KB reached the scanner, so the adjudicator rejected
every draft rather than the scan reading nothing.

## Execution policy

- **Plan:** Opus · **Implement:** Sonnet · **Review:** a *separate* Sonnet instance
- **Combine plan+impl:** YES for S/XS effort · NO for P0/P1 or L/XL (plan first, then implement)
- **Blocker gate — present the plan and wait for sign-off before implementing:** every item
  under `## Standalone — do NOT bundle`, plus anything P0, auth-touching, DB/migration, or L/XL.
- Runbook: `scripts/audit/fix-flow.md`. Branch: `audit-fix/gate-a-2026-07-20`.
- **Every finding below carries only its `Where` and `What to do`.** The full
  **Technical / Plain English / Evidence** for a finding lives in the `sources/<run>.md` file
  named at the end of its line. **The planning instance for a unit MUST open every source file
  its findings point at** before writing a plan — the one-line summaries here are an index, not
  the finding. A plan written from this file alone is working from a third of the evidence.
- This file is **not** in the format `PROMPT-consolidated-fix-runner.md` expects (no per-bundle
  completion checkbox, no embedded four-prompt blocks). Drive it with `execute audit` /
  `fix-flow.md`, not the autonomous runner.

## Progress

- P0 Blocker: 0 of 0 complete *(both P0s re-tiered to P3 — see S1/S2)*
- P1 High: **13 of 13 complete ✅** *(14 originally; WHK-1 re-tiered to P2 — see S3)*
- P2 Medium: 61 of 69 complete *(B8 `models-data/PRIV-2`+`PRIV-3` authored 2026-07-22 UNPUSHED — applied to live dev at cutover Phase 0 with B20, like the rest of the deferred schema — Josh)*
- P3 Low: 16 of 46 complete
- *Total reconciles to 128. Discovered during execution (outside the 128): 8 of 9 complete (DISC-2, DISC-3, DISC-4, DISC-5, DISC-6, DISC-7, DISC-8, DISC-9). Remaining: DISC-1 (routed to the fresh-db-pipeline session — see its note).*

**All P0 and P1 findings are now closed.** Everything remaining is P2/P3.

> **Session 1 stopped here (2026-07-20), 8 units committed** (B2, S1+S2, S3, B1, B3, B4, B5, B6).
> The remainder is handed off in two prompts in this folder:
> - `PROMPT-execute-P2-remaining.md` — B7, B8, B9, B10, B11, B12, B13, B14, B15, B20, B21, S4
> - `PROMPT-execute-P3-remaining.md` — B16, B17, B18, B19 (run after the P2 session)
>
> ⚠️ **B7 is partially landed:** `public-surface/PRIV-2` and `PRIV-3` (`PublicCustomerLeadController.php`
> UA cap + `PublicCustomerLeadRequest.php` `strip_tags`) were written by the second developer and
> committed early at Josh's direction (lint + targeted tests verified; did NOT go through this run's
> plan→implement→independent-review cycle). **The other 8 B7 findings remain open** — the P2 session
> must work them and reconcile, not duplicate the two already landed.

> **⚠️ The "Findings at a glance" table above is wrong as generated.** It claims P1 13 / P2 62 /
> P3 51. Counting the actual finding lines gives **P0 2 / P1 14 / P2 68 / P3 44** — both sum to 128,
> but the per-tier split does not match. The counts tracked here are the line-level ones, adjusted
> for the three re-tiers recorded below.

**Units worked:** B2 ✅ · S1+S2 ✅ · S3 ✅ · B1 ✅ · B3 ✅ · B4 ✅ · B5 ✅ · B6 ✅ (2026-07-20)
**P2 session (2026-07-21):** B7 ✅ *(3 fixed, 2 already-fixed, 2 landed-early-verified, 2 Josh decisions, 1 → B13)* · B8 ✅ *(item_views purge fixed, feedback retention already-shipped; 2 audit-table purges deferred to cutover — Josh)* · B9 ✅ *(4 races fixed: IP-cap advisory lock, handle savepoint-retry, stuck-build watchdog, rename-lock scope; dual independent review)* · B10 ✅ *(report() on 8 deletion/PII-erasure catches + request(); fixed a strict-Log-mock regression the full suite caught)* · B11 ✅ *($fillable hardening across 4 findings; handle/handle_lc kept fillable per Josh to avoid a ~90-file test ripple; SEC-3/SEC-4 needed forceCreate/forceFill not the audit's "clean removal"; dual fresh-grep review)* · B12 ✅ *(GBP reviewer-PII strip durable across the 2-day refresh + self-heal on claim; IG narrowed to bioLinks/syncFindings/unmatched; logged DISC-7 InstagramAutoSync)*
**P2 session part 3 (2026-07-21):** B15 ✅ *(EDGE-1 timeouts on 5 `Http::` calls — B1 had already done `purgeUrls`; SEC-1 `rawurlencode` handle+product-handle; CFG-1/2/3 moved token URLs, UA, enum caps to config)* · B21 ✅ *(SQLite `pre_account_builds.user_id` stub flipped NULL→NOT NULL for prod parity; blast radius 0 — every creation path already sets it via `->user()->associate()`)* · B20 ✅ *(5 findings; 11 new migration files authored UNPUSHED per Josh — 2-table RLS enable+force+policies, menu UUID defaults, design_kits backfill, pg_trgm + 7 CONCURRENTLY GIN indexes; gated for the cutover db-push window)* · B13 ✅ *(Worker: EDGE-2 strip Cookie/Authorization, EDGE-1 strip Vary before cache.put, PRIV-1 drop raw handle/host from 5 error logs, CFG-1 TTLs→wrangler.toml `[vars]`; CFG-3 closed no_change_needed — already guarded by `ReservedSubdomainWorkerSyncTest`; CFG-2 closed won't-fix — stale premise, staging env was removed)* · B14 ✅ *(SEC-2 explicit same-site site_id/subdomain check on the no-Origin ingest path + regression test — defence-in-depth over the resolver's existing DB filter; SEC-1 added a 20/day per-IP early-access cap alongside 5/min + 12/h-email; public-surface/SEC-1 moved the Google Maps key off the public route into authed `GET /api/config/integrations`; CFG-1/CFG-2 no_change_needed — Nightwatch prod guard already live, `/horizon` Basic-auth-sealed + HORIZON_DOMAIN is an ops task)* · S4 ✅ *(models-data/SEC-1, 16-model sweep in 3 gated+reviewed tiers: Tier1 8 mechanical (6 Moderation `$guarded=['id']`→explicit `$fillable` verified column-by-column vs DDL, 2 Views →`$guarded=['*']`); Tier2a ContentSelection/Enquiry/Feedback + Tier2b EmailSubscription/EarlyAccessSignup/Block — trusted writers converted to forceFill/forceCreate/associate BEFORE narrowing `$fillable`, value-assertion tests per field. IntegrationConnection `user_id` KEPT fillable + documented (Josh); User already done in 2cac1ef3. Agents found silent writers beyond the audit map — Enquiry's 5 status-transition methods, EarlyAccess `invite()`'s `fill()` (the "invite links 404 forever" bug), Block skeleton-for-policy sites. Logged DISC-8 + DISC-9. Independent review PASS per tier)*

**Standing decision (Josh, 2026-07-20):** the prod cutover will collapse migration history
into a fresh baseline, so **none of these migration files will replay against prod.** B2, S1,
S2 and B19 are therefore hygiene for local `db reset`, preview branches and disaster recovery —
not prod-blocking. Scope them accordingly; prefer in-place edits over new migration versions,
since any new version gets applied to the live dev DB by `db push`.

## Cross-run root causes

Findings that surfaced independently in multiple runs. Agreement across runs is a confidence
signal — these are the ones least likely to be adjudication noise.

| Root cause | Findings | Runs that found it |
|---|---|---|
| **Edge-cache invalidation has no safety net** — three ways to lose an invalidation (never dispatched / deduped away / timed out) and no reconcile loop under any of them | 8 | preaccount-claim · public-surface · user-api · cache-invalidation · cache-edge-reconcile |
| **Policy gate omitted on write endpoints** — the `authorizeForUser` convention applied inconsistently | 16 | user-api · staff-api |
| **PII written to logs / audit rows without minimisation** | 10 | public-surface · authz-core · webhooks-internal · requests-resources · edge-worker · outbound-ssrf |
| **Migration transaction convention is inconsistent** — backfills wrapped that shouldn't be, DDL pairs unwrapped that should be | 6 | migrations-early · migrations-recent |
| **`$fillable` doctrine not applied** — server-only lifecycle fields mass-assignable | 5 | preaccount-claim · models-data |
| **Retention windows declared but never pruned** | 4 | models-data · wiring · gdpr-deletion-export |
| **Bare `DB::transaction()` not pinned to the `pgsql` connection** | 6 | claim-and-provision · state-machines |

**Note on IDs:** finding IDs are per-run and collide across runs (three unrelated `EDGE-1`s,
two unrelated `WHK-1`s, two unrelated `PRIV-1`s). Every ID below is namespaced `run/ID`.
Never merge on ID alone.

---

## Discovered during execution — not in the original 23 runs

Found while working the units below. Each is recorded here because the audit's own scope
never opened the file, which is the failure mode `CLAUDE.md`'s audit-integrity guards exist
to catch: **a lens reports nothing for files it never reads, and that is indistinguishable
from clean.**

- [x] **`discovered/DISC-9`** · P3 · S — the SQLite test stub for `site.blocks` (`tests/Pest.php::setupBlocksTable()`) declares `user_id`/`site_id` NULLABLE, but production (`20260526000000_baseline_standalone_user.sql`) has both NOT NULL — the same test/prod parity shape as B21/`parity-jobs`, for a different table
  - **Done 2026-07-21:** flipped `user_id`/`site_id` `NULL`→`NOT NULL` in `setupBlocksTable()` (and corrected the now-stale "all columns nullable" docblock). Prod NOT NULL is the baseline's `professional_id`/`site_id` + the `20260527030000_rename_professional_to_user.sql` rename to `user_id` (a RENAME preserves NOT NULL) — the finding's baseline-only citation was slightly imprecise, substance holds. Two test call-sites that raw-inserted `site.blocks` without owner columns surfaced and were fixed by supplying real values (NOT stub relaxation): `UpdateLinkBlockLiveCheckTest` (`$professional->id` + its `$site`) and `CheckStreamingLiveStatusJobTest` (synthetic UUIDs — the job filters only on block_group/live_check_enabled/deleted_at/is_active and never reads the owner, verified). Full suite green (4691 passed, 0 failures); independent Sonnet review PASS.
  - **Where:** `tests/Pest.php::setupBlocksTable()` vs `supabase/migrations/20260526000000_baseline_standalone_user.sql` (`site.blocks`)
  - **What to do:** own decision — flip the two columns to NOT NULL in the stub (as B21 did for `pre_account_builds.user_id`) so a dropped FK fails loudly in tests instead of persisting null. S4 Tier 2b's value-assertion tests already cover the immediate risk; this closes the general gap.
  - **Why the audit missed it:** the `parity-jobs` lens found `pre_account_builds` but never enumerated `site.blocks`. Surfaced while converting Block writers in S4 Tier 2b.
- [x] **`discovered/DISC-8`** · P2 · S — `HasActionLogLifecycle` writes `'failed_at' => now()` to `moderation.action_log`, which has no `failed_at` column — a latent 42703 (undefined_column) on Postgres in the moderation action-failure path
  - **Done 2026-07-21 (Option B — Josh):** dropped the phantom `'failed_at' => now()` from `failed()`; failure is now recorded via `status='failed'` + the existing `failure_reason` column (`Str::limit($e->getMessage(), 1000)`), which this path never populated before. No schema change. The SQLite stub already lacked `failed_at` (matching prod), so the bug hid only because no test exercised the `failed()` hook — new `tests/Feature/Moderation/HasActionLogLifecycleTest.php` exercises it and is proven to fail against the pre-fix code (SQLite `no such column: failed_at`, the 42703 analogue). Full suite green (4695 passed); independent Sonnet review PASS (empirical load-bearing check).
  - **Where:** `app/Jobs/Moderation/Concerns/HasActionLogLifecycle.php:64` · `ActionLogEntry::query()->where('id', ...)->update(['status'=>'failed', 'failed_at'=>now(), ...])`
  - **What to do:** own decision — either add a `failed_at` column (new migration, gated) or drop the phantom column and record failure via the existing `status='failed'` + `failure_reason`. It's a query-builder update, so it bypasses `$fillable` — unrelated to S4's mechanism.
  - **Why the audit missed it:** no lens opened the moderation job `Concerns/`; the mismatch only manifests on a real Postgres write of the rarely-exercised failure path (the SQLite stub tolerates it). Surfaced while enumerating `action_log` columns for S4 Tier 1.
- [x] **`discovered/DISC-7`** · P3 · M — `InstagramConnectionSeeder::seed()` → `InstagramAutoSync::seed()` writes NEW `IntegrationConnection` rows (facebook/tiktok/x/linkedin/fresha/square) parsed from a scraped bio, for a not-yet-consenting provisional subject, as a side effect of the shared seed call
  - **Done 2026-07-22 (follow recommendation — Josh):** added a consent capability `can_autosync_scraped_connections` (`! User::isUnclaimed()`) to `AccountCapabilities`/`AccountCapabilitySet`, AND-ed into BOTH the social and booking write gates in `InstagramAutoSync::handleClassifiedLink()`. An `unclaimed` provisional subject's bio-derived platforms now route to the existing `unmatched` → custom-link-suggestion fallback instead of auto-creating `IntegrationConnection` rows (no connection records, no refresh-treadmill jobs pre-consent). No-op for claimed users (capability = true) — `google_business_full_sync`/`can_use_booking` themselves untouched, no `status` branch at the call site. Tests: capability (unclaimed=false / active+suspended=true) + behavioral (unclaimed→no facebook/fresha rows, claimed→rows created, isolated on a business user so claim-status is the only variable), proven to fail pre-fix. Full suite green (4700 passed); independent Sonnet review PASS. ⚠️ **MERGE NOTE (Josh):** `platform-write-locking` (branch `audit-fix/platform-write-locking-2026-07-21`) plans to add locking to `InstagramAutoSync` — at merge, check `handleClassifiedLink`/the write path for overlap (it had NOT touched this file as of 2026-07-22).
  - **Where:** `app/Services/Platforms/InstagramAutoSync.php` (called from `InstagramConnectionSeeder::seed()`) · pre-account IG generation path
  - **What to do:** own decision + commit — NOT folded into B12. B12's PRIV-2 trims the IG connection's own stored fields, but does not undo these auto-created sibling connections. Whether to skip `InstagramAutoSync` for provisional (`unclaimed`) builds needs its own call, since `seed()` is shared machinery (threading a provisional flag). This is arguably the larger "collects more pre-consent than needed" issue than the payload fields B12 addressed.
  - **Why the audit missed it:** the `state-machines`/`preaccount-claim` scopes opened the generator but not the transitive `InstagramAutoSync` write. Surfaced while planning B12.
- [x] **`discovered/DISC-6`** · P2 · S — `UserBootstrapService::bootstrap()` has the same `handle_lc` TOCTOU as the pre-account path (B9 LIFE-3), but its catch only disambiguates *email* reuse — a concurrent handle collision on `core_users_handle_lc_unique` re-throws as an unhandled 500
  - **Done 2026-07-21 (`7db4f3c7`):** fixed as a friendly `HANDLE_ALREADY_TAKEN` → 409, **not** B9's retry — a deliberate, Josh-approved deviation. Bootstrap's create path is HTTP-dead (410 `SIGNUP_MOVED`), so the only live surface is the existing-user rename where the handle is **user-chosen**; re-allocating a different handle would be a new bug. Classified by re-query (never driver-message), symmetric with the email branch. Tests: CI classifier (SQLite `handle_lc` index) + Postgres-gated genuine-race sentinel + controller 409 translation.
  - **Where:** `app/Services/User/UserBootstrapService.php` (the `catch` that handles email reuse) · `app/Services/User/HandleAllocator.php` (shared `allocate()` — same lockless EXISTS loop)
  - **What to do:** apply the same savepoint-retry-past-handle-collision fix B9 applied to `PreAccountBuildService` (`tryCreateProvisionalUser` pattern). Own commit + review — NOT folded into B9 (different file/flow, and the authenticated signup path deserves isolated verification).
  - **Why the audit missed it:** `HandleAllocator`'s docblock notes it was "extracted verbatim from `BootstrapRequest::generateHandleFromDisplayName`", so both signup paths inherit the identical race; the `state-machines` scope opened `PreAccountBuildService` but the bootstrap caller's catch was out of that lens's frame. Surfaced while planning B9 LIFE-3.
- [x] **`discovered/DISC-1`** · P2 · S — `DROP INDEX` without `CONCURRENTLY` on `site.blocks`, inside a transaction alongside a full-table `UPDATE site.blocks`
  - **Routed 2026-07-22 (Josh):** deferred to the fresh-db-concurrently-pipeline session (`docs/superpowers/plans/2026-07-21-fresh-db-concurrently-pipeline-PROMPT.md`). DISC-1's durable fix is a guard-script check (`DROP INDEX` non-CONCURRENTLY on a HOT table) in `scripts/guard-no-unsafe-migrations.php` — the SAME file that session's Path-B `≤1 CONCURRENTLY per file` check edits, so doing them separately would guarantee a merge conflict; the guard rule lands there instead. The already-applied migration file itself is NOT rewritten (the cutover re-applies against a traffic-free DB, so the hot-table lock is harmless there). **Done 2026-07-22** (this session, branch `audit-fix/migration-safety-2026-07-22`): added guard **Check 7** (`DROP INDEX` non-CONCURRENTLY on a `HOT_TABLES` table, own cutoff `20260722000000`) in `scripts/guard-no-unsafe-migrations.php`, a `CONVENTIONS.md §1` note, and a behavioral regression test in `MigrationTransactionBoundaryTest`; the historical file is untouched per the rationale above.
  - **Where:** `supabase/migrations/20260701180000_strip_block_settings_keys_and_views.sql:19`
  - **What to do:** fold into **B19**. This is a *strictly stronger* instance of what `migrations-early/MIG-1` (S1) complains about — `site.blocks` IS on the repo's own `HOT_TABLES` list (`scripts/guard-no-unsafe-migrations.php:34`), whereas `site.site_media` and `site.enquiries` are not. Found while planning S1/S2; `migrations-early`'s scope never opened this file.
  - **Why the audit missed it:** the file sits outside the `migrations-early` scope group, and the guard script has no `DROP INDEX` check of any kind — so nothing in either the automated or the audited path was looking at it.

- [x] **`discovered/DISC-5`** · P2 · S — Staff `{category}` routes 500 in production (broken scoped route-model binding)
  - **Done 2026-07-21 (`d1169d55`):** option (a) — renamed the route param + controller args `{category}` → `{serviceCategory}` (verified no `route('category')` / named-route / frontend coupling; URL path unchanged). Added an HTTP-level binding test asserting the resolved category id + a cross-tenant 404 (scopeBindings isolation preserved). Corrected the now-stale `StaffOwnedRecordActorGateTest` docblock that described this bug as still-open.
  - **Where:** `routes/api/staff.php` — every staff route with a `{category}` segment (service-category show/update/destroy/restore/forceDestroy, both route groups) · `app/Models/Core/User/User.php`
  - **What to do:** own commit + review, not folded into B6. Fix is one of: rename the route parameter `{category}` → `{serviceCategory}`, or add a `categories()` alias relation on `User`.
  - **Technical:** Laravel's `scopeBindings()` resolves the parent relation for a `{category}` child as `Str::plural(Str::camel('category'))` = `categories()`, but `User` defines the relation as `serviceCategories()`. So `User::categories()` does not exist and every such route 500s with "Call to undefined method". Independently reproduced in review with a probe request.
  - **Why it matters:** the entire staff service-category management surface beyond `index()`/`store()` is currently broken in production. No audit lens found this — it surfaced only when B6 tried to write an HTTP-level test against the route (which is why `StaffOwnedRecordActorGateTest` tests those via `Gate` directly, not HTTP).
  - Surfaced during B6, not by the original audit.
- [x] **`discovered/DISC-4`** · P3 · S — No test exercises the real `ffprobe` invocation chain that video-upload validation depends on
  - **Done 2026-07-21:** added `tests/Feature/Media/VideoProbeRealChainTest.php` (3 cases, `VideoVariantService` NOT mocked). Two stub `ffprobe` scripts (exit non-zero; exit-0 with non-JSON stdout) pointed at via `config('partna.ffprobe_binary')` prove `probe()` throws the distinct `ffprobe failed (exit` / `non-JSON` RuntimeExceptions, and a real video upload through `UserUploadController` returns **422** carrying that message AND persists **no** `SiteMedia` row (fail-closed). The message substrings are load-bearing — they rule out a false pass from a fake file merely lacking a video stream (a different branch/message). Note: `ffprobe` is not installed on the dev machine, so this stub-driven test is the ONLY exercise of the control. Test-only, no `app/`/`config/` change. Full suite green (4694 passed); independent Sonnet review PASS.
  - **Where:** `app/Services/Media/VideoVariantService.php:75-79` (`probe()`) · every video test mocks `VideoVariantService` wholesale
  - **What to do:** point `config('partna.ffprobe_binary')` at a stub script (one exiting non-zero, one emitting crafted bad JSON) so the real `Process` → `\RuntimeException` → `InvalidVideoFileException` → 422 chain is exercised without mocking the class.
  - **Why it matters:** `requests-resources/SEC-2` was closed *because* this control is strictly stronger than the byte-sniff the audit asked for. But `MediaUploadFailureHandlingTest`, `MediaUploadBreadcrumbTest`, `GalleryMixedReorderTest`, `VideoUploadsFlagTest` and `MediaJobReliabilityTest` all `Mockery::mock(VideoVariantService::class)`, so **the control our closure relies on is verified by no automated test.** Its fail-closed behaviour was confirmed by manual experiment during review, not by CI. Someone narrowing the catch type, changing the ffprobe invocation, or a build script silently ceasing to install the binary would not be caught.
  - Surfaced during B4 review, not by the original audit.
- [x] **`discovered/DISC-3`** · P3 · M — Three shared SQLite test stubs still declare columns that production dropped
  - **Done 2026-07-22:** removed the 15 dead columns — 5 profile cols (hero_title/hero_subtitle/primary_button_text/primary_button_url/bio_text, dropped by `20260705120002`) from `setupSitesTable()`'s CREATE list AND its `$promotedCols` ALTER map; 10 `square_*`/`fresha_*` cols from `setupServicesTable()` (never in the standalone baseline — baseline ~L924-929 document their v2 exclusion); and the false `whereNull('square_variation_id')` comment in `ServicesIsolationTest.php` (grep confirms zero `square_variation_id` in `app/`). Verified none present in current prod DDL, none referenced by `app/` or any test — the "191-file" readers of `setupSitesTable()` touch none of the removed columns, so removal was zero-breakage. Full suite green (4695 passed); independent Sonnet review PASS.
  - **Where:** `tests/Pest.php:489-493` (`setupSitesTable()` — `hero_title`, `hero_subtitle`, `primary_button_text`, `primary_button_url`, `bio_text`, all dropped by `20260705120002_drop_dead_profile_columns_tables.sql`) · `tests/Pest.php:1189-1198` (`setupServicesTable()` — all 10 dropped `square_*`/`fresha_*` columns) · `tests/Feature/Security/TenantIsolation/ServicesIsolationTest.php:18` (`square_variation_id`, dropped pre-baseline)
  - **What to do:** own cleanup unit, NOT folded into an audit bundle. `setupSitesTable()` is used by **191 test files**, so the blast radius warrants its own plan and review.
  - **Why this is lower severity than the B3 drift:** these are the *inverse* hazard — extra dead columns **present** in the stub, rather than real columns **missing** from it. Nothing in `app/` selects these names (verified by grep), so no query silently receives a string literal. It is schema drift, not a masked live bug.
  - **Bonus finding:** `ServicesIsolationTest.php:18`'s own comment claims "the controller adds `whereNull('square_variation_id')`". Grepping `app/` for `square_variation_id` returns zero hits — the justifying comment is false and the column is dead cruft from the removed Square integration.
- [x] **`discovered/DISC-2`** · P3 · S — `site.enquiries` loses index coverage for the `(user_id, created_at DESC)` access path
  - **Where:** `supabase/migrations/20260527160001_enquiry_inbox_indexes.sql` (drop) · consumers `StaffEnquiryController::index`, `UserEnquiryController::index`
  - **What to do:** no action now — documented and accepted in the migration file. If the staff enquiry inbox ever gets slow, add `(user_id, created_at DESC) INCLUDE (status)`.
  - **Technical:** `enquiries_user_created_idx (user_id, created_at DESC)` is dropped in favour of `idx_enquiries_user_status_created (user_id, status, created_at DESC)`. These are NOT equivalent: `status` sits between the two columns, so `(user_id, created_at)` is not a leading-contiguous prefix. A query filtering `user_id` alone and ordering by `created_at DESC` can use the composite for the filter but must then Sort, losing LIMIT early-termination. `StaffEnquiryController::index` issues exactly that query with no `status` predicate.
  - **Plain English:** the new index was assumed to be a drop-in replacement for the old one. It isn't quite — it works for the filter but not for the sort, so the staff inbox listing has to sort results in memory instead of reading them already-ordered. Harmless at 5 rows; worth revisiting if enquiry volume grows.
  - **Not a regression from this run** — the drop pre-existed; S1/S2 only moved it so it could run `CONCURRENTLY`. What this run fixed was the *comment* that wrongly claimed full coverage.

---

## Standalone — do NOT bundle

Each of these needs its own plan, its own sign-off, and its own commit.

### S1: `migrations-early/MIG-1` · ~~**P0**~~ → **P3** · Effort S · → `sources/migrations-early.md`

> **Re-tiered P0 → P3 (2026-07-20, Josh signed off).** The audit's own escalation is conditional on
> "these migrations ever replaying against a populated, serving database." Josh confirmed the prod
> cutover collapses migration history into a fresh baseline, so that replay never happens — the
> remaining surfaces are local `db reset`, preview branches and DR, all of which start empty. Further,
> `site.site_media` is **not** a hot table by the repo's own definition: `HOT_TABLES` in
> `scripts/guard-no-unsafe-migrations.php:34` and `docs/migration-guidelines.md:51` both list only
> `site.design_kits`, `site.sites`, `site.blocks`, `core.users`. Dev row count: **82**. The table is
> created by the baseline one migration-day *before* this file runs. There was no outage to prevent.
> Fixed anyway because it costs one keyword and restores consistency with four sibling files.

- [x] **`migrations-early/MIG-1`** · ~~P0~~ P3 — `DROP INDEX` without `CONCURRENTLY` on hot table `site.site_media`
  - **Where:** `supabase/migrations/20260527000000_fix_sort_order_unique_constraints.sql`
  - **What to do:** change both `DROP INDEX` statements to `CONCURRENTLY`; the file already lacks a transaction wrapper.

**Risk gate: DB/migration + P0.** A plain `DROP INDEX` takes `ACCESS EXCLUSIVE`, blocking every
read and write on the table while it waits for in-flight queries. ⚠️ **Verify before fixing:**
CLAUDE.md records a known "CONCURRENTLY/pipeline CLI incompatibility" that already breaks
`supabase db reset` on a fresh DB — adding `CONCURRENTLY` may trade a lock stall for a migration
that will not run at all under `db push`. Resolve that interaction as part of this fix.

**Mitigating context:** at cutover the target is a freshly re-baselined DB with no traffic and
near-empty tables, so the lock has nothing to block. Severity is real but conditional on these
migrations ever replaying against a populated, serving database.

### S2: `migrations-early/MIG-2` · ~~**P0**~~ → **P3** · Effort S · → `sources/migrations-early.md`

> **Re-tiered P0 → P3** for the same reasons as S1. `site.enquiries` is not on `HOT_TABLES`; dev row
> count: **5**; table created by the baseline before this file runs.

- [x] **`migrations-early/MIG-2`** · ~~P0~~ P3 — `DROP INDEX` without `CONCURRENTLY` on `site.enquiries`, inside a data-writing transaction
  - **Where:** `supabase/migrations/20260527160000_enquiry_inbox.sql`
  - **What to do:** move the `DROP INDEX` into the sibling CONCURRENTLY file as `DROP INDEX CONCURRENTLY IF EXISTS`.
  - **Done, with one deliberate deviation:** the drop was placed **after** the three `CREATE INDEX
    CONCURRENTLY` in the sibling, not before as the audit text says. Before would open a window with
    neither the old nor the new index present.
  - **The "CONCURRENTLY/CLI incompatibility" question that gated both S1 and S2 was a non-question.**
    Both target files already contain `CONCURRENTLY` statements, and four other files in
    `supabase/migrations/` already ship `DROP INDEX CONCURRENTLY`. Whether the CLI's implicit
    transaction blocks `CONCURRENTLY` is a pre-existing, folder-wide property — adding the keyword
    cannot make these files un-pushable because they are already in that class.
  - Review round 1 caught that the new comment claimed `(user_id, created_at)` is a prefix of
    `(user_id, status, created_at)`. It is not — see `discovered/DISC-2` above.

**Risk gate: DB/migration + P0.** Structurally worse than S1: `DROP INDEX CONCURRENTLY` **cannot
run inside a transaction block** — Postgres rejects it outright — so the one-keyword fix is
unavailable here. The migration must be split first. Plan S1 and S2 as **one unit**; they share
the CLI-incompatibility question above.

### S3: `webhooks-idempotency/WHK-1` · ~~**P1**~~ → **P2** · Effort ~~M~~ S · → `sources/webhooks-idempotency.md`

- [x] **`webhooks-idempotency/WHK-1`** · ~~P1~~ P2 — Auth-hook idempotency short-circuit always replays `continue`, silently converting a lost REJECT into an allow on Supabase's retry
  - **Where:** `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:53-59,108-123` — **line numbers stale**, WHK-101 shifted them ~5; the short-circuit is at :63-64.
  - **What to do:** store the decision alongside the dedup anchor; return the stored decision, not a hardcoded `continue`, on replay.

  **Mechanism confirmed; impact analysis corrected; re-tiered P1 → P2 (Josh signed off).** Three of the audit's claims did not survive checking:
  - **It cannot promote anyone to AAL2.** `reject` is only ever computed when `valid === false` — the handler returns `continue` early on success. A replayed `continue` on an already-failed attempt yields Supabase's default behaviour for `valid: false`, which is to fail it. What a lost `reject` actually costs is the **session revocation** it triggers (log out of all active sessions). Real, but not the described breach.
  - **There is no mandatory retry.** Supabase HTTP hooks retry only on `429`/`503` with a non-empty `retry-after`, inside a 5s budget. This controller emits `200`/`400`/`500` — none in the retry set.
  - **The hook cannot fire today.** MFA Verification Attempt is a Teams/Enterprise-plan hook; both Partna orgs are on **free**, and `supabase/config.toml` has no hook block. This is latent hardening ahead of enabling MFA, not a live fail-open.

  **The audit's prescribed fix was rejected and NOT implemented.** It says to store the decision in a second cache key and *"default to `continue` when no stored decision is found"*. Two independent keys on the same store can be evicted independently (LRU / TTL skew), so an anchor surviving while the decision key is evicted lands on that `continue` default — a fail-open on exactly the path the fix exists to close. *(A full `Cache::flush()` is NOT the hazard: it wipes the anchor too, so the request recomputes cleanly. Partial eviction is.)*

  **Implemented instead:** resolve the replay from Postgres, where the decision is already durably recorded — WHK-101's `audit.auth_factor_events.webhook_id` + partial unique index, with `event_type` mapping 1:1 onto the wire decision. The cache anchor degrades to a pure latency optimisation whose loss is always safe. No migration, no grant, no config change. Mapping verified exhaustive over reachable rows (`unenroll` always writes a NULL `webhook_id`).

  **Fail-closed in every enumerated mode**, including a strict improvement: today a failed crash-path `Cache::forget` leaves a hardcoded `continue`; now no row was written, so it recomputes. Accepted deliberate change: a replay can recompute to `reject` where the original returned `continue`, because the first delivery's own failure row now counts — fail-closed, and the attempt had already failed.

  **Docs corrected** (class docblock + `docs/auth/mfa-foundation.md`): the old "we reject further attempts" overstated the code, and is plausibly what led the audit to misread the impact.

  **Test honesty note:** 5 tests added; only 2 are genuine fail-before/pass-after regression tests (stored-reject-replays-as-reject, and dedup-hit-with-no-durable-row). The other 3 pass against pre-fix code too — verified empirically by the implementer and independently by the reviewer, and kept as robustness guards rather than claimed as differentiators.

**Risk gate: auth.** The only finding in the gate that makes the system *less* safe under retry
than under normal operation. Idempotency caching is meant to be safety-preserving; replaying a
hardcoded `continue` inverts it, so a legitimate REJECT becomes an allow. It sits at the Supabase
auth boundary — every correct authorization check downstream is irrelevant if the wrong identity
gets an account — and it is invisible in the happy path, so no amount of pilot usage surfaces it.

### S4: `models-data/SEC-1` · **P2** · Effort **L** · → `sources/models-data.md`

- [x] **`models-data/SEC-1`** · P2 — Eleven models declare permissive mass-assignment, deviating from the codebase's own `$fillable` doctrine
  - **Where:** `app/Models/Moderation/*`, `app/Models/Core/Site/*`, `User.php`, `app/Models/Views/*`
  - **What to do:** replace with explicit `$fillable` allowlists; migrate skeleton-pattern call sites to explicit assignment.

**Risk gate: L effort.** Do NOT attempt alongside B5. Per the *Fillable Tenancy-FK → associate()*
rule, removing a tenancy FK from `$fillable` is not a one-liner — trusted paths must move to
`->relation()->associate()` first or creation silently breaks.

---

## Suggested Bundled Sessions

### Bundle B1 — Edge-cache invalidation: close all four holes · **P1** · Effort M

**Models:** plan=Opus · impl=Sonnet · review=Sonnet

The highest-confidence finding in the gate: five independent runs, three lens bundles, one
missing architectural piece. There are three ways to lose an invalidation and no recovery path
under any of them. Fix the reconcile loop **first** — without it the three dispatch-site fixes
are cleanup that will silently regress.

`edge-worker` returned no P0/P1, so **the Worker needs no change**; this is entirely Laravel-side.

> **⚠️ The "fix LIFE-1 first" sequencing constraint was DROPPED — it rests on a false dependency.**
> The four real bugs here are all *missing-dispatch* defects: a purge or sync that is never queued.
> A recovery path for *failed* purges does nothing for a purge that was never dispatched. There is
> no ordering dependency between them.
>
> **⚠️ 3 of 9 findings were already closed or false.** LIFE-1 and LIFE-3 were fixed by a *previous*
> audit-fix run (`3f41d147`, `f5cdcd99`) before Gate A ever ran — both are ancestors of this branch.
> This is the staleness class CLAUDE.md warns about: *"After a baseline, audit the delta
> (`--changed-since <ref>`), not the repo."* Gate A audited the repo.

- [x] **`cache-edge-reconcile/LIFE-1`** · P1 · M — Cloudflare purge job can time out mid-purge for large catalogs, **permanently** stalling edge invalidation → `sources/cache-edge-reconcile.md`
  - `app/Services/Cloudflare/CloudflarePurgeService.php:66-72,147-149,163-165,207` — split the purge URL list across multiple delayed dispatches, or raise the timeout with bounded limits.
  - **`no_change_needed` as written — already fixed by `f5cdcd99`.** Both load-bearing claims are false at HEAD: `$timeout` is **180**, not the 15 the finding quotes (with a documented derivation and a test sweep pinning the `uniqueFor > timeout` invariant), and chunk pacing is budget-capped.
  - **A genuine residual was fixed:** the outbound Cloudflare calls set no explicit HTTP timeout, so Laravel's 30s default meant ~6 stalled chunks could consume the whole 180s job budget while ~44 went unpurged. Added `->timeout(10)->connectTimeout(3)` (10 not 5 — 5 risks failing purges that currently succeed, and there is no p99 latency data). This **narrows** the hole from 6 chunks to 18; it does not close it, and the derivation comment says so rather than claiming immunity. Also added the URL-volume warning the finding asked for and which was never implemented.
  - The proposed retry ledger + scheduled re-dispatch was **declined** as scope expansion (Josh, 2026-07-20) — LIFE-1 as written is closed, and a deterministic failure (revoked token) is bounded by the Worker's 24h TTL with any later edit re-purging.
- [x] **`cache-invalidation/WHK-1`** · P1 · S — `SyncSubdomainToKvJob::uniqueId()` doesn't discriminate a delete-triggered retire from a routine sync; the retire is silently dropped → `sources/cache-invalidation.md`
  - `SyncSubdomainToKvJob.php:66-71`, `UserObserver.php:77,178,203` — fold `capturedHandle` into the `uniqueId()` discriminator; add a concurrent sync+retire regression test.
  - **Confirmed, and worse than written.** The job implements `ShouldBeUniqueUntilProcessing`, not `ShouldBeUnique` (audit text is wrong), so the drop window is "queued but not yet started". The full trace: a hard delete's retire is deduped away by an in-flight plain sync; the surviving job's `withTrashed()->find()` returns null, and with `capturedHandle` null the handle resolves to `''` — so **no KV delete happens at all** and the entry stays live indefinitely. The weekly `partna:backfill-subdomain-kv` cannot repair it because the user row is gone.
  - Fixed with a `:retire-handle:` discriminator. Over-fixing was the real risk (the bare `$userId` key deliberately coalesces ~45s observer storms); review enumerated every dispatch site and confirmed plain syncs still collapse.
- [x] **`cache-invalidation/CCH-3`** · P1 · M — SWR recompute failures propagate instead of falling back to the known-good stale value → `sources/cache-invalidation.md`
  - `CacheLockService.php:97-119`, `SiteCacheService.php:227-245` — wrap recompute in try/catch; on failure `report()` and return the stale value.
  - Fixed at both sites. **The catch is scoped inside the `if ($stale !== null)` guard**, so the cold-miss path is untouched and a genuine failure with nothing to fall back on still propagates — a silent "serve stale forever" would be worse than a 500. `SiteCacheService` falls through into its existing healing ladder rather than duplicating it. Masking is bounded: the stale key's TTL is not refreshed on a failed recompute, so it self-corrects within one TTL window.
- [x] **`preaccount-claim/EDGE-1`** · P1 · S — Claim transition never purges the edge cache; stale pre-claim content served up to 7 days → `sources/preaccount-claim.md`
  - `app/Services/PreAccount/ClaimSiteService.php:76-84` — dispatch `CloudflareCachePurgeJob` afterCommit as `SiteObserver` does; assert the dispatch in a test.
  - Confirmed: `UserObserver::PUBLIC_PROFILE_USER_FIELDS` excludes `status`, so the claim's status flip never reaches `SiteObserver::saved()`. Purges by `$site->subdomain` (not `$professional->handle`) to match `SiteObserver` exactly. No double-purge — claim uses `invalidateSite()` (pure cache deletes), not `touchSite()`.
- [x] **`user-api/EDGE-1`** · P1 · M — Reordering services or service categories never purges the edge cache → `sources/user-api.md`
  - `app/Services/Site/ReorderService.php` + both reorder controllers — pass `fn() => $site->touch()` as the afterCommit arg; touch the site in `reorderLayout` too.
  - Confirmed at all three call sites (`ReorderService` writes via a query-builder mass update, so no model events fire). **Gap the audit missed:** neither controller used `ResolveCurrentSite` — the trait was added, matching the `UserGalleryController` convention. Burst safety verified: `CloudflareCachePurgeJob::uniqueId()` doesn't vary per invocation, so a drag-and-drop burst coalesces under its 240s lock.
- [x] **`public-surface/EDGE-1`** · P1 · S — Alias 301 in `show()` ships no `Cache-Control`, unlike sibling `showByHeader()` → `sources/public-surface.md`
  - Fixed. Impact is lower than the finding implies — `show()` is a JSON API endpoint, not the HTML page, so the "visitor stranded permanently" narrative doesn't apply. TTL confirmed safe against alias expiry: 5 minutes is far inside the 14d `reclaim_until` / 90d `expires_at` windows.
- [x] **`public-surface/EDGE-2`** · P2 · S — `show()` and `showByHeader()` handle the same alias redirect differently: one preserves the path, the other goes to homepage → `sources/public-surface.md`
  - **`no_change_needed` — premise false.** `show()` cannot "preserve the visited path": it is bound to the fixed path `/public/site` on the subdomain host. And the two methods sit on **different hosts serving different clients** — `showByHeader()` is on the API host for the Next.js proxy. Unifying them is actively harmful in both directions: one would send a JSON client to an HTML homepage; the other would build a URL on the wrong host with the required `X-Site-Subdomain` header lost (→ 400). Replaced with explanatory comments on both methods.
- [x] **`public-surface/CFG-3`** · P3 · S — Alias-redirect `Cache-Control` TTL hardcoded as a string literal → `sources/public-surface.md`
  - The source file states EDGE-1 / EDGE-2 / CFG-3 should be fixed together; keep them in one commit.
  - Extracted to `partna.cache.alias_redirect_max_age`. **The audit's suggested key was wrong** — `partna.public_site.*` does not exist and there is no such namespace; used the existing `partna.cache` block.
- [x] **`cache-edge-reconcile/LIFE-3`** · P2 · S — Purge product/menu/event lookup failures log at `debug`, invisible to Nightwatch at production log level → `sources/cache-edge-reconcile.md`
  - **`no_change_needed` — already fixed by `3f41d147`** (OBS-101). The three catch blocks already pair `report($e)` with `Log::warning`, with a covering test.

### Bundle B2 — Migration atomicity, ahead of the cutover · **P1** · Effort M

**Models:** plan=Opus · impl=Sonnet · review=Sonnet
**Risk gate: DB/migration — sign-off required.**

⚠️ **Sequence these ahead of S1/S2 despite the lower tier.** The P0s are lock stalls that need a
populated, serving table to hurt — at cutover the target is fresh and idle, so their real-world
severity is near zero *for this operation*. These P1s don't care about table size: a backfill
that commits before its `DROP COLUMN` fails leaves a state that is neither the old schema nor
the new one, where re-running and rolling back are both unsafe. That is the failure mode that
turns a one-shot operation into an unrecoverable one.

Note B2 contains **opposite** errors — MIG-1/2/3 are under-wrapped, MIG-4 is over-wrapped. The
rule: **backfills want to be split, DDL pairs want to be atomic.**

- [x] **`migrations-recent/MIG-3`** · P1 · S — `DROP CONSTRAINT` + `ADD CONSTRAINT ... NOT VALID` auto-commit separately; momentary window with no CHECK active on `site.sites` and `core.users` → `sources/migrations-recent.md`
  - 6 files incl. `20260711000000_staff_account_type.sql` — wrap both statements in one transaction per file.
  - **Fixed 2026-07-20, stated reason rejected.** The CLI wraps each file in one implicit transaction, so no "gap window" ever existed. The real defect: `VALIDATE` shared the transaction with the `DROP`, holding `ACCESS EXCLUSIVE` through the validation scan. Fixed by two-window `BEGIN`/`COMMIT` per file (5 files), per the `20260712000000` exemplar. 6th file was already fixed as MIG-103 (2026-07-11) — `no_change_needed`.
- [x] **`migrations-recent/MIG-1`** · P1 · S — Backfill INSERT and `DROP COLUMN` auto-commit separately; half-applied risk on `site.menu_items` → `sources/migrations-recent.md`
  - `20260701140100_menu_item_platforms_table.sql` — wrap in one transaction; add `SET LOCAL lock_timeout` before the drop.
  - **Premise false** — file was already atomic; the described retry dead-end cannot occur. Explicit single-window `BEGIN`/`COMMIT` + timeouts applied as hygiene (Josh's call), so atomicity no longer depends on undocumented CLI behaviour.
- [x] **`migrations-recent/MIG-2`** · P1 · S — Same non-atomic backfill/drop pattern on `site.menus` → `sources/migrations-recent.md`
  - **Premise false**, same as MIG-1. Same hygiene treatment.
- [x] **`migrations-recent/MIG-4`** · P1 · M — `ADD COLUMN` + full-table `UPDATE` + `ADD CONSTRAINT`/`VALIDATE` combined in one transaction on `site.sites`; violates the repo's own written convention → `sources/migrations-recent.md`
  - Split into: DDL · backfill outside the transaction · `ADD CONSTRAINT NOT VALID` · `VALIDATE`.
  - **Premise true.** Done as four windows **in place** (not new files) — new migration versions would have been pushed to the live dev DB and re-run the backfill after `20260701200000` stripped the keys, nulling all ten columns. Also fixed the `WHERE settings IS NOT NULL` tautology (`settings` is `NOT NULL DEFAULT '{}'`) → `settings ?| array[...]`. Independent windows required `ADD COLUMN IF NOT EXISTS` + `DROP CONSTRAINT IF EXISTS` guards for replay-safety (caught in review round 1).

### Bundle B3 — PII that survives redaction or leaks on export · **P1** · Effort M

**Models:** plan=Opus · impl=Sonnet · review=Sonnet

Legal risk, not technical debt — pilot means real visitor and staff PII. Note the two `PRIV-1`s
here are **unrelated defects** from different runs.

> **⚠️ Framing correction: `Customer::redact()` and `Enquiry::redact()` are DEAD CODE.**
> `grep -rn -e "->redact()" app/ tests/` returns only test files — no controller, route, job, command,
> service or observer calls either method; the destroy endpoint soft-deletes. So the audit's
> *"Affects: every customer a professional redacts"* describes a flow that does not exist. PRIV-1,
> PRIV-3 and PRIV-4 are **forward-looking hygiene, not active leaks**. `gdpr-deletion-export/PRIV-1`
> is the only live legal exposure in this bundle.

- [x] **`models-data/PRIV-1`** · P1 · S — `Customer::redact()` cascade skips `LeadSubmission`, leaving visitor PII behind after a redaction → `sources/models-data.md`
  - Bulk-UPDATE `LeadSubmission` to null `ip_hash`/`user_agent`/`referrer`; add a coverage test.
  - Scope note: `state-machines` and `gdpr-deletion-export` both audited the *deletion* cascade and found no equivalent gap, so this is **one missed table, not a systemic cascade problem**.
  - Fixed via the Eloquent model (pinned to `pgsql` by `BaseModel` — a raw `DB::table()` would resolve SQLite under test), scoped strictly to `where('customer_id', $this->id)`. Grant safety verified: `20260607000000_restrict_app_backend_append_only_grants.sql:25-30` excludes the analytics tables from the UPDATE/DELETE revocation by name. **Irreversible**, so the test asserts a *second* customer's rows survive untouched.
- [x] **`gdpr-deletion-export/PRIV-1`** · P1 · S — GDPR deletion-audit export discloses **staff** IP address and browser fingerprint to the data subject → `sources/gdpr-deletion-export.md`
  - Redact `ip_address`/`user_agent` for non-professional `actor_type` rows in `streamDeletionAudit()`, as `streamHandleChangeLog()` already does. Honouring the subject's Article 15 right currently breaches the staff member's.
  - Confirmed on all three legs. Affected rows are exactly `actor_type = 'staff_admin'` (events `admin_initiated`/`admin_cancelled`); `system` rows already carry null ip/ua, and the `purged` row has `user_id => null` so it never matches the export query. Self-service rows keep the subject's own IP — legitimately theirs. Taxonomy is provably exhaustive: `UserDeletionAuditEntry` defines exactly three `actor_type` values and the production CHECK constraint enforces the same three. `reason` deliberately retained (it is *about* the subject; Article 15 arguably requires it). Test asserts the staff IP appears **nowhere** in the serialised payload, not merely that the field is null.
- [x] **`preaccount-claim/PRIV-3`** · P2 · S — `Customer::redact()` erases contact PII but leaves the third-party POS `external_id` intact → `sources/preaccount-claim.md`
  - **Skipped by decision (Josh, 2026-07-20) — the prescribed fix is unsafe.** `external_id` is load-bearing in two live paths the audit did not check: `UserEnquiryController:221` uses `empty($customer->external_id)` as a **delete guard**, so nulling it would make redacted customers deletable as spam artefacts; and `PublicCustomerLeadController:106` deliberately re-populates it on the next lead submission, so the erasure would not even be durable. Recorded instead as a docblock: `external_id` is retained as a third-party reconciliation key this routine cannot authoritatively sever (the POS is system-of-record), and such a row is **contact-erased**, not fully erased. Both cited call sites verified verbatim in review.
- [x] **`models-data/PRIV-4`** · P3 · S — `Enquiry::redact()` leaves `subject` unscrubbed → `sources/models-data.md`
  - `site.enquiries.subject` is **`varchar(100) NOT NULL`** (baseline `20260526000000:974`; no later migration relaxes it), so `null` would throw 23502 on Postgres while passing on SQLite. Used `'[redacted]'`, matching the existing Notification title/body idiom — applied identically in both `Enquiry::redact()` and `Customer::redact()`'s bulk cascade, so the two paths cannot diverge.
- [x] **`gdpr-deletion-export/PRIV-2`** · P2 · M — Nine GDPR export sections stream unfiltered table columns with no explicit allowlist → `sources/gdpr-deletion-export.md`
  - Add explicit `->select()` allowlists; add a `SELECT *` guard test. This is what let PRIV-1 happen.
  - **The audit undercounted: eleven, not nine** — it missed two bare `SELECT *` inside `site()` (`site.sites` and `site.blocks`), and `site.sites` is the highest-churn table in the schema.
  - **The dangerous part was never the `->select()` — it was where the column names come from.** Ten SQLite stubs were already drifted from production DDL (`site.services` stubbed `name` where prod has `title`; `site.sites` stubbed 5 columns against a real 24; `site.blocks` stubbed `type` vs `block_type`; `design_kits` stubbed columns that were *dropped*). Because SQLite treats an unknown quoted identifier as a **string literal**, allowlists written from those stubs would pass CI and return literal text instead of data — the 2026-07-19 `streamMedia` incident, eleven times over. All stubs repaired against real DDL.
  - Each allowlist is set to the table's **current full column set**, so the change is provably behaviour-neutral (no column stops being exported) and the guard is purely forward-looking.
  - **Two new guards** in `DataExportCoverageTest`: a `SELECT *` check, and a column-existence check that derives each table's true column set by replaying every `ADD`/`DROP`/`RENAME COLUMN` across `supabase/migrations/`. The latter is DB-independent, so SQLite cannot mask a bad name — it is the actual prevention. It found **zero** pre-existing mismatches across the 20 already-disciplined sections, so the 2026-07-19 incident was isolated rather than symptomatic.
  - `site.design_kits` exempted from the allowlist rather than enumerated: 67 token columns across 27 churn migrations, zero PII by construction (verified column by column in review). A hand-maintained 67-name list is a stale-name hazard, not a safeguard.

### Bundle B4 — Upload content validation · **P1** · Effort S

**Models:** plan=Opus · impl=Sonnet · review=Sonnet

⚠️ **VERIFY THE PREMISE FIRST.** SEC-1 claims there is no validation "at any layer", but the
`outbound-ssrf` run scoped `app/Services/Media` — where magic-byte sniffing would live — and
found nothing. Either the check exists downstream and SEC-1 is scoped too narrowly, or it
genuinely doesn't and the security lens framed the gap as SSRF-adjacent. Grep the media pipeline
for `finfo`/magic-byte checks **before** implementing. If validation already exists downstream,
close SEC-1 as `no_change_needed` and keep only SEC-2.

- [x] **`requests-resources/SEC-1`** · P1 · S — Document upload has no downstream content validation at any layer → `sources/requests-resources.md`
  - `app/Http/Requests/Api/User/Documents/UploadDocumentRequest.php:14-19` — add a document MIME whitelist + byte-sniff assertion to `SniffsFileMimeType`, called via `withValidator()`.
  - **`no_change_needed` — premise false.** `UserDocumentController::store():55-60` already byte-sniffs with `finfo(FILEINFO_MIME_TYPE)` against `['application/pdf','image/jpeg','image/png']` and returns **415** on mismatch. It runs **before** the `DB::transaction()` (:90) and before the R2 `Storage::put()` (:148), so a rejected file is never persisted. The stored extension is derived from the *sniffed* MIME (:82-86), never the client filename.
  - **Verified as a genuinely pre-existing control, not a same-session fix:** `git blame` dates the check to `2134e544` (2026-04-22), five weeks before this branch, and `git diff origin/development` shows zero changes to that controller here.
  - **No bypass exists.** Only two files construct a `SiteMedia` row anywhere in `app/`. `MediaUploadService`'s `$pool` is constrained by `Rule::in(config('partna.upload_pools'))`, and `config/partna.php:998` hardcodes `['gallery','content']` — not env-overridable, does not include `documents`. No staff, internal, pre-account, import or job path writes the documents pool.
  - **Polyglot risk assessed and held:** a PNG-header polyglot would pass a header-bytes sniff, but `PublicDocumentDownloadController:45-51` forces `Content-Disposition: attachment` on every public document URL, defeating inline content-type confusion. The one non-forcing URL (`DocumentMediaResource::preview_url`) never appears in the public payload.
  - Existing coverage is genuine, not vacuous: `DocumentControllerIntegrationTest:163-174` uploads PNG bytes named `fake.pdf` with a PDF `Content-Type` and asserts 415.
- [x] **`requests-resources/SEC-2`** · P3 · S — Video upload path skips the Form-Request magic-byte check the image path already has → `sources/requests-resources.md`
  - **`no_change_needed` — premise literally true but the control is redundant and weaker than what already runs.** `MediaUploadService::upload():100` calls `VideoVariantService::probeAndValidate()` synchronously **before** `createMediaRow()` — real `ffprobe`, requiring a decodable stream, codec allowlist (`h264`/`hevc`/`vp9`), resolution and duration ceilings. That is content *parsing*, strictly stronger than a `finfo` header sniff, and it runs earlier in the pipeline than the check the audit wanted to add.
  - **The fail-open scenario was tested, not assumed.** With `ffprobe` genuinely absent locally, `Process::run()` returns without throwing (exit 127), `isSuccessful()` is false, and `probe():75-79` throws `\RuntimeException` → caught → 422. **Fails closed.** Production installs real binaries via `deploy/ffmpeg.sh`.
  - Adding a shallow Form-Request byte-sniff would risk rejecting valid `.mov`/`.webm` uploads (which `finfo` can report as `application/octet-stream`) for no verified benefit.
  - Video upload is opt-in — `config('partna.features')` defines no `video_uploads` key, so the flag resolves false absent a DB row, consistent with P3.
  - ⚠️ See `discovered/DISC-4`: the control this closure relies on is covered by no automated test.

### Bundle B5 — Policy gate sweep: user API · **P2** · Effort S ×10

**Models:** plan=Sonnet · impl=Sonnet · review=Sonnet *(combine plan+impl — all S)*

One mechanical theme: write endpoints that skip `authorizeForUser`. Under Supabase JWT
`Auth::user()` is always null, so a missing gate fails **open**, not closed. `authz-core`
returned zero P0/P1, so the policy *layer* is sound — these are call sites that never invoke it.

> **⚠️ None of these ten closed a live, exploitable authorization hole.** In every case the write was
> already unreachable by a wrong-tenant actor — the target resource was resolved from the verified
> JWT's own site/user relation rather than a client-supplied id, or the query was already scoped
> `where('user_id', $actor->id)`, or (SEC-10) a working-but-inline check already returned the correct
> 404. Verified per finding in review: for 8 of the 10 a cross-tenant test is *structurally impossible
> to write*, because there is no client-controlled id to attack.
>
> What the bundle buys is **defence-in-depth and doctrine consistency**: these call sites now run
> through the same Policy layer as every sibling action, so a future Policy-layer change (the dormant
> fresh-AAL2 gate, a future staff or by-UUID access path) applies here automatically instead of
> silently exempting ten actions — and the codebase no longer has "looks gated, isn't" methods sitting
> beside properly gated ones, which is what produces a real bug the next time someone copies the wrong
> sibling as a template.
>
> **The trap this unit had to avoid:** `$this->authorize(...)` calls `Gate::forUser(null)` under
> Supabase JWT and **silently passes** — a gate written that way looks exactly like a fix and enforces
> nothing. All eight gates use `authorizeForUser($pro, ...)` with the JWT-resolved user. Review proved
> the tests catch the trap by temporarily swapping one gate to `authorize()` and confirming the test
> then failed.

- [x] **`user-api/SEC-1`** · P2 · S — `UserDocumentController::store` writes without a Policy gate → `sources/user-api.md`
  - Fixed — skeleton `SitePolicy::create` via `(new SiteMedia)->site()->associate($site)`, which sets both the `site_id` attribute and the relation the policy cross-checks.
- [x] **`user-api/SEC-2`** · P2 · S — `UserSectionBlockController` write endpoints skip the Policy gate (upsert, reorder, remove) → `sources/user-api.md`
  - Fixed on all three — `Block` skeleton carrying `user_id` + `site_id` (both verified `$fillable`).
- [x] **`user-api/SEC-3`** · P2 · S — `UserWorkplaceController` write endpoints skip the Policy gate → `sources/user-api.md`
  - Fixed on all three (upsert/destroy/setPreviousWebsite) by gating the parent `$site`. **This indirection is required, not a shortcut:** `Workplace` has no `user_id` column at all — its PK is `site_id`, so `SitePolicy::resolveOwnerId()` against a bare `Workplace` returns null and always denies. Workplace ownership *is* Site ownership.
- [x] **`user-api/SEC-4`** · P2 · S — `CustomDomainController` write endpoints skip the Policy gate (store, verify, setPrimary, destroy) → `sources/user-api.md`
  - **`no_change_needed` — already fixed by commit `7b68bda5` ("SEC-108") earlier the same day, from a different audit run.** All five call sites verified gated in current code. Third independent confirmation that Gate A scanned a stale snapshot.
- [x] **`user-api/SEC-5`** · P2 · S — `UserServiceCategoryController::reorder` skips the Policy gate → `sources/user-api.md`
  - Fixed — `ServicePolicy::update`, integrated cleanly with the `ResolveCurrentSite` + `$site->touch()` closure this method gained in B1.
- [x] **`user-api/SEC-6`** · P2 · S — `UserServiceController::reorder` / `reorderLayout` skip the Policy gate → `sources/user-api.md`
  - Fixed on both, same integration with B1's changes. Review confirmed the gate runs *before* the write and the touch closure still fires.
- [x] **`user-api/SEC-7`** · P2 · S — `SectorController::update` skips the Policy gate → `sources/user-api.md`
  - **`no_change_needed` — already fixed by `7b68bda5` ("SEC-105").** Same stale-snapshot cause as SEC-4.
- [x] **`user-api/SEC-8`** · P2 · S — `HandleReclaimController::store` skips the Policy gate → `sources/user-api.md`
  - Fixed — added `ResolveCurrentSite` + `SitePolicy::update`.
- [x] **`user-api/SEC-9`** · P2 · S — `UserEnquiryController::destroy` skips the Policy gate → `sources/user-api.md`
  - Fixed — `EnquiryPolicy::delete`. This was the one method in the file that bypassed the `transition()` helper's own gate.
- [x] **`user-api/SEC-10`** · P2 · S — `ContentController::destroyUpload` uses an inline ownership check instead of the Policy → `sources/user-api.md`
  - CI already fails the build on inline 403 aborts in controllers; this one predates or evades that check.
  - Fixed — inline `site_id` comparison replaced by `authorizeForUser($pro, 'delete', $upload)`. **The only one of the ten with a real IDOR vector to test**, and it has an end-to-end cross-tenant 404 test. Review confirmed `setRelation` cannot inject a wrong site: the policy independently cross-checks the route-bound model's real `site_id`. The retained inline pool check is a genuine business rule (which media bucket the endpoint serves), not disguised ownership.

### Bundle B6 — Policy gate + PII gating: staff API · **P2** · Effort S–M

**Models:** plan=Sonnet · impl=Sonnet · review=Sonnet

Same theme as B5 on the staff surface, plus staff-visible third-party PII with no admin gate.
All of this sits behind `require.aal2` + a `core.partna_staff` row, which is why it caps at P2.

> **Honest enforcement summary (from review).** The gates that deny something *new*: `staffManage`
> on the customer/service/category staff mutations (SEC-2), `StaffNotificationEmailPolicyController`
> writes (SEC-1), `StaffAccountDeletionController::initiate/cancel` (SEC-3), `StaffLinkBlockManagement
> ::store/reorder` (SEC-4), `StaffEmailSubscriberController::export` (PRIV-2), the workplace PII
> redaction (PRIV-1), and — after a review FAIL — `StaffSiteManagementController::update` (SEC-5). The
> eleven `staffView` additions (SEC-5) are audit seams only: `staffView` is a `return true` no-op, so
> they deny nobody today and exist so a future Policy-layer change applies here automatically. All the
> real gates have support-vs-admin tests; review proved they catch the `authorize()` no-op trap.

- [x] **`staff-api/SEC-5`** · P2 · M — Eleven endpoints across eight staff controllers omit the Policy-layer role gate the route group's own convention requires → `sources/staff-api.md`
  - Eleven `staffView` seams added (deny nobody — audit-trail consistency). **Review caught one genuine defect:** `StaffSiteManagementController::update` (a mutating admin action — rename with force-publish + subdomain override) was gated with the `staffView` no-op instead of `staffManage`, so at the Policy layer a support actor passed as readily as an admin. No live bypass (the `staff.admin` middleware still blocked it), but it silently opted out of the defence-in-depth every sibling mutation has. Fixed to `staffManage` + a support-vs-admin denial test.
- [x] **`staff-api/SEC-2`** · P2 · M — Customer/Service/ServiceCategory staff controllers authorize the *professional*, not the *staff actor* → `sources/staff-api.md`
  - The gate answered the wrong question — a professional always owns their own rows, so authorizing the professional was tautologically true. Now gates the staff *actor* via `staffManage` on all mutations; reads (`show`/`index`) stay on `staffView` so support staff aren't blocked from records they can see today.
- [x] **`staff-api/SEC-1`** · P2 · S — `StaffNotificationEmailPolicyController` has zero Policy-layer authorization on all four methods → `sources/staff-api.md`
  - Added `staffView`/`staffManage`; added a `NotificationPolicy::staffView` ability (policy was already registered).
- [x] **`staff-api/SEC-3`** · P2 · S — `StaffAccountDeletionController` reads raw `input()` instead of `validated()`; `initiate()`/`cancel()` have no Policy gate → `sources/staff-api.md`
  - Gated `initiate`/`cancel` on `staffManage`; `input()` → `validated()` verified to drop no field; added a `StaffCancelDeletionRequest` rather than blind-casting a plain `Request`.
- [x] **`staff-api/SEC-4`** · P2 · S — `StaffLinkBlockManagementController::index()/store()` lack authorization while siblings have it → `sources/staff-api.md`
  - `index` → `staffView`, `store`/`reorder` → `staffManageBlock`. Also caught that `reorder()` had *no* gate (the audit claimed it matched its siblings — it didn't).
- [x] **`staff-api/PRIV-2`** · P2 · S — Staff email-subscriber list and CSV export expose third-party PII to any staff member, no admin gate → `sources/staff-api.md`
  - `export()` (unbounded CSV dump) gated admin-only; `index()` (paginated, individually audit-logged) left ungated. Both routes are already in `RecordStaffAuditEntry::PII_READ_ROUTE_NAMES`.
- [x] **`staff-api/PRIV-1`** · P2 · S — `StaffWorkplaceController` returns phone and address to all staff, no admin PII gate → `sources/staff-api.md`
  - All nine address/geo/phone fields redacted for support, visible for admin. (Weaker PII than it sounds — once the workplace section is published, these fields are already public on the sitepage; the gate matters for the unpublished case.)
- [x] **`requests-resources/PRIV-1`** · P2 · S — `StaffFeedbackResource` exposes the submitting professional's email to every staff member, bypassing the `$showPii` gate pattern → `sources/requests-resources.md`
  - **Declined — won't-fix (Josh, 2026-07-20). Product decision, not a code fix.** Support needs the submitter's email to follow up on a bug report when the separate `reply_email` field is blank. An existing test (`StaffFeedbackListTest`) asserts a *support*-role staffer sees this email, and the resource docblock says staff need full context across all users' submissions. Redacting it would break a documented, tested workflow with no replacement mechanism.
- [x] **`staff-api/SEC-6`** · P3 · S — `StaffMeController::show()` returns `supabase_uid` without a Policy gate (self-referential, low value) → `sources/staff-api.md`
  - Trivial `PartnaStaffPolicy::view` gate.

### Bundle B7 — PII minimisation in logs and audit rows · **P2** · Effort S–M

**Models:** plan=Sonnet · impl=Sonnet · review=Sonnet

Ten findings, six runs, one theme: identifiers written to logs or audit tables raw where the
codebase's own hash-before-log pattern exists elsewhere in the same file.

- [x] **`webhooks-internal/PRIV-1`** · P2 · M — CSP report sink logs raw IP/UA/URL to Nightwatch with no redaction, on an unauthenticated internet-reachable endpoint → `sources/webhooks-internal.md`
  - **Fixed.** `CspReportController`: raw `ip` → `ip_hash` via `HashesClientData::hashIp` (HMAC-SHA256); `normalise()` strips query strings from `document-uri`/`blocked-uri` (all key casings), keeping scheme+host+path; CSP sentinels (`inline`/`eval`/`self`) pass through untouched. Auth/throttle unchanged.
- [x] **`authz-core/PRIV-1`** · P2 · M — Auth-factor audit log stores raw IP and User-Agent with no minimisation → `sources/authz-core.md`
  - **Fixed with a schema-forced deviation (verified in review).** `audit.auth_factor_events.ip` is Postgres `inet` — an HMAC hex string is NOT valid inet and would insert-FAIL on Postgres while passing SQLite (the string-literal trap). So new rows write `ip = NULL` and fold the HMAC into the existing `metadata` jsonb (`metadata.ip_hash`); `user_agent` → browser/platform summary. New shared trait `App\Support\Concerns\MinimisesClientData` (`hashClientIp` HMAC-SHA256 via `app.key`, `summariseUserAgent`); `TokenRevocationService` left unchanged (minimal blast radius). Additive only, no migration. `countRecentFailures` keys on user_id+factor_id (not ip/ua) — confirmed safe.
- [x] **`outbound-ssrf/PRIV-1`** · P2 · M — Original image/video uploads stored verbatim without EXIF/GPS stripping → `sources/outbound-ssrf.md`
  - **Deferred to a dedicated media-pipeline task (Josh, 2026-07-21).** Exposure analysis: public variants are already EXIF-clean (GD re-encode drops it); only the private-ACL original backup (not publicly served, kept for re-processing) retains EXIF. The GD re-encode fix would recompress/degrade the DR original, and video needs a separate `ffmpeg -map_metadata` remux — M-effort on the hot upload path for a low-exposure private backup. Not folded into a log-hygiene bundle.
- [x] **`public-surface/PRIV-4`** · P2 · S — Bootstrap collision path logs a real user's raw email to persistent structured logs → `sources/public-surface.md`
  - **`no_change_needed` — already fixed by `4559cecc` (2026-07-20, "hash identifiers before logging — SEC-5/SEC-102").** `BootstrapController` already logs `email_hash`; no raw `email` remains in that log call. Confirmed by `git blame` in review — genuinely pre-existing, not a same-session edit.
- [x] **`public-surface/PRIV-1`** · P2 · S — Newsletter signup infers and stores a subscriber's real name from their email address without consent → `sources/public-surface.md`
  - **Won't-fix (Josh, 2026-07-21).** The name-inference is a deliberately-built feature; kept. Recorded as a product decision, not a code defect (same class as B6 `requests-resources/PRIV-1`).
- [x] **`public-surface/PRIV-2`** · P2 · S — `PublicCustomerLeadController` stores a raw unbounded User-Agent, inconsistent with its own `logLead()` → `sources/public-surface.md`
  - **Landed early (commit `51e7d1a9`, Josh).** UA capped via `AnalyticsEventSanitizer::userAgent` in `upsertMarketingSubscription`. Verified correct in the P2 session.
- [x] **`public-surface/PRIV-3`** · P2 · S — RUM beacon logs a professional's handle unhashed while hashing every other identifying field in the same call → `sources/public-surface.md`
  - **Fixed.** `AnalyticsController::rum` now logs `hash('sha256', strtolower($handle))`.
- [x] **`requests-resources/PRIV-2`** · P3 · S — Analytics endpoints store the raw referrer URL with no query-string minimisation → `sources/requests-resources.md`
  - **`no_change_needed` — already fixed.** `AnalyticsController::buildEvent()` funnels every beacon through `AnalyticsEventSanitizer::referrer()` (strips query string + fragment) in the write path; the validators intentionally still accept a raw referrer so beacons don't 422. Pre-existing (`2ad3d7cb`). Verified in review.
- [x] **`requests-resources/PRIV-3`** · P3 · S — `PublicCustomerLeadRequest` trims but doesn't `strip_tags` the notes field, unlike the sibling public-form pattern → `sources/requests-resources.md`
  - **Landed early (commit `51e7d1a9`, Josh).** `strip_tags` applied to `notes`, matching `PublicEnquiryRequest`. Verified correct in the P2 session.
- [x] **`edge-worker/PRIV-1`** · P3 · S — Worker error logs include the raw handle/hostname/URL in structured fields → `sources/edge-worker.md`
  - **Deferred to the B13 Worker session** (same file `cloudflare-worker/src/index.js`, same single deploy path; P3, the handle is the visitor's own public URL already visible to Cloudflare on every request). Tracked with B13's Worker changes rather than touching the Worker in a log-hygiene bundle.

### Bundle B8 — Retention windows that never prune · **P2** · Effort M

**Models:** plan=Sonnet · impl=Sonnet · review=Sonnet

The *Waitlist retirement* work (2026-07-19) established the pattern: a table with PII needs a
scheduled prune **and** export/purge wiring, guarded by `DataExportCoverageTest`. These are the
tables that still lack it.

- [x] ⏸ **`models-data/PRIV-2`** · P2 · M — `UserDeletionAuditEntry.professional_email_snapshot` has no retention bound or scheduled purge → `sources/models-data.md`
  - **Deferred to the pre-cutover schema window (Josh, 2026-07-21).** `audit.user_deletion_audit` is append-only for `app_backend` (SELECT/INSERT only; `20260527010000` revokes UPDATE/DELETE schema-wide). Pruning needs a new SECURITY DEFINER prune function like `audit.prune_handle_change_log()` (`20260718010000`) = a NEW migration, which `db push` applies to the live dev DB. Zero rows today (pre-pilot, ~7yr window). Author it with PRIV-3 as one consistent prune in the cutover window — see the `## Requires a schema change` table.
  - **⏸ Authored (UNPUSHED) 2026-07-22 · branch `audit-fix/gate-a-b8-cutover-2026-07-22`.** Josh's calls: **anonymise-in-place** (redact PII columns, keep the event row — the deletion/export record survives for compliance/fraud, only the personal data is bounded, mirroring `gdpr:prune-completed-exports`), **7-year** window, **shared SECURITY DEFINER** path. Migration `20260722010000_audit_pii_snapshot_retention_prune.sql` adds `audit.prune_user_deletion_audit()` (no trigger-disable needed — unlike `handle_change_log`, this table has only the schema-wide REVOKE, no append-only trigger). Command `audit:prune-pii-snapshots` (daily 03:55, floor 1yr), config `partna.audit.pii_retention_years` (default 7), test `PruneAuditPiiSnapshotsTest`, registered in `FunctionSearchPathTest`. **Not `db push`'d** — applied at cutover Phase 0 with B20.
- [x] ⏸ **`models-data/PRIV-3`** · P2 · M — `DataExportAudit.professional_email_snapshot` / `recipient_email` have no retention bound or scheduled purge → `sources/models-data.md`
  - **Deferred to the pre-cutover schema window (Josh, 2026-07-21).** `audit.data_export_audit` *does* have a `GRANT UPDATE` (`20260624`), so this one could anonymise via a migration-free scheduled UPDATE — but deferred with PRIV-2 to keep the sibling tables on one consistent prune mechanism rather than splitting them.
  - **⏸ Authored (UNPUSHED) 2026-07-22 · same branch/migration/command as PRIV-2.** Kept on the **shared SECURITY DEFINER** path (Josh) rather than the migration-free UPDATE split: `audit.prune_data_export_audit()` in the same `20260722010000` migration, called by the same `audit:prune-pii-snapshots` command. Redacts `recipient_email`/`professional_handle_snapshot` → `'[redacted]'`, `professional_email_snapshot`/`error_message` → NULL; event row kept. **Not `db push`'d** — cutover Phase 0.
- [x] **`wiring/PRIV-1`** · P2 · S — Feedback PII (email, handle, ip_hash) has no retention window or cleanup for non-deleted rows → `sources/wiring.md`
  - **`no_change_needed` — already shipped on this branch by `6ac34154` (2026-07-20, internal "PRIV-8"), default set to 90d by `905e04b9`.** `partna.feedback.retention_days` + `PruneOldFeedbackSubmissionsCommand` (batched hard-delete on `created_at`, `--dry-run`/`--days`, pgsql-pinned, logs counts only) + weekly Sunday schedule in `routes/console.php` + 5 tests. Verified in review.
- [x] **`gdpr-deletion-export/PRIV-3`** · P2 · S — `analytics.item_views` has no FK to `core.users`, so visitor IP/UA rows outlive an account by up to 90 days → `sources/gdpr-deletion-export.md`
  - **Fixed — code only, no migration.** Added `AccountDeletionService::purgeItemViewsPii()` (DELETE `analytics.item_views` WHERE `user_id = $professional->id`, pgsql-pinned, fault-tolerant), wired into `purge()` before `forceDelete()`, and added `analytics.item_views` to `PURGED_PII_TABLES`. `app_backend` already holds DELETE (used by `PurgeRawAnalyticsEvents`). Over-deletion guard test asserts a second professional's rows survive. Reviewed PASS on the irreversible path.
  - ~~⚠️ Schema change~~ — no migration needed; the DELETE grant already exists.

### Bundle B9 — Pre-account lifecycle: races and stuck builds · **P2** · Effort M

**Models:** plan=Opus · impl=Sonnet · review=Sonnet

`claim-and-provision` found **nothing above P3** — the claim race itself is well defended
(pinned transaction, `lockForUpdate`, savepoint-wrapped 23505 recovery, unique partial index).
These are the adjacent paths that lack the same rigour.

- [x] **`state-machines/LIFE-4`** · P2 · M — `PreAccountBuild` has no reconcile path for a build stuck in `pending`/`building`; only `failed` or 30-day expiry get swept → `sources/state-machines.md`
  - **Fixed.** New `builds:reconcile-stuck` command (hourly) marks builds stuck in pending/building past `partna.pre_account.stuck_build_sla_minutes` (default 30) as `failed`/`FAILURE_STUCK_TIMEOUT` — mirrors `CleanupStuckMediaProcessingCommand`'s "make state honest, don't re-dispatch from the sweep" design; re-trigger flows through `reserve()`, which was extended to re-dispatch a stale pending/building build (not just `failed`). SLA ≈ 3× the job's 300s timeout + 600s `ShouldBeUnique` TTL, so a fresh dispatch isn't dropped nor races a live job. No migration (`failure_code` is app-vocabulary, no CHECK).
- [x] **`state-machines/LIFE-3`** · P2 · M — `HandleAllocator::allocate()` has a check-then-insert race on `handle_lc`; the catch can't disambiguate the collision cause → `sources/state-machines.md`
  - **Fixed at the source (better than the audit's reactive-catch suggestion).** Provisional-user creation moved into a savepoint-retry helper (`createProvisionalUserWithRetry`/`tryCreateProvisionalUser`, 5 attempts, `report()`+throw on exhaustion) mirroring `SiteProvisioningService::tryCreateSite` — each attempt is its own nested pgsql transaction, so a `handle_lc` 23505 rolls back only that savepoint and the loop re-`allocate()`s the next suffix. This makes the existing outer catch's assumption (violation == `pre_account_builds_live_source_unique`) true by construction, so it needed no change. Postgres-gated savepoint test added.
- [x] **`state-machines/LIFE-2`** · P2 · S — Pre-account build IP abuse-cap is a check-then-act race with no lock → `sources/state-machines.md`
  - **Fixed.** The cap count-check moved inside the build transaction, preceded by `pg_advisory_xact_lock(hashtext("pre_account_build_ip:{ipHash}"))` — same idiom as `InsertWithSortOrder`/`ReorderService`; concurrent same-IP builds now serialize. Staff builds still skip.
- [x] **`cache-edge-reconcile/LIFE-2`** · P2 · S — Subdomain-rename lock covers `subdomain_changed_at` but not `subdomain` itself, letting a rapid double-rename drop an alias hop → `sources/cache-edge-reconcile.md`
  - **Fixed (isolated review — standalone).** The `lockForUpdate()` query now selects `subdomain` alongside `subdomain_changed_at`; `$site->subdomain`/`$current` derive from the locked row and the no-op check moved after the lock, so the intermediate subdomain's alias and `HandleChangeLog.old_handle` are correct under a double-rename. Cooldown (LIFE-5) ordering preserved. Regression test proves the fix (fails pre-fix). *Non-blocking follow-up: `$locked` could null-deref if the site row is hard-deleted mid-rename (narrow rename-vs-delete race; pre-existing failure mode in a different form).*

### Bundle B10 — Nightwatch blind spots: swallowed failures · **P2** · Effort S

**Models:** plan=Sonnet · impl=Sonnet · review=Sonnet *(combine plan+impl)*

`Log::error` without `report()` never reaches Nightwatch, and per the *Nightwatch Alert Model*
note alerts fire on **issues**, not log queries — so these failures are currently invisible.
All of them are on PII-erasure or deletion paths, where silent failure is the worst kind.

- [x] **`state-machines/LIFE-5`** · P2 · S — Seven PII-erasure sub-methods in `AccountDeletionService::purge()` log failures but never `report()` them → `sources/state-machines.md`
  - **Fixed.** Added `report($e)` alongside the existing `Log::error` in all seven named sub-methods **plus** `purgeItemViewsPii` (the 8th, added by B8 — same pattern). Additive only: log levels + swallow-and-continue fault tolerance unchanged. The pre-existing top-level `report()` precedents (Supabase-deletion, forceDelete) were undisturbed. A pre-existing strict-`Log`-mock test broke because `report()` cascades a 2nd `Log::error` via the framework handler in tests → fixed with `Exceptions::fake()` + an `assertReported` that strengthens it.
- [x] **`state-machines/LIFE-1`** · P2 · S — `AccountDeletionService::request()` swallows a transaction failure without `report()` → `sources/state-machines.md`
  - **Fixed.** Added `report($e)` before the 503 return, keeping the `Log::error`. New `AccountDeletionNightwatchReportingTest` proves both LIFE-1/LIFE-5 report (verified fail-before/pass-after in review).

### Bundle B11 — `$fillable` doctrine on tenant-owned models · **P2** · Effort M

**Models:** plan=Opus · impl=Sonnet · review=Sonnet

⚠️ Do NOT bundle with S4 (the L-effort eleven-model sweep). Per the *Fillable Tenancy-FK →
associate()* rule, trusted creation paths must move to `->relation()->associate()` **before** the
FK leaves `$fillable`, or creation silently breaks. `pre_account_builds.user_id` /
`built_by_staff_id` are already correctly non-fillable — follow that precedent.

- [x] **`preaccount-claim/SEC-2`** · P2 · M — `User::$fillable` includes server/staff-only lifecycle fields (status, deletion tokens, handle) → `sources/preaccount-claim.md`
  - **Fixed, with one scope refinement (Josh, 2026-07-21).** Removed `status`, the six deletion-lifecycle columns, and `admin_notes`; converted every trusted writer to `forceFill()`/direct assignment (the 4 `AccountDeletionService` deletion writes, `StaffUserController::update` admin_notes split, `tryCreateProvisionalUser`, `UserBootstrapService` both branches). **`handle`/`handle_lc` KEPT fillable (Josh):** the audit's "small diff" estimate was wrong — removal rippled to ~90 test files that create users via raw `User::create([...handle...])` (handle is `NOT NULL`, no default), for near-zero gain since the `/me` endpoint already excludes it and renames go through `RenameSubdomainAction::forceFill`. `account_type`/`primary_email` stay fillable (validated flows). Independent review (fresh-grep) PASS.
- [x] **`preaccount-claim/SEC-1`** · P2 · M — `user_id` is mass-assignable on four tenant-owned models → `sources/preaccount-claim.md`
  - **Fixed.** Removed `user_id` from `Customer`/`Service`/`ServiceCategory`/`UserConfirmationPreference`. Skeleton pre-create sites (`authorizeForUser`) → direct assignment; literal `::create` sites → relation `create()` (FK set outside mass-assignment). **Audit missed `ConfirmationPreferenceService::updateForProfessional`/`enableForProfessional`** (`updateOrCreate` INSERT path mass-assigns the search keys) — converted to `firstOrNew` + explicit `user_id`. Value-assertion test (`->fresh()->user_id`) added.
- [x] **`preaccount-claim/SEC-3`** · P2 · S — `UserDeletionAuditEntry::$fillable` includes spoofable actor/IP fields on an append-only compliance table → `sources/preaccount-claim.md`
  - **Fixed — the audit's "clean model-only change" framing was wrong.** `professional_email_snapshot` is `NOT NULL`; both writers (`logAuditEvent` + the PURGED create) mass-assign the removed fields, so a bare `$fillable` removal would 23502 on Postgres / silently drop on the trust-critical audit table. Converted both to `forceCreate([...])` (+ the `PurgePendingDeletionTest` fixture).
- [x] **`preaccount-claim/SEC-4`** · P2 · S — `PreAccountBuild::$fillable` includes state-machine fields the app never mass-assigns → `sources/preaccount-claim.md`
  - **Fixed — the audit missed 3 files of the write path.** Removed `build_state`/`claimed_at`/`failure_code`; converted `reserve()`, `ClaimSiteService::claim()`, the **five** `GeneratePreAccountSiteJob` state writes, and `ReconcileStuckPreAccountBuilds` to `forceFill` (`requestBuild`'s `new PreAccountBuild` sets `build_state` via direct assignment). A naive removal would have silently stalled every build in `pending` = flagship outage. 6 test-fixture files converted to `forceFill`; review verified exactly 5 job forceFill sites via fresh grep.

### Bundle B12 — Pre-claim scraping stores more third-party data than it renders · **P2** · Effort M

**Models:** plan=Opus · impl=Sonnet · review=Sonnet

Provisional (`unclaimed`) users have not consented to anything. Both generators persist the full
vendor payload when the unclaimed sitepage renders only a few fields.

- [x] **`preaccount-claim/PRIV-1`** · P2 · M — Google Business generator spreads full mapped Place Details (including reviewer names/photos) into a provisional user's payload → `sources/preaccount-claim.md`
  - **Fixed durably (the audit's premise was corrected in planning).** Exposure isn't gated by claim status — unclaimed+claimed sites share the render pipeline and `PublicIntegrationConnectionResource` already serves reviews/photos; it's gated by `is_published`, so the real risk is staff/ManyChat `publish=true` builds. New `GoogleBusinessPayload::stripThirdPartyPii()` removes the `reviews` array and `photos[].authors` (keeps rating/reviewCount and everything else), applied in `GoogleBusinessSourceGenerator` AND — critically, since google-business is refreshable on a 2-day TTL — in `GoogleBusinessFetch::fetch()` gated on `user()->value('status') === 'unclaimed'`. That gate self-heals on claim (status flips → next refresh restores full data, no ClaimSiteService change) and never strips an `active` user's data (parity test proves it). Authenticated connect/enrich never call the helper.
- [x] **`preaccount-claim/PRIV-2`** · P2 · M — Instagram scraper stores third-party profile data for a not-yet-consenting provisional user → `sources/preaccount-claim.md`
  - **Fixed with a narrowed scope (the audit's literal "trim to name/bio/avatar" was rejected).** The IG payload has NO third-party PII (no reviewers/followers-as-PII) and is NOT refreshable, so gutting `images`/`videos`/counts would break the deliberate WYSIWYG preview for zero privacy gain. Instead `InstagramSourceGenerator` drops only `bioLinks`/`syncFindings`/`unmatched` (never in the public allowlist → zero render impact) via a post-seed `saveQuietly` — the shared `InstagramConnectionSeeder::seed()` is untouched, IdentitySync already fired on `seed()`. Kept the `IdentitySync`-via-observer contract intact; narrowed what's written, didn't bypass the machinery.

### Bundle B13 — Cloudflare Worker hardening · **P2** · Effort S–M

**Models:** plan=Sonnet · impl=Sonnet · review=Sonnet
**Risk gate: Cloudflare Worker / KV — sign-off required.**

The Worker returned no P0/P1 — its read path is sound. These are defence-in-depth.

- [x] **`edge-worker/EDGE-2`** · P2 · S — Visitor `Cookie`/`Authorization` headers forwarded unfiltered to the sitepage origin → `sources/edge-worker.md`
- [x] **`edge-worker/EDGE-1`** · P2 · S — `Vary` header from origin isn't sanitized before the response is written to the edge cache → `sources/edge-worker.md`
- [x] **`edge-worker/CFG-3`** · P2 · M — Reserved-subdomain list is a manual, unenforced mirror of `config/partna.php` → `sources/edge-worker.md`
  - Add a CI diff check between the config list and the JS `RESERVED` set; no runtime fetch.
- [x] **`edge-worker/CFG-1`** · P3 · S — Cache TTLs are hardcoded constants, not environment-configurable → `sources/edge-worker.md`
- [x] **`edge-worker/CFG-2`** · P3 · S — Production domain hardcoded with a half-wired staging env already in the repo → `sources/edge-worker.md`
  - **Closed WON'T-FIX (2026-07-21):** stale premise. The `[env.staging]` block the finding assumed was already removed from `wrangler.toml` (placeholder KV namespace ids, EDGE-102), so `PARTNA_DOMAIN = "partna.au"` being hardcoded is correct — there is no second domain to parameterise for. If staging ever ships with a distinct domain, re-add `[env.staging]` + an env-var override then. Verified in B13 recon.

### Bundle B14 — Public route and ingest hardening · **P2** · Effort S–M

**Models:** plan=Sonnet · impl=Sonnet · review=Sonnet

- [x] **`wiring/SEC-2`** · P2 · M — Analytics ingest endpoints validate no site/subdomain ownership, only rate limit → `sources/wiring.md`
- [x] **`wiring/SEC-1`** · P2 · S — Early-access marketing form is the only public mutation route with no bot-token gate → `sources/wiring.md`
- [x] **`public-surface/SEC-1`** · P2 · S — Public unauthenticated endpoint returns the Google Maps API key, relying solely on provider-side referrer restriction → `sources/public-surface.md`
- [x] **`wiring/CFG-1`** · P2 · S — Nightwatch enabled by default but the token has no explicit fallback or production guard → `sources/wiring.md`
- [x] **`wiring/CFG-2`** · P3 · S — Horizon dashboard domain unset, so `/horizon` is reachable from every visitor subdomain → `sources/wiring.md`

### Bundle B15 — Outbound HTTP hardening · **P2** · Effort S–M

**Models:** plan=Sonnet · impl=Sonnet · review=Sonnet

The June 3rd SSRF fix (`SafeUrlFetcher` + host allowlist + image-only content-type) **held** —
this run found no regression. These are the remaining rough edges.

- [x] **`outbound-ssrf/EDGE-1`** · P2 · M — Outbound Cloudflare and streaming-API calls have no explicit HTTP timeout (six `Http::` calls) → `sources/outbound-ssrf.md`
- [x] **`outbound-ssrf/SEC-1`** · P3 · S — `CloudflarePurgeService` interpolates handle and product-handle into purge URLs without URL-encoding → `sources/outbound-ssrf.md`
- [x] **`outbound-ssrf/CFG-1`** · P3 · S — OAuth token URLs hardcoded in `StreamingTokenManager` while sibling clients use config → `sources/outbound-ssrf.md`
- [x] **`outbound-ssrf/CFG-2`** · P3 · S — `SafeUrlFetcher` User-Agent strings hardcoded with no config override → `sources/outbound-ssrf.md`
- [x] **`outbound-ssrf/CFG-3`** · P3 · S — `CloudflarePurgeService` hardcodes deep-link enumeration caps as literals → `sources/outbound-ssrf.md`

### Bundle B16 — Pin bare `DB::transaction()` to the pgsql connection · **P3** · Effort S ×6

**Models:** plan=Sonnet · impl=Sonnet · review=Sonnet *(combine plan+impl — mechanical)*

Six sites, one mechanical change. `BaseModel` forces the pgsql connection for models, but a bare
`DB::transaction()` resolves the *default* connection — which is SQLite under test. Cheap to fix,
and it removes a whole class of test-vs-prod divergence.

- [x] **`claim-and-provision/TXN-4`** · P3 · S — `RenameSubdomainAction`'s concurrent-rename row lock reads the default connection, not the pinned one its own contract requires → `sources/claim-and-provision.md`
- [x] **`claim-and-provision/TXN-1`** · P3 · S — `ConfirmationPreferenceService:58-70` → `sources/claim-and-provision.md`
- [x] **`claim-and-provision/TXN-2`** · P3 · S — `InsertWithSortOrder:21-31` (advisory-lock insert) → `sources/claim-and-provision.md`
- [x] **`claim-and-provision/TXN-3`** · P3 · S — `ReorderService:24-50` (advisory-lock reorder) → `sources/claim-and-provision.md`
- [x] **`state-machines/LIFE-6`** · P3 · S — `SendAccountDeletionRequestMailJob:65-83` dedup check → `sources/state-machines.md`
- [x] **`state-machines/LIFE-7`** · P3 · S — `ExportUserDataJob:111-115` email-dedup check → `sources/state-machines.md`

### Bundle B17 — Cache-layer helper hygiene · **P3** · Effort S

**Models:** plan=Sonnet · impl=Sonnet · review=Sonnet *(combine plan+impl)*

Do **after** B1 — B1 changes the invalidation call sites these helpers would wrap.

- [x] **`cache-invalidation/CCH-2`** · P3 · S — No shared `:stale`-key helper; the suffix is hand-concatenated at every invalidation site → `sources/cache-invalidation.md`
- [x] **`cache-invalidation/CCH-1`** · P3 · S — `ServiceCategoryObserver` bypasses the cache-service layer for services-key invalidation → `sources/cache-invalidation.md`
- [x] **`cache-invalidation/TXN-1`** · P3 · S — Inconsistent `->afterCommit()` usage on job dispatches inside `SiteObserver::saved()` → `sources/cache-invalidation.md`
- [x] **`claim-and-provision/CCH-1`** · P3 · S — Idempotency purge index key duplicated as a hardcoded string instead of a shared helper → `sources/claim-and-provision.md`
- [x] **`webhooks-idempotency/CCH-1`** · P2 · S — JWKS-outage throttle lock relies on the default cache store instead of pinning `cache_locks` → `sources/webhooks-idempotency.md`
- [x] **`webhooks-idempotency/WHK-2`** · P2 · S — Email-hook dedup TTL hardcoded 300s, duplicated rather than shared with the verifier's replay window → `sources/webhooks-idempotency.md`
- [x] **`webhooks-internal/CFG-1`** · P3 · S — Coupled hardcoded webhook timestamp tolerance not sourced from shared config (two 300 literals) → `sources/webhooks-internal.md`
- [x] **`webhooks-internal/CFG-2`** · P3 · S — Idempotency-cache TTL config read has no inline fallback, inconsistent with siblings in the same controller → `sources/webhooks-internal.md`

### Bundle B18 — Config extraction sweep · **P3** · Effort S–M

**Models:** plan=Sonnet · impl=Sonnet · review=Sonnet *(combine plan+impl)*

`config/partna.php` is the canonical home for all Partna limits and flags. These are literals
that should live there. Purely mechanical — do last.

- [x] **`authz-core/SEC-1`** · P3 · S — `EnsurePartnaAdmin`'s staff-lookup fallback is dead code given the app's fixed middleware order → `sources/authz-core.md`
  - Delete the fallback query; treat a missing `partna_staff` attribute as a hard failure. Verify the middleware ordering claim against `bootstrap/app.php` before deleting.
- [x] **`authz-core/CFG-1`** · P3 · S — Hardcoded HTTP timeouts in `SupabaseAdminService` instead of the existing shared config key → `sources/authz-core.md`
- [x] **`authz-core/CFG-2`** · P3 · M — Rate limits hardcoded in `AppServiceProvider::configureRateLimiting()` → `sources/authz-core.md`
- [x] **`authz-core/CFG-3`** · P3 · M — Platform refresh intervals hardcoded in `PlatformRegistryServiceProvider` → `sources/authz-core.md`
- [x] **`user-api/CFG-1`** · P3 · S — Analytics date-range clamping constants hardcoded → `sources/user-api.md`
- [x] **`user-api/CFG-2`** · P3 · S — `SERIES_DAYS` hardcoded in the dev-insights controller → `sources/user-api.md`
- [x] **`user-api/CFG-3`** · P3 · M — Pagination and query-limit defaults hardcoded across dashboard list endpoints → `sources/user-api.md`
- [x] **`staff-api/CFG-1`** · P3 · S — Hardcoded cache TTL in `StaffAggregateAnalyticsController` → `sources/staff-api.md`
- [x] **`staff-api/CFG-2`** · P3 · S — Hardcoded cache TTL in `StaffStatsController` → `sources/staff-api.md`
- [x] **`staff-api/CFG-3`** · P3 · S — Magic number for user-agent truncation in `StaffSiteManagementController` → `sources/staff-api.md`
- [x] **`public-surface/CFG-1`** · P3 · S — Public subscription list-key allowlist lives in `config/subscriptions.php` instead of `config/partna.php` → `sources/public-surface.md`
- [x] **`public-surface/CFG-2`** · P3 · S — QR code size, margin, and cache lifetime hardcoded → `sources/public-surface.md`

### Bundle B19 — Migration hygiene (non-blocking) · **P2/P3** · Effort M

**Models:** plan=Opus · impl=Sonnet · review=Sonnet
**Risk gate: DB/migration — sign-off required.** Do **after** S1/S2 and B2.

Several of these are explicitly "accept the exemption, apply the pattern going forward" rather
than edits — read each source entry before changing anything.

**⚠️ PROMOTED 2026-07-22 (Josh): the 7 P2 migration-safety items below** (`migrations-early/MIG-3, MIG-4, MIG-5, MIG-6` and `migrations-recent/MIG-5, MIG-6, MIG-7`) **are routed to the fresh-db-concurrently-pipeline session** (`docs/superpowers/plans/2026-07-21-fresh-db-concurrently-pipeline-PROMPT.md`) — the session that owns `scripts/guard-no-unsafe-migrations.php` and the fresh-apply / cutover path, same home as `discovered/DISC-1`. They stay `[ ]` here and are worked + tracked THERE. B19's remaining **P3** items (`migrations-early/MIG-7`, `migrations-recent/MIG-8`, `pii-schema/SCHEMA-4/5/6`) stay in gate-a. (MIG-7/MIG-8 — the P3 `lock_timeout` guard gap — are the same theme as the promoted P2s; left in gate-a per the "P2s" scope, promote too if desired.)

- [x] **`migrations-early/MIG-3`** · P2 · M — Inline CHECK on `site.sites.skeleton_id` validates existing rows under `ACCESS EXCLUSIVE` → `sources/migrations-early.md` — ✅ 2026-07-22: documented in `CONVENTIONS.md §2` (inline column CHECK). Historical file not rewritten — 0-row validation at the empty-DB cutover.
- [x] **`migrations-early/MIG-4`** · P2 · S — Unqualified `DROP FUNCTION` leaves an orphaned trigger referencing a dropped column → `sources/migrations-early.md` — ✅ 2026-07-22: documented in `CONVENTIONS.md §7` (schema-qualify DROP FUNCTION/TRIGGER). Historical instance already closed by `20260527070001`; no concurrent traffic between two sequential applies at cutover.
- [x] **`migrations-early/MIG-5`** · P2 · M — Full-table `UPDATE` backfills run inside migration transactions instead of being extracted (5 files) → `sources/migrations-early.md` — ✅ 2026-07-22 (no_change): convention already documented at `CONVENTIONS.md §5`; the 5 historical files are grandfathered and scan 0 rows at the empty-DB cutover.
- [x] **`migrations-early/MIG-6`** · P2 · S — `NOT VALID` + `VALIDATE` bundled into one long transaction spanning six unrelated fixes, including hot table `site.site_media` → `sources/migrations-early.md` — ✅ 2026-07-22: guard **Check 8** now catches this shape on new files; historical file grandfathered (empty-DB cutover), not rewritten.
- [x] **`migrations-recent/MIG-6`** · P2 · S — Non-CONCURRENTLY unique index build justified only by dev's row count, not the prod re-baseline → `sources/migrations-recent.md` — ✅ 2026-07-22 (accept): `20260704140000` already carries `-- guard:no-unsafe-migrations:disable-file` with a dev-scoped justification. The empty-DB fresh-baseline cutover moots the "prod holds acct-% rows" premise — the file re-applies against a table with zero matching rows. Not rewritten.
- [x] **`migrations-recent/MIG-7`** · P2 · S — Design-kit rework migrations drop hot-table columns with no transaction wrapper and no documented rollback (5 files) → `sources/migrations-recent.md` — ✅ 2026-07-22 (accept as documented exemption): the 5 files carry an explicit "test users only — destructive drops are sanctioned" justification; at the empty-DB cutover the remap `UPDATE`s touch 0 rows, so no half-remap is possible. Applied files not rewritten (Josh's standing decision).
- [x] **`migrations-recent/MIG-5`** · P2 · S — `VALIDATE CONSTRAINT` in the same transaction as `ADD CONSTRAINT NOT VALID`, wasting the two-step optimisation → `sources/migrations-recent.md` — ✅ 2026-07-22: guard **Check 8** (VALIDATE deferred to a separate txn) + `CONVENTIONS.md §2` note; historical file grandfathered, not rewritten.
- [x] **`migrations-early/MIG-7`** · P3 · M — Missing `SET LOCAL lock_timeout`/`statement_timeout` guards on DDL touching live-traffic tables (~50 files) → `sources/migrations-early.md` — ✅ 2026-07-22: forward-only policy documented in `CONVENTIONS.md §8`; guard **Check 5** enforces it for new files. No retrofit — historical files have no lock contention at the empty-DB cutover.
- [x] **`migrations-recent/MIG-8`** · P3 · M — Same guard gap across 30+ recent files; consider a runner-level default → `sources/migrations-recent.md` — ✅ 2026-07-22: same disposition as `migrations-early/MIG-7` — `CONVENTIONS.md §8` + guard Check 5 enforce forward; no retrofit.
- [x] **`pii-schema/SCHEMA-4`** · P3 · S — `CREATE UNIQUE INDEX` without CONCURRENTLY on live `site.platform_connections` (accept exemption) → `sources/pii-schema.md` — ✅ 2026-07-22 (accept as-is, per the audit's own instruction): `20260704140000` carries `disable-file` documenting zero acct-% rows; empty-DB cutover ⇒ 0-row index scan. Same file as `migrations-recent/MIG-6`.
- [x] **`pii-schema/SCHEMA-5`** · P3 · S — `ADD CONSTRAINT CHECK` without `NOT VALID` + separate `VALIDATE` on two pre-beta tables (accept exemption) → `sources/pii-schema.md` — ✅ 2026-07-22 (accept as-is): both `20260714200000` and `20260718200000` carry `disable-file` with a pre-beta row-count rationale (10 rows / near-empty); 0-row scan at the empty-DB cutover.
- [x] **`pii-schema/SCHEMA-6`** · P3 · S — `core.users` unique-index rebuild without CONCURRENTLY (accept as-is) → `sources/pii-schema.md` — ✅ 2026-07-22 (accept as-is): `20260718200000`'s own header documents the momentary ACCESS EXCLUSIVE on a near-empty `core.users`; file already `disable-file`-exempt (this is also the DROP INDEX pair grandfathered in Check 7's note). 0-row at the empty-DB cutover.

### Bundle B20 — Schema: RLS gaps and column defaults · **P2** · Effort S

**Models:** plan=Opus · impl=Sonnet · review=Sonnet
**Risk gate: RLS + migration — sign-off required.** ⚠️ **Schema change** — see list below.

- [x] **`pii-schema/SCHEMA-1`** · P2 · S — `site.workplaces` tenant-data table created without RLS → `sources/pii-schema.md`
- [x] **`pii-schema/SCHEMA-2`** · P2 · S — `site.content_selection` tenant-data table created without RLS → `sources/pii-schema.md`
- [x] **`pii-schema/SCHEMA-3`** · P2 · S — `site.menu_platform_links` and `site.menu_item_platforms` UUID PKs lack a DB-side `gen_random_uuid()` default → `sources/pii-schema.md`
- [x] **`user-api/SCHEMA-1`** · P2 · M — `updateOrInsert` on `site.design_kits` is not atomic; a missing row can still race under concurrent first-time writes → `sources/user-api.md`
  - The `trg_create_empty_design_kit` trigger should make this unreachable — verify the premise before adding a backfill migration.
- [x] **`staff-api/SCHEMA-1`** · P3 · M — `core.users` staff search lacks `pg_trgm` indexes for `ILIKE '%term%'` queries → `sources/staff-api.md`

### Bundle B21 — Test/prod parity · **P2** · Effort S

**Models:** plan=Sonnet · impl=Sonnet · review=Sonnet *(combine plan+impl)*

The single parity finding from three runs over ~700 KB. All three parity runs came back
essentially clean, which is the verdict on the defect class behind both Instagram incidents.

- [x] **`parity-jobs/PARITY-1`** · P2 · S — `pre_account_builds.user_id` is NOT NULL in prod but nullable in the SQLite test seed, and the factory never sets it → `sources/parity-jobs.md`
  - Change `user_id TEXT NULL` to NOT NULL in `tests/Pest.php`'s stub table.

---

## Requires a schema change — lead time before cutover

These cannot be shipped as code alone. Migration edits must land **before** the replay;
new-migration items should be authored and reviewed before the cutover window opens.

| Item | Kind | Note |
|---|---|---|
| `migrations-early/MIG-1`, `MIG-2` (S1, S2) | **Edit unapplied migration** | Must land before replay. MIG-2 needs the file split; both blocked on the CONCURRENTLY/CLI question. |
| `migrations-recent/MIG-1`–`MIG-4` (B2) | **Edit unapplied migration** | Must land before replay. Half-applied risk is the unrecoverable failure mode. |
| B19 — all items | **Edit unapplied migration** | Non-blocking, but same window. |
| `pii-schema/SCHEMA-1`, `-2`, `-3` (B20) | **New migration** | RLS enablement + column defaults. |
| `user-api/SCHEMA-1` (B20) | **New migration** | Backfill for missing `design_kits` rows — verify the trigger premise first. |
| `staff-api/SCHEMA-1` (B20) | **New migration** | `pg_trgm` extension + GIN indexes, `CONCURRENTLY`. |
| `gdpr-deletion-export/PRIV-3` (B8) | **New migration or purge step** | `analytics.item_views` has no FK to `core.users`. |

## Known limits of this gate

Recorded so a future reader doesn't over-read the clean results.

1. **`pii-schema` breached the recall threshold.** 415 KB / ~104K tokens, and the scanner emitted
   `WARNING: payload exceeds ~100K tokens — scan recall degrades`. Its clean P0/P1 result is the
   least trustworthy in the set. `campaigns.md` claims every scope group is measured under
   350 KB; that one is not, and the scope should be split before it is re-run.
2. **Zero-finding results are weaker evidence than positive ones.** Payloads were verified as
   genuinely scanned, but "no constructible failure found" is not "correct".
3. **Gate B is untouched.** The parity verdict is well-supported for core write paths and
   **unproven** for the scraper write paths in `app/Services/Platforms/` — where both Instagram
   incidents actually happened. `platforms-svc-a`/`-b` are deferred to pre-launch.
4. **Large scopes detect less.** `authz-core` (274 KB) and `wiring` (262 KB) both returned clean
   at P0/P1; per the measured recall curve, a clean result on a large scope is weaker evidence
   than one on a small scope. Read those as "no obvious hole", not "proven correct".

## Source runs

| Run | Findings | P0 | P1 | Source |
|---|---|---|---|---|
| preaccount-claim | 8 | 0 | 1 | `sources/preaccount-claim.md` |
| public-surface | 10 | 0 | 1 | `sources/public-surface.md` |
| authz-core | 5 | 0 | 0 | `sources/authz-core.md` |
| user-api | 15 | 0 | 1 | `sources/user-api.md` |
| requests-resources | 5 | 0 | 1 | `sources/requests-resources.md` |
| staff-api | 12 | 0 | 0 | `sources/staff-api.md` |
| webhooks-internal | 3 | 0 | 0 | `sources/webhooks-internal.md` |
| models-data | 5 | 0 | 1 | `sources/models-data.md` |
| claim-and-provision | 5 | 0 | 0 | `sources/claim-and-provision.md` |
| cache-invalidation | 5 | 0 | 2 | `sources/cache-invalidation.md` |
| webhooks-idempotency | 3 | 0 | 1 | `sources/webhooks-idempotency.md` |
| edge-worker | 6 | 0 | 0 | `sources/edge-worker.md` |
| wiring | 5 | 0 | 0 | `sources/wiring.md` |
| outbound-ssrf | 6 | 0 | 0 | `sources/outbound-ssrf.md` |
| state-machines | 7 | 0 | 0 | `sources/state-machines.md` |
| cache-edge-reconcile | 3 | 0 | 1 | `sources/cache-edge-reconcile.md` |
| migrations-early | 7 | 2 | 0 | `sources/migrations-early.md` |
| migrations-recent | 8 | 0 | 4 | `sources/migrations-recent.md` |
| parity-models | 0 | 0 | 0 | `sources/parity-models.md` |
| parity-services | 0 | 0 | 0 | `sources/parity-services.md` |
| parity-jobs | 1 | 0 | 0 | `sources/parity-jobs.md` |
| gdpr-deletion-export | 3 | 0 | 1 | `sources/gdpr-deletion-export.md` |
| pii-schema | 6 | 0 | 0 | `sources/pii-schema.md` |
| **Total** | **128** | **2** | **13** | |
