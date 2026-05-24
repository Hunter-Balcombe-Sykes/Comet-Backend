# Consolidated Foundation Audit — 2026-05-24

**Branch:** `development`
**Sources:** 24 lens audits (Lens 16 failed in run 1; re-fired as 5 narrower lenses 21–25 in run 2) + `composer audit` + `composer outdated --direct`
**Total raw findings:** 76 (64 lens findings + 8 CVE advisories + 4 major-version-available deps)
**Final consolidated count:** 7 P0 (open) + 1 closed, 18 P1, 42 P2, 27 P3 = ~94 actionable items across 26 fix bundles (3 standalone). Dedup merges: 4 from run 1, 3 from run 2.

> **Run 2 update (Lens 16 gap closure):** the 5 narrower lenses fired after the initial consolidation produced 12 new findings (1 P1, 4 P2, 7 P3) plus 3 duplicate-confirms (CRED-1/2 strengthen #P1-09, CRED-4 strengthens #P2-11). Each new item is tagged with its lens slug; P0-08 (coverage gap) is now resolved.

> **Read before fixing:** Items prefixed with `#P0-`, `#P1-`, etc. are the stable consolidated IDs. The "Lens" tag in each item points to the original lens-level finding ID (e.g. `RES-1`, `AUDIT-2`) for cross-referencing the per-lens audit file in `audits/foundation-audit-v1/audit-2026-05-24-<lens-slug>.md` (this folder).

## Model selection — read once

Every item and bundle ends with a `Models: impl=<x> · review=<y>` line. Use the **impl** model to spawn the implementation session; spawn a fresh session with the **review** model after the fix lands.

- **haiku** — trivial mechanical changes: delete a file/line, add a config default, single-line `report($e)` or `request_id`, drop a `'body' =>` log key. Few file edits, no architectural judgment. Fast.
- **sonnet** — standard implementation: refactors, new Resource/Service classes, observer changes, queue swaps, transactional unifications, scheduler entries, Resource creation. The default for most items.
- **opus** — load-bearing invariants with asymmetric blast radius: auth gates, RLS policies, transaction boundaries, single-writer KV contract, GDPR/PII flows, schema migrations, decisions where a wrong call propagates silently.

**Review rule of thumb**: use **opus** for review on anything touching auth, RLS, KV writer contract, transactions, GDPR/PII, schema migrations, or the mail-send layer (items where a subtle wrong fix doesn't blow up loudly). Use **sonnet** for everything else. Never review with haiku.

**Workflow per session**:
1. Pick the item or bundle, read its `Models:` line.
2. Spawn the implementation session with the **impl** model (`/model sonnet` in Claude Code, or `claude --model sonnet`).
3. Paste the item/bundle block; the session uses the `Where:` paths to locate the code.
4. After the fix lands, spawn a separate review session with the **review** model. Paste the same item/bundle plus the changed files. Ask it to verify the fix matches the finding and check for regressions.

---

## Dependency advisories

### Security CVEs (composer audit — 8 advisories, all `symfony/*`)

These are all resolved by upgrading `symfony/*` packages to `>=7.4.12` (or `>=8.0.12`). Currently affected via `laravel/framework 12.42.0` which pins older Symfony components. The IsGranted bypass (CVE-2026-45075) and UrlGenerator host injection (CVE-2026-45065) are the highest-impact items in this set.

- [ ] **#CVE-1** · P0 — `symfony/http-kernel` CVE-2026-45075 — HEAD request bypasses `methods: ['GET']` filter in `#[IsGranted]` / `#[IsSignatureValid]` / `#[IsCsrfTokenValid]`. Bypass of security attributes. Models: impl=sonnet · review=sonnet.
- [ ] **#CVE-2** · P0 — `symfony/routing` CVE-2026-45065 — UrlGenerator route-requirement bypass via unanchored regex alternation → off-site `//host` URL injection. Open redirect surface. Models: impl=sonnet · review=sonnet.
- [ ] **#CVE-3** · P0 — `symfony/mime` CVE-2026-45067 — Email Header / SMTP Command Injection via CRLF in `Symfony\Component\Mime\Address`. Direct relevance: our `SiteEnquiryNotification` (#P1-13) builds subjects from user input. Models: impl=sonnet · review=sonnet.
- [ ] **#CVE-4** · P1 — `symfony/mime` CVE-2026-45070 — Email Header Injection via Non-Token Characters in Mime Parameter Names. Models: impl=sonnet · review=sonnet.
- [ ] **#CVE-5** · P1 — `symfony/mailer` CVE-2026-45068 — Argument Injection in `SendmailTransport` via Dash-Prefixed Recipient Address. Only applies if we ever switch from Resend → Sendmail; mitigated by current transport. Models: impl=sonnet · review=sonnet.
- [ ] **#CVE-6** · P1 — `symfony/yaml` (3 CVEs as one upgrade) — CVE-2026-45304 (billion laughs), CVE-2026-45305 (ReDoS), CVE-2026-45133 (stack exhaustion via unbounded recursion). DoS surface only on YAML input we control (config files), but worth catching with the symfony bump anyway. Models: impl=sonnet · review=sonnet.

### Direct dependency drift (composer outdated --direct)

- [ ] **#DEP-MAJ-1** · P3 — `laravel/framework` 12.42.0 → 13.11.2 (major). Defer until v13 is the established LTS line; current 12.x is supported. Audit timing later. Models: impl=sonnet · review=sonnet.
- [ ] **#DEP-MAJ-2** · P3 — `endroid/qr-code` 5.1.0 → 6.1.3 (major). Used for handle/account-deletion QR? Verify call sites before upgrading. Models: impl=sonnet · review=sonnet.
- [ ] **#DEP-MAJ-3** · P3 — `laravel/boost` 1.8.13 → 2.4.8 (major) and `laravel/tinker` 2.11.1 → 3.0.2 (major). Dev-only deps; can be done independently. Models: impl=sonnet · review=sonnet.
- [x] **#DEP-MIN-1** · P3 — Patch/minor bumps available for: `firebase/php-jwt`, `laravel/horizon`, `laravel/nightwatch`, `laravel/pail`, `laravel/pint`, `laravel/sail`, `league/flysystem-aws-s3-v3`, `pestphp/pest`, `pestphp/pest-plugin-laravel`, `predis/predis`. Apply in one PR with `composer update --no-dev` then test. Models: impl=sonnet · review=sonnet.

---

## Cross-lens high-confidence findings

Themes that surfaced under multiple lens framings — these are the highest-confidence findings because three different prompts independently hit the same root cause.

- [ ] **#X-1** — **PII leakage via logs, Redis payloads, and exception messages**
    - Source lenses: AUDIT-1, AUDIT-2, AUDIT-3 (observability/PII), AUDIT-4 (downstream API bodies), AUDIT (mail), MEDIA findings.
    - Pattern: `'body' => $response->body()` in `Log::error`, raw `email` in job constructor properties (serialized to Redis), and customer emails in `failed()` handlers — written in different files at different times, but the same root error.
    - Touches: `AccountDeletionService`, `SupabaseAdminService`, `SendEnquiryNotificationJob`, `SyncCustomerMarketingOptInJob`, `KickApiClient`, `TwitchApiClient`, `EmailConfirmMail`, `SiteEnquiryNotification`.
    - Consolidated into bundle **B3**.

- [ ] **#X-2** — **Raw Eloquent models leaking through Resource-less controllers**
    - Source lenses: RES-1, RES-2, RES-3, RES-4 (P1 cluster), RES-7 (P2).
    - Pattern: `return $this->success(['service' => $service])` with no Resource wrap; controllers in `Professional/SiteManagement/` and `Staff/ProfessionalSiteManagement/` collectively expose `Site.settings` JSONB, `Theme.config` JSONB, raw `Block`, raw `Customer`, raw `LinkBlock` — every new column auto-leaks.
    - Consolidated into bundle **B4**.

- [ ] **#X-3** — **`BootstrapController` god method (3 distinct failures emerged in 3 lenses)**
    - Source lenses: GDPR (P0 waitlist crash), ARCH (P1 disabled-account 200 `{}`), ARCH (P2 200-line procedural blob).
    - The architectural mess (ARCH-5) directly produces ARCH-1 (response object returned through transaction closure loses HTTP status) and GDPR-1 (no validation against `applicant_type` enum). Extracting to `ProfessionalBootstrapService` while fixing both is one bundle.
    - Consolidated into bundle **B2**.

- [ ] **#X-4** — **Post-strip dead code & schema drift (8 findings across 5 lenses)**
    - Source lenses: CAP (AccountType enum P2), ARCH (P2 + 3×P3), BOOT (P3), RLS (2×P3), CONFIG (P2).
    - Pattern: the standalone strip (commits 5e92aac6, 821aff26 on 2026-05-22) removed code/schema but left orphans: AccountType has only `Individual` case (will throw ValueError on stale dev rows), `ACTION_UNSELECT_PRODUCT` ships in API responses, `AccountCapabilitySet` has 15 always-false params, `NoRecipientEmailException` duplicate, `seed.sql` is entirely dead, `config.toml` still lists `billing`/`retail` schemas, `DB_SEARCH_PATH` still includes `brand`/`commerce`/`billing`, gallery `store()` is a permanent 410 stub.
    - Consolidated into bundle **B11**.

- [ ] **#X-5** — **`CheckStreamingLiveStatusJob` has three independent hygiene problems**
    - Source lenses: SCHED-1 (P2 — lock timeout = cadence creates race), JOBS-3 (P3 — lands on default queue), JOBS-4 (P3 — failed() handler unreachable + missing `report()`).
    - All three fix in one ~10-line PR touching the job class + the schedule entry.
    - Consolidated into bundle **B6** (scheduler) plus a small companion in **B12** (job retry safety).

---

## P0 — must fix before adding new features

- [x] **#P0-01** Symfony CVE-2026-45075 — IsGranted attribute bypass — Lens: composer audit
    - Where: `composer.lock` — `symfony/http-kernel <7.4.12`
    - What: HEAD requests bypass `methods: ['GET']` filter in `#[IsGranted]` / `#[IsSignatureValid]` / `#[IsCsrfTokenValid]`. Direct relevance to any future controller using Symfony security attributes; our middleware-based auth is the primary defense today.
    - Fix: `composer require symfony/http-kernel:^7.4.12` (or pin via the laravel/framework upgrade path); re-run `composer audit` to confirm.
    - Models: impl=sonnet · review=sonnet

- [x] **#P0-02** Symfony CVE-2026-45065 — UrlGenerator host injection — Lens: composer audit
    - Where: `composer.lock` — `symfony/routing <7.4.12`
    - What: Route-requirement bypass via unanchored regex alternation produces `//host` URLs. Anywhere we generate URLs from user-controlled route params (handle redirects, alias targets) is a potential open redirect.
    - Fix: include in the same symfony bump as P0-01.
    - Models: impl=sonnet · review=sonnet

- [x] **#P0-03** Symfony CVE-2026-45067 — Email/SMTP CRLF injection — Lens: composer audit
    - Where: `composer.lock` — `symfony/mime <7.4.12` (and `<6.4.40` for older lines)
    - What: CRLF injection in `Symfony\Component\Mime\Address`. Pairs with #P1-13 (`SiteEnquiryNotification` builds subjects from unsanitised user input) — the framework-level mitigation that finding relies on is exactly what this CVE breaks.
    - Fix: include in the same symfony bump.
    - Models: impl=sonnet · review=sonnet

- [x] **#P0-04** Symfony YAML DoS triplet (CVE-2026-45304/45305/45133) — Lens: composer audit
    - Where: `composer.lock` — `symfony/yaml <7.4.12`
    - What: Billion-laughs, ReDoS, and stack-exhaustion vectors. We only parse YAML from `config/*` and CI workflow files, so the attack surface is internal — but the upgrade is bundled with all the other symfony bumps anyway.
    - Fix: bundled symfony upgrade.
    - Models: impl=sonnet · review=sonnet

- [x] **#P0-05** Symfony Mailer CVE-2026-45068 — Sendmail argument injection — Lens: composer audit
    - Where: `composer.lock` — `symfony/mailer <7.4.12`
    - What: Dash-prefixed recipient addresses inject `sendmail` arguments. Not exploitable today because we use Resend, but a transport swap is one config change away.
    - Fix: bundled symfony upgrade.
    - Models: impl=sonnet · review=sonnet

- [x] **#P0-06** Symfony MIME CVE-2026-45070 — non-token char header injection — Lens: composer audit
    - Where: `composer.lock` — `symfony/mime <7.4.12`
    - What: Companion to CVE-2026-45067; together they cover the email header injection surface.
    - Fix: bundled symfony upgrade.
    - Models: impl=sonnet · review=sonnet

- [x] **#P0-07** GDPR — waitlist divert crashes with DB CHECK constraint violation — Lens: `GDPR-1`
    - Where: `app/Http/Controllers/Api/PublicSite/BootstrapController.php:57` / `supabase/migrations/20260526000000_baseline_standalone_user.sql:433`
    - What: `'applicant_type' => 'individual'` is sent on every waitlist divert; the schema `CHECK (applicant_type IN ('influencer', 'professional', 'other'))` rejects it. Every public bootstrap call with the waitlist flag on results in a 500.
    - Fix: change to `'professional'` (closest semantic match) OR add `'individual'` to the constraint via a new migration. Also verify `phone`/`industry` NOT NULL columns are supplied (currently absent). Add a feature test exercising a real DB insert.
    - Note: depends on whether the waitlist flag is on in any environment — pull `cloud env:logs partna development --minutes 60 | grep waitlist_signups` to confirm scope before fixing.
    - Models: impl=sonnet · review=opus

- [x] **#P0-08** Coverage gap — Lens 16 RESOLVED via run-2 lenses 21–25 — Lens: meta
    - Status: closed. The 5 narrower lenses produced full coverage of webhook signature verification, rate limiting + CORS, security headers + HSTS, env reads + secrets, and Cloudflare worker + R2 bucket scope. See the "Coverage report" at the bottom for the updated matrix.
    - Findings from this re-fire: 1 new P1 (#P1-18 webhook replay), 4 new P2 (#P2-39 .. #P2-42), 7 new P3 (#P3-21 .. #P3-27), plus 3 duplicate-confirms.

---

## P1 — fix soon

- [x] **#P1-01** Bootstrap returns HTTP 200 `{}` for disabled/suspended/pending_deletion accounts — Lens: `ARCH-1`
    - Where: `app/Http/Controllers/Api/PublicSite/BootstrapController.php:114–116`
    - What: `return $this->error(...)` inside a `DB::transaction` closure returns the `JsonResponse` *object* as the closure's value. The outer `$this->success($result)` then JSON-encodes the response object → produces `{}`. The intended 403 disappears; frontend never sees it.
    - Fix: throw a `RuntimeException('ACCOUNT_DISABLED')` inside the closure (matching the `EMAIL_ALREADY_REGISTERED` pattern); add a branch in the outer catch that returns `$this->error('Account is disabled. Contact support.', 403)`. Better: lift the status check above the transaction entirely.
    - Models: impl=sonnet · review=sonnet

- [x] **#P1-02** Synchronous mail on Supabase email hook → duplicate auth emails on transport slowness — Lens: `PERF-1`
    - Where: `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:69`
    - What: `Mail::send($mailable)` blocks for the Resend round-trip (100–400 ms typical, multi-second under load). Supabase Send Email Hook is at-least-once: any non-2xx (including PHP-FPM timeout) triggers a retry — meaning users get two signup-confirm / password-reset / magic-link emails when Resend hiccups.
    - Fix: switch to `Mail::queue($mailable)`. Ensure the `mail` queue worker is on Horizon (`config/horizon.php`). On queue-dispatch failure, log + return 200 `{handled:false}` rather than 500.
    - Models: impl=sonnet · review=sonnet

- [x] **#P1-03** R2 public bucket accepts arbitrary MIME via spoofed extensions — Lens: `MEDIA-1`
    - Where: `app/Services/Media/ImageVariantService.php:219–227`
    - What: `storeOriginal()` runs synchronously in the upload controller before the async MIME-validating job (`ProcessImageVariantsJob`). A user uploads `phishing.html` renamed to `.jpg`; it lands on the public R2 bucket at a predictable URL on the Partna media domain. RCE is not possible (R2 doesn't execute), but stored XSS via SVG and phishing pages under the Partna brand are.
    - Fix: add `finfo(FILEINFO_MIME_TYPE)` sniff at the top of `storeOriginal()` against `ALLOWED_IMAGE_MIMES`. Throw `UnprocessableImageException` on mismatch. The check must run **before** `$this->disk()->put()`.
    - Models: impl=sonnet · review=opus

- [x] **#P1-04** `VideoVariantService::deleteVariants()` aborts on first storage failure, permanently orphaning DB rows — Lens: `MEDIA-4`
    - Where: `app/Services/Media/VideoVariantService.php:338–360`
    - What: Loop throws `RuntimeException` on first R2 delete error; `MediaVariant::where(...)->delete()` is positioned *after* the loop, so any transient R2 error leaves partial storage state + intact DB rows pointing at a mix of live and gone paths. Retries can't fully reconcile because `allFiles()` won't re-list already-deleted files.
    - Fix: best-effort delete loop — collect failures, continue, log each. Move the `MediaVariant::delete()` call to run unconditionally after the loop. If any deletions failed, throw a summarising exception so the job can be retried for storage cleanup only.
    - Models: impl=sonnet · review=sonnet

- [x] **#P1-05** `service` & `service-category` controllers return raw Eloquent models on every read/write — Lens: `RES-1`
    - Where: `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php:37,70,85,96` · `ProfessionalServiceController.php:134,164,208` · `ProfessionalCustomerController::restore()`
    - What: Every column (`price_cents`, `currency_code`, `deleted_at`, future `internal_cost_cents`) auto-ships in API responses.
    - Fix: create `ServiceResource` + `ServiceCategoryResource`, wrap all returns. Fix `ProfessionalCustomerController::restore()` to wrap `$customer->fresh()` in `CustomerResource` (the only method in that controller without it).
    - Models: impl=sonnet · review=sonnet

- [x] **#P1-06** Customer list response uses `pagination` key instead of `meta` — breaks paginated contract — Lens: `RES-2`
    - Where: `app/Http/Controllers/Api/Professional/Customers/ProfessionalCustomerController.php:100-102`
    - What: Manual `$payload['pagination'] = $payload['meta']; unset($payload['meta']);` produces a shape that matches no other paginated endpoint in the codebase. Staff mirror uses `meta` correctly → professional vs staff dashboards see different envelopes.
    - Fix: delete the rename. Confirm with frontend whether any code depends on `pagination`; if so, dual-key during a transition window then drop.
    - Models: impl=sonnet · review=sonnet

- [x] **#P1-07** Link-block, site, theme controllers return raw Eloquent models — Lens: `RES-3`
    - Where: `ProfessionalLinkBlockController.php:148,200` · `ProfessionalSiteController.php:30,42,108` · `ProfessionalThemeController.php:38` · `SiteVisibilityController.php` · `StaffSiteManagementController.php` (update)
    - What: `Site.settings` JSONB (design tokens, GBP data, internal flags) and `Theme.config` JSONB ship wholesale in dashboard responses.
    - Fix: create `LinkBlockResource`, `SiteResource`, `ThemeResource` with explicit allowlists; replace all `->toArray()` / `->fresh()` returns.
    - Models: impl=sonnet · review=sonnet

- [x] **#P1-08** Staff `show()` endpoints return raw Eloquent models without Resource wrapping — Lens: `RES-4`
    - Where: `StaffCustomerManagementController.php:105` · `StaffServiceManagementController.php:94` · `StaffServiceCategoryManagementController.php:91` · `StaffSectionManagementController.php:28` · `StaffLinkBlockManagementController.php:27`
    - What: Staff sees every DB field whether relevant or not — especially risky on PII tables and tables with `admin_notes`. Any new column auto-leaks to staff before it reaches the professional surface.
    - Fix: reuse the Resources created in P1-05/P1-07; create `SectionBlockResource` (mirror `serializeSection` pattern from professional side).
    - Models: impl=sonnet · review=sonnet

- [x] **#P1-09** Supabase Admin API response body (PII) written to logs on deletion/MFA-unenroll failure — Lens: `AUDIT-1`
    - Where: `app/Services/Professional/AccountDeletionService.php:383–387` · `app/Services/Auth/SupabaseAdminService.php:87–93`
    - What: GoTrue v2 error responses include the user object (email, `user_metadata`, phone). `Log::error([..., 'body' => $response->body()])` and `RuntimeException` messages embedding `$response->body()` write that PII into Nightwatch + Horizon failed-jobs — retention windows outside the GDPR erasure sweep.
    - Fix: drop `'body' => ...` from log contexts (keep `auth_user_id`, `status`). For `unenrollMfaFactor`, throw with status code only. Mirror the SHA-256 fingerprint pattern already used in `createUser`.
    - Models: impl=sonnet · review=opus

- [x] **#P1-10** Customer + professional emails serialised into Redis job payloads — Lens: `AUDIT-2`
    - Where: `SendEnquiryNotificationJob.php:37–40` · `SyncCustomerMarketingOptInJob.php:30–34`
    - What: Public constructor properties get serialised into the Redis payload. After GDPR-erasure the Postgres row is gone but the queued/failed job retains the email indefinitely.
    - Fix: drop the email props from constructors; pass UUIDs and look up the email from the database inside `handle()`. By erasure time the row is gone, so the lookup returns null → no PII to surface.
    - Models: impl=sonnet · review=opus

- [x] **#P1-11** Customer email logged in failed-job handler — Lens: `AUDIT-3`
    - Where: `SyncCustomerMarketingOptInJob.php:56–62`
    - What: `'email' => $this->email` in the `failed()` `Log::error` context contradicts the sibling job's documented pattern.
    - Fix: drop the email key from the log context; keep `professional_id` for correlation.
    - Models: impl=haiku · review=sonnet

- [ ] **#P1-12** Professional soft-delete leaves subdomain permanently live in Cloudflare KV — Lens: `SUBKV-1`
    - Where: `app/Observers/Professional/ProfessionalObserver.php:51–61`
    - What: `deleted()` only invalidates cache. No `RetireSubdomainFromKvJob` dispatched. `<handle>.partna.au` stays resolvable indefinitely; a re-sync can't clean it up because `SyncSubdomainToKvJob` early-exits when `User::find()` returns null on the soft-deleted row.
    - Fix: in `ProfessionalObserver::deleted()`, dispatch `RetireSubdomainFromKvJob::dispatch($professional->handle)` after cache invalidation, guarded by `if ($professional->handle)`. Add a test in `tests/Feature/Professional/ProfessionalObserverHandleChangeTest.php`.
    - **STANDALONE candidate** — touches the SUBDOMAIN_KV writer contract; bundle B8 covers it.
    - Models: impl=sonnet · review=opus

- [ ] **#P1-13** `app_backend` role granted `BYPASSRLS` — DB is not a second line of defense — Lens: `RLS-1`
    - Where: `supabase/migrations/20260526000000_baseline_standalone_user.sql` (Section 12)
    - What: Migration explicitly grants BYPASSRLS to the Laravel service user "because several KEEP RLS tables have no explicit `app_backend` policy." A single Policy bug, a raw `DB::raw` query in untrusted hands, or an `authorizeForUser` omission exposes every row in every schema with no fallback. Policy layer is currently the *sole* enforcement.
    - Fix: incremental — audit every table without an `app_backend` policy, add the standard `FOR ALL TO app_backend USING (true) WITH CHECK (true)` (the migration already establishes this pattern for `enquiries`, `feature_flags`), then drop `BYPASSRLS`. Effort estimate XL (~16–32h).
    - **STANDALONE** — schema-level work, requires careful audit. Bundle B19.
    - Models: impl=opus · review=opus

- [x] **#P1-14** Mailables bypass `BaseTransactionalMail` (11 of 15 classes) — Lens: `MAIL-1`
    - Where: `app/Mail/Notifications/*` · `app/Mail/Gdpr/ProfessionalDataExportMail.php` · `app/Mail/HandleAliasExpiringMail.php` · `app/Mail/SiteEnquiryNotification.php` · `app/Mail/StaffBroadcastMail.php`
    - What: Bypasses the canonical from/reply-to and the `X-Partna-Pipeline: transactional` header, breaking Resend bounce attribution. The 4 Auth mails are the correct pattern.
    - Fix: change `extends Mailable` → `extends BaseTransactionalMail` and call `buildEnvelope()` in each. Add a Pest arch test asserting every class under `app/Mail/` extends `BaseTransactionalMail`.
    - Models: impl=sonnet · review=sonnet

- [x] **#P1-15** DSAR export missing `email_subscriptions` rows with `professional_id = NULL` — Lens: `GDPR-2`
    - Where: `app/Services/Professional/DataExport/DataExportPayloadBuilder.php::streamEmailSubscriptions()`
    - What: `ensureSidestUpdatesSubscription()` creates `notifications.email_subscriptions` rows with `professional_id = NULL` keyed only by email. The DSAR export filters exclusively on `professional_id` → user's own consent record is invisible in their export. Article 15 violation.
    - Fix: add an `OR (professional_id IS NULL AND email = $professional->email)` clause (or a parallel query). Unit-test the merged generator with a seeded global row.
    - Models: impl=sonnet · review=opus

- [x] **#P1-16** DSAR export missing `waitlist_signups` rows entirely — Lens: `GDPR-3`
    - Where: `DataExportPayloadBuilder::stream()`
    - What: `core.waitlist_signups` has no `professional_id` FK; the link is `email_lc`. No `streamWaitlistSignups()` method exists. Article 15 violation for any user who pre-registered.
    - Fix: add `streamWaitlistSignups($email)` querying `WHERE email_lc = lower($professional->email)`. Yield it from `stream()` under `'waitlist'`. Test with a seeded row.
    - Models: impl=sonnet · review=opus

- [x] **#P1-17** OTP code rendered in email subject AND preheader — shoulder-surfable on lock screen — Lens: `MAIL-4`
    - Where: `app/Mail/Auth/EmailConfirmMail.php:21` · `resources/views/emails/auth/email-confirm.blade.php:1`
    - What: `->subject("Your Partna verification code: {$this->code}")` plus `@section('preheader', "Your Partna verification code: {$code}")` puts the 6-digit OTP in iOS/Android push banners and lock-screen previews. Brief physical proximity to a locked device leaks the code.
    - Fix: change subject to `'Verify your Partna email address'`; change preheader to `'Open this email to get your verification code.'`. The code is already prominently rendered in-body — no UX loss.
    - Models: impl=haiku · review=sonnet

- [x] **#P1-18** Supabase email hook has no webhook-id deduplication — replay window of 5 min — Lens: `WH-1` (run 2)
    - Where: `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:30–86` · `app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php:39–53`
    - What: The `SupabaseEmailHookSignatureVerifier` correctly enforces the 300-second timestamp tolerance (good), but the `webhook-id` is extracted only for HMAC signing then discarded — the controller never sees it. Within that 5-minute window: (a) a captured signed webhook replays cleanly, sending duplicate auth emails (signup confirm / password reset / magic link); (b) Supabase's own at-least-once retry on transient 5xx also passes through this gap legitimately, producing duplicate sends. Standard Webhooks spec §4 explicitly requires receivers to track `webhook-id` for idempotency.
    - Fix: attach the `webhook-id` to the request as an attribute after signature passes; at the top of the controller call `Cache::add("email_hook:{$webhookId}", 1, now()->addSeconds(300))`. If the key existed, return `200 OK ['ok' => true, 'handled' => false, 'duplicate' => true]` and skip `Mail::send()`. TTL matches `TIMESTAMP_TOLERANCE` exactly. Log duplicates at `info` so ops can distinguish retry-noise from active replay attempts.
    - Pairs with: P1-02 (which queues the mail) — when both fixes land, retries are cheap-no-ops and duplicates are impossible.
    - Models: impl=sonnet · review=opus

---

## P2 — fix when touching this code

### Authorization & policy doctrine

- [x] **#P2-01** Professional routes have no AAL2 enforcement — Lens: `AUTH-1`
    - Where: `routes/api/professional.php` (group middleware)
    - Fix: wire `BasePolicy::requiresFreshAal2()` into sensitive policy methods on `ProfessionalSelfPolicy` (account deletion `confirm`, profile `update`). Do NOT add `require.aal2` as group middleware — would lock out users without TOTP. Reference: `docs/auth/mfa-foundation.md`.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-02** `ProfessionalLinkBlockController::store()` + `reorder()` skip `authorizeForUser` — Lens: `AUTH-2`
    - Where: `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php:42–44, 70, 255`
    - Fix: replace empty `authorizeCustomLinks()` calls with skeleton-pattern `authorizeForUser($pro, 'create', $skeleton)`. Delete the false-security gate method entirely. Sibling `update()` (line 118) and `destroy()` (line 245) show the correct pattern.
    - Models: impl=sonnet · review=sonnet

- [ ] **#P2-03** JWT `aal` claim trusted blindly — stolen aal2 token bypasses MFA for token lifetime — Lens: `JWT-1`
    - Where: `app/Http/Middleware/Auth/RequireAal2.php:26-33`
    - Decision needed: accept the JWT statelessness tradeoff (document it) OR implement Redis-backed session liveness check using `supabase_session_id` (already extracted). At Supabase default 1h TTL the practical window is bounded.
    - **STANDALONE** — needs human decision on tradeoff. Bundle B20.
    - Models: impl=sonnet · review=opus (after design decision — see B20 notes)

- [ ] **#P2-04** Logout is cosmetic — JWT remains valid until natural expiry — Lens: `JWT-2`
    - Where: `app/Http/Middleware/Auth/VerifySupabaseJwt.php`
    - Decision needed: same shape as P2-03 — either shorten Supabase JWT TTL to 15min (no code) OR Redis-backed blocklist on logout.
    - **STANDALONE** — needs human decision. Bundle B20.
    - Models: impl=sonnet · review=opus (after design decision — see B20 notes)

### Data integrity & lifecycle

- [x] **#P2-05** `pseudonymiseAccountPii()` runs outside the confirmation transaction — Lens: `DEL-1`
    - Where: `app/Services/Professional/AccountDeletionService.php:127` (also `:273`)
    - Fix: snapshot `primary_email` before transaction; wrap `executeConfirmation()` + `pseudonymiseAccountPii()` in a single `DB::transaction`. Apply same fix to `adminInitiate()`.
    - Models: impl=sonnet · review=opus

- [x] **#P2-06** Deletion token write/rollback uses two unguarded `update()` calls — Lens: `DEL-2`
    - Where: `AccountDeletionService.php:50–70`
    - Fix: wrap token write + `Mail::send()` in `DB::transaction`; throw on mail failure for automatic rollback.
    - Models: impl=sonnet · review=opus

### Performance & jobs

- [x] **#P2-07** Account deletion emails sent synchronously — Lens: `PERF-2`
    - Where: `AccountDeletionService.php:59, :175`
    - Fix: confirmation path (line 175) — straight swap to `Mail::queue()`. Request path (line 59) — needs a `SendAccountDeletionRequestMailJob` that clears the token on failure to preserve the existing correctness invariant.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-08** `DeleteMediaArtifactsJob.failed()` no `report($e)` — silent permanent failures — Lens: `JOBS-1`
    - Where: `app/Jobs/DeleteMediaArtifactsJob.php:93–100`
    - Fix: add `report($e);` as first line of `failed()`. One-line change.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-09** `ExportProfessionalDataJob` sends duplicate GDPR export email on crash-retry — Lens: `JOBS-2`
    - Where: `app/Jobs/Gdpr/ExportProfessionalDataJob.php:63–103`
    - Fix: add `email_sent_at` to `data_export_audits` (or use existing `file_path` set as upload marker); guard `Mail::send()` with a check. Mirror pattern from `SendEnquiryNotificationJob`.
    - Models: impl=sonnet · review=opus

### Cache & observability

- [x] **#P2-10** SWR fast path serves stale payload without backward-compat healing — Lens: `CACHE-1`
    - Where: `app/Services/Cache/SiteCacheService.php` (SWR path ~line 118)
    - What: Sites whose cache was populated before the V2 strip (2026-05-22) serve a broken shape (missing `services`/`legal`) for up to 2.5h after the primary key expires.
    - Fix: run the same `array_key_exists('services')` + `ensureBlockCollections()` + `legal` backfill that the primary-hit path already does. On guard fail, forget both keys and fall through.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-11** Third-party streaming API response bodies logged verbatim — Lens: `AUDIT-4`
    - Where: `KickApiClient.php:63–67` · `TwitchApiClient.php:55–59`
    - Fix: drop `'body' => $response->body()` from `Log::error` context; keep `status`.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-12** Staff audit write-failure warning lacks request correlation ID — Lens: `AUDIT-5`
    - Where: `app/Services/Audit/StaffAuditService.php:41–46`
    - Fix: add `'request_id' => request()?->header('X-Request-Id')` to log context. Match pattern from `FeatureFlagService`, `NotificationPublisher`.
    - Models: impl=haiku · review=sonnet

### Capabilities & enum integrity

- [ ] **#P2-13** Single-case `AccountType` enum will crash on stale `account_type` rows — Lens: `CAP-1`
    - Where: `app/Enums/AccountType.php` · `app/Models/Core/Professional/User.php:91`
    - Fix: (a) one-off `UPDATE core.users SET account_type = 'individual' WHERE account_type != 'individual'` in every non-fresh env; (b) add `CHECK (account_type = 'individual')` constraint in a new migration; (c) optional `try/catch ValueError` in the accessor as a defensive net.
    - Models: impl=sonnet · review=opus

- [x] **#P2-14** `SendTransactionalNotificationEmailJob` dispatches to suspended/disabled accounts — Lens: `CAP-2`
    - Where: `app/Jobs/Notifications/SendTransactionalNotificationEmailJob::handle()`
    - Fix: add `where('status', 'active')` to the `primary_email` lookup query OR early-return after a `User::find()` status check.
    - Models: impl=sonnet · review=sonnet

### Config hygiene

- [x] **#P2-15** `queue.batching` & `queue.failed` fall back to `sqlite` while everything else uses `pgsql` — Lens: `CONFIG-1`
    - Where: `config/queue.php`
    - Fix: change both `env('DB_CONNECTION', 'sqlite')` → `env('DB_CONNECTION', 'pgsql')`. Add both keys to `EnvCheckService::REQUIRED`.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-16** Default log level is `debug` across all channels — Lens: `CONFIG-2`
    - Where: `config/logging.php` (6 channels) + `config/nightwatch.php`
    - Fix: change fallback to `'warning'`. Add `LOG_LEVEL` to `EnvCheckService::RECOMMENDED`.
    - Models: impl=haiku · review=sonnet

- [ ] **#P2-17** `DB_SEARCH_PATH` includes dropped schemas `brand`/`commerce`/`billing` — Lens: `CONFIG-3`
    - Where: `config/database.php`
    - Fix: change default to `'public,core,site,notifications,analytics'`. Set explicitly in production `.env`.
    - Models: impl=haiku · review=sonnet

### Mail safety

- [x] **#P2-18** Unescaped notification title in email preheader (4 templates) — Lens: `MAIL-2`
    - Where: `resources/views/emails/notifications/{feature_announcement,incident,policy_update,profile_tasks}.blade.php:2`
    - Fix: wrap title in `e()`: `@section('preheader', e($notification->title))`. Or move to shared `_partial-content.blade.php`.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-19** `SiteEnquiryNotification` subject built from unsanitised user input — Lens: `MAIL-3`
    - Where: `app/Mail/SiteEnquiryNotification.php:24`
    - Fix: strip `\r\n` from `name` and `subject` before interpolation: `preg_replace('/[\r\n]+/', ' ', $value)`. Defense-in-depth — Symfony Mailer's strip is the only thing protecting today, and CVE-2026-45067 just confirmed that protection isn't bulletproof.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-20** `EventServiceProvider::boot()` doesn't call `parent::boot()` — Lens: `MAIL-5`
    - Where: `app/Providers/EventServiceProvider.php:20–30`
    - Fix: add `parent::boot();` as first line. Future `$listen`/`$subscribe` registrations will silently no-op otherwise.
    - Models: impl=haiku · review=sonnet

### Bootstrap & exceptions

- [x] **#P2-21** Domain exception safety net missing — Lens: `BOOT-1`
    - Where: `bootstrap/app.php` + `app/Exceptions/Streaming/KickRateLimitException.php` + `app/Exceptions/Gdpr/DataExportInProgressException.php`
    - Fix: introduce `HttpStatusCodeInterface { getHttpStatusCode(): int; getHttpHeaders(): array; }`. Domain exceptions implement it. Renderer checks `$e instanceof HttpStatusCodeInterface` in the `else` branch.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-22** `current.pro` not appended to `api` middleware group — Lens: `BOOT-2`
    - Where: `bootstrap/app.php` (alias block)
    - Fix: create an `auth.api` group `['supabase.jwt', 'require.email_verified', 'current.pro']` and apply it everywhere, OR append `LoadCurrentProfessional` to the `api` group via priority list. Add a test that asserts `$request->attributes->get('professional')` is non-null on any route with `supabase.jwt`.
    - Models: impl=sonnet · review=opus

### Scheduler

- [x] **#P2-23** `CheckStreamingLiveStatusJob` lock timeout = scheduling cadence — Lens: `SCHED-1`
    - Where: `routes/console.php`
    - Fix: `withoutOverlapping(2)` → `withoutOverlapping(5)`. Add inline comment explaining the ceiling.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-24** `handles:notify-expiry` missing `withoutOverlapping` — Lens: `SCHED-2`
    - Where: `routes/console.php`
    - Fix: add `->withoutOverlapping(60)->runInBackground()`.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-25** `partna:prune-notifications` missing BOTH `withoutOverlapping` and `onOneServer` — Lens: `SCHED-3`
    - Where: `routes/console.php`
    - Fix: add `->withoutOverlapping(120)->onOneServer()`.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-26** Five scheduled tasks use bare `withoutOverlapping()` (24h default lock) — Lens: `SCHED-4`
    - Where: `routes/console.php` (entries for `AggregateCacheMetricsJob`, `horizon:snapshot`, `media:cleanup-stuck-processing`, `queue:prune-failed`, `partna:analytics:purge-raw-events`)
    - Fix: explicit per-task timeouts (10/10/30/60/30 mins respectively). Inline comments on each.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-27** Seven scheduled tasks missing `onOneServer()` — duplicate execution on multi-server — Lens: `SCHED-5`
    - Where: `routes/console.php` (7 entries — see lens file)
    - Fix: add `->onOneServer()` to each. The four that already have it form the established pattern.
    - Models: impl=haiku · review=sonnet

### KV lifecycle

- [ ] **#P2-28** Alias DB row deletion doesn't remove the corresponding KV entry — Lens: `SUBKV-2`
    - Where: `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php::writeAliasEntries()`
    - Fix: dispatch `RetireSubdomainFromKvJob::dispatch($alias->handle)` from `handles:prune-expired-aliases` (or via a model observer on the alias table's `deleted` event). Permanent (null `expires_at`) aliases never self-evict otherwise.
    - **STANDALONE** — touches SUBDOMAIN_KV writer contract. Bundle B8.
    - Models: impl=sonnet · review=opus

### Architecture cleanup

- [x] **#P2-29** Analytics `summary()` bundles date parsing, raw SQL, cache orchestration in 350 lines — Lens: `ARCH-2`
    - Where: `ProfessionalAnalyticsController.php:26`
    - Fix: extract `AnalyticsQueryService` + `AnalyticsCacheService`. Controller becomes thin delegate.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-30** Upload controller bundles pool-limit + R2 + video-probe + job dispatch — Lens: `ARCH-3`
    - Where: `ProfessionalUploadController.php`
    - Fix: extract `MediaUploadService` (or extend existing `MediaService`).
    - Models: impl=sonnet · review=sonnet

- [ ] **#P2-31** `ACTION_UNSELECT_PRODUCT` constant + DB rows are dead post-strip but ship in API — Lens: `ARCH-4`
    - Where: `app/Services/Professional/ConfirmationPreferenceService.php:15,21`
    - Fix: remove from `SUPPORTED_ACTIONS` and `defaultMap()`. Migration: `DELETE FROM core.professional_confirmation_preferences WHERE action_key = 'unselect_product'`.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-32** Bootstrap god method extraction — Lens: `ARCH-5`
    - Where: `BootstrapController.php:29` (~200 lines of mixed concerns)
    - Fix: extract `ProfessionalBootstrapService::bootstrap($uid, $data): array`. Controller becomes waitlist gate + rate limit + validation + HTTP. Pre-req for P1-01 + P0-07 fixes.
    - Models: impl=sonnet · review=opus

### Resource cleanups

- [x] **#P2-33** ID field type inconsistency across Resources — Lens: `RES-5`
    - Where: 6 Resource files (mixed `(string) $this->id` vs raw)
    - Fix: standardise to `(string) $this->id`. Consider a base `ApiResource` enforcing the cast.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-34** Manual paginated envelopes (Enquiry + Gallery) — Lens: `RES-6`
    - Where: `ProfessionalEnquiryController.php:33-42` · `ProfessionalGalleryController.php:54-70`
    - Fix: use `$this->paginatedResponse(...)`; create `GalleryImageResource`.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-35** `serializeSection()` uses `$section->toArray()` as base — internal Block columns leak — Lens: `RES-7`
    - Where: `ProfessionalSectionBlockController.php::serializeSection()`
    - Fix: explicit field allowlist. Apply same fix to `StaffSectionManagementController::upsert()`.
    - Models: impl=sonnet · review=sonnet

### Database RLS

- [x] **#P2-36** Public INSERT policies on `waitlist_signups` + `email_subscriptions` have no validation — Lens: `RLS-2`
    - Where: `supabase/migrations/20260526000000_baseline_standalone_user.sql`
    - Fix: add `WITH CHECK (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$' AND length(name) > 0)` to both policies.
    - Models: impl=sonnet · review=opus

### GDPR completeness

- [x] **#P2-37** Handle change log not in DSAR export — Lens: `GDPR-4`
    - Where: `DataExportPayloadBuilder::stream()`
    - Fix: add `streamHandleChangeLog($professionalId)`; yield under `'audit.handle_change_log'`.
    - Models: impl=sonnet · review=opus

- [x] **#P2-38** `streamLeadSubmissions` implemented but never yielded from `stream()` — Lens: `GDPR-5`
    - Where: `DataExportPayloadBuilder.php:300` and `stream()`
    - Fix: add the yield block. Decide on IP/UA redaction (mirror `enquiries()` pattern).
    - Models: impl=sonnet · review=opus

### Webhook & infrastructure security (run-2 additions)

- [x] **#P2-39** Supabase email hook secret has no deploy-time assertion — production-breaking misconfig fails silently — Lens: `WH-2` (run 2)
    - Where: `app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php:25–31`
    - What: The middleware's fail-closed 503 on missing `SUPABASE_EMAIL_HOOK_SECRET` is correct security posture, but operationally invisible — Supabase retries, gives up, and users see broken signup/password-reset/magic-link with no server-side exception in Nightwatch. A typo or missed secret-rotation ships silently.
    - Fix: in `AppServiceProvider::boot()`, throw a `RuntimeException` in `production` when `config('services.supabase.email_hook_secret')` is empty. Keep the middleware's 503 as a runtime/test-env safety net. Add `SUPABASE_EMAIL_HOOK_SECRET` to deploy-checklist comments in `.env.example`.
    - Models: impl=sonnet · review=sonnet

- [ ] **#P2-40** Error responses (4xx/5xx) bypass `SecureHeaders` — no CSP/HSTS/X-Frame on any rendered exception — Lens: `SEC-1` (run 2)
    - Where: `app/Http/Middleware/SecureHeaders.php:14` · `bootstrap/app.php` exception render closure
    - What: `SecureHeaders` calls `$response = $next($request)` with no try/catch. Exceptions propagate past line 14 unhandled; Laravel's Kernel catches them and renders a fresh response that **never re-enters the global middleware pipeline**. Result: every 401/403/404/422/423/429/500/503 ships with only `Access-Control-Allow-Origin: *` — no `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, CSP, or HSTS.
    - Fix: extract a static `SecureHeaders::applyTo(Response $r): void` helper containing the header block; call it from both the middleware's post-`$next` path AND the `withExceptions()->render()` closure in `bootstrap/app.php`, immediately before `return $response`. Single source of truth, no drift risk. Pairs with #P3-11 (CORS guard fragility — same architectural root).
    - Models: impl=sonnet · review=opus

- [ ] **#P2-41** CSP missing `form-action` and `base-uri` directives — both default to `*` — Lens: `SEC-2` (run 2)
    - Where: `app/Http/Middleware/SecureHeaders.php:48` (non-Horizon CSP) and `:35-47` (Horizon CSP)
    - What: Per CSP Level 2, `form-action` and `base-uri` are standalone directives that do NOT fall back to `default-src 'none'` — omitting them allows any origin. Horizon dashboard (the only HTML surface) is exposed: an XSS payload could inject `<form action="https://attacker/steal">` or `<base href="https://attacker/">` and neither would be CSP-blocked.
    - Fix: append `form-action 'self'; base-uri 'self'` to both the non-Horizon and Horizon CSP strings. Purely additive on the non-Horizon side (default-src is already `'none'`); safe on Horizon since forms POST back to the same origin.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-42** Cloudflare KV/Purge services silently no-op in production when misconfigured — Lens: `CF-1` (run 2)
    - Where: `app/Services/Cloudflare/CloudflareKvService.php:55–58` · `app/Services/Cloudflare/CloudflarePurgeService.php:46–49`
    - What: Both services gate every operation on `if (! $this->configured) { Log::debug(...); return; }` with no environment awareness. The guard is correct for local dev (no CF credentials), but fires equally if any production env var is absent/misspelled/dropped during rotation. `SyncSubdomainToKvJob` completes "successfully" without writing to KV; `<handle>.partna.au` never resolves; cache purges never reach the edge. Failure is undetectable until a user reports a broken subdomain.
    - Fix: add `app()->environment('production', 'staging')` check inside the `! $this->configured` branch — throw `RuntimeException` (or `Log::critical`) instead of returning silently. Keep silent no-op for `local`/`testing`. Companion: add a deploy-time check (or extend `EnvCheckService`) asserting `services.cloudflare.account_id`, `kv_namespace_id`, `api_token`, `zone_id`, and `cache_purge_token` are all non-empty in production. **Adjacent to bundle B8 (KV lifecycle)** — same writer surface.
    - Models: impl=sonnet · review=opus

### Concurrency & idempotency (B10 follow-up)

- [ ] **#P2-43** Concurrent `confirm()` (and `cancel()` / `request()`) on AccountDeletion can persist duplicate audit rows + queue duplicate mails — Lens: `IDEMPOTENCY-1` (B10 follow-up)
    - Where: `app/Services/Professional/AccountDeletionService.php:101` (confirm), `:401` (cancel), `:41` (request) · `app/Http/Controllers/Api/User/ProfessionalAccountDeletionController.php`
    - What: B10 made `executeConfirmation()` transactional, but the token check at line 123 runs on a model loaded by the route resolver BEFORE the transaction starts. Two concurrent `confirm()` calls (browser refresh mid-request, mobile double-tap, two tabs) both pass the `hash_equals` check, both enter the transaction, Postgres serializes the row UPDATE — but BOTH commit a fresh `EVENT_CONFIRMED` audit row and BOTH queue an `AccountDeletionScheduledMail`. Final DB state is correct (status, PII, token all final after race resolves); the defect is forensic accuracy (audit log says the user confirmed twice when they clicked once) and user-facing duplicate emails. Same race exists on `cancel()` and `request()` paths. Window is narrow (~100ms between route-resolver load and transaction commit) but real.
    - Fix: Bundle B27 — HTTP-layer `Idempotency-Key` middleware. Frontend sends a UUID per logical operation; middleware caches `{user_id, key} → response` in Redis with 24h TTL; replay returns cached response without re-entering the controller. Applies to all mutation endpoints, extensible beyond AccountDeletion. Per-service compare-and-set (`UPDATE … WHERE deletion_token_hash = ? RETURNING affected_rows`) is the cheaper local fix if the middleware is deferred.
    - Models: impl=sonnet · review=opus

---

## P3 — nice to have

### Resource cleanups
- [x] **#P3-01** `IndividualProfileResource` constructor has 9 positional `array` params — Lens: `RES-8`. Fix: named-args / single associative `array $sections` param. Models: impl=sonnet · review=sonnet.

### Cache hygiene
- [x] **#P3-02** `BlockObserver::onBlockMutated` double-busts site cache — Lens: `CACHE-2`. Fix: rely solely on `site->touch()` chain. Models: impl=haiku · review=sonnet.
- [x] **#P3-03** `ServiceCategoryObserver` over-invalidates 13+ keys when 4 needed — Lens: `CACHE-3`. Fix: targeted `Cache::forget()` calls. Models: impl=sonnet · review=sonnet.
- [x] **#P3-04** `BlockObserver` independent try-catches mask partial Redis-vs-Cloudflare failure — Lens: `LISTENER-1`. Fix: combined log context (or reorder). Models: impl=sonnet · review=sonnet.
- [x] **#P3-05** Silent lock-release failures produce no signal — Lens: `AUDIT-6`. Fix: optional Redis counter `INCR cache:lock_release_failures`. Models: impl=sonnet · review=sonnet.

### Architecture residue
- [ ] **#P3-06** Gallery `store()` is permanent 410 stub — Lens: `ARCH-6`. Fix: remove route + method. Models: impl=haiku · review=sonnet.
- [ ] **#P3-07** `professionalHasBookingIntegration()` hardcoded `false` — Lens: `ARCH-7`. Fix: add `// NOTE: booking removed 2026-05-22, update on reintegration`. Models: impl=haiku · review=sonnet.
- [ ] **#P3-08** `AccountCapabilitySet` 15 always-false constructor params — Lens: `ARCH-8`. Fix: drop stripped capability flags. Models: impl=sonnet · review=sonnet.
- [x] **#P3-09** `buildBlockFields()` 60+ line method in controller — Lens: `ARCH-9`. Fix: extract `LinkBlockFieldBuilder`. Models: impl=sonnet · review=sonnet.

### Bootstrap & exceptions
- [ ] **#P3-10** Dead `App\Exceptions\NoRecipientEmailException` (zero imports, duplicate of GDPR variant) — Lens: `BOOT-3`. Fix: delete the file. Models: impl=haiku · review=sonnet.
- [x] **#P3-11** Manual CORS injection in exception renderer is fragile against new branches — Lens: `BOOT-4`. Fix: extract to named closure / helper. Models: impl=sonnet · review=sonnet.

### Self-service authz doctrine
- [x] **#P3-12** `ProfessionalController::update` + `ProfessionalAccountDeletionController` omit `authorizeForUser` — Lens: `AUTH-3`. Fix: add the policy call. Pre-req for AAL2 wiring (P2-01) to actually fire. Models: impl=sonnet · review=sonnet.

### Jobs
- [x] **#P3-13** `CheckStreamingLiveStatusJob` lands on default queue despite 90s timeout — Lens: `JOBS-3`. Fix: `$this->onQueue('streaming')` in constructor. Models: impl=haiku · review=sonnet.
- [x] **#P3-14** `CheckStreamingLiveStatusJob.failed()` missing `report($e)` (unreachable today) — Lens: `JOBS-4`. Fix: add `report($e)` for safety; consider adding to per-platform `catch` in `handle()`. Models: impl=haiku · review=sonnet.

### Config & RLS hygiene
- [x] **#P3-15** Nightwatch captures exception source code by default — Lens: `CONFIG-4`. Fix: default `false`; opt-in when debugging. Models: impl=haiku · review=sonnet.
- [ ] **#P3-16** `seed.sql` is entirely dead code (guard fires immediately) — Lens: `RLS-3`. Fix: rewrite for surviving tables. Models: impl=sonnet · review=sonnet.
- [ ] **#P3-17** `config.toml` lists dropped schemas `billing`/`retail` — Lens: `RLS-4`. Fix: remove from `schemas` and `extra_search_path`. Evaluate adding `site`/`notifications` if direct PostgREST access is ever intended. Models: impl=haiku · review=sonnet.
- [x] **#P3-18** `site_media` DELETE policy is staff-only — undocumented asymmetry — Lens: `RLS-5`. Fix: add one-line comment documenting that app uses soft-delete (UPDATE deleted_at). Models: impl=haiku · review=sonnet.

### Deploy
- [ ] **#P3-19** `post-update-cmd` force-publishes vendor assets — Lens: `DEP-1`. Fix: drop `--force`. Pair with #P3-20. Models: impl=haiku · review=sonnet.

### Out of scope but flagged
- [ ] **#P3-20** `post-create-project-cmd` creates dead SQLite file — Lens: `DEP-2`. Fix: remove the line. Models: impl=haiku · review=sonnet.

### Run-2 additions (lens-16 gap closure)
- [x] **#P3-21** Dev placeholder root route `Route::get('/', fn () => 'joshua hunter is awesome')` has no rate limit and no production purpose — Lens: `RATE-1` (run 2). Where: `routes/web.php:3–5`. Fix: delete the route entirely. Other web route `/p/{professionalId}.svg` correctly carries `throttle:public-site`. Models: impl=haiku · review=sonnet.
- [ ] **#P3-22** `Access-Control-Allow-Origin: *` on every response rather than an allowlisted origins set — Lens: `SEC-3` (run 2). Where: `SecureHeaders.php:19-21` + `bootstrap/app.php` exception handler. Fix: add `frontend_origins` array to `config/partna.php` (e.g. `['https://app.partna.au', 'https://*.partna.au']`); validate `Origin` header in `SecureHeaders` and the exception render closure; echo back only on match. Defence-in-depth — Bearer tokens already prevent credentialed cross-origin reads, but `*` removes a browser-side barrier. Models: impl=sonnet · review=sonnet.
- [ ] **#P3-23** HSTS header omits `preload` — first-visit window unprotected — Lens: `SEC-4` (run 2). Where: `SecureHeaders.php:52`. Fix: change value to `max-age=31536000; includeSubDomains; preload`; verify all `*.partna.au` subdomains are HTTPS-only before submitting to hstspreload.org. Cloudflare's "Always Use HTTPS" provides partial edge mitigation today. Models: impl=haiku · review=sonnet.
- [ ] **#P3-24** No CSP violation reporting endpoint — blocked requests are silent — Lens: `SEC-5` (run 2). Where: `SecureHeaders.php:48`. Fix: add `report-uri /api/internal/csp-report` (or modern `Report-To` header with `report-to` directive). Minimal self-hosted endpoint logs the violation body via `Log::warning`; Nightwatch surfaces patterns. Especially valuable under a strict `default-src 'none'` policy where misconfigured allowlists fail silently in users' browsers. Models: impl=sonnet · review=sonnet.
- [ ] **#P3-25** `MediaDiskResolver` reads `$_ENV` / `$_SERVER` superglobals directly — Lens: `CRED-3` (run 2). Where: `MediaDiskResolver.php:31–33`. **Documented exception** — Laravel Cloud caches config at deploy time; platform-injected env vars arriving after deploy aren't visible via `config()`. The superglobal read is intentional and bounded. Fix: strengthen the existing inline comment to mark this as an approved exception so future audits / cleanups don't silently remove it. Long-term: move the probe to a service provider that sets a `partna.media_disk_runtime` config key. Models: impl=haiku · review=sonnet.
- [x] **#P3-26** Video artifacts use predictable paths (image variants use content-hashed) — Lens: `R2-1` (run 2). Where: `VideoVariantService.php` upload step 7. What: `$remotePath = "{$basePath}/{$variantKey}.mp4"` is fully deterministic given the `mediaId`. Videos spend time in `pending`/`processing` states with artifacts already public; predictable path + a known UUID = direct fetch of unprocessed content. UUIDs are only handed to authenticated users, so the enumeration path requires an auth bypass — making this P3 (defense-in-depth / consistency with image service). Fix: incorporate `substr(hash_file('sha256', $tmpMp4), 0, 16)` into each variant path, the HLS directory, the playlist, and poster.jpg. Update `adaptive.m3u8` and `MediaVariant` DB rows accordingly. Mechanical lift — `ImageVariantService` already shows the pattern. Models: impl=sonnet · review=sonnet.
- [x] **#P3-27** Original uploads stored publicly with no documented intent — Lens: `R2-2` (run 2). Where: `ImageVariantService.php::storeOriginal()` (~line 191). What: originals land in the public R2 bucket at a content-hashed path; docblock says they're for "disaster-recovery / re-processing" which doesn't require public read. Hash makes the path unguessable in isolation. Fix: if originals are DR-only, change ACL to `'private'` and update `deleteVariants()` accordingly. If intentionally public (download-original feature), add a code comment documenting the decision. Models: impl=sonnet · review=sonnet.

---

## Suggested bundled fix sessions

### Bundle B1: Symfony CVE upgrade (6 items — #P0-01..#P0-06) — Effort: S
- [x] Bundle status checkbox (merged: 441ebf53)
- Items: `#P0-01`, `#P0-02`, `#P0-03`, `#P0-04`, `#P0-05`, `#P0-06`
- Models: impl=sonnet · review=sonnet
- Rationale: all 8 CVEs resolve with one coordinated `symfony/*` bump. Doing them separately would mean N composer-update cycles + N test runs for no marginal value.
- Suggested approach: `composer require symfony/http-kernel:^7.4.12 symfony/routing:^7.4.12 symfony/mime:^7.4.12 symfony/mailer:^7.4.12 symfony/yaml:^7.4.12`; rerun `composer audit`; run `composer test`; verify no behavioral regression in mail/routing/yaml-loading paths. Open PR with the advisory IDs in the description.
- Dependencies: none — should be first into main.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation:*

> Implement the bundle above. Reference the files in each item's `Where:` line and apply each `Fix:` description. Run `composer test`. Summarise the diff.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: composer dep tree, mail/routing/yaml-loading paths
> 3. Did the implementation miss obvious edge cases?
> 4. Run `composer test`. Report the result.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B2: Bootstrap controller fix (3 items) — Effort: M
- [x] Bundle status checkbox (merged: e1d214e1 / PR #115)
- Items: `#P0-07`, `#P1-01`, `#P2-32`
- Models: impl=sonnet · review=opus
- Rationale: all three findings are in `BootstrapController.php`. The architectural fix (extract `ProfessionalBootstrapService`) makes the GDPR-1 fix and the disabled-account fix structurally easier (throwing exceptions instead of returning response objects through closures).
- Suggested approach: extract `ProfessionalBootstrapService` first; rebuild controller as a thin delegate; in the service, use exception-based control flow for both `EMAIL_ALREADY_REGISTERED` and the new `ACCOUNT_DISABLED`; fix the waitlist applicant_type as part of the rewrite (or add `'individual'` to the constraint via a new migration — flag for human decision).
- Dependencies: none.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-mandatory — architectural cascade):*

> The bundle above is architectural with cascade risk. Do NOT write code yet.
>
> 1. Read all referenced files end-to-end (`BootstrapController.php`, related services, the baseline migration's waitlist CHECK constraint)
> 2. Identify cascading effects (callers that break, tests needing updates)
> 3. Show me a numbered plan with explicit ordering of steps
> 4. Wait for my approval
>
> Then implement step-by-step, running `composer test` after each step. Summarise.

*Review (opus — security/correctness-critical):*

> The bundle above is security/correctness-critical. Review with adversarial eye — what could go wrong?
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: bootstrap signup flow, waitlist divert, disabled-account 403 path, GDPR applicant_type constraint
> 3. Edge cases the implementor likely missed (transaction-closure-returns-response object, partial-state on mid-transaction failure)
> 4. Run `composer test`. Report the result.
>
> Be paranoid. Don't validate confidently — try to break it.

### Bundle B3: PII/log hygiene sweep (5 items) — Effort: S
- [x] Bundle status checkbox
- Items: `#P1-09`, `#P1-10`, `#P1-11`, `#P2-11`, `#P2-12`
- Models: impl=sonnet · review=opus
- Rationale: every item is "stop logging/serializing a sensitive value." Same shape, mostly one-line changes. One coordinated pass produces a single PR that the privacy team can review.
- Suggested approach: change job constructors to take UUIDs not emails (look up in `handle()`); drop `'body' => $response->body()` and `'email' =>` from log contexts; add `request_id` to `StaffAuditService::record()`. Add a sweep test that asserts no `Mail::*->...->send($email)` call site uses unsanitised input.
- Dependencies: none.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first):*

> Read the bundle above. Read each file in the `Where:` lines.
>
> Show me a numbered plan first. Wait for my approval before any code.
>
> Then implement, run `composer test`, and summarise the diff.

*Review (opus — privacy-critical):*

> The bundle above is privacy-critical (GDPR / Privacy Act exposure). Review with adversarial eye — what PII could still leak?
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: log call sites in audited files, Redis job payload shapes, GDPR-erasure boundary
> 3. Edge cases the implementor likely missed (e.g. `$response->json()` substring containing email; serialised model relations in failed-job context)
> 4. Run `composer test`. Report the result.
>
> Be paranoid. Don't validate confidently — try to break it.

### Bundle B4: Resource creation pass — raw Eloquent leakage (5 items) — Effort: M
- [x] Bundle status checkbox (shipped in PR #117, merge e10ae2b3, 2026-05-24)
- Items: `#P1-05`, `#P1-06`, `#P1-07`, `#P1-08`, `#P2-35`
- Models: impl=sonnet · review=sonnet
- Rationale: creating Resource classes is one focused exercise. Doing them piecemeal means 5 small PRs each needing a frontend stakeholder review for shape verification.
- Suggested approach: build `ServiceResource`, `ServiceCategoryResource`, `LinkBlockResource`, `SiteResource`, `ThemeResource`, `SectionBlockResource`. Wire into all professional + staff controller methods. Fix the `pagination` → `meta` rename. Verify frontend compatibility before merge.
- Dependencies: none, but coordinate with frontend.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first):*

> Read the bundle above. Read each file in the `Where:` lines.
>
> Show me a numbered plan first — include which Resources you'll create, their field allowlists, and which controller methods get wrapped. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: API response shapes for `/services`, `/site`, `/themes`, `/links`, `/customers`; staff endpoints
> 3. Did the implementation miss obvious edge cases (e.g. `null` field handling, nested relation serialisation)?
> 4. Run `composer test`. Report the result.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B5: Resource doctrine cleanups (3 items) — Effort: S
- [x] Bundle status checkbox (shipped on `development`, 2026-05-24)
- Items: `#P2-33`, `#P2-34`, `#P3-01`
- Models: impl=sonnet · review=sonnet
- Rationale: all three are doctrine-consistency fixes. Best done after B4 lands so all Resources exist before standardising patterns across them.
- Suggested approach: introduce base `ApiResource` (or trait) enforcing `(string) $this->id`; refactor manual paginated envelopes; rewrite `IndividualProfileResource` constructor.
- Dependencies: B4.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first):*

> Read the bundle above. B4 must be merged first — confirm by reading the new Resource files.
>
> Show me a numbered plan: where you'll put `ApiResource` (or trait), which existing Resources adopt it first, how `IndividualProfileResource` is rewritten. Wait for my approval.
>
> Then implement, run `composer test`, summarise the diff.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: Resource class doctrine, paginated envelope shape, `IndividualProfileResource` call site
> 3. Did the implementation miss obvious edge cases (e.g. `null` UUIDs, missing optional fields)?
> 4. Run `composer test`. Report the result.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B6: Scheduler hardening pass (5 items) — Effort: S (~30min)
- [x] Bundle status checkbox
- Items: `#P2-23`, `#P2-24`, `#P2-25`, `#P2-26`, `#P2-27`
- Models: impl=haiku · review=sonnet
- Rationale: all five are in `routes/console.php`. The 30-min change pattern is `add ->onOneServer()` + `add ->withoutOverlapping(N)` per task with explicit timeouts.
- Suggested approach: one PR walking through every `Schedule::` entry; align with the 4 well-configured tasks (`handles:prune-expired-aliases`, `handles:notify-expiry`, `feature-flags:prune-expired`, `partna:backfill-subdomain-kv`). Add a brief comment block at the top of the file documenting the pattern so future entries inherit it.
- Dependencies: none.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation:*

> Implement the bundle above. Reference the files in each item's `Where:` line and apply each `Fix:` description. Run `composer test`. Summarise the diff.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: scheduler entries in `routes/console.php`, multi-server safety, lock timeout values vs each task's expected runtime
> 3. Did the implementation miss obvious edge cases (e.g. a scheduled job longer than the lock timeout)?
> 4. Run `composer test`. Report the result.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B7: GDPR/DSAR completeness (4 items) — Effort: S-M
- [x] Bundle status checkbox
- Items: `#P1-15`, `#P1-16`, `#P2-37`, `#P2-38`
- **Review expanded scope:** opus review surfaced 11 additional GDPR-completeness gaps not in the original 4 items; all addressed in this PR. See commit body / `audits/foundation-audit-v1/audit-2026-05-24-gdpr-end-to-end-completeness-dsar-coverage-deletio.md` for the full set.
- Models: impl=sonnet · review=opus
- Rationale: all in `DataExportPayloadBuilder.php`. The pattern is identical: add a `stream<Table>()` generator + yield from `stream()`.
- Suggested approach: write the 4 new stream methods; yield each; add seeded-row tests for each table; ensure `streamLeadSubmissions` redaction matches `enquiries()`. One PR.
- Dependencies: none.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first):*

> Read the bundle above. Read `DataExportPayloadBuilder.php` and the baseline migration for the schemas of `waitlist_signups`, `email_subscriptions`, `handle_change_log`, and the lead-submissions table.
>
> Show me a numbered plan: each new stream method's query shape, redaction policy, and yield key. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review (opus — GDPR/Article-15 compliance):*

> The bundle above is GDPR/Article-15 compliance work. Review with adversarial eye — what PII is still missing from the export?
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: `DataExportPayloadBuilder` yield list, DSAR completeness, PII redaction (especially for `lead_submissions` IP/UA fields)
> 3. Edge cases: is there ANY other table holding PII by email that isn't in the yield list? Did the implementor cross-reference the schema?
> 4. Run `composer test`. Report the result.
>
> Be paranoid. An Article-15 gap is a regulatory risk — don't validate confidently.

### Bundle B8: Cloudflare KV lifecycle (2 items) — Effort: S
- [ ] Bundle status checkbox
- Items: `#P1-12`, `#P2-28`
- Models: impl=sonnet · review=opus
- Rationale: both add dispatch sites for `RetireSubdomainFromKvJob` (the existing retire job). The single-writer invariant (`SyncSubdomainToKvJob` for writes) is preserved — `RetireSubdomainFromKvJob` is the explicit delete-only counterpart.
- Suggested approach: add the dispatch in `ProfessionalObserver::deleted()`; add the dispatch in `handles:prune-expired-aliases` (or via an alias-table observer). Add feature tests asserting the retire job is pushed.
- Dependencies: none.
- **WARNING — sensitive area**: touches the SUBDOMAIN_KV writer contract. Reviewer must verify no other write path is introduced.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first — sensitive writer surface):*

> Read the bundle above. This touches the SUBDOMAIN_KV single-writer invariant — `SyncSubdomainToKvJob` is the only write path; `RetireSubdomainFromKvJob` is the explicit delete-side counterpart.
>
> 1. Read `ProfessionalObserver`, `SyncSubdomainToKvJob`, `RetireSubdomainFromKvJob`, and `handles:prune-expired-aliases`
> 2. Confirm you understand which jobs write to KV vs delete from KV
> 3. Show me a numbered plan: where you'll dispatch `RetireSubdomainFromKvJob`, what tests prove the invariant holds. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review (opus — single-writer invariant, security-critical):*

> The bundle above touches the SUBDOMAIN_KV single-writer invariant. Review with adversarial eye.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: SUBDOMAIN_KV single-writer invariant, subdomain routing, alias resolution. **Verify no new KV write path was introduced — only `SyncSubdomainToKvJob` should write; only `RetireSubdomainFromKvJob` should delete.**
> 3. Edge cases: what happens on observer-fire when handle is null? on already-deleted KV entry? on retry storm?
> 4. Run `composer test`. Report the result.
>
> Be paranoid. The single-writer invariant must hold — try to find a way to break it.

### Bundle B9: BaseTransactionalMail migration + mail safety (5 items) — Effort: M
- [x] Bundle status checkbox
- Items: `#P1-14`, `#P1-17`, `#P2-18`, `#P2-19`, `#P2-20`
- Models: impl=sonnet · review=sonnet
- Rationale: all touch `app/Mail/*` or `resources/views/emails/*` or `EventServiceProvider`. The migration to `BaseTransactionalMail` is the structural fix; the others are localized hardening that's natural to do in the same pass.
- Suggested approach: migrate 11 mailables; add Pest arch test; fix CRLF strip in `SiteEnquiryNotification`; remove OTP from subject/preheader; escape preheaders in 4 notification templates; add `parent::boot()` to `EventServiceProvider`. One PR; reviewer should walk the email-rendering test suite locally.
- Dependencies: none.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first):*

> Read the bundle above. Read `BaseTransactionalMail`, the 11 listed mailables, the 4 notification blade templates, and `EventServiceProvider`.
>
> Show me a numbered plan: which mailables get migrated first, how the `Envelope`/`Content` fluent API ones are handled, and the Pest arch test shape. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: mail send layer, `X-Partna-Pipeline` header presence, subject/preheader rendering, `EventServiceProvider::boot()` order
> 3. Did the implementation miss obvious edge cases (preheader still containing template vars, CRLF strip not applied to all user-input fields)?
> 4. Run `composer test`. Optionally render one of each email locally to verify visual integrity.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B10: AccountDeletionService correctness (3 items) — Effort: M
- [x] Bundle status checkbox
- Items: `#P2-05`, `#P2-06`, `#P2-07`
- Models: impl=sonnet · review=opus
- Rationale: all in `AccountDeletionService.php`. Transaction unification fixes both DEL-1 (PII-wipe outside transaction) and DEL-2 (token race) simultaneously. Email queueing is a natural companion.
- Suggested approach: unify the deletion-confirmation transaction; collapse the token write+rollback into a `DB::transaction` with throw-on-mail-failure; create `SendAccountDeletionRequestMailJob` for the request path; queue the confirmation mail directly.
- Dependencies: none.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first — transaction correctness):*

