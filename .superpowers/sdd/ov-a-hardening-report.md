# OV-A hardening — plan + report

Branch `tobias/ov-a-hardening` off `development` (OV-A merged). Real `composer install` vendor (worktree, no symlink) so tests exercise this checkout.

## Goal
Harden the merged staff-accounts backend on its privileged/public surface: bind bootstrap email to the verified JWT, close two deny-path test gaps, land three cheap security wins. Surgical — preserve the normal (non-invite) signup path.

## Findings (pre-change facts)
- `POST /bootstrap` middleware = `supabase.jwt` + `throttle:bootstrap` only (NO `require.email_verified`). `VerifySupabaseJwt::setSupabaseContext()` sets request attribute `supabase_claims` (full claim array incl. `email`) on BOTH the JWKS and auth-server-fallback paths.
- `BootstrapController::bootstrap()` read `primary_email` from the request BODY for: invite email-match (`INVITE_EMAIL_MISMATCH`), individual-waitlist divert, and the create payload → both compared values were attacker-controllable.
- `BootstrapRequest` already reads `$this->attributes->get('supabase_uid')` in `rules()`, proving request attributes (incl. `supabase_claims`) are available inside the FormRequest. `prepareForValidation()` runs before the rules + before the controller reads any input.
- `EarlyAccessSignup::findByInviteToken()` is the SINGLE resolver used by both `BootstrapController` (bypass) and `PublicEarlyAccessController::resolveInvite()` (PII prefill) — one expiry check there covers both surfaces. `invited_at` is set alongside the token hash in `EarlyAccessService::invite()`.
- `PublicEarlyAccessController::store()` returned 201 (created) vs 200 (existing) + 201 on honeypot → new-vs-existing (and honeypot-vs-real) enumeration signal.
- `StaffNotificationController::store()` sits in the `staff.admin` route group (admin-only middleware) but makes NO policy call — the only staff write controller without `authorizeForUser(...staffManage...)` defense-in-depth. `NotificationPolicy` (mapped to `Notification::class`) had only User-actor methods.
- `AnalyticsQueryService::scopedTable()` already supports string (single user) / array (`whereIn` segment) / null (all users). `StaffAggregateAnalyticsController::summary()` maps `segment_id` → `SegmentResolver::userIds()` (array) or null. Neither the array nor null path had a test asserting correct scoped aggregates.

## Plan (code)
1. **Bind bootstrap email to verified claim** — in `BootstrapRequest::prepareForValidation()`, before `sanitizeEmails`, overwrite `primary_email` with `supabase_claims['email']` when present. Single-source fix: validation (uniqueness), invite-match, divert, create payload, and `markSignedUp` all then read the verified value. Empty/absent claim email → keep body (preserves phone/anon + the direct-controller unit tests that set no claims).
2. **Invite token 14-day expiry** — `EarlyAccessSignup::findByInviteToken()`: after hash lookup, return null when `invited_at` is null or older than `INVITE_TTL_DAYS = 14`. Expired token → bootstrap `INVITE_INVALID`, resolveInvite 404.
3. **Uniform early-access status** — `PublicEarlyAccessController::store()`: honeypot + created + existing all return 200 (was 201/201/200). Upsert behavior unchanged.
4. **Notification store policy** — add `staffManage(PartnaStaff, Notification|string|null)` (`isAdmin()`) to `NotificationPolicy`; call `authorizeForUser($staff, 'staffManage', Notification::class)` at top of `store()`. Admin-only, exact parity with the `staff.admin` middleware.

## Plan (tests)
- `tests/Feature/PublicSite/BootstrapInviteTest.php` (new): valid invite + matching claim → 200 + row→signed_up; unknown token → 422 INVITE_INVALID; claim-email ≠ invite-email (body tries to spoof match) → 422 INVITE_EMAIL_MISMATCH; no token under waitlist mode → 403 WAITLIST_ONLY; >14d token → 422 INVITE_INVALID. Direct-controller pattern (mirrors existing bootstrap tests), `UserBootstrapService` mocked, REAL `EarlyAccessService` so the signed_up flip is exercised.
- `tests/Feature/Staff/StaffAggregateAnalyticsScopeTest.php` (new): seed 2 users in a manual segment + 1 outside, seeded visits; segment scope counts only the 2 (`whereIn`), all-users scope counts 3 (null).
- `tests/Feature/PublicSite/PublicEarlyAccessTest.php` (update): 201→200 assertions; add >14d expiry → findByInviteToken null + resolveInvite 404.

## Constraints / verification
- No migrations (code-only; TTL is a model const). `composer test` green (real vendor) + `php artisan pint --dirty`.
- Normal signup preserved: claim email == body email in the happy path, so the create payload is unchanged; direct-controller tests with no claims fall back to body.

## Status
See final agent reply. Deferrals appended to `docs/superpowers/plans/2026-07-10-dashboard-batch.md` Tail (not fixed): invite_meta.entries.*.architecture shape validation; defensive AccountType::Staff reject in UserBootstrapService.
