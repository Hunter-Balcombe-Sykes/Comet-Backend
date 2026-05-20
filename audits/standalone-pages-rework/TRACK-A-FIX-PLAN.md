# Track A — Consolidated Fix Plan

**Source:** `audit-2026-05-20-TRACK-A-FULL-CONSOLIDATED.md` (66 surviving findings)
**Date:** 2026-05-20

This document re-groups the 66 audit findings into **19 fix units** — each fix unit
is one PR / one orchestrator session. Findings that touch the same file or share a
root cause are merged into a single fix. Fix units are listed in **strict execution
priority order**: do F1 first, F19 last. The list interleaves bundles and
single-item fixes — a single-item P1 fix outranks a multi-item P2 bundle.

**Priority bands**
- **F1–F11 — Pilot blockers.** Every fix in this band contains at least one P1. Must ship before pilot launch.
- **F12–F16 — Pre-pilot polish.** P2-only bundles. Should ship before pilot; none are launch-gating on their own.
- **F17–F19 — Scale / cleanup.** P3-only. Defer until after pilot or batch into a quiet sprint.

---

## Priority-ordered fix list

| ✓ | # | Fix | Tier | Effort | Implement | Review | Est. time |
|---|---|-----|------|--------|-----------|--------|-----------|
| ☑ | F1 | Shopify webhook tenant-spoofing + webhook hardening | P1 | M | Opus | Opus | ~3h |
| ☑ | F2 | JWT auth fail-closed | P1 | S | Opus | Opus | ~1.5h |
| ☑ | F3 | Brand-status notification correctness | P1 | S | Sonnet | Sonnet | ~1h |
| ☑ | F4 | Account lifecycle correctness + coverage | P1 | M | Opus | Opus | ~4h |
| ☑ | F5 | Job-queue correctness | P1 | M | Opus | Sonnet | ~3h |
| ☑ | F6 | Redis DB collision + config hygiene | P1 | M | Sonnet | Sonnet | ~2.5h |
| ☑ | F7 | Stale PL/pgSQL function reference | P1 | S | Sonnet | Sonnet | ~0.5h |
| ☑ | F8 | `Block` model soft-deletes | P1 | S | Sonnet | Sonnet | ~0.5h |
| ☑ | F9 | Cloudflare KV writer correctness | P1 | S | Sonnet | Sonnet | ~1h |
| ☐ | F10 | Backfill job scaling (standalone) | P1 | L | Opus | Opus | ~1 day |
| ☐ | F11 | Bootstrap brand-attach coverage (standalone) | P1 | L | Opus | Sonnet | ~1 day |
| ☑ | F12 | Cache invalidation hygiene | P2 | M | Sonnet | Sonnet | ~3h |
| ☑ | F13 | RLS policy gaps (schema sweep) | P2 | M | Sonnet | Opus | ~3h |
| ☑ | F14 | Billing atomicity (Stripe-in-transaction) | P2 | S | Opus | Sonnet | ~2h |
| ☑ | F15 | Public-site API contract + coverage | P2 | M | Sonnet | Sonnet | ~3h |
| ☑ | F16 | Job observability / logging | P2 | M | Sonnet | Sonnet | ~2.5h |
| ☐ | F17 | Query micro-optimisations | P3 | S | Sonnet | Sonnet | ~1h |
| ☐ | F18 | Test-suite fragility | P3 | S | Sonnet | Sonnet | ~0.5h |
| ☐ | F19 | Dead-code / cosmetic cleanup | P3 | S | Sonnet | Sonnet | ~1h |

**Totals** — Band 1 (F1–F11): ~3 working days · Band 2 (F12–F16): ~1.7 days · Band 3 (F17–F19): ~0.3 day · **Whole plan ≈ 5 working days.**

**Model guidance:** Opus for security-critical paths and concurrency/transaction reasoning (F1, F2, F4, F5, F10, F14) and for the L-effort standalones; Sonnet for mechanical, well-scoped changes. Review with Opus where a wrong fix is silently exploitable (F1, F2, F4, F10) or widens a security surface (F13 RLS); Sonnet review elsewhere.