> Read the bundle above. Read `AccountDeletionService.php` end-to-end — pay close attention to the audit-then-pseudonymise ordering invariant in the existing code comments.
>
> Show me a numbered plan: where the unified `DB::transaction` boundary sits, how the `$emailSnapshot` is passed to `logAuditEvent`, how `SendAccountDeletionRequestMailJob` preserves the rollback-on-failure invariant. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review (opus — transaction boundaries + GDPR + concurrency):*

> The bundle above touches transaction boundaries, PII pseudonymisation, and concurrency. Review with adversarial eye.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: deletion transaction boundary, token race window, PII pseudonymisation timing, mail-rollback invariant (user holds token IFF received email)
> 3. Edge cases: what happens on `Mail::queue` failure (job dispatch fails after token written)? On admin-initiated deletion path (line 273)? On double-submit?
> 4. Run `composer test`. Report the result.
>
> Be paranoid. A subtle wrong fix here = persistent PII in pending_deletion accounts.

### Bundle B11: Post-strip dead code cleanup (9 items) — Effort: S-M
- [ ] Bundle status checkbox
- Items: `#P2-13`, `#P2-17`, `#P2-31`, `#P3-06`, `#P3-07`, `#P3-08`, `#P3-10`, `#P3-16`, `#P3-17`
- Models: impl=sonnet · review=sonnet
- Rationale: every item is "remove a thing that the standalone strip left orphaned." Same root cause (the 2026-05-22 strip), same risk profile (low — none of these are load-bearing), one focused cleanup PR.
- Suggested approach: walk each item; for `AccountType` constraint, write a supabase migration that runs the `UPDATE` then adds the CHECK; for `search_path` and `config.toml`, drop the dropped schemas; delete dead code files; rewrite `seed.sql` for surviving tables. Confirm zero references for each delete via grep before removing.
- Dependencies: should run after #P1-01 / #P2-32 (Bundle B2) so the Bootstrap rewrite is in.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first):*

