# Waitlist capture retirement + early-access PII coverage — design

**Date:** 2026-07-19
**Status:** Approved, pending implementation
**Depends on:** `feat/pre-account-sites-2026-07-18` merged to `development`

## 1. Problem

Two signup-capture systems exist in parallel, and the wrong one is wired into the
privacy machinery.

**`core.waitlist_signups` (V2, May 2026) is write-only.** Its only readers are its own
garbage collectors — `PruneWaitlistSignupsCommand` (deletes),
`AccountDeletionService::purgeWaitlistSignup()` (deletes), and
`DataExportPayloadBuilder::streamWaitlistSignups()` (GDPR export). There is no staff
endpoint, no Resource class, and no policy; `PolicyCoverageTest` exempts it as
*"public submission, no actor"*. It holds **1 row** (2026-05-26, all optional fields
null) and took **0 requests** in the last 24h of Cloud logs.

**`core.early_access_signups` (OV-A, July 2026) superseded it** with a full
`waitlist → invited → signed_up` lifecycle and staff CRUD + bulk invite
(`StaffEarlyAccessController`). It holds 4 real rows (Jul 10–13, 3 already invited).

**The privacy wiring is inverted.** `early_access_signups` stores `email`,
`consent_ip_hash`, and `consent_user_agent` but appears in **neither** the data-export
registry nor the account-deletion purge. A user who signs up via early access and then
requests export or erasure gets an incomplete export and leaves an orphaned PII row.

### 1.1 Frontend verification (resolved)