---

# Band 1 — Pilot blockers (F1–F11)

## ☑ F1 — Shopify webhook tenant-spoofing + webhook hardening
**Tier:** P1 · **Effort:** M · **Items:** SHOP-1 (P1), WEBHOOK-3 (P2), WEBHOOK-4 (P3)
**Implement:** Opus · **Review:** Opus · **Est. time:** ~3h

All three findings harden webhook controllers; doing them together avoids touching
the webhook concern twice.

- **SHOP-1** — In `HandlesShopifyWebhook::__invoke()` extract authoritative shop
  identity from the HMAC-protected body (`myshopify_domain` / `domain`); if it
  disagrees with the unsigned `X-Shopify-Shop-Domain` header, return `400`. Apply
  the same cross-check in `ShopifyAppUninstalledWebhookController` and
  `ShopifyGdprWebhookController`. Test: sign body for Shop A, header Shop B → 400.
- **WEBHOOK-3** — Add delivery dedup to `SupabaseAuthHookController`: after
  signature validation, `Cache::add($id, true, $windowSeconds)`; on `false` return
  `['decision' => 'continue']`.
- **WEBHOOK-4** — Reject Shopify GDPR webhooks older than ~5 min via the
  `X-Shopify-Triggered-At` header (422).

**Files:** `app/Http/Controllers/Concerns/HandlesShopifyWebhook.php`,
`ShopifyAppUninstalledWebhookController.php`, `ShopifyGdprWebhookController.php`,
`SupabaseAuthHookController.php`.

## ☑ F2 — JWT auth fail-closed
**Tier:** P1 · **Effort:** S · **Items:** AUTH-1 (P1), AUTH-2 (P2)
**Implement:** Opus · **Review:** Opus · **Est. time:** ~1.5h

Both in `VerifySupabaseJwt.php`.

- **AUTH-1** — `claimsMatchConfig` must fail closed: make it return `false`
  immediately when the expected issuer or audience is an empty string, AND add a
  boot-time assertion in `AppServiceProvider::boot()` (non-local/non-testing) that
  `supabase.jwt_issuer` / `supabase.jwt_audience` are non-empty.
- **AUTH-2** — Flip `SUPABASE_JWKS_FAIL_CLOSED` default to `true` (explicit opt-in
  for auth-server fallback); add a Nightwatch alert on the JWKS-failure log line.

**Files:** `app/Http/Middleware/Auth/VerifySupabaseJwt.php`,
`app/Providers/AppServiceProvider.php`, `config/supabase.php`.

## ☑ F3 — Brand-status notification correctness
**Tier:** P1 · **Effort:** S · **Items:** BRAND-1 (P1), BRAND-2 (P2), BRAND-3 (P3)
**Implement:** Sonnet · **Review:** Sonnet · **Est. time:** ~1h

All three in `SendBrandStatusNotificationJob.php` — one file, one fix.

- **BRAND-1** — Replace the dead `'building'` match arm with
  `BrandStatus::Onboarding->value`; add an explicit `ReadyForAffiliates` arm.
- **BRAND-2** — Change the `default` arm to `Log::warning(...) + return` (fail
  loud, not silently wrong).
- **BRAND-3** — Guard against the brand being soft-deleted between fan-out and
  leaf execution: `Professional::find(...)` → log debug + return if null.

**Files:** `app/Jobs/Notifications/SendBrandStatusNotificationJob.php`.

## ☑ F4 — Account lifecycle correctness + coverage
**Tier:** P1 · **Effort:** M · **Items:** ACCT-1 (P1), ACCT-2 (P2), ACCT-3 (P3), ACCT-4 (P3), TEST-1 (P1), TEST-2 (P1)
**Implement:** Opus · **Review:** Opus · **Est. time:** ~4h

The ACCT findings and the two missing test files all live in `Services/Accounts/` —
write the fixes and the tests that lock them in within one session.

- **ACCT-1** — `DB::transaction` closure returns `bool`; gate the three post-commit
  dispatches (`SyncSubdomainToKvJob`, `CloudflareCachePurgeJob`,
  `AccountTypeTransitionEvent`) behind `if ($mutated)`.