> Read the bundle above. Confirm B2 (Bootstrap) is merged before starting.
>
> For each item, grep the codebase to verify zero references before deleting. Show me a numbered plan with: which files deleted, which lines modified, which migration runs the AccountType UPDATE + CHECK constraint. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: `AccountType` enum + new CHECK constraint, removed-feature artifacts (no API still shipping `unselect_product`), dead code references
> 3. Edge cases: did the AccountType migration include the UPDATE before the CHECK? Are there any dev/CI rows still violating?
> 4. Run `composer test`. Report the result.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B12: Job retry safety (5 items) — Effort: S
- [x] Bundle status checkbox
- Items: `#P2-08`, `#P2-09`, `#P2-14`, `#P3-13`, `#P3-14`
- Models: impl=sonnet · review=sonnet
- Rationale: all are `report()`/idempotency/queue-lane fixes scattered across job files. Same review surface (Horizon dashboard behavior, Nightwatch alerts).
- Suggested approach: add `report($e)` calls; introduce `email_sent_at` column on `data_export_audits` via migration; guard the `Mail::send` with the new column; assign `streaming` queue to `CheckStreamingLiveStatusJob`; add status check to `SendTransactionalNotificationEmailJob`.
- Dependencies: none.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first):*

> Read the bundle above. Read each referenced job file.
>
> Show me a numbered plan: the migration shape for `email_sent_at`, the guard placement in `ExportProfessionalDataJob::handle()`, and the queue lane assignment. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: Horizon dashboard, Nightwatch alerts, `ExportProfessionalDataJob` idempotency under retry
> 3. Edge cases: what happens if `email_sent_at` write fails after `Mail::send()`? Does the queue lane have workers provisioned?
> 4. Run `composer test`. Report the result.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B13: Config defaults hardening (3 items) — Effort: S
- [x] Bundle status checkbox
- Items: `#P2-15`, `#P2-16`, `#P3-15`
- Models: impl=haiku · review=sonnet
- Rationale: all are `config/*.php` default changes plus `EnvCheckService` additions.
- Suggested approach: change defaults; update `EnvCheckService::REQUIRED`/`RECOMMENDED`; explicit values in production `.env`.
- Dependencies: none.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation:*