The in-repo comments claim the endpoint is live and are **stale**.
`app/(marketing)/waitlist/page.tsx` says *"Uses the same working waitlist endpoint"* but
imports `EarlyAccessForm`. Verified via GitHub API against `PartnaAu/partna-frontend`
(the org's only repo):

- Every marketing surface — root landing (×2), `/waitlist`, `/about`, `/industries`, all
  7 `/features/*` pages — renders `EarlyAccessForm`, which posts to
  `/api/public/early-access`.
- **`WaitlistForm` has zero importers.** The only org-wide hits for the symbol are its own
  `export function` and a comment in `early-access-form.tsx` stating it *"replac[es]
  WaitlistForm"*.
- No consumer of `public/waitlist` exists outside `partna-frontend`.

Deleting the backend route therefore requires **no** frontend coordination.

## 2. Goals / non-goals

**Goals**
1. Remove the dead `waitlist_signups` capture path end to end, including the table.
2. Wire `early_access_signups` into data export and account deletion.
3. Preserve retention enforcement over pre-signup PII.
4. Add a guard test so the next PII table cannot silently miss the export registry.

**Non-goals**
- Renaming `partna.waitlist.enabled` (see §4).
- Frontend dead-code removal (§8 — separate repo, non-blocking).
- Any change to the early-access lifecycle, invite tokens, or staff endpoints.

## 3. Baseline and sequencing

Implementation lands **after** `feat/pre-account-sites-2026-07-18` merges. That branch
(21 commits, in a sibling worktree) already deleted the individual-waitlist divert from
`BootstrapController` and relocated the `WAITLIST_ONLY` gate to
`PreAccountBuildController:26`. It rewrites 113 lines of `BootstrapController`, so
working in parallel would guarantee conflicts.

After that merge, `core.waitlist_signups` has exactly one writer left: the unreachable
public form.

Branch: `chore/retire-waitlist-capture-<date>` off `development`.

## 4. Deletions

All verified single-consumer:

| Target | Location / note |
|---|---|
| `POST /public/waitlist` route + import | `routes/api.php:125-126`, `:22` |
| `PublicWaitlistController` | |
| `PublicWaitlistSignupRequest` | |
| `WaitlistSignup` model + `app/Models/Core/Waitlist/` dir | |
| `RateLimiter::for('waitlist')` | `AppServiceProvider:533` — sole consumer is the deleted route |
| `bot.token:waitlist` usage | same route. Verified: `bot.token` is a bare middleware alias (`bootstrap/app.php:101`); `VerifyBotToken` holds no scope allowlist and no config block backs the key, so the scope string dies with the route |
| `partna.waitlist.types` / `.industries` | `config/partna.php:817-829` — read only by the deleted Request |
| `partna.individual_waitlist_enabled` | `config/partna.php:899` + comment `:896` |
| `SIDEST_INDIVIDUAL_WAITLIST_ENABLED` | `.env.example:226` |
| `core.waitlist_signups` | Supabase migration, `DROP TABLE` |

**Kept deliberately:** `config('partna.waitlist.enabled')` remains the live signup
kill-switch, read by `PreAccountBuildController:26` and
`PublicSignupAvailabilityController:21`. It is **not** renamed despite the now-misleading
name — it lives on the in-flight branch, and renaming manufactures a conflict for
cosmetic gain. Add a comment clarifying it gates signup generally.

## 5. Early-access PII coverage

1. **Export** — in `DataExportPayloadBuilder`, replace the `waitlist` registry entry
   (`:134`) and `streamWaitlistSignups()` (`:550`) with an `early_access` entry resolving
   by `email_lc`. Neither table has a `user_id`, so the existing email-keyed lookup
   pattern carries over unchanged. Export columns: `id, email, type,
   workplace_or_industry, platforms, status, source, invited_at, signed_up_at,
   created_at, updated_at`. **Excluded:** `invite_token_hash` (credential material),
   `consent_ip_hash`, `consent_user_agent` — consistent with the existing posture of not
   re-exporting hashed consent telemetry.

2. **Deletion** — in `AccountDeletionService`, replace `purgeWaitlistSignup()` (`:717`)
   and its call site (`:609`) with `purgeEarlyAccessSignup()`: same `email_lc` lookup,
   same try/catch-and-log-only posture so a purge failure never fails the deletion.

3. **Retention** — repoint rather than delete. `PruneWaitlistSignupsCommand` becomes
   `early-access:prune-old-signups`; `partna.waitlist.retention_days` becomes
   `partna.early_access.retention_days` (unchanged 730-day default). Update the
   `routes/console.php:235` schedule and its `onFailure` reporter.

   Deleting the prune command without replacing it would remove the only retention
   control over pre-signup PII — a regression dressed as cleanup.

   **Predicate change:** the new command prunes
   `status != 'signed_up' AND created_at < cutoff`. The original filtered on
   `last_submitted_at`, which `early_access_signups` does not have. Excluding
   `signed_up` matches the original's documented "non-converting applicant" intent —
   converted rows are governed by the account-deletion purge instead.

## 6. Orphan row

The single `waitlist_signups` row is **dropped with the table**. No data migration.

`early_access_signups.type` is `NOT NULL CHECK (type IN ('partna','business'))` and the
source row's `applicant_type` is null, so migrating it would require inventing a
constrained value. SQLite ignores CHECK constraints entirely, so such an insert would
pass `composer test` green and fail only on real Postgres.

## 7. Guard test

`tests/Feature/Security/DataExportCoverageTest.php`, following the established
`PolicyCoverageTest` convention rather than inventing a new one:

- Scan `app/Models/`; flag any model whose `$fillable` or `$hidden` contains `email`,
  `email_lc`, or `consent_ip_hash`.
- Assert each flagged model's table is either covered by the export registry **and** has
  a purge path, or listed in an `EXPORT_EXEMPT` allowlist with a written justification.
- `DataExportPayloadBuilder` exposes covered table names as a const the test reads
  directly, rather than the test grepping source.

This is the control that would have caught the original bug: the registry is a
hand-maintained array, so a new PII table is silently absent rather than loudly missing.

## 8. Test surface

**Delete:** `tests/Feature/PublicSite/PublicWaitlistControllerTest.php`,
`tests/Feature/Console/PruneWaitlistSignupsCommandTest.php`,
`tests/Unit/Models/WaitlistSignupTest.php`.

**Edit:**

| File | Change |
|---|---|
| `tests/Pest.php:847-873` | remove `setupWaitlistTable()` — callers are `PruneWaitlistSignupsCommandTest:23` (deleted) and `BootstrapDivertAndDisabledTest:9` (pre-account branch) |
| `tests/Feature/Validation/RequestValidationTest.php` | remove the 2 waitlist tests (`:44`, `:67`) + import `:6` |
| `tests/Feature/Security/PublicRateLimiterCfConnectingIpTest.php:50` | drop `'waitlist'` from the dataset |
| `tests/Unit/AddPublicCacheHeadersTest.php:77` | remove `/api/public/waitlist` from the path list |
| `tests/Feature/Security/PolicyCoverageTest.php:19,42` | remove import + `POLICY_EXEMPT` entry |
| `tests/Feature/Account/AccountDeletionPurgePiiTest.php` | retarget to `early_access_signups` |
| `tests/Feature/User/DataExport/*` | retarget the waitlist assertions to `early_access` |

**Untouched (config stays):** `BootstrapHandleAliasUniquenessTest:14`,
`PublicLoginIdentifierControllerTest:93`, `PublicSignupAvailabilityControllerTest:19` all
set `partna.waitlist.enabled => false` and keep working.

**Already handled by the pre-account branch:** `BootstrapWaitlistGateTest`,
`BootstrapDivertAndDisabledTest`, `BootstrapInviteTest`. Reconcile at rebase; do not
plan edits against their pre-merge state.

## 9. Verification and rollout

- Full `composer test` — **not** a filtered subset. Deleting a model can break
  same-namespace short references that a targeted run won't surface.
- Migration applied to dev ref `glncumufgaqcmqhzwrxm` via `supabase db push`
  (`--dry-run` first).
- **Post-deploy check on real Postgres** via `cloud tinker development`: run an export
  and a purge for a seeded early-access email. The SQLite mirror
  (`tests/Pest.php:1519`) drifts from prod — it declares `type TEXT NOT NULL` without the
  CHECK and `created_at TEXT NULL` where prod is `NOT NULL DEFAULT now()`. The new prune
  command filters on `created_at`, so a green suite does not by itself prove the
  predicate behaves correctly against prod.
- Prod DB remains on the pre-standalone schema, so the `DROP TABLE` rides the eventual
  prod re-baseline rather than shipping separately.

## 10. Risks

| Risk | Mitigation |
|---|---|
| Frontend call site missed | Verified zero importers org-wide (§1.1); `PartnaAu` has one repo |
| Rebase conflict with pre-account branch | Sequenced after merge (§3); test reconciliation called out in §8 |
| Retention silently dropped | Prune command repointed, not deleted (§5.3) |
| SQLite/Postgres drift hides a bug | Real-Postgres verification step (§9) |
| Recurrence of the coverage gap | Guard test (§7) |

## 11. Follow-up — `partna-frontend` (separate repo, non-blocking)

Dead code, removable any time after: `app/(marketing)/_components/waitlist-form.tsx`,
`app/api/public/waitlist/route.ts`, the `INDIVIDUAL_WAITLIST` string in
`lib/auth-errors.ts`, and the stale header comments on `(marketing)/waitlist/page.tsx`.

The `/waitlist` **page stays** — it is the destination for every marketing CTA and
already renders `EarlyAccessForm`.