- **ACCT-2** — Call `AccountCapabilities::flushCache()` right after
  `setRawAttributes(...) + syncOriginal()` in the transition service.
- **ACCT-3** — Remove the unused `BrandPartnerLinkService` injection + misleading
  `brand_id` docblock.
- **ACCT-4** — Normalise `stripe_connect_status` comparison to `strtolower()`.
- **TEST-1** — New `tests/Unit/Services/Accounts/AccountCapabilitiesTest.php`:
  all 16 flags × 3 account types + `null` input + `flushCache()` behaviour.
- **TEST-2** — New `AccountTypeTransitionServiceTest.php`: Brand→anything throws,
  same-state no-op, individual→partner full dispatch, concurrent-race bail-out
  (this also covers ACCT-1's regression).

**Files:** `app/Services/Accounts/AccountTypeTransitionService.php`,
`app/Services/Accounts/AccountCapabilities.php`,
`app/Listeners/.../ToggleStripeRequirementBannerOnTransition.php`,
`tests/Unit/Services/Accounts/*`.

## ☑ F5 — Job-queue correctness
**Tier:** P1 · **Effort:** M · **Items:** QUEUE-1 (P1), QUEUE-2 (P2), QUEUE-3 (P2), QUEUE-4 (P2), QUEUE-5 (P3)
**Implement:** Opus · **Review:** Sonnet · **Est. time:** ~3h

- **QUEUE-1** — Make `ExportChunkJob` retries idempotent: early-exit guard at top
  of `handle()` — if `$audit->chunks_completed > $this->chunkIndex`, skip
  fetch/write and jump to next dispatch / finaliser.
- **QUEUE-2 / QUEUE-3** — Identical fix: add `public int $uniqueFor = 600;` to
  `FanOutBrandStatusNotificationJob` and `SendStaffBroadcastEmailsJob` (and correct
  the misleading comment on the latter).
- **QUEUE-4** — Add a wall-clock guard to `ReconcileStuckShopifyIntegrationsJob`:
  break early at 80% of `$timeout`, log remaining-unprocessed count.
- **QUEUE-5** — Implement the documented stuck-`PROCESSING` cleanup command for
  `ProcessImageVariantsJob` / `ProcessVideoVariantsJob`.

**Files:** `app/Jobs/Exports/ExportChunkJob.php`,
`app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php`,
`app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php`,
`app/Jobs/Shopify/ReconcileStuckShopifyIntegrationsJob.php`,
`app/Jobs/ProcessImageVariantsJob.php`, `app/Jobs/ProcessVideoVariantsJob.php`.

## ☑ F6 — Redis DB collision + config hygiene
**Tier:** P1 · **Effort:** M · **Items:** CFG-1 (P1), CFG-2–4 (P2), CFG-5–7 (P3)
**Implement:** Sonnet · **Review:** Sonnet · **Est. time:** ~2.5h

- **CFG-1** — `.env.example`: `REDIS_CACHE_DB=1`, cascade `REDIS_SESSION_DB=2`,
  `REDIS_QUEUE_DB=3`, `REDIS_CACHE_LOCKS_DB=4` — stop `Cache::flush()` from
  `FLUSHDB`-ing the Horizon queue.
- **CFG-2** — Production boot guard for missing `STRIPE_SECRET_KEY` in
  `AppServiceProvider` (matches existing 4-guard pattern; coordinate with F2's
  guard additions).
- **CFG-3** — Align `config/` fallback defaults to `.env.example`:
  `DB_CONNECTION`→`pgsql`, `QUEUE_CONNECTION`→`redis`, `SESSION_DRIVER`→`cookie`.
  **DEFERRED:** the `STRIPE_API_VERSION` (clover vs acacia) and
  `SHOPIFY_API_VERSION` (2026-04 vs 2025-01) splits are NOT resolved here — both
  carry real payment/catalog-flow implications and need a product decision.
  Stripe connect/payout services consume `services.stripe.api_version`; the
  export `StripeClient` consumes `partna.exports.commission.stripe_api_version` —
  they share one env var but want different versions. Split to a follow-up.
- **CFG-4** — Boot guard: refuse to boot in production when `NIGHTWATCH_ENABLED`
  is true and `NIGHTWATCH_TOKEN` is empty (chosen over flipping the default to
  preserve prod telemetry-on behaviour).
- **CFG-5** — Add the ~30 missing `config/partna.php` knobs to `.env.example` as
  commented entries.
- **CFG-6** — `config/mail.php` fallbacks → `hello@partna.au` / `Partna`.
- **CFG-7** — Strip `config/auth.php` to a minimal stub (Supabase JWT auth — the
  session guard / Eloquent user model / password reset are dead code).

**Files:** `.env.example`, `config/{database,queue,services,partna,session,nightwatch,mail,auth}.php`,
`app/Providers/AppServiceProvider.php`.

## ☑ F7 — Stale PL/pgSQL function reference
**Tier:** P1 · **Effort:** S · **Item:** MIG-1 (P1)
**Implement:** Sonnet · **Review:** Sonnet · **Est. time:** ~0.5h

New migration `20260524000000_fix_prevent_staff_escalation_stale_ref.sql` with
`CREATE OR REPLACE FUNCTION core.prevent_staff_escalation()` replacing the stale
`core.comet_staff` reference with `core.partna_staff`.

**Files:** `supabase/migrations/` (new file).

## ☑ F8 — `Block` model soft-deletes
**Tier:** P1 · **Effort:** S · **Item:** DATA-1 (P1)
**Implement:** Sonnet · **Review:** Sonnet · **Est. time:** ~0.5h

Add `use Illuminate\Database\Eloquent\SoftDeletes;` and
`'deleted_at' => 'datetime'` to `$casts` on `Block` — restores FK integrity for
`analytics.link_clicks` and re-arms `BlockObserver::deleted()` cache invalidation.

**Files:** `app/Models/Core/Site/Block.php`.

## ☑ F9 — Cloudflare KV writer correctness
**Tier:** P1 · **Effort:** S · **Items:** SYNC-1 (P1), SYNC-2 (P2)
**Implement:** Sonnet · **Review:** Sonnet · **Est. time:** ~1h

**Already resolved in commit `65a01434` (PR #85).** `CloudflareKvService::put()`
carries the `?int $expirationTtl` param + `?expiration_ttl=` query append; all
`SyncSubdomainToKvJob` call sites pass the third arg; the partner-link query has
the `->orderBy('created_at')` tie-break. No code change needed — verified
2026-05-20.

Both in the KV write path.

- **SYNC-1** — Add the `?int $expirationTtl` parameter to
  `CloudflareKvService::put()` and append `?expiration_ttl=` to the KV REST URL —
  the alias call site already passes a third arg that PHP silently discards.
- **SYNC-2** — Add `->orderBy('created_at')` as a secondary sort after
  `->orderBy('slot')` in `SyncSubdomainToKvJob` so colliding `slot` values resolve
  deterministically.

**Files:** `app/Services/Cloudflare/CloudflareKvService.php`,
`app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`.

## ☐ F10 — Backfill job scaling (STANDALONE — do not bundle)
**Tier:** P1 · **Effort:** L · **Item:** SCALE-1 (= API-2)
**Implement:** Opus · **Review:** Opus · **Est. time:** ~1 day

`BackfillBrandHasEnabledVariantsJob` — add cursor checkpointing in
`provider_metadata`, batch `metafieldsSet` in groups of 25, raise `$timeout` to
300s / `$tries` to 5. Needs careful Shopify rate-limit handling; own session.

**Files:** `app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php`.

## ☐ F11 — Bootstrap brand-attach coverage (STANDALONE — do not bundle)
**Tier:** P1 · **Effort:** L · **Item:** TEST-4
**Implement:** Opus · **Review:** Sonnet · **Est. time:** ~1 day

New `tests/Feature/PublicSite/BootstrapBrandAttachTest.php` — all 4 brand-attach
branches (invite_token, partner_professional_id, join_handle, signup_code), happy
path + each error branch + cap-exceeded 422 + EMAIL_ALREADY_REGISTERED 409.
Requires understanding the `&$brandSignupCodeError` partial-commit pattern; own
session.

**Files:** `tests/Feature/PublicSite/BootstrapBrandAttachTest.php` (new).

---

# Band 2 — Pre-pilot polish (F12–F16)

## ☑ F12 — Cache invalidation hygiene
**Tier:** P2 · **Effort:** M · **Items:** CACHE-1, CACHE-2, CACHE-6, SCALE-3
**Implement:** Sonnet · **Review:** Sonnet · **Est. time:** ~3h

- **CACHE-1** — `CommissionPayoutObserver::updated` must forget both
  `affiliatePayoutState` key and its `:stale` twin on every status branch.
- **CACHE-2** — `BrandStoreSettingsController` deploy trigger: replace bare
  `Cache::forget($key)` with `Cache::deleteMultiple([$key, $key.':stale'])`.
- **CACHE-6** — `SiteCacheService::invalidateSite` brand branch must also clear
  Hydrogen affiliate-page caches for connected `BrandPartnerLink` rows.
- **SCALE-3** — Replace per-affiliate O(N) dispatch in `invalidateSite` with a
  single job that `chunkById(500, ...)` internally.

**Files:** `app/Observers/Core/CommissionPayoutObserver.php`,
`app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php`,
`app/Services/Cache/SiteCacheService.php`.

## ☑ F13 — RLS policy gaps (schema sweep)
**Tier:** P2 · **Effort:** M · **Items:** RLS-1 … RLS-11
**Implement:** Sonnet · **Review:** Opus · **Est. time:** ~3h

One migration adding the missing policies across the schema:
- **RLS-1** — anon SELECT on `core.professionals` + `site.services`.
- **RLS-2** — RLS + policies on `core.feature_flags` / `core.feature_flag_overrides`.
- **RLS-3** — RLS + policies on `brand.signup_code_audit`.
- **RLS-4** — RLS + policies on `commerce.commission_export_audit`.
- **RLS-5→11** — staff/owner SELECT policies for `analytics.lead_submissions`,
  `site.enquiries`, `site.professional_handle_aliases`, `core.handle_change_log`,
  `core.gdpr_requests`, `core.data_export_audit`, `billing.webhook_events`,
  `analytics.cart_events`, `analytics.section_views`,
  `notifications.broadcast_email_receipts`.

**Files:** `supabase/migrations/` (new file).

## ☑ F14 — Billing atomicity (Stripe-in-transaction)
**Tier:** P2 · **Effort:** S · **Items:** TRNX-3, TRNX-4, TRNX-5
**Implement:** Opus · **Review:** Sonnet · **Est. time:** ~2h

Same anti-pattern across three billing actions — fix together.
- **TRNX-3** — `ResumeProfessionalSubscriptionAction`: move the Stripe call out of
  `DB::transaction`, sequence with `DB::afterCommit()`.
- **TRNX-4** — `CancelProfessionalSubscriptionAction`: local update first inside
  the transaction, Stripe call after; `Log::warning` on post-commit Stripe failure.
- **TRNX-5** — `ChangeProfessionalPlanAction`: write `plan_id` locally + clear
  entitlement cache immediately after Stripe succeeds (keep webhook as backup).

**Files:** `app/Services/Billing/{Resume,Cancel,Change}Professional*Action.php`.

## ☑ F15 — Public-site API contract + coverage
**Tier:** P2 · **Effort:** M · **Items:** APIC-1, APIC-2, PROF-2, TEST-3, TEST-5
**Implement:** Sonnet · **Review:** Sonnet · **Est. time:** ~3h

- **APIC-1** — `IndividualProfileController` extends `ApiController`; use the
  standard `success`/`error` envelope. Verify the Astro Worker parses it.
- **APIC-2** — Mirror the `live_check_enabled` per-site cap from
  `UpdateLinkBlockRequest` into `StoreLinkBlockRequest::withValidator()`.
- **PROF-2** — Verify PR #100 added a design-key allowlist on
  `IndividualProfileController:64`; **if already done, mark CLOSED** — the
  consolidated audit flags this as verify-then-close.
- **TEST-3** — Unit-test `filterBlockSettings()` allow-list (known / unknown / bio).
- **TEST-5** — New `PublicShopifyStorefrontControllerTest` — both resolution
  strategies, 202 pending + dedup, 404, 422.

**Files:** `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`,
`app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php`,
`tests/Feature/PublicSite/*`.

## ☑ F16 — Job observability / logging
**Tier:** P2 · **Effort:** M · **Items:** OBS-1, OBS-2, OBS-3, OBS-4, OBS-5
**Implement:** Sonnet · **Review:** Sonnet · **Est. time:** ~2.5h

- **OBS-1** — Add `failed()` handler + `report()` to `AggregateCacheMetricsJob`.
- **OBS-2** — Add success / `not_connected` logging to `SyncStripeAccountStatusJob`.
- **OBS-3** — Add success logging to `ProvisionBrandDnsTxtJob`.
- **OBS-4** — Completion-summary logs on the three daily sweep jobs.
- **OBS-5** — Remove the duplicate `Log::warning` from `RetireSubdomainFromKvJob`'s
  catch block (keep `failed()` as the single log site).

**Files:** `app/Jobs/Cache/AggregateCacheMetricsJob.php`,
`app/Jobs/Stripe/SyncStripeAccountStatusJob.php`,
`app/Jobs/Cloudflare/{ProvisionBrandDnsTxtJob,RetireSubdomainFromKvJob}.php`,
`InviteExpirySweepJob`, `NudgeStuckOnboardingJob`,
`SendWeeklyAnalyticsNotificationJob`.

---

# Band 3 — Scale / cleanup (F17–F19)

## ☐ F17 — Query micro-optimisations
**Tier:** P3 · **Effort:** S · **Items:** DB-14, DB-15, SCALE-2
**Implement:** Sonnet · **Review:** Sonnet · **Est. time:** ~1h

- **DB-14** — Collapse `VoidExpiredPayoutsJob::fireGraceWarnings` 3 queries into one.
- **DB-15** — Stop double-loading `$brandProfessional` in
  `ProcessShopifyOrderWebhookJob`.
- **SCALE-2** — Add `->limit(200)` + separate count to `MonitorManualRefundQueueJob`.

## ☐ F18 — Test-suite fragility
**Tier:** P3 · **Effort:** S · **Items:** TEST-6, TEST-7
**Implement:** Sonnet · **Review:** Sonnet · **Est. time:** ~0.5h

- **TEST-6** — Add the missing owner-delete happy-path test to
  `DocumentPolicyEnforcementTest`.
- **TEST-7** — Make `AuditTableHardeningTest` whitespace-tolerant (regex variant).

## ☐ F19 — Dead-code / cosmetic cleanup
**Tier:** P3 · **Effort:** S · **Items:** PROF-4, WEBHOOK-5
**Implement:** Sonnet · **Review:** Sonnet · **Est. time:** ~1h

- **PROF-4** — Hash the user-controlled handle component in the Redis key
  (`md5($handleLc)`) or ban colons in handle validation.
- **WEBHOOK-5** — Remove `FreshaCatalogWebhookController` and its route entirely
  (booking / Fresha dropped 2026-05-11).

---

## Notes on bundling decisions

- **F4** folds TEST-1/TEST-2 into the ACCT fixes deliberately — the tests lock in
  ACCT-1's race fix and you are already in `Services/Accounts/`. The two L-effort
  test items (TEST-4) are kept standalone (F11) per the consolidated audit.
- **F10** and **F11** are kept as single-item standalone fixes despite being P1 —
  both are L-effort and the audit explicitly flags them as do-not-bundle.
- **PROF-2** (F15) may already be resolved by PR #100; the consolidated audit
  marks it verify-then-close. If verification confirms closure, F15 drops one item.
- **QUEUE-2** and **QUEUE-3** are byte-identical fixes (`$uniqueFor = 600`) — same
  PR, no separate review needed.
- Total: 19 fix units covering all 66 surviving findings (PROF-2 counted once).