> Implement the bundle above. Reference the files in each item's `Where:` line and apply each `Fix:` description. Run `composer test`. Summarise the diff.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: config defaults, `EnvCheckService` coverage, `.env.example` consistency
> 3. Did the implementation miss obvious edge cases (e.g. log level change breaking a developer's local debugging)?
> 4. Run `composer test`. Report the result.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B14: Cache layering hardening (4 items) — Effort: S
- [x] Bundle status checkbox
- Items: `#P2-10`, `#P3-02`, `#P3-03`, `#P3-04`
- Models: impl=sonnet · review=sonnet
- Rationale: all touch `SiteCacheService`/`ProfessionalCacheService`/Observers. SWR healing + over-invalidation pruning + observer consolidation = one cache-correctness PR.
- Suggested approach: add SWR healing (matches primary-path); drop redundant `invalidateSite` from `BlockObserver`; targeted `Cache::forget` in `ServiceCategoryObserver`; combined try-catch in `BlockObserver` to surface Redis-vs-Cloudflare failure pairs.
- Dependencies: none, but verify P2-10 (SWR healing) before the V2-strip cache TTL has fully expired (~2.5h from each affected site's last cache write).

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first):*

> Read the bundle above. Read `SiteCacheService.php` (especially the SWR fast-path), `BlockObserver.php`, `ServiceCategoryObserver.php`.
>
> Show me a numbered plan: how the SWR healing mirrors the primary-hit path, what specific cache keys `ServiceCategoryObserver` should target instead of full invalidation. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: `SiteCacheService` SWR path, `BlockObserver` invalidation, `ServiceCategoryObserver` invalidation, cache stampede risk
> 3. Did the implementation miss obvious edge cases (e.g. stale-key healing race when both primary and stale expire simultaneously)?
> 4. Run `composer test`. Report the result.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B15: Architecture extraction (3 items) — Effort: L
- [x] Bundle status checkbox
- Items: `#P2-29`, `#P2-30`, `#P3-09`
- Models: impl=sonnet · review=sonnet
- Rationale: each item is "controller has 200+ lines of business logic; extract service." Same refactor pattern; cumulative reviewer fatigue is lower if grouped.
- Suggested approach: extract `AnalyticsQueryService` + `AnalyticsCacheService`; extract `MediaUploadService`; extract `LinkBlockFieldBuilder`. Each becomes a thin-delegate controller.
- Dependencies: none, but high-touch — recommend splitting into separate PRs per extraction if size becomes unwieldy.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-mandatory — large refactor with cascade risk):*

