# Handoff: Continue "Delete Dead Profile Features" (Tasks 7 verify → 11)

Paste everything below into a **fresh Sonnet session** to continue. Run the inline
execution on **Sonnet**; any review subagents inherit the session model.

---

You are continuing an in-progress plan execution. Use the **superpowers:executing-plans**
skill (inline execution — work the tasks yourself, don't fan out to subagents).

## Where the work lives
- **Work in this worktree:** `/Users/joshuahunter/Herd/Side Street/backend/.worktrees/delete-dead-profile-features`
- **Branch:** `chore/delete-dead-profile-features-2026-07-05` (based off `origin/development`)
- **Plan file:** `docs/superpowers/plans/2026-07-05-delete-dead-profile-features.md`
  (read it fully — it lives in the MAIN checkout `/Users/joshuahunter/Herd/Side Street/backend/`,
  is untracked, and is NOT copied into the worktree)
- This is a **manual `git worktree`** with its OWN `vendor/` and a copied `.env`. It already
  works — just `cd` in and run. Do NOT recreate it and do NOT use the harness worktree tool
  (`EnterWorktree`): on this repo it symlinks `vendor`/`.env` to main and breaks the feature suite.

## Progress so far
- **Tasks 1–6: committed, each `composer test` green** (baseline was 3141 passing; after Task 6: 3090).
- **Task 7: ALL edits applied but NOT yet tested or committed** — the working tree is dirty.
  Your FIRST action is to verify + commit Task 7 (below).
- Tasks 8–11: not started.

## Cadence (every task)
Surgical edits → `composer test` green (minus intentionally-deleted tests) → `git add -A` →
`git diff --cached --stat` (confirm surgical) → pint ONLY changed files:
`git diff --cached --name-only --diff-filter=ACM | grep '\.php$' | xargs ./vendor/bin/pint --test`
→ commit with the plan's **exact** message + the Co-Authored-By / Claude-Session trailers your
harness provides (match recent commits on the branch). Josh reviews at the end, not per-task.

## STEP 0 — verify & commit Task 7 (edits already in the working tree)
Already applied: deleted `UserCredential`, `UserExperience`, `CredentialsVisibility`,
`ExperienceVisibility`; `SectionVisibilityServiceProvider` (−2 imports, −2 `register()`);
`AppServiceProvider` (−2 imports, −2 `Gate::policy`, kept `Workplace`); `tests/Pest.php`
(removed `setupUserCredentialsTable`/`setupUserExperienceTable` + their calls in
`setupUsersTable()`); `SectionVisibilityTestCase` + `DataExportTestCase` (removed inline
`user_credentials`/`user_experience` CREATE TABLE); `SectionVisibilityLinkOnlyTest` (deleted
4 cred/exp cases); `SectionVisibilityRegistryTest` (removed cred/exp from "has a rule" list);
`BatchCheckQueryCountTest` (dropped cred/exp from `$types`, EXISTS assertion 8→6).

1. `composer test` → expect green, ~3086 passing, 0 failures (−4 deleted cred/exp cases from 3090).
2. Stage, diff-stat, pint changed files (see cadence).
3. Commit: `refactor(user): delete credentials/experience models + visibility rules`

If the suite is red, fix it before committing (do NOT commit red).

## Tasks 8–11 (do IN ORDER — follow the plan file exactly)
- **Task 8** — `refactor(site): remove bio + hero/CTA promoted columns from API surface`
  Remove `bio` from the 4 user resources + `User::$fillable` + both user-update requests;
  remove the 5 hero/CTA keys (`hero_title, hero_subtitle, primary_button_text,
  primary_button_url, bio_text`) from `Site::PROMOTED_SETTINGS_KEYS` **and** `$fillable`
  (keep the other survivors), both site-update requests, and `SiteResource`. Trim/delete the
  listed tests (incl. the SQLite `bio` column in `StaffSiteControllerTest` if declared there).
- **Task 9** — `refactor(user): purge users.bio from observer/cache/moderation/deletion`
  Remove `bio` from `UserObserver` (PUBLIC_PROFILE_USER_FIELDS), `UserBootstrapService`,
  `UserCacheService`, `AccountDeletionService` (pseudonymise + evidence-redaction list),
  `EvidenceSnapshotService`; remove the 3 analytics labels (`bio`/`experience`/`credentials`
  — KEEP the unrelated `'about' => 'About'` label). Update the 4 listed tests.
  **ALSO: fix the `UserObserver` docblock (~lines 30–34) that still references the removed
  `SitepageDataResolverService::getBio` / `aboutPayload()`** — this comment fix was deliberately
  deferred from Task 6 to Task 9 because Task 9 owns this file.
- **Task 10** — `refactor(sections): drop bio/credentials/experience from block-type config`
  Remove `credentials, experience, bio` from BOTH config section lists (`block_types.sections`
  AND `allowed_sections`) → final 10 survivors: gallery, services, booking, contacts_collection,
  barbershop_info, documents, newsletter, contact, public_contact, workplace. Swap `bio` test
  fixtures to a surviving type (e.g. `newsletter`) across the 6 listed test files; update
  `BlockTypesConfigTest` (final 10-value list) + `SectionBlockInvalidTypeTest` (assert `bio`
  now rejected).
- **Task 11 (LAST)** — `feat(schema): drop dead bio/credentials/experience columns, tables, and block types`
  Write `supabase/migrations/20260705120000_drop_dead_profile_features.sql`. In this order:
  DELETE rows of the 5 dead block types → DROP+recreate `site.all_site_data` and
  `site.public_site_payload` WITHOUT the dead refs (copy the CURRENT view bodies verbatim from
  `supabase/migrations/20260701200000_strip_site_settings_jsonb_keys.sql`, then delete `p.bio,`
  from all_site_data and the 6 hero/bio refs + `'bio', p.bio,` from public_site_payload) → trim
  the `blocks_group_type_check` CHECK to the 10 survivors (NOT VALID then VALIDATE) → drop the 5
  `site.sites` hero/bio columns + `core.users.bio` → drop `core.user_credentials` +
  `core.user_experience`. Then run **`supabase db push --dry-run`** and **STOP**. Do NOT run
  `supabase db push` — Josh applies it to dev (ref `glncumufgaqcmqhzwrxm`) himself after deploy.
  Commit the migration file.

## Hard-won lessons from Tasks 1–7 (apply these)
- **grep every symbol before AND after each task.** The plan's file lists are code-complete but
  miss comment/docblock/scaffolding references. Leaving a deleted class/method named in a live
  comment is stale — fix it (retarget to a surviving sibling, or reword).
- **`AuditPipelineIntegrityTest` fails CI if you delete a directory that's named in
  `scripts/audit/audit.sh` `codebase_chunks()`** (it bit Task 1 when `tests/Feature/Countdown`
  was deleted → had to remove that path from the `feature-media-jobs` chunk). If any task
  deletes a whole test directory, grep `scripts/audit/audit.sh` for it.
- Where removing a call leaves dead code (empty method/closure, orphaned import), remove that
  too (e.g. Task 4 removed the now-empty `withValidator()` + its `Validator` import).
- SQLite test schema (`tests/Pest.php`) may keep a `bio` column even after Task 11 drops it in
  Postgres — that's fine (schemas drift), as long as nothing asserts on it. Only remove test
  `bio` columns the plan names.
- Task 3's open decision was resolved: **PRESERVE `public_contact`** (already implemented — it's
  a live feature: write paths, its own visibility rule, observer sync, 3 resources, cache, GDPR
  export, signup uniqueness).

## At the very end (after Task 11 dry-run)
Josh's top-level instruction was: **"once finished implementation please review and then push to
development, checking for conflicts and supabase if needed."** So:
1. `git fetch`, rebase the branch on latest `origin/development` (concurrent work is landing —
   expect 1–3 rebase cycles), resolve conflicts, re-run `composer test`.
2. **Confirm with Josh how he wants it landed** (push feature branch / open PR / merge to
   development) before pushing — pushing is outward-facing.
3. Do NOT run `supabase db push`. Josh applies the Task 11 migration to dev after the code deploys.
