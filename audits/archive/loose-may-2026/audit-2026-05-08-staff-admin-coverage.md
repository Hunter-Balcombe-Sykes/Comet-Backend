# Partna — Staff/Admin Coverage Gap Audit (2026-05-08)

**Lens:** What can Partna staff/admin do for a professional/brand/affiliate today, vs what self-service users can do? List net-new staff endpoints and platform-support primitives, prioritised for unattended fix sessions.

**Scope:** `routes/api/staff.php` vs `routes/api/professional.php`, plus cross-cutting support tooling (impersonation, audit log, per-tenant flags, bulk ops). Excludes public/storefront routes and webhook receivers.

**Reading order:** least-urgent first → most-urgent last inside each tier (read bottom-up). Top-level checkbox = "fully shipped including tests."

> Items here are **net-new feature additions**, not findings to patch. Bundles are still useful (shared pattern, one PR, one reviewer mental model), but each item is additive — there's no existing bug. The orchestrator is fine to run the bundled items unattended; the standalone list flags the ones that need design-conversation first.

---

## Progress

- P0: 2 of 5 complete (#SHOP-1, #GDPR-1)
- P1: 8 of 13 complete (#SQUARE-1, #FRESHA-1, #SHOP-2, #ANALYTICS-1, #CATALOG-1, #CATALOG-2, #NOTIF-1, #INVITE-1)
- P2: 5 of 13 complete (#BRAND-SETUP-1, #COLLECTION-1, #GBP-1, #BOOK-1, #STRIPE-PM-1)
- P3: 0 of 6 complete (defer-by-default per agent memory; build only when a real ticket lands)

B2 read-only inspector bundle landed on 2026-05-17. Items still carrying an
unticked write half: #ENQUIRY-1 (admin DELETE), #BRAND-DESIGN-1 (resync POST),
#AFF-SEL-1 (reset-to-defaults POST) — covered by future admin-write sessions.

## Coverage summary (today)

| Domain | Self-service | Staff coverage | Gap |
|---|---|---|---|
| Identity, profile, GDPR (export/erasure), customers, services, sections, links, site design, subscription edits, brand profile, commission ledger view + void + payout retry, brand-affiliate links, notification email policies | Full | Full or near-full | minor |
| Brand store settings | edit + deploy | edit only — no deploy | gap |
| Notifications | list/read/dismiss | send only | gap |
| Brand-affiliate invites | full lifecycle | cancel only | gap |
| Themes | list/select | indirect via `/site` PATCH `theme_id` | acceptable |
| Image uploads / gallery / brand-logo / placeholders / documents | full | **none** | major |
| Enquiries inbox | full | **none** | major |
| Square / Fresha integrations | full lifecycle | **none** | major |
| Shopify integration | full lifecycle | resync only | major |
| Stripe Connect / payment methods / top-ups / payouts | full | **none** (only commission-payout retry) | major |
| Brand catalog (products, metafields, commission, discount, active) | full | read-only inspector + admin override on commission/discount/active | minor |
| Brand collections | full | **none** | major |
| Brand design / Shopify design resync | full | **none** | gap |
| Brand onboarding readiness / setup wizard | full | **none** | gap |
| Affiliate selections / variants / reset | full | **none** | gap |
| Affiliate custom photos | full | **none** | gap |
| Booking settings / analytics | self only | **none** | gap |
| Brand commerce-analytics overview | self only | **none** | gap |
| Email subscribers | self only | **none** | major (GDPR) |
| Google Business Profile | self only | **none** | gap |
| Audit log of staff writes | n/a | only deletion-specific log | major |
| Impersonation ("act as professional") | n/a | **none** | major |
| Per-tenant feature flag overrides | n/a | **none** | gap |

**Weighted coverage estimate: ~50%.** Strong on identity/site/commerce-ledger; weak on uploads, integrations, catalog, affiliate, observability.

---

## Suggested Bundled Sessions

Each bundle shares a file pattern, an existing service, or a domain — bundling yields one Pest sweep, one reviewer mental model, one PR. Bundles do not change the priority order; tackle high-priority items inside a bundle first.

### High-impact bundles (P0/P1)

- [x] **B1 — Integration status + disconnect mirrors.** #SQUARE-1, #FRESHA-1, #SHOP-1, #SHOP-2. ~3–4h. All four are "create a staff endpoint that calls the existing service the self-service controller already uses." Same pattern, same auth tier (admin write for disconnect/re-register, any-staff for status). One Pest sweep across all four. **Don't pull in:** #PAYOUT-1 (Stripe field-mapping risk — keep standalone).

- [x] **B2 — Read-only inspector mirrors.** #GDPR-1, #ENQUIRY-1, #BRAND-SETUP-1, #BRAND-DESIGN-1, #COLLECTION-1, #GBP-1, #BOOK-1, #ANALYTICS-1, #CATALOG-1, #STRIPE-PM-1, #AFF-SEL-1 (read-only part). ~6–8h. All "GET endpoint exposing the same payload the brand sees." Mechanical mirror — wire the controller, return the existing service's response, write a Pest test that hits the endpoint as staff. Same review surface (no writes, no side-effects). Shipped 2026-05-17 — 11 GET endpoints across 11 new staff controllers + 31 Pest tests.

- [x] **B3 — Staff subscription extensions.** #SUB-1, #SUB-2. ~1–2h. Both extend `StaffSubscriptionManagementController` with parallel methods that already exist on `SubscriptionController`. One file, two methods, one Pest test that asserts each returns the same shape as self-service.

- [x] **B4 — Catalog admin overrides.** #CATALOG-2 (3 endpoints: commission, discount, active). ~2–3h. Same pattern across three product-field PATCH endpoints. Pre-req: #CATALOG-1 (the read-only inspector) so the admin can see what they're editing. One Pest sweep that toggles each field and asserts it round-trips through the brand's own dashboard. Shipped 2026-05-17.

- [x] **B5 — Notification + invite admin extensions.** #NOTIF-1, #INVITE-1. ~3–5h. Both extend an existing staff controller (`StaffNotificationController` / `StaffInviteController`) with the missing write methods that already exist on the self-service side. Shared mental model: "what can a brand do that staff can't yet?" One PR, two reviewer-friendly diffs.

- [x] **B6 — Tiny admin parity writes.** #STORE-1, #BRAND-INVITE-PROMOTE-1, #BULK-1, #AFF-PHOTO-1, #NOTES-1. ~3–5h. Each is ≤1h but they share the "extend an existing controller / add one column" shape. Bundling avoids 5 micro-PRs. Shipped 2026-05-17 (commit `615d3656`).

- [ ] **B7 — Staff upload + media management.** #UPLOAD-1. ~6–10h. Single large bundle covering image-pool / brand-logo / placeholder-image / document under one shared `StaffUploadController` (or sibling per pool, mirroring `ProfessionalUploadController`). Standalone-bundle because all four pools share the `BrandDesignMediaService` infrastructure and the same R2 + WebP variant pipeline — splitting them creates four near-identical sessions. **Watch out:** placeholder-image reorder + delete have ordering invariants — add a Pest test that asserts the `display_order` column stays contiguous after delete.

### Standalone — do NOT bundle

These are best in their own session because bundling would force unrelated architectural decisions, expand test scope unhelpfully, or risk a worse fix:

- **#OPS-1 (impersonation)** — JWT-claim extension + frontend banner mode + new `impersonator_staff_id` claim + 30-min TTL. Security-sensitive; needs a design conversation about audit-log integration and the brand-side warning UI. Do NOT run unattended.
- **#OPS-2 (audit log)** — new `core.staff_audit_log` table + middleware that wraps every admin write. Borderline-runnable IF you predefine the payload-scrub allowlist (otherwise the orchestrator may log secrets). Recommended: pre-write the scrub allowlist in this file before kicking off the session.
- **#LEDGER-1 (manual commission adjustment)** — touches money source-of-truth (`commerce.commission_movements`). Idempotency, math, currency invariants need human review. Standalone.
- **#FF-1 (per-tenant feature flag overrides)** — architectural change touching every `config('partna.features.*')` callsite. Standalone refactor.
- **#PAYOUT-1 (Stripe Connect status read)** — Stripe field-mapping is fiddly (requirements arrays, capability states, payouts_enabled vs charges_enabled). Standalone for review of which fields to expose vs hide.
- **#WEBHOOK-1 (Shopify event replay)** — touches `commerce.order_events` dedup logic and re-runs the order pipeline for one event. Standalone for safety review of the idempotency guarantee.

### Dependencies between bundles / items

- **B4 follows B2** — admin overrides on catalog need the read-only inspector first, otherwise admin is editing blind.
- **B6's #BRAND-INVITE-PROMOTE-1 follows B5's #INVITE-1** — promote-flow is the natural ride-along once the invite write surface exists.
- **#OPS-1 (impersonation) should land before #OPS-2 (audit log) is finalised** — the audit log schema must capture impersonator_staff_id from day one. Build OPS-2's table to include the column even if OPS-1 isn't shipped yet.
- **B7 (uploads) should land after #OPS-2 (audit log)** — file replacements are exactly the kind of change that needs a footprint.

---

## P0 — must-have before scaling support

- [ ] **#PAYOUT-1** · P0 — Read-only Stripe Connect status per brand
    - **Where:** new `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffStripeConnectController.php`; mirror reads from `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php:status`.
    - **Affects:** Support's ability to triage a stuck commission payout. Today `/staff/commission-payouts/{payout}/retry` exists but staff has no view into *why* it failed.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Add route `GET /staff/professionals/{professional}/stripe/status` under the read-only staff group (`staff` middleware, no `staff.admin`).
        - Controller returns `{ has_account, charges_enabled, payouts_enabled, requirements_summary, payment_methods_count, default_payment_method_last4, funding_mode }`. Strip raw Stripe response — only expose the curated fields.
        - Reuse `StripeConnectService` (whatever powers self-service `/stripe/status`).
        - Pest test in `tests/Feature/Staff/StripeConnect/StaffStripeStatusTest.php`: assert non-staff gets 403, staff gets the curated payload.
    - **Technical:** Self-service controller already shapes the Stripe response; this is a thin staff wrapper. The risk is exposing fields that leak more than support needs (e.g., raw `requirements.currently_due` arrays can include internal Stripe IDs) — keep the field list tight and document in a comment why each is exposed.
    - **Plain English:** Staff can see if a brand's Stripe is fully verified before retrying their payout, instead of retrying blind.
    - **Evidence:**
        ```php
        // routes/api/professional.php:400-413 — what brands have today
        Route::get('/stripe/status', [StripeConnectController::class, 'status']);
        Route::post('/stripe/connect/onboard', ...);
        Route::post('/stripe/connect/dashboard', ...);
        // staff has none of these — only commission-payout retry exists
        ```

- [x] **#SHOP-1** · P0 — Staff Shopify webhook re-register
    - **Where:** extend `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyResyncController.php`; mirror `app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController::registerWebhooks`.
    - **Affects:** Brands whose order webhooks have drifted (Shopify topic-version bump, scope re-grant, etc.). Today the only fix is asking the brand to click a button in their dashboard.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Add route `POST /staff/professionals/{professional}/integrations/shopify/register-webhooks` under the admin-write group.
        - Controller calls the same service the self-service `registerWebhooks` calls. Pass through the result (registered topics + any failures).
        - Add a Pest test that asserts non-admin staff gets 403 and admin gets the webhook list.
    - **Technical:** `routes/api/professional.php:310` already wires this for brands. Staff parity is one new method on the existing resync controller. Webhook drift is the #1 cause of stuck commission rollups (per memory: shopify_integration_lessons), so support needs a non-brand-dependent way to re-arm.
    - **Plain English:** When a brand's Shopify order webhooks stop firing, staff can re-register them without waiting for the brand.
    - **Evidence:**
        ```php
        // routes/api/professional.php:310
        Route::post('/shopify/webhooks/register', [ShopifyIntegrationController::class, 'registerWebhooks']);
        // routes/api/staff.php:232 — only resync exists for staff
        Route::post('/professionals/{professional}/integrations/shopify/resync', [StaffShopifyResyncController::class, 'invoke']);
        ```

- [ ] **#OPS-2** · P0 — Platform audit log of all staff writes
    - **Where:** new migration `supabase/migrations/<ts>_create_staff_audit_log.sql` (table `core.staff_audit_log`); new middleware `app/Http/Middleware/Logging/RecordStaffAuditEntry.php`; new model `app/Models/Core/Staff/StaffAuditEntry.php`.
    - **Affects:** Compliance, internal accountability, future impersonation (#OPS-1) attribution. Today only `professional_deletion_audit_entries` exists — every other admin write is invisible.
    - **Effort:** M (~4–6h)
    - **What to do:**
        - Migration: `core.staff_audit_log (id uuid pk, staff_id uuid fk, impersonator_staff_id uuid null, professional_id uuid fk, action text, route text, http_method text, payload_summary jsonb, ip text, user_agent text, created_at timestamptz)`. Include `impersonator_staff_id` from day one even though #OPS-1 isn't shipped.
        - Middleware: registered on the `staff.admin` route group. After-response hook writes one row per request. Payload-summary is built from a **predefined allowlist** (see scrub rules below) — never include raw request bodies.
        - GET `/staff/professionals/{professional}/audit-log` returning the last 200 entries (any-staff read, paginated).
        - Pest test that asserts an admin PATCH writes an audit row, asserts the row excludes scrubbed fields, asserts a non-admin GET request *doesn't* write a row (only admin writes are audited).
        - **Scrub allowlist (predefine before running):**
            - Always include: route name, professional_id, status code, ts, IP.
            - Allowed payload keys per controller:
                - `StaffProfessionalController::update` → `['display_name','primary_email','phone','professional_type','status']`
                - `StaffStoreSettingsController::update` → `['default_commission_rate','payout_hold_days']`
                - `StaffBrandProfileController::update` → `['brand_status','affiliate_visibility','setup_complete']`
                - All other admin writes → empty payload, route + status only.
            - Never include: raw passwords, Stripe payment methods, OAuth tokens, full email bodies.
    - **Technical:** Pattern already exists for deletions (`ProfessionalDeletionAuditEntry`). Generalising to all admin writes is the natural extension. The middleware approach (vs decorating each controller) keeps the controllers clean and guarantees coverage of new endpoints by default. The scrub allowlist must be in the middleware (not the controller) so a forgotten controller-side opt-in can't accidentally log secrets.
    - **Plain English:** Every change a staff member makes to a brand's account leaves a footprint — who, what, when, from where. Visible to other staff; the brand can request a copy on GDPR access requests.
    - **Evidence:**
        ```php
        // Existing pattern to mirror, app/Models/Core/Professional/ProfessionalDeletionAuditEntry.php
        // (already used in StaffAccountDeletionController:99-103)
        $auditEntries = ProfessionalDeletionAuditEntry::query()
            ->where('professional_id', $professional->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'event', 'actor_type', 'reason', 'metadata', 'created_at']);
        ```

- [ ] **#OPS-1** · P0 — Impersonation ("act as professional") token mint
    - **Where:** new `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffImpersonationController.php`; new claim handling in `app/Http/Middleware/Auth/VerifySupabaseJwt.php` (or sibling); frontend coordination required.
    - **Affects:** Staff's ability to reproduce a UI bug without a screen-share. Adoption blocker the moment volume picks up.
    - **Effort:** M (~6–10h)
    - **What to do:**
        - POST `/staff/professionals/{professional}/impersonate` (admin-only) returns a 30-min signed JWT with claims `{ sub: <pro_supabase_uid>, impersonator_staff_id: <staff_id>, impersonation_expires_at, scope: 'impersonation' }`.
        - Middleware: when a JWT carries `impersonator_staff_id`, every request to a write endpoint writes both IDs to the `staff_audit_log` (#OPS-2). Read endpoints log only on first read of the impersonation session.
        - Frontend coordination: dashboard shows a persistent banner "Impersonating <handle> as <staff_name> — exit" while an impersonation token is active.
        - Hard limits: token TTL 30min, no refresh, can't impersonate other staff, can't impersonate via impersonation (no chaining).
        - Pest tests: token mint requires admin; non-admin staff get 403; impersonating staff cannot mint another impersonation token; expired token is rejected.
    - **Technical:** Standalone — do NOT run unattended. The frontend banner mode and the audit-log integration both need product-side decisions (e.g., "can a brand block impersonation?") before implementation. Recommended to spec this in a separate doc and then run as a focused session with explicit security review.
    - **Plain English:** Staff can log in as a brand for 30 minutes to see exactly what the brand sees. The brand sees a banner in their own dashboard saying "Partna staff impersonated your account at <time>" so there's no surveillance surprise.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/VerifySupabaseJwt.php — single existing JWT verifier
        // No impersonation logic anywhere yet:
        // grep -r "impersonat" app/ → no matches as of 2026-05-08
        ```

- [x] **#GDPR-1** · P0 — Email-subscriber list + export for any brand (GDPR Article 15/20)
    - **Where:** new `app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php`; mirror `app/Http/Controllers/Api/Professional/Notifications/ProfessionalEmailSubscriptionController.php`.
    - **Affects:** Compliance — GDPR Article 15/20 requests for marketing-list inclusion arrive at Partna support, not the brand.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Add routes under read-only staff group (`staff` middleware):
            - `GET /staff/professionals/{professional}/email-subscribers` (paginated list)
            - `GET /staff/professionals/{professional}/email-subscribers/export` (CSV stream)
        - Reuse the same service that powers self-service `index` and `export`.
        - Pest test: any-staff GET succeeds, non-staff gets 403, CSV export downloads correctly.
    - **Technical:** Self-service controller already handles paging and CSV streaming. Staff version is a thin wrapper that takes a professional_id route param instead of resolving from JWT. No writes — Article 17 erasure for email subscribers should remain a self-service action by the subscriber themselves (handled by the existing unsubscribe link mechanism, not this audit).
    - **Plain English:** When a person emails Partna asking "delete me from Brand X's mailing list" or "send me a copy of every email Brand X has on me," support can answer.
    - **Evidence:**
        ```php
        // routes/api/professional.php:277-278 — what brands see
        Route::get('/email-subscribers', [ProfessionalEmailSubscriptionController::class, 'index']);
        Route::get('/email-subscribers/export', [ProfessionalEmailSubscriptionController::class, 'export']);
        // routes/api/staff.php — no equivalent exists
        ```

---

## P1 — meaningful gap, today needs DB or a dev

- [x] **#ANALYTICS-1** · P1 — Brand commerce-analytics overview for staff
    - **Where:** new `app/Http/Controllers/Api/Staff/StaffSite/StaffBrandCommerceAnalyticsController.php`; mirror `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php`.
    - **Affects:** Sales team / support both need GMV/conversion view for a brand without a screen-share.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Add `GET /staff/professionals/{professional}/commerce-analytics` under read-only staff group.
        - Return the same payload shape as the self-service `BrandCommerceAnalyticsController::overview`.
        - Reuse the same service / cache key (commerce reads are fronted by `CacheLockService::rememberLocked` per CLAUDE.md commerce-read pattern).
        - Pest test that hits the endpoint, asserts the cached payload is returned, and asserts non-staff gets 403.
    - **Technical:** Self-service overview already returns a curated brand dashboard payload. Staff parity is a thin wrapper. Don't recompute — share the cache key with the self-service path so a single bust covers both.
    - **Plain English:** Support can see a brand's GMV / orders / conversion view without asking the brand to share their screen.
    - **Evidence:**
        ```php
        // routes/api/professional.php:172
        Route::get('/brand/commerce-analytics', [BrandCommerceAnalyticsController::class, 'overview']);
        ```

- [x] **#LEDGER-1** · P1 — Manual commission adjustment entry (admin)
    - **Where:** new `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionAdjustmentController.php`; new action `app/Actions/Commerce/PostCommissionAdjustmentAction.php`.
    - **Affects:** Support's ability to credit/debit a professional for a mis-attributed order. Today this requires engineering + raw SQL.
    - **Effort:** M (~3–4h)
    - **What to do:**
        - Add `POST /staff/commissions/adjust` (admin-only) accepting `{ professional_id, amount_cents, reason, reference }`.
        - Action writes one row to `commerce.commission_movements` with `entry_type='adjustment'`, `actor_type='staff'`, `actor_id=<staff_id>`. Rejects if `reason` is < 20 chars (enforce a meaningful audit trail).
        - Idempotency via a `reference` field (caller-supplied unique ID); reject duplicate references with 409.
        - Pest tests: positive adjustment, negative adjustment (clawback shape), idempotency check, reason validation.
    - **Technical:** Standalone — do NOT bundle. `commerce.commission_movements` is the money source-of-truth post-Phase-4 (per CLAUDE.md commerce architecture). Idempotency, currency, and rollup invalidation all need careful review. The action must invalidate the brand's cached commerce overview and the affiliate's projection cache (push-invalidation pattern).
    - **Plain English:** Admin can credit or debit an affiliate or brand's ledger when an order was mis-attributed, with a reason on the record.
    - **Evidence:**
        ```sql
        -- supabase/migrations/<phase-4-rename>_rename_ledger_to_movements.sql
        -- entry_type IN ('payout', 'clawback', 'adjustment')
        -- 'adjustment' is already a valid type — no schema change needed
        ```

- [x] **#NOTES-1** · P1 — Admin notes column on professionals (B6, shipped 2026-05-17)
    - **Where:** new migration adding `core.professionals.admin_notes TEXT NULL`; wire into `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php` and `app/Http/Resources/ProfessionalStaffResource.php`.
    - **Affects:** Tribal knowledge currently lives in Slack threads.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Migration: `ALTER TABLE core.professionals ADD COLUMN admin_notes TEXT NULL;`.
        - Uncomment the field in `StaffUpdateProfessionalRequest:50` and add validation rule `'admin_notes' => ['sometimes', 'nullable', 'string', 'max:5000']`.
        - Add `admin_notes` to `ProfessionalStaffResource` output (staff-only resource — never expose to brand-side resources).
        - Pest test: admin PATCHes notes, GET returns them, professional-side `/me` does *not* expose them.
    - **Technical:** The placeholder is already in the form request as a comment. One column, one resource line, one test. The only subtle bit is making sure the field is staff-resource-only — `ProfessionalResource` (used by `/me`) must not include it.
    - **Plain English:** Staff can pin "VIP brand, do not suspend" or "DMCA pending" to a brand's record so the next staff member sees it.
    - **Evidence:**
        ```php
        // app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateProfessionalRequest.php:48-50
        // optional staff-only flags (ONLY keep these if your DB/model actually has them)
        // 'is_suspended' => ['sometimes', 'boolean'],
        // 'admin_notes'  => ['sometimes', 'nullable', 'string', 'max:5000'],
        ```

- [ ] **#ENQUIRY-1** · P1 — Enquiries inbox for staff (read + delete)
    - **Where:** new `app/Http/Controllers/Api/Staff/StaffSite/StaffEnquiryController.php`; mirror `app/Http/Controllers/Api/Professional/ProfessionalEnquiryController.php`.
    - **Affects:** Spam waves, abuse complaints, GDPR right-to-erasure on contact-form submissions.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - `GET /staff/professionals/{professional}/enquiries` (paginated, any-staff).
        - `DELETE /staff/professionals/{professional}/enquiries/{id}` (admin-only, audit-logged via #OPS-2).
        - Skip PATCH — read + delete covers spam/erasure.
        - Pest tests: list returns enquiries, admin delete works, non-admin delete returns 403.
    - **Technical:** Mirror the self-service controller's index + destroy methods. Use the same Eloquent query (filtered by professional_id route param instead of JWT).
    - **Plain English:** Support can clear a spam enquiry that's filling a brand's contact-form inbox, or fulfil a "delete my contact-form submission" GDPR request.
    - **Evidence:**
        ```php
        // routes/api/professional.php:209-213
        Route::get('/enquiries', [ProfessionalEnquiryController::class, 'index']);
        Route::patch('/enquiries/{id}', [ProfessionalEnquiryController::class, 'update']);
        Route::delete('/enquiries/{id}', [ProfessionalEnquiryController::class, 'destroy']);
        ```

- [x] **#STORE-1** · P1 — Staff-side `deploy` for brand store settings (push to Shopify) (B6, shipped 2026-05-17)
    - **Where:** extend `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffStoreSettingsController.php`.
    - **Affects:** After staff edits commission rate, the brand still sees stale rates in Shopify until the brand clicks Deploy themselves.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Add `POST /staff/professionals/{professional}/store-settings/deploy` (admin-only).
        - Call the same service that powers self-service `BrandStoreSettingsController::deploy`.
        - Audit-log entry includes `'staff-deploy'` action type.
        - Pest test: admin triggers deploy, asserts the Shopify-side metafield-write job is dispatched.
    - **Technical:** The intentional design (per `StaffStoreSettingsController:11-12`) is that staff edits skip metafield sync. Adding a manual deploy route preserves that design — staff opts in to the Shopify-side push when they're ready, instead of every PATCH triggering one.
    - **Plain English:** After admin edits a brand's commission rate, admin can push the change live to the brand's Shopify storefront.
    - **Evidence:**
        ```php
        // routes/api/professional.php:370 — what brands have today
        Route::post('/brand/store-settings/deploy', [BrandStoreSettingsController::class, 'deploy']);
        // app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffStoreSettingsController.php:11-12
        // V2: Staff admin overrides brand commission rate and payout hold days. DB-only write —
        // deliberately skips Shopify metafield sync to avoid API calls during support operations.
        ```

- [x] **#CATALOG-2** · P1 — Brand catalog admin overrides (commission, discount, active)
    - **Where:** extend the read-only catalog inspector (#CATALOG-1) with admin writes; mirror `app/Http/Controllers/Api/Professional/Store/BrandCatalogController.php` methods `updateCommission`, `updateDiscount`, `toggleActive`.
    - **Affects:** Mis-priced products, broken checkouts during a brand's off-hours.
    - **Effort:** M (~2–3h)
    - **What to do:**
        - Add three admin-only routes:
            - `PATCH /staff/professionals/{professional}/brand/catalog/{productGid}/commission`
            - `PATCH /staff/professionals/{professional}/brand/catalog/{productGid}/discount`
            - `PATCH /staff/professionals/{professional}/brand/catalog/{productGid}/active`
        - Reuse the self-service service methods. Audit-logged via #OPS-2 with the productGid as part of the payload-summary allowlist.
        - Pest sweep that toggles each field and verifies it propagates to Shopify (use the existing test fixture for catalog mutations).
    - **Technical:** Pre-req on #CATALOG-1 (read-only inspector) so admin can see what they're editing. The self-service controller throttles writes via `brand-catalog-writes` — staff routes should reuse the same throttle.
    - **Plain English:** Admin can fix a mis-priced discount or deactivate a broken product without waiting for the brand.
    - **Evidence:**
        ```php
        // routes/api/professional.php:359-364
        Route::patch('/brand/catalog/{productGid}/commission', [BrandCatalogController::class, 'updateCommission']);
        Route::patch('/brand/catalog/{productGid}/discount', [BrandCatalogController::class, 'updateDiscount']);
        Route::patch('/brand/catalog/{productGid}/active', [BrandCatalogController::class, 'toggleActive']);
        ```

- [x] **#CATALOG-1** · P1 — Read-only brand catalog inspector
    - **Where:** new `app/Http/Controllers/Api/Staff/StaffSite/StaffBrandCatalogController.php`; mirror reads from `app/Http/Controllers/Api/Professional/Store/BrandCatalogController.php`.
    - **Affects:** Triaging "my discount isn't showing" requires a Shopify admin login support doesn't have.
    - **Effort:** L (~3–5h)
    - **What to do:**
        - Add read-only routes (any-staff):
            - `GET /staff/professionals/{professional}/brand/catalog` (paginated)
            - `GET /staff/professionals/{professional}/brand/catalog/all`
            - `GET /staff/professionals/{professional}/brand/catalog/debug`
            - `GET /staff/professionals/{professional}/brand/catalog/{productGid}` (single product)
        - Reuse the self-service service. Catalog endpoints are slow + heavy — share the same cache layer self-service uses (or accept the same TTL).
        - Pest tests cover paged list, single-product, and debug shapes.
    - **Technical:** Catalog endpoints hit Shopify GraphQL on cache miss. Staff parity should never bypass the cache (don't add a `?fresh=true` flag) — that would let a forgotten admin tab burn the brand's Shopify rate-limit budget. Same TTL, same single-flight lock.
    - **Plain English:** Staff can see what Partna has fetched from a brand's Shopify, including derived flags and metafields, without an admin login.
    - **Evidence:**
        ```php
        // routes/api/professional.php:340-358
        Route::get('/brand/catalog', [BrandCatalogController::class, 'index']);
        Route::get('/brand/catalog/all', [BrandCatalogController::class, 'all']);
        Route::get('/brand/catalog/debug', [BrandCatalogController::class, 'debug']);
        ```

- [x] **#INVITE-1** · P1 — Full brand-affiliate invite admin (single, bulk, CSV, resend)
    - **Where:** extend `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffInviteController.php`; mirror `app/Http/Controllers/Api/Professional/BrandAffiliateInviteController.php`.
    - **Affects:** Brands stuck on CSV import / 200-affiliate launches.
    - **Effort:** M (~3–4h)
    - **What to do:**
        - Add admin-only routes:
            - `POST /staff/professionals/{professional}/invites` (single)
            - `POST /staff/professionals/{professional}/invites/bulk`
            - `POST /staff/professionals/{professional}/invites/import-csv`
            - `POST /staff/professionals/{professional}/invites/{invite}/resend`
        - Reuse the self-service service. Apply the same `brand-funding-gate` middleware as self-service so an underfunded brand can't get invites sent on their behalf.
        - Pest sweep across all four endpoints.
    - **Technical:** Self-service routes (`routes/api/professional.php:107-117`) gate write endpoints behind `brand.only` + `brand-funding-gate`. The staff version skips `brand.only` (staff is by definition not a brand) but must keep `brand-funding-gate` to prevent staff bypassing the funding requirement.
    - **Plain English:** Support can mass-invite affiliates on behalf of a brand whose CSV import keeps failing.
    - **Evidence:**
        ```php
        // routes/api/professional.php:107-117
        Route::middleware(['brand.only'])->group(function (): void {
            Route::middleware('brand-funding-gate')->group(function (): void {
                Route::post('/brand-affiliate-invites', ...);
                Route::post('/brand-affiliate-invites/bulk', ...);
                Route::post('/brand-affiliate-invites/import-csv', ...);
            });
            Route::delete('/brand-affiliate-invites/{invite}', ...);
        });
        ```

- [x] **#NOTIF-1** · P1 — Per-pro notification list / mark-read / dismiss
    - **Where:** extend `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php`; mirror reads/writes from `app/Http/Controllers/Api/Professional/Notifications/NotificationController.php`.
    - **Affects:** Stuck banners on a brand's dashboard support can't clear.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Add admin-only routes:
            - `GET /staff/professionals/{professional}/notifications`
            - `POST /staff/professionals/{professional}/notifications/{notification}/read`
            - `POST /staff/professionals/{professional}/notifications/{notification}/dismiss`
        - Reuse the self-service service.
        - Pest test: admin marks a notification read on behalf of a pro, asserts the pro's GET no longer flags it.
    - **Technical:** `StaffNotificationController` already has a `store` method (send a notification). Adding the inverse direction (manage a pro's incoming notifications) keeps the controller cohesive. No new model touched.
    - **Plain English:** Support can clear a "your Stripe needs attention" banner that's no longer relevant.
    - **Evidence:**
        ```php
        // routes/api/professional.php:266-270
        Route::get('/me/notifications', [NotificationController::class, 'index']);
        Route::post('/me/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('/me/notifications/{notification}/dismiss', [NotificationController::class, 'dismiss']);
        ```

- [ ] **#UPLOAD-1** · P1 — Staff-side image / brand-logo / placeholder / document management
    - **Where:** new `app/Http/Controllers/Api/Staff/StaffSite/StaffUploadController.php` (or split per pool); mirror `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php` and `BrandGalleryController` / `ProfessionalDocumentController`.
    - **Affects:** A brand can't upload a 5 MB logo because their WiFi flakes — staff has zero ability to do it for them.
    - **Effort:** L (~6–10h)
    - **What to do:**
        - Mirror the full upload surface under `/staff/professionals/{professional}/...`:
            - `POST .../uploads` (image)
            - `POST .../uploads/brand-logo`, `DELETE .../uploads/brand-logo`
            - `POST .../uploads/brand-placeholder-image`, `GET .../uploads/brand-placeholder-images`, `POST .../uploads/brand-placeholder-images/reorder`, `DELETE .../uploads/brand-placeholder-images/{media}`
            - `GET .../images`, `POST .../images/reorder`, `DELETE .../images/{image}`
            - `GET .../documents`, `POST .../documents`, `PATCH .../documents/{document}`, `DELETE .../documents/{document}`
        - Auth: any-staff for GET, admin-only for POST/PATCH/DELETE. All audit-logged via #OPS-2.
        - Reuse `BrandDesignMediaService` (per memory: design tokens + media live there).
        - Pest sweep covering each pool.
    - **Technical:** Standalone-bundle (B7) because the four pools share infrastructure. Don't split into four sessions — the orchestrator would re-derive the same scaffolding four times. The R2 + WebP variant pipeline should be reused as-is — no new media flow.
    - **Plain English:** Support can upload a logo, fix a corrupt PDF, or reorder a brand's placeholder images on a brand's behalf.
    - **Evidence:**
        ```php
        // routes/api/professional.php:225-263 — full self-service surface
        Route::post('/uploads', [ProfessionalUploadController::class, 'upload']);
        Route::middleware(['brand.only'])->group(function () {
            Route::post('/uploads/brand-logo', [ProfessionalUploadController::class, 'uploadBrandLogo']);
            Route::delete('/uploads/brand-logo', [ProfessionalUploadController::class, 'destroyBrandLogo']);
            Route::post('/uploads/brand-placeholder-image', ...);
            // etc — 9 endpoints total
        });
        Route::get('/documents', ...);
        Route::post('/documents', ...);
        // staff side: zero equivalents
        ```

- [x] **#SHOP-2** · P1 — Staff-side Shopify disconnect
    - **Where:** extend `StaffShopifyResyncController` (rename to a more general staff Shopify integration controller, or add sibling).
    - **Affects:** Cleanup after a brand walks away mid-onboarding without disconnecting.
    - **Effort:** S (~1h)
    - **What to do:**
        - `POST /staff/professionals/{professional}/integrations/shopify/disconnect` (admin-only).
        - Reuse the self-service `ShopifyIntegrationController::disconnect` service.
        - Audit-logged.
    - **Technical:** Must trigger the same teardown self-service does (revoke tokens, mark integration disconnected, fire `BrandShopifyDisconnected` event so caches invalidate). Don't shortcut by deleting the row.
    - **Plain English:** Admin can sever a stale Shopify connection on a brand's behalf.
    - **Evidence:**
        ```php
        // routes/api/professional.php:308
        Route::post('/shopify/disconnect', [ShopifyIntegrationController::class, 'disconnect']);
        ```

- [x] **#FRESHA-1** · P1 — Read-only Fresha integration status + force disconnect
    - **Where:** new `app/Http/Controllers/Api/Staff/StaffSite/StaffFreshaController.php`; mirror `FreshaIntegrationController::status` + `disconnect`. Gate on `feature:fresha_sync`.
    - **Affects:** Fresha account disputes ("which Partna account is connected to my Fresha?").
    - **Effort:** S (~1h)
    - **What to do:**
        - `GET /staff/professionals/{professional}/fresha/status` (any-staff).
        - `POST /staff/professionals/{professional}/fresha/disconnect` (admin-only).
        - Skip connect/sync/push — those are user actions.
    - **Technical:** Mirror exactly. Per memory (`fresha_integration_status`), the integration is scaffolded but unverified — staff endpoints should not assume push/sync work end-to-end.
    - **Plain English:** Support can see and break a stale Fresha connection.
    - **Evidence:**
        ```php
        // routes/api/professional.php:457-458
        Route::get('/fresha/status', [FreshaIntegrationController::class, 'status']);
        Route::post('/fresha/disconnect', [FreshaIntegrationController::class, 'disconnect']);
        ```

- [x] **#SQUARE-1** · P1 — Read-only Square integration status + force disconnect
    - **Where:** new `app/Http/Controllers/Api/Staff/StaffSite/StaffSquareController.php`; mirror `SquareIntegrationController::status` + `disconnect`. Gate on `feature:square_sync`.
    - **Affects:** Same shape as Fresha — stale OAuth cleanup.
    - **Effort:** S (~1h)
    - **What to do:**
        - `GET /staff/professionals/{professional}/square/status` (any-staff).
        - `POST /staff/professionals/{professional}/square/disconnect` (admin-only).
        - Skip connect/sync/push.
    - **Technical:** Identical pattern to #FRESHA-1.
    - **Plain English:** Support can see and break a stale Square connection.
    - **Evidence:**
        ```php
        // routes/api/professional.php:291-293
        Route::get('/square/status', [SquareIntegrationController::class, 'status']);
        Route::post('/square/connect', ...);
        Route::post('/square/disconnect', [SquareIntegrationController::class, 'disconnect']);
        ```

---

## P2 — reduces toil, workarounds exist

- [ ] **#FF-1** · P2 — Per-tenant feature flag overrides
    - **Where:** new migration adding `core.professional_feature_overrides (professional_id uuid pk, flag_name text, enabled bool, created_at timestamptz)`; new service `app/Services/Features/FeatureFlagService.php` wrapping `config('partna.features.*')`.
    - **Affects:** Beta cohorts. Today flags like `smart_booking`, `square_sync`, `fresha_sync` are global.
    - **Effort:** L (~6–10h)
    - **What to do:**
        - Migration as above. Composite PK on (professional_id, flag_name).
        - `FeatureFlagService::enabled(Professional $pro, string $flag): bool` checks override first, falls back to global config.
        - Refactor every `config('partna.features.<flag>')` callsite to go through the service. Use `grep -r "config('partna.features" app/` to find all callsites.
        - Add `GET /staff/professionals/{professional}/feature-flags` (any-staff read) and `PATCH .../feature-flags` (admin write, audit-logged).
        - Pest sweep that asserts override > global fallback in both directions.
    - **Technical:** Standalone — touches every flag-check site. Worth doing once, then permanent.
    - **Plain English:** Staff can turn smart-booking on for one brand to test it, without flipping it for everyone.
    - **Evidence:**
        ```php
        // grep -r "config('partna.features" app/ | head -3
        // Multiple callsites currently — all hardcoded global lookups.
        ```

- [x] **#BULK-1** · P2 — Bulk professional-status patch (B6, shipped 2026-05-17)
    - **Where:** extend `StaffProfessionalController`.
    - **Affects:** Compliance sweeps (suspending fraud-account waves).
    - **Effort:** S (~1–2h)
    - **What to do:**
        - `POST /staff/professionals/bulk-status` (admin-only) with `{ ids: uuid[], status: 'active' | 'suspended' }`.
        - Cap at 100 IDs per request, throttle 5/min, audit-log one entry per professional.
        - Pest test: 100 IDs succeed, 101 IDs return 422.
    - **Technical:** Single transaction. Reuse `updateStatus` logic per row.
    - **Plain English:** Admin can suspend a wave of fraud accounts in one request.
    - **Evidence:** N/A — net-new.

- [x] **#WEBHOOK-1** · P2 — Shopify event-replay endpoint
    - **Where:** new `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyEventReplayController.php`.
    - **Affects:** Stuck commission rollups for a single order.
    - **Effort:** M (~2–3h)
    - **What to do:**
        - `POST /staff/professionals/{professional}/shopify/events/replay` with `{ shopify_event_id }` (admin-only).
        - Re-runs the order pipeline for the named event from `commerce.order_events`. Idempotent — uses the existing `shopify_event_id` dedup key.
        - Pest test: replay succeeds and is a no-op (no duplicate rows in `commerce.orders`).
    - **Technical:** Standalone — touches dedup logic. Don't bypass the dedup; let it short-circuit on duplicates and return a "already processed" flag.
    - **Plain English:** When one order's commission rolls up wrong, staff can replay just that event without a full resync.

- [x] **#STRIPE-PM-1** · P2 — Read-only payment methods + payouts list
    - **Where:** extend `StaffStripeConnectController` (added in #PAYOUT-1).
    - **Affects:** Card-decline triage.
    - **Effort:** S (~1h)
    - **What to do:**
        - `GET /staff/professionals/{professional}/stripe/payment-methods` (any-staff, last4 + brand only).
        - `GET /staff/professionals/{professional}/stripe/payouts` (any-staff, paginated).
        - Pest test asserts no full PAN, expiry, or CVC ever leaves Stripe.
    - **Technical:** Read-only mirrors. Field allowlist must be tight — never expose raw Stripe responses.
    - **Plain English:** Support can see "card ending 4242 declined on 2026-05-07" without asking the brand.

- [x] **#SUB-2** · P2 — Subscription preview-change for staff
    - **Where:** extend `StaffSubscriptionManagementController`.
    - **Affects:** Plan-change conversations.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - `GET /staff/professionals/{professional}/subscription/preview-change?plan_id=...` (any-staff).
        - Reuse self-service `previewPlanChange` service.
    - **Plain English:** "If I move you to plan X, your bill becomes…" before making the change.
    - **Evidence:**
        ```php
        // routes/api/professional.php:287
        Route::get('/me/subscription/preview-change', [SubscriptionController::class, 'previewPlanChange']);
        ```

- [x] **#SUB-1** · P2 — Stripe billing portal link minted by staff
    - **Where:** extend `StaffSubscriptionManagementController`.
    - **Affects:** Card-decline recovery.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `POST /staff/professionals/{professional}/subscription/billing-portal` (admin-only).
        - Reuse self-service `billingPortal` service. Email the URL to the brand (don't return it directly to staff — security defence).
    - **Technical:** The portal session belongs to the brand's Stripe customer; staff should never see a billing-portal URL that grants access to the brand's payment methods.
    - **Plain English:** Admin mints a billing-portal link, the brand receives it by email, the brand can update their card without re-onboarding.

- [x] **#BOOK-1** · P2 — Booking settings inspector (read-only)
    - **Where:** new `StaffBookingController` gated on `feature:smart_booking`.
    - **Effort:** S (~1h)
    - **What to do:**
        - `GET /staff/professionals/{professional}/booking/settings` (any-staff).
        - `GET /staff/professionals/{professional}/booking/analytics` (any-staff).
        - No writes.
    - **Evidence:**
        ```php
        // routes/api/professional.php:166-169
        Route::middleware('feature:smart_booking')->group(function () {
            Route::patch('/booking/settings', [ProfessionalSiteController::class, 'updateBookingSettings']);
            Route::get('/booking/my-analytics/overview', [BookingAnalyticsController::class, 'myOverview']);
        });
        ```

- [x] **#GBP-1** · P2 — Google Business Profile read for staff
    - **Where:** extend `StaffSiteController`.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - `GET /staff/professionals/{professional}/site/google-business-profile` (any-staff).
        - Read-only first.
    - **Evidence:**
        ```php
        // routes/api/professional.php:130, 134
        Route::get('/site/google-business-profile', [ProfessionalGoogleBusinessProfileController::class, 'show']);
        Route::put('/site/google-business-profile', [ProfessionalGoogleBusinessProfileController::class, 'upsert']);
        ```

- [x] **#AFF-PHOTO-1** · P2 — Affiliate custom-product-photo admin (delete only) (B6, shipped 2026-05-17)
    - **Where:** new `StaffAffiliatePhotoController`.
    - **Affects:** DMCA / inappropriate content takedowns.
    - **Effort:** M (~1–2h)
    - **What to do:**
        - `GET /staff/professionals/{professional}/affiliate/products/{gid}/photos` (any-staff).
        - `DELETE /staff/professionals/{professional}/affiliate/products/{gid}/photos/{media}` (admin-only, audit-logged).
        - Skip upload — that's user content.
    - **Plain English:** Support can remove an inappropriate photo on behalf of a brand.

- [ ] **#AFF-SEL-1** · P2 — Affiliate selections inspector + force reset
    - **Where:** new `StaffAffiliateSelectionController`.
    - **Affects:** Stuck affiliate storefronts.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - `GET /staff/professionals/{professional}/affiliate/selections` (any-staff).
        - `POST /staff/professionals/{professional}/affiliate/selections/reset-to-defaults` (admin-only).
    - **Plain English:** When an affiliate's storefront is stuck on stale selections, support can reset them to brand defaults.

- [x] **#COLLECTION-1** · P2 — Brand collections inspector (read-only)
    - **Where:** new `StaffBrandCollectionController`.
    - **Effort:** S (~1h)
    - **What to do:**
        - `GET /staff/professionals/{professional}/brand/collections/{collectionType}/products` where `collectionType ∈ {active, default, favourites}`.
        - Read-only — collection curation is a brand decision.

- [ ] **#BRAND-DESIGN-1** · P2 — Brand design resolved shape + Shopify resync
    - **Where:** new `StaffBrandDesignController`.
    - **Effort:** S (~1h)
    - **What to do:**
        - `GET /staff/professionals/{professional}/brand/design` (any-staff).
        - `POST /staff/professionals/{professional}/brand/design/resync` (admin-only).
    - **Plain English:** Theme tokens drift between Partna and Shopify after manual edits in the Shopify admin; resync is the canonical fix.

- [x] **#BRAND-SETUP-1** · P2 — Brand onboarding readiness + setup status (read-only)
    - **Where:** new `StaffBrandSetupController`.
    - **Effort:** S (~1h)
    - **What to do:**
        - `GET /staff/professionals/{professional}/brand/onboarding-readiness` (any-staff).
        - `GET /staff/professionals/{professional}/brand/setup/status` (any-staff).
        - No writes — let brand complete it themselves; let support diagnose.

---

## P3 — speculative, defer until ticket lands

> Per agent memory, P3 items typically skip in unattended runs. Build only when a real ticket arrives.

- [ ] **#MSG-1** · P3 — Transactional email send-on-behalf with templates
    - **Where:** new `StaffEmailController`; new template registry.
    - **Effort:** M (~3–5h)
    - **What to do:** `POST /staff/professionals/{professional}/emails/send` with `{ template_id, vars }`. Templates: welcome-back, payment-issue, scheduled-maintenance.
    - **Plain English:** One-off emails go through a templated, audit-logged path instead of personal inboxes.

- [x] **#BRAND-INVITE-PROMOTE-1** · P3 — Staff promote a brand-partner connection (B6, shipped 2026-05-17)
    - **Where:** new `StaffBrandPartnerController`.
    - **Effort:** S (~1h)
    - **What to do:** `POST /staff/professionals/{affiliate}/brand-partners/{brand}/promote`. Mirror self-service.

- [ ] **#PAYOUT-PAUSE-1** · P3 — Brand-level payout pause toggle
    - **Where:** extend `BrandStoreSettings` with `payouts_paused BOOL`.
    - **Effort:** S (~1–2h)
    - **What to do:** PATCH `/staff/.../store-settings` honours `payouts_paused`; payout job skips paused brands.
    - **Plain English:** Disputed-brand pause without disconnecting Stripe.

- [ ] **#TAX-1** · P3 — Tax-compliance export per professional
    - **Where:** new `StaffTaxExportController` + queued job.
    - **Effort:** L (~6–10h)
    - **What to do:** `POST /staff/professionals/{professional}/tax-export?period=YYYY` queues a job emitting CSV of payouts + adjustments + clawbacks; signed-URL email.

- [ ] **#FAILED-JOB-1** · P3 — Per-pro failed-job inspector
    - **Where:** new `StaffFailedJobController`.
    - **Effort:** S (~1h)
    - **What to do:** `GET /staff/professionals/{professional}/failed-jobs` filtering Horizon's failed-job list by professional payload.

- [ ] **#ORDER-1** · P3 — Order inspector for staff
    - **Where:** new `StaffOrderController`.
    - **Effort:** L (~4–8h)
    - **What to do:** `GET /staff/professionals/{professional}/orders`, `.../orders/{order}`, `.../orders/{order}/events`. Read-only against `commerce.orders` + `order_events`.
    - **Plain English:** Per-order dispute triage. Today via SQL only.

---

## Suggested rollout order

1. **Sprint 1 (P0 foundation):** #OPS-2 audit log → #OPS-1 impersonation → #NOTES-1 admin notes → #GDPR-1 email subscribers. Audit-log first because every later admin endpoint should be logged from day one.
2. **Sprint 2 (P0 commerce):** #PAYOUT-1 Stripe status → #SHOP-1 webhook re-register → #LEDGER-1 manual adjustment.
3. **Sprint 3 (P1 integrations + uploads):** B1 (#SQUARE-1, #FRESHA-1, #SHOP-2) → B7 (#UPLOAD-1).
4. **Sprint 4 (P1 catalog + analytics):** #CATALOG-1 → #CATALOG-2 → B2 (read-only inspectors) → #ANALYTICS-1 → #INVITE-1.
5. **Sprint 5 (P1 polish):** B3 (subscription) → B5 (#NOTIF-1, #INVITE-1 if not yet) → #ENQUIRY-1 → #STORE-1.
6. **Backlog (P2/P3):** pick up as tickets arrive.

---

## Quick reference — what staff can do *today*

**Any staff (read + soft-delete + data-export-to-pro):** profiles, customers, services, categories, sections, links, sites, analytics summary, subscription view, integrations view, invites view, deletion state, commissions, payouts, soft-delete + restore on professionals/customers/services.

**Admin staff (writes):** professional profile + status + hard-delete; customer CRUD; service/category/section/link CRUD; site (theme + design + subdomain + publish + settings); subscription change/cancel/resume; brand profile; brand store settings (DB only — no deploy); commission void; commission-payout retry + ack-manual-refund; affiliate status toggle; brand-affiliate link create/delete; invite cancel; Shopify resync; notification send + email-policy management (global + per-pro); data-export send-to-staff; deletion initiate + cancel.