> The bundle above is large architectural extraction with cascade risk. Do NOT write code yet.
>
> 1. Read each referenced controller end-to-end (`ProfessionalAnalyticsController`, `ProfessionalUploadController`, `ProfessionalLinkBlockController`)
> 2. Identify cascading effects (existing tests, related controllers, observer dependencies)
> 3. Show me a numbered plan with explicit ordering and which extractions can be split into separate PRs
> 4. Wait for my approval — confirm if I want one PR or split
>
> Then implement step-by-step, running `composer test` after each extraction. Summarise.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: `AnalyticsQueryService` extraction, `MediaUploadService` extraction, `LinkBlockFieldBuilder` extraction, related controller behaviour
> 3. Did the implementation miss obvious edge cases (e.g. error paths, transaction boundaries, request validation flow)?
> 4. Run `composer test`. Report the result.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B16: Auth policy doctrine (3 items) — Effort: S-M
- [x] Bundle status checkbox
- Items: `#P2-01`, `#P2-02`, `#P3-12`
- Models: impl=sonnet · review=opus
- Rationale: doctrine consistency — every authenticated mutation should call `authorizeForUser`. Once #P3-12 is fixed, #P2-01 (AAL2 hook) automatically wires through the policy.
- Suggested approach: fix link-block store/reorder skipping; add `authorizeForUser` to self-service controllers; wire `requiresFreshAal2()` into `ProfessionalSelfPolicy::update` + `delete` + account-deletion `confirm`.
- Dependencies: should land after Bundle B2 (Bootstrap) so policy calls are exercised by the new service path.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first — auth correctness):*

> Read the bundle above. Confirm B2 (Bootstrap) is merged. Read `BasePolicy`, `ProfessionalSelfPolicy`, `ProfessionalLinkBlockController`, `ProfessionalController::update`, and `ProfessionalAccountDeletionController`.
>
> Show me a numbered plan: which policy methods get `requiresFreshAal2()`, the skeleton-pattern for link-block create, where `authorizeForUser` gets added to self-service controllers. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review (opus — auth correctness):*

> The bundle above is auth correctness work. Review with adversarial eye — what auth bypass is now possible?
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: `authorizeForUser` doctrine (CI-enforced no inline 403 in controllers), AAL2 policy hooks firing, `ProfessionalSelfPolicy` method coverage
> 3. Edge cases: is there ANY mutation route on the professional surface that still skips the policy? Did AAL2 hook fire on the right methods only?
> 4. Run `composer test`. Report the result.
>
> Be paranoid. Auth bugs at scale = mass data exposure.

### Bundle B17: Bootstrap & exception hardening (4 items) — Effort: S
- [x] Bundle status checkbox
- Items: `#P2-21`, `#P2-22`, `#P3-05`, `#P3-11`
- Models: impl=sonnet · review=sonnet
- Rationale: all touch `bootstrap/app.php` or adjacent middleware/exception code. Same review surface (exception renderer + middleware stack).
- Suggested approach: introduce `HttpStatusCodeInterface`; create `auth.api` group; extract CORS guard; add lock-release failure counter.
- Dependencies: none.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first):*

> Read the bundle above. Read `bootstrap/app.php`, the relevant domain exceptions (`KickRateLimitException`, `DataExportInProgressException`), and the existing CORS-injection block in the exception render closure.
>
> Show me a numbered plan: where `HttpStatusCodeInterface` lives, how the `auth.api` group is wired, how the CORS guard becomes a reusable helper. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: exception render closure, middleware group structure (`current.pro` resolution on all auth routes), CORS guard helper coverage on error responses
> 3. Did the implementation miss obvious edge cases (e.g. an unhandled exception type still hitting the generic 500 path)?
> 4. Run `composer test`. Report the result.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B18: Media pipeline hardening (2 items) — Effort: S-M
- [x] Bundle status checkbox
- Items: `#P1-03`, `#P1-04` (note: `#P2-30` lives in B15 for the architectural extraction)
- Models: impl=sonnet · review=opus
- Rationale: image MIME validation + video delete safety. Both touch `app/Services/Media/`.
- Suggested approach: add `finfo` byte-sniff to `storeOriginal()`; stream `fopen` instead of `file_get_contents`; refactor `VideoVariantService::deleteVariants` to best-effort delete loop with deferred-unconditional DB cleanup.
- Dependencies: none.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first — security gate placement is critical):*

> Read the bundle above. Read `ImageVariantService::storeOriginal()` and `VideoVariantService::deleteVariants()` end-to-end.
>
> Show me a numbered plan: where the `finfo` byte-sniff sits (must precede `$this->disk()->put()`), how the stream-based upload swaps in, and the best-effort delete loop structure. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review (opus — security gate placement):*

> The bundle above includes a security gate (MIME validation) whose placement is critical. Review with adversarial eye.
>
> 1. Does each fix match its finding?
> 2. **Verify MIME validation precedes `$this->disk()->put()`** — if a single line allows the put to fire before validation, the gate is defeated.
> 3. Check for regressions in: video delete loop atomicity (DB cleanup must run even if some files fail), memory pressure (no `file_get_contents` on large uploads), R2 public bucket scope
> 4. Edge cases: SVG with malicious script content, MIME-mismatch on a hash collision, transient R2 error mid-loop
> 5. Run `composer test`. Report the result.
>
> Be paranoid. A spoofed MIME landing in the public bucket = stored XSS on the Partna domain.

### Bundle B19: app_backend BYPASSRLS revocation (1 item — XL) — Effort: XL (~16–32h)
- [ ] Bundle status checkbox
- Items: `#P1-13`
- Models: impl=opus · review=opus
- Rationale: this is one large item that touches every schema. Doing it standalone keeps blast radius contained.
- Suggested approach: incremental — for each schema (`core`, `site`, `notifications`, `analytics`), enumerate tables without an `app_backend` policy; add `FOR ALL TO app_backend USING (true) WITH CHECK (true)` to each; verify nothing breaks at each step; finally drop `BYPASSRLS`. Add integration tests asserting RLS blocks `app_backend` on tables where it shouldn't write (e.g., `core.partna_staff` from non-admin context).
- Dependencies: should NOT bundle with anything else.
- **WARNING — production-affecting schema work**.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (one-schema-per-session — DO NOT do the full bundle in one shot):*

> B19 is XL effort across multiple schemas (`core`, `site`, `notifications`, `analytics`). Do NOT implement the entire bundle in this session.
>
> For THIS session: pick ONE schema only (start with `core`).
>
> 1. Read the baseline migration to enumerate tables in that schema
> 2. Identify tables `app_backend` touches but lacks an explicit policy (`FOR ALL TO app_backend USING (true) WITH CHECK (true)`)
> 3. Show me a plan: which tables get policies, what each policy says, what tests verify the policy holds
> 4. Wait for my approval
>
> Then implement for THIS schema only via a supabase migration. Run `composer test`. Summarise.
>
> The BYPASSRLS revocation itself happens in a final session AFTER all schemas are covered — do NOT remove BYPASSRLS in this session.

*Review (opus — schema-wide security work):*

> The bundle above is the most security-critical work in the entire audit. Review with maximum adversarial eye.
>
> 1. Does each new policy correctly cover the tables `app_backend` legitimately reads/writes for THIS schema?
> 2. Check for regressions in: RLS policies, `app_backend` privileges, tenant isolation, RLS bypass via `service_role`
> 3. Edge cases: cross-schema joins, raw `DB::raw` queries, queue-job-triggered DB writes
> 4. Run `composer test`. Report the result.
>
> Be maximally paranoid. This is the audit's load-bearing security improvement — get it wrong and tenant data leaks across users.

### Bundle B20: JWT revocation tradeoff decision (2 items) — Effort: M (or S if accepting tradeoff)
- [ ] Bundle status checkbox
- Items: `#P2-03`, `#P2-04`
- Models: impl=opus (for the design decision) → sonnet (for implementation) · review=opus
- Rationale: both are the same design decision — should we trust the JWT claim or check session liveness on every request?
- Suggested approach: requires Josh's decision. Path A: shorten Supabase token TTL to 15 minutes (no code) + document the tradeoff. Path B: implement Redis-backed session liveness check using already-extracted `supabase_session_id`. Path C: hybrid (revocation blocklist on logout/admin action, no per-request liveness).
- Dependencies: human decision before any code.
- **STANDALONE — needs human-in-loop**.

**Session prompt** (paste with the bundle — this is a DESIGN conversation, not implementation):

*Design — opus model — DO NOT WRITE CODE:*

> B20 needs a design decision before any code. Do NOT write code in this session.
>
> Read the bundle above. Then evaluate the three paths:
>
> - **Path A**: shorten Supabase JWT TTL to 15 minutes (no code — Supabase Auth setting only)
> - **Path B**: Redis-backed session liveness check on every request using already-extracted `supabase_session_id` (~100ms upstream when uncached, ~1ms cached via 60s TTL)
> - **Path C**: hybrid — Redis blocklist written on logout / admin-revoke webhook, no per-request liveness check (low-latency, but blocklist may miss revocations Supabase makes outside the webhook path)
>
> For each path lay out:
> 1. Implementation cost (hours)
> 2. Request-path latency cost
> 3. Security delta vs status quo (which attack windows shrink, which remain)
> 4. Operational complexity (e.g. cache cluster requirements, webhook reliability requirements)
>
> Recommend one with reasoning. Wait for my decision before any code is written.

*(After decision lands, a follow-up session implements the chosen path with `sonnet` for impl + `opus` for review.)*

### Bundle B21: Public-RLS input validation + policy documentation (2 items) — Effort: S
- [x] Bundle status checkbox
- Items: `#P2-36`, `#P3-18`
- Models: impl=sonnet · review=opus
- Rationale: both touch RLS policy definitions. The WITH CHECK email regex on public inserts (P2-36) is a real hardening; the site_media DELETE policy comment (P3-18) is a one-line documentation fix on the same migration file. Bundling them keeps the migration churn in one PR.
- Suggested approach: one supabase migration adding the WITH CHECK clauses + the documentation comment above `site_media_delete_staff`. Verify Laravel-side inserts still pass through (they should — they don't fabricate emails).
- Dependencies: none, but coordinate timing if other RLS work (B19) is in flight.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first — schema migration):*

> Read the bundle above. Read the existing RLS policies on `waitlist_signups` and `email_subscriptions` in the baseline migration, and the existing `site_media_delete_staff` policy.
>
> Show me a numbered plan: the exact regex used in the WITH CHECK clause, how the comment documents the staff-only DELETE asymmetry, and how to verify Laravel-side inserts still pass through. Wait for my approval.
>
> Then implement via a supabase migration, run `composer test`, and summarise the diff.

*Review (opus — schema migration affects all environments):*

> The bundle above is a schema migration that changes RLS policy behaviour. Review with adversarial eye.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: RLS WITH CHECK clauses, public insert surface (do legitimate Laravel-side inserts still pass?), schema migration safety (does it deploy cleanly?)
> 3. Edge cases: international email format (e.g. `user@xn--brand`), names with leading/trailing whitespace, missing values
> 4. Run `composer test`. Report the result.
>
> Be paranoid. A regex regression breaks all signups silently.

### Bundle B22: Dependency drift cleanup (1 grouped item) — Effort: S
- [x] Bundle status checkbox
- Items: `#DEP-MIN-1`
- Models: impl=sonnet · review=sonnet
- Rationale: ten patch/minor bumps in one PR. Major bumps (`#DEP-MAJ-1..3`) stay deferred.
- Suggested approach: `composer update <package> ... --with-all-dependencies` for the listed packages; run `composer test`; verify Horizon dashboard, Pail, and Nightwatch still function.
- Dependencies: after Bundle B1 (Symfony CVEs).

**Session prompts** (paste with the bundle into a fresh session):

*Implementation:*

> Implement the bundle above. Confirm B1 (Symfony CVE upgrade) is already merged. Run `composer outdated --direct` first to confirm the list; then `composer update` the named packages with `--with-all-dependencies`. Run `composer test`. Summarise the diff.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding (correct packages bumped, lockfile clean)?
> 2. Check for regressions in: composer dep tree, Horizon dashboard functionality, Pail tailing, Nightwatch reporting
> 3. Did the implementation miss obvious edge cases (deprecation warnings, removed APIs in the bumped packages)?
> 4. Run `composer test`. Report the result.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B23: Composer-script cleanup (2 items) — Effort: S
- [ ] Bundle status checkbox
- Items: `#P3-19`, `#P3-20`
- Models: impl=haiku · review=sonnet
- Rationale: both are 1-line `composer.json` edits.
- Suggested approach: remove `--force` from `post-update-cmd`; remove the SQLite touch from `post-create-project-cmd`.
- Dependencies: none.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation:*

> Implement the bundle above. Reference the files in each item's `Where:` line and apply each `Fix:` description. Run `composer test`. Summarise the diff.

*Review:*

> Review the implementation of the bundle above.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: composer.json scripts, project setup flow for a new contributor
> 3. Did the implementation miss obvious edge cases?
> 4. Run `composer test`. Report the result.
>
> Be skeptical — the implementor had tunnel vision; you're the cold eye.

### Bundle B24: Webhook hardening (4 items) — Effort: S
- [x] Bundle status checkbox
- Items: `#P1-02`, `#P1-18`, `#P2-39`, `#P3-21`
- Models: impl=sonnet · review=opus
- Rationale: all four touch the Supabase email-hook surface or its rate-limit posture. Queue the mail + webhook-id dedup + boot-time secret assertion + removing the unrelated `/` route are best done as one focused PR so the reviewer's mental model is "audit-hook + rate-limit hygiene." Doing P1-02 and P1-18 together is especially valuable — together they make retries cheap no-ops AND make replays impossible.
- Suggested approach: switch `Mail::send()` → `Mail::queue()` in the controller (P1-02); implement the `Cache::add()` dedup check (TTL = `TIMESTAMP_TOLERANCE`); add the `AppServiceProvider` production-only assertion for `services.supabase.email_hook_secret`; delete the dev placeholder route from `routes/web.php`. Add a feature test that replays the same `webhook-id` twice and asserts the second is a no-op.
- Dependencies: ideally lands AFTER Bundle B1 (Symfony CVE upgrade) — the IsGranted/HEAD bypass is a webhook-adjacent concern and merging them out of order leaves a short window.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first — security gate):*

> Read the bundle above. Read `SupabaseEmailHookController`, `VerifySupabaseEmailHookSignature`, `SupabaseEmailHookSignatureVerifier`, and `AppServiceProvider::boot()`.
>
> Show me a numbered plan: where the `Cache::add()` dedup check sits (must be AFTER signature validation passes, BEFORE `Mail::queue`), how `webhook-id` flows from middleware to controller, where the boot-time secret assertion lives, and the test that replays the same `webhook-id` twice. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review (opus — security + replay protection):*

> The bundle above is webhook security work. Review with adversarial eye — how could an attacker replay or bypass?
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: Supabase email-hook controller (signature → dedup → queue order), signature verification, replay window (TTL matches `TIMESTAMP_TOLERANCE`), mail queueing
> 3. Edge cases: cache eviction within the 300s window, `webhook-id` collision/spoofing, queue-dispatch failure after dedup write, missing/malformed `webhook-id` header
> 4. Run `composer test`. Report the result.
>
> Be paranoid. A replay window = duplicate auth emails at minimum, potentially worse.

### Bundle B25: Security headers & CORS completeness (5 items) — Effort: S-M
- [ ] Bundle status checkbox
- Items: `#P2-40`, `#P2-41`, `#P3-22`, `#P3-23`, `#P3-24`
- Models: impl=sonnet · review=opus
- Rationale: all five are header-string changes in `SecureHeaders.php` + `bootstrap/app.php` exception render closure. Per the SEC-1 / SEC-3 architectural note: error responses bypass `SecureHeaders`, so a shared `SecureHeaders::applyTo(Response $r): void` static method is the cleanest fix — adopted once, both the middleware and the exception handler call it. CORS allowlist + CSP `form-action`/`base-uri` + HSTS `preload` + CSP `report-uri` all sit naturally within that one helper.
- Suggested approach: extract the helper; update both call sites; add `form-action 'self'; base-uri 'self'` to both CSP strings; change HSTS to `max-age=31536000; includeSubDomains; preload` in non-local envs; add `report-uri /api/internal/csp-report` and a minimal logging endpoint for it; replace wildcard `Access-Control-Allow-Origin` with allowlist validation against `config('partna.frontend_origins')`. Companion: subsume #P3-11 (CORS guard fragility) into the same refactor since it has the same architectural root.
- Dependencies: should land BEFORE the public-traffic pilot — these are the headers browsers expect on a production API.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first — shared helper to avoid drift):*

> Read the bundle above. Read `SecureHeaders.php` and the exception render closure in `bootstrap/app.php`.
>
> Show me a numbered plan: the static `SecureHeaders::applyTo(Response $r): void` helper signature, both call sites that invoke it, the CSP additions (`form-action 'self'; base-uri 'self'`), the HSTS `preload` change, the `report-uri` endpoint route + handler, and the CORS allowlist config. Also confirm #P3-11 (CORS guard fragility) is subsumed in the same refactor. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review (opus — security architecture):*

> The bundle above is the security-headers + CORS architecture. Review with adversarial eye.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: `SecureHeaders` helper, exception render closure (every 4xx/5xx ships full headers), CSP/HSTS/CORS allowlist, `Origin` header validation
> 3. Edge cases: missing/unknown `Origin` header behaviour, browser preflight (OPTIONS) handling, CSP report-uri endpoint reachability, HSTS rollback risk if `preload` ever needs removal
> 4. Run `composer test`. Report the result.
>
> Be paranoid. A wrong CORS allowlist locks out the frontend; a wrong CSP breaks the Horizon dashboard.

### Bundle B26: Cloudflare service guardrails (3 items) — Effort: S
- [x] Bundle status checkbox — merged to development 2026-05-25 (commit 6910c838)
- Items: `#P2-42`, `#P3-26`, `#P3-27`
- Models: impl=sonnet · review=opus
- Rationale: all three are in `app/Services/Cloudflare/*` and `app/Services/Media/*` — same domain, same risk surface (silent prod failure → unrouted/visible content).
- Suggested approach: add the `environment('production', 'staging')` guard inside the `! $this->configured` branch on both Cloudflare services (throw on misconfig); extend `EnvCheckService` with the CF env keys; port the content-hash pattern from `ImageVariantService` to `VideoVariantService` (variants, HLS dir, playlist, poster); decide and document the `storeOriginal()` ACL.
- Dependencies: should NOT land same PR as Bundle B8 (KV lifecycle) — both touch the SUBDOMAIN_KV writer path. Sequence B8 first, B26 second to keep blast radius small per PR.

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first — KV writer adjacent):*

> Read the bundle above. Confirm B8 (KV lifecycle) is already merged. Read `CloudflareKvService`, `CloudflarePurgeService`, `VideoVariantService`, and `ImageVariantService::storeOriginal()`.
>
> Show me a numbered plan: where the `app()->environment('production', 'staging')` guard sits in each service's `!$this->configured` branch, the EnvCheckService additions, the variant-path hash structure for video, and the storeOriginal ACL decision (private vs public-with-comment). Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review (opus — KV writer adjacency + production fail-loud semantics):*

> The bundle above sits adjacent to the SUBDOMAIN_KV writer contract and changes production fail-loud semantics. Review with adversarial eye.
>
> 1. Does each fix match its finding?
> 2. Check for regressions in: Cloudflare KV/Purge services, env-aware guards (local-dev still no-ops correctly), KV writer adjacency (no new write path introduced), video variant paths, R2 ACL semantics
> 3. Edge cases: a single CF env var missing in production (should throw, NOT silently degrade); a `staging` env without CF (should also throw); legitimate local dev (should still silently no-op); video hash collision; existing `MediaVariant` rows referencing the old non-hashed paths
> 4. Run `composer test`. Report the result.
>
> Be paranoid. Silent production failure is what this fix is trying to prevent — don't reintroduce it via a wrong env check.

### Bundle B27: Idempotency-key middleware (1 item — #P2-43) — Effort: M-L
- [ ] Bundle status checkbox
- Items: `#P2-43`
- Models: impl=sonnet · review=opus
- Rationale: HTTP-layer idempotency middleware closes the concurrent-double-submit class of bug across every mutation endpoint, not just AccountDeletion. Frontend sends `Idempotency-Key: <uuid>` per logical user action; middleware caches the first response in Redis (24h TTL) and replays on retries. Eliminates duplicate-audit-row + duplicate-mail risk on confirm/cancel/request today, and prevents future endpoints from re-introducing the same race. Discovered during B10 adversarial review — the unified transaction in B10 narrowed the race but doesn't eliminate it because the token check runs on a route-resolver-loaded model before the transaction starts.
- Suggested approach:
    1. `App\Http\Middleware\IdempotencyKey` middleware: read `Idempotency-Key` header (UUID v4 format); skip if absent (opt-in, backward-compatible).
    2. Redis key: `idempotency:{user_id}:{key}` → JSON `{status, headers, body}`. TTL 24h.
    3. On request: if key exists, return cached response immediately (add `Idempotency-Replayed: true` header). If absent, run handler, capture response, write to Redis, return.
    4. Decide error-caching policy: cache 2xx/4xx only (NOT 5xx) so users can retry after transient failures without being stuck on the replayed error.
    5. Apply to `confirm`, `cancel`, `request` routes on `ProfessionalAccountDeletionController` first; extend to other mutation endpoints in follow-up sweeps.
    6. Coordinate with frontend: per-button-click UUID generation, refreshed on page navigation. Document the header contract in `docs/api.md`.
- Dependencies: none (standalone middleware; frontend coordination required for full effect).

**Session prompts** (paste with the bundle into a fresh session):

*Implementation (plan-first — middleware design):*

> Read Bundle B27 + #P2-43 above. Read existing middleware patterns in `app/Http/Middleware/` (`ResolvesSiteFromRequest`, `EnsurePartnaAdmin` for shape; `VerifySupabaseJwt` for header reading). Look at how AccountDeletionController routes are registered in `routes/api/user.php`.
>
> Show me a numbered plan: middleware class shape, Redis key scheme, TTL choice, replay header, route registration, handling of streaming/file responses, HTTP-method scope (POST/PUT/PATCH/DELETE only?), error-caching policy, test strategy. Wait for my approval.
>
> Then implement, run `composer test`, and summarise the diff.

*Review (opus — concurrency + cache poisoning):*

> The bundle above introduces HTTP-layer idempotency caching. Review with adversarial eye.
>
> 1. Does the middleware correctly serialize concurrent requests with the same key, or can the second request execute the handler before the first finishes writing to Redis?
> 2. Cache poisoning: can a user replay a stale error forever? Is the error-caching policy actually enforced (no 5xx caching)?
> 3. Key scope: is `{user_id, key}` adequate, or do we need to include the route name to prevent cross-endpoint collisions?
> 4. Redis outage: fail-open (proceed without idempotency, accept race) or fail-closed (503)? Document the choice.
> 5. Streaming responses, file downloads — does the middleware break them?
> 6. TTL choice (24h) — too long (stale replays after user re-auth)? Too short (user retries from a tab opened yesterday)? Match to the longest legitimate user-retry window.
> 7. Edge cases: idempotency key reuse across different request bodies (replay attack? user error?); empty `Idempotency-Key` header vs absent header; non-UUID keys.
>
> Run `composer test`. Be paranoid.

---

## Standalone — do NOT bundle

These items must run in isolation due to single-writer invariants, schema-level work, or required human decisions:

- [ ] **#P1-13 → Bundle B19** — RLS BYPASSRLS revocation. Multi-schema policy work; one mistake unblocks every tenant. Must be carefully sequenced.
- [ ] **#P2-03 + #P2-04 → Bundle B20** — JWT revocation strategy. Needs explicit human decision on the security tradeoff before any code; AGENTS.md/CLAUDE.md don't decide for us here.
- [ ] **#P0-08** — Lens 16 re-run. Requires re-firing the audit pipeline with narrower scopes; not a code change. RESOLVED via run-2 lenses 21–25 above.

> **Note on bundle B8 (KV lifecycle)**: contains items that touch the SUBDOMAIN_KV writer contract but the single-writer invariant is preserved (B8 uses `RetireSubdomainFromKvJob`, which is the explicit delete-side counterpart to `SyncSubdomainToKvJob`). It's safe to bundle but reviewer must verify no additional write path is introduced.

---

## Deduplication notes

| Merge | Source findings | Why merged |
|------|------------------|------------|
| #X-1 | AUDIT-1, AUDIT-2, AUDIT-3, AUDIT-4, MAIL-3, MAIL-4 | All "stop emitting PII into log/payload/header." Different files, same root pattern. Kept as distinct P1/P2 items but grouped in bundle B3 + B9. |
| #X-2 | RES-1, RES-2, RES-3, RES-4, RES-7 | All "raw Eloquent leaking through Resource-less controller." Kept as distinct items because they touch different controllers, but bundled in B4. |
| #X-3 | GDPR-1, ARCH-1, ARCH-5 | All in `BootstrapController.php`. ARCH-5 (god method) is the structural cause of ARCH-1 (response object returned from transaction closure); GDPR-1 (waitlist crash) is the missed-validation symptom. Bundled in B2. |
| #X-4 | CAP-1, ARCH-4, ARCH-6, ARCH-7, ARCH-8, BOOT-3, RLS-3, RLS-4, CONFIG-3 | All "post-strip leftover." Bundled in B11. Kept as distinct items because fixes touch distinct files and the orchestrator can run them independently if needed. |
| #X-5 | SCHED-1, JOBS-3, JOBS-4 | All on `CheckStreamingLiveStatusJob`. SCHED-1 is the scheduler entry, JOBS-3/4 are the job class. Kept distinct because SCHED-1 goes in B6 (scheduler bundle) and JOBS-3/4 in B12 (job safety bundle). |

Dropped during adjudication (not appearing here): CACHE-1 ThemeConfig false-positive (dead path), CONFIG-1 false positive on queue connection default (verified aligned), FFLAG-1 sub-finding (confidence below threshold), JWT-3 MfaFactor URL (config default verified), JWT-4 process-global leeway (theoretical only), MEDIA-2 video safeExtension (already correct), MEDIA-5/6/7 (low confidence / by design), SUBKV-1 race-on-rename (premise invalidated by observer source), DEP-1 guard cutoff (verified calibrated), JOBS-1 cleanup-job orphan (watchdog command exists).

**Run-2 dedup notes:**

| Merge | Source findings | Why merged |
|------|------------------|------------|
| #P1-09 reinforced | CRED-1, CRED-2 (run 2) | Both directly mirror AUDIT-1 — same "Supabase Admin response body in logs/exception messages" pattern, just verified against different call sites (`SupabaseAdminService::unenrollMfaFactor` and `AccountDeletionService::deleteSupabaseAuthUser`). Strengthens the case rather than adding new IDs. |
| #P2-11 reinforced | CRED-4 (run 2) | Confirms AUDIT-4 — Twitch/Kick API body logging is the same pattern, verified again. |
| #P2-42 + adjacent to B8 | CF-1 (run 2) | The "Cloudflare service silently no-ops" finding sits adjacent to the SUBDOMAIN_KV single-writer invariant (Bundle B8). Same risk class (silent state drift) but distinct fix (environment-aware guard rather than additional writer paths). Kept as a separate P2 in a new bundle B26 to keep PR blast radius small. |

Run-2 dropped during adjudication: RATE-2 (false positive — controller verifies signature inline), RATE-3 (false positive — all 11 named limiters registered), RATE-4 (auto-dropped per internal-endpoint rule), R2-2 (verified — same hash pattern as image variants; no security delta), WH-2's draft "300s replay" framing (the 5-min window was already enforced correctly — finding kept but re-scoped to deploy-time assertion).

---

## Coverage report

### Coverage by lens

| Lens | Status | Findings | Scope |
|------|--------|----------|-------|
| 1 — authorization / route security | ✓ | 3 (0 P1, 2 P2, 1 P3) | `app/Http/Controllers`, `app/Policies`, `app/Http/Middleware`, `app/Http/Requests`, `routes` |
| 2 — data integrity & races | ✓ | 2 (0 P1, 2 P2, 0 P3) | `app/Services`, `app/Models`, `app/Jobs`, baseline migration |
| 3 — cache correctness | ✓ | 3 (0 P1, 1 P2, 2 P3) | `app/Services/Cache`, `app/Jobs/Cache`, `app/Observers`, `app/Services/PublicSite`, `app/Services/Site` |
| 4 — performance & N+1 | ✓ | 2 (1 P1, 1 P2, 0 P3) | `app/Http/Controllers`, `app/Services`, `app/Http/Resources` |
| 5 — organization & dead code | ✓ | 9 (1 P1, 4 P2, 4 P3) | controllers + services + Concerns traits |
| 6 — API contracts | ✓ | 8 (4 P1, 3 P2, 1 P3) | `app/Http/Resources`, controllers, routes |
| 7 — logging & observability | ✓ | 6 (3 P1, 2 P2, 1 P3) | `app/Services`, `app/Jobs`, logging middleware, `Audit` service |
| 8 — job idempotency | ✓ | 4 (0 P1, 2 P2, 2 P3) | `app/Jobs` |
| 9 — Cloudflare KV invariants | ✓ | 2 (1 P1, 1 P2, 0 P3) | `app/Services/Cloudflare`, `app/Jobs/Cloudflare`, `cloudflare-worker` |
| 10 — capabilities enforcement | ✓ | 2 (0 P1, 2 P2, 0 P3) | `app/Services/Accounts`, notification jobs, controllers, `app/Enums` |
| 11 — JWT & MFA | ✓ | 2 (0 P1, 2 P2, 0 P3) | `app/Services/Auth`, auth middleware, exceptions |
| 12 — media & streaming | ✓ | 4 (2 P1, 2 P2, 0 P3) | `app/Services/Media`, `app/Services/Streaming`, streaming jobs |
| 13 — RLS & DB authz | ✓ | 5 (1 P1, 1 P2, 3 P3) | `supabase/migrations`, seed, config |
| 14 — config & listeners | ✓ | 5 (0 P1, 3 P2, 2 P3) | `config`, console commands, listeners, observers, FeatureFlags |
| 15 — providers & mail | ✓ | 5 (1 P1, 4 P2, 0 P3) | `app/Providers`, `app/Mail`, mail views, helpers |
| **16 — rate limit & webhooks (run 1)** | ✗ FAILED | 0 | scope too broad — split into lenses 21–25 below |
| 17 — bootstrap & exceptions | ✓ | 4 (0 P1, 2 P2, 2 P3) | `bootstrap`, `app/Exceptions` |
| 18 — scheduler safety | ✓ | 5 (0 P1, 5 P2, 0 P3) | `routes/console.php`, console commands |
| 19 — GDPR & DSAR | ✓ | 5 (1 P0, 2 P1, 2 P2, 0 P3) | GDPR jobs/mail/models/exceptions, DataExport service, controllers, baseline migration |
| 20 — deploy & CI | ✓ | 2 (0 P1, 0 P2, 2 P3) | `deploy`, `.github`, `scripts`, `.env.example`, `composer.json` |
| **21 — webhook signature (run 2)** | ✓ | 2 (1 P1, 1 P2, 0 P3) | `SupabaseEmailHookController.php`, `VerifySupabaseEmailHookSignature.php`, `SupabaseEmailHookSignatureVerifier.php` |
| **22 — rate limiting & CORS (run 2)** | ✓ | 1 (0 P1, 0 P2, 1 P3) — most DeepSeek drafts dropped on verification | `routes/*`, `app/Http/Middleware`, `config/cors.php` |
| **23 — security headers & HSTS (run 2)** | ✓ | 5 (0 P1, 2 P2, 3 P3) | `SecureHeaders.php`, `bootstrap/app.php`, `AddPublicCacheHeaders.php`, `AddETagHeaders.php`, `VerifySupabaseJwt.php` |
| **24 — env reads & secrets (run 2)** | ✓ | 4 (0 P1, 2 P2, 2 P3) — 2 P2 are duplicate-confirms of #P1-09; 1 P3 duplicate-confirms #P2-11 | `SupabaseAdminService.php`, `AccountDeletionService.php`, `MediaDiskResolver.php`, streaming clients |
| **25 — Cloudflare worker & R2 scope (run 2)** | ✓ | 3 (0 P1, 1 P2, 2 P3) | `CloudflareKvService.php`, `CloudflarePurgeService.php`, `ImageVariantService.php`, `VideoVariantService.php`, `MediaDiskResolver.php` |
| composer audit | ✓ | 8 CVEs | dep tree |
| composer outdated --direct | ✓ | 4 majors + 10 minors | dep tree |

### Coverage gap closure — RESOLVED via run 2

The lens-16 failure was closed by splitting into 5 narrower lenses (21–25) which all completed successfully. The surfaces previously uncovered are now mapped to consolidated findings as follows:

| Previously uncovered surface | Coverage achieved via | Findings |
|---|---|---|
| Webhook signature verification (Supabase + extensibility for Cloudflare / Resend) | Lens 21 | #P1-18, #P2-39 |
| Rate limiting on public/auth routes + CORS config | Lens 22 | #P3-21 (most DeepSeek drafts dropped on verification — 11 named limiters confirmed registered in `AppServiceProvider`; signature verification confirmed inline in `SupabaseAuthHookController`) |
| Security headers (X-Frame, CSP, HSTS, Referrer-Policy, etc.) | Lens 23 | #P2-40, #P2-41, #P3-22, #P3-23, #P3-24 |
| Hardcoded secrets / env reads outside `config()` | Lens 24 | #P3-25 (documented exception in `MediaDiskResolver`); CRED-1/2 duplicate-confirm #P1-09; CRED-4 duplicate-confirms #P2-11 |
| Cloudflare worker auth + R2 presigned/public bucket scope | Lens 25 | #P2-42, #P3-26, #P3-27 |

**Net residual gaps after run 2:** none flagged as critical by either pipeline. The remaining open question is whether `Resend` webhook handlers (if added later) will adopt the same `webhook-id` dedup pattern from #P1-18 — track in code review going forward.

---

*Generated 2026-05-24 by manual consolidation of 24 lens audits (19 from run 1, lens 16 failed + replaced by 5 narrower lenses 21–25 in run 2), `composer audit`, and `composer outdated --direct`. Audit-orchestrator parseable (P0/P1/P2/P3 items + bundles all use stable IDs + checkboxes). Each item and bundle carries a `Models: impl=… · review=…` line for per-session model selection.*
