# Backend Review — PARTNA-STANDALONE-PAGES-NEW-DIRECTION (v3)

Reviewer: backend Claude (Comet-Backend session, 2026-05-19)
Plan reviewed: `/Users/joshuahunter/Downloads/PARTNA-STANDALONE-PAGES-NEW-DIRECTION 2.md` (1718 lines)
Codebase consulted: `/Users/joshuahunter/Herd/Side Street/backend` (Partna / Comet-Backend, branch `development`)

---

## 1. Executive summary

The plan is architecturally sound and the audit-resolution log is impressively thorough. From a backend lens, the routing model (three-branch KV + Service Binding + explicit Cache API) maps cleanly onto our existing `SyncSubdomainToKvJob` / `CloudflareKvService` shape. The new services (`AccountCapabilities`, `AccountTypeTransitionService`, `CloudflarePurgeService`, `BrandSignupCodeService`) all fit our `Services/<Domain>/` convention. §28.16 soft-delete migration is correctly scoped and the audit reasoning is right.

**Biggest risks, in priority order:**

1. **§8 backfill ignores `professional_type = 'influencer'`.** The live enum has THREE values (`brand`, `professional`, `influencer`), not two. The backfill SQL as written would put every influencer into the `else → individual` bucket, which is probably what we want, but the plan doesn't acknowledge influencers exist. Same gap for the dual-write trigger.
2. **Rule #9 enforcement is broken against the real code.** The architecture-test grep targets the literal token `SUBDOMAIN_KV` but our PHP code routes everything through `CloudflareKvService::put/delete`. The token `SUBDOMAIN_KV` doesn't appear in PHP at all. The test would assert vacuously. Worse, `RetireSubdomainFromKvJob` is a real second writer/deleter that the rule textually forbids — so either rule or job needs reconciling.
3. **`brand_status` vocabulary is wrong throughout the plan.** Plan uses `'Onboarding'` and `'Live'` (capitalised). Actual enum is `building | preview | live | systems_down` (lowercase). §13, §28.13, §58 verification text need updates.
4. **`AccountTypeDefaultsService` already exists** at `app/Services/Professional/AccountTypeDefaultsService.php` and reads `partna.account_type_defaults` config. The plan introduces `AccountCapabilities` without acknowledging this adjacent concept — high risk of two parallel "what does this account type get" registries diverging.
5. **§28.11 understates the Stripe rework.** `StripeConnectController` has ~10 `$role === 'brand'` branches and `CommissionPolicy` hard-codes `professional_type === 'brand'`. "Wrapped in capability guard" is not realistic — these are interior branches, not outer gates.

Nothing is fatal. All five risks can be addressed by tightening the plan, not redesigning. Track A is realistic for one focused developer but is closer to 3–4 weeks of work than the parallelism in §47 implies.

---

## 2. Section-by-section findings

### §28.1 — account_type migration

**Severity: SHOULD-FIX**

- **Issue:** `professional_type` has three live values (`brand`, `professional`, `influencer` — see `config/partna.php → professional_types`, line documented inline). The backfill rule covers two. Influencers fall into the `else → individual` bucket, which is plausibly right but should be stated explicitly.
- **Issue:** Adding `NOT NULL` + `CHECK` in one step on an existing table will fail if any row's backfill is null. The plan's brand_signup_code migration (§36) correctly does this in three steps; the account_type migration text (§28.1 / §8) should mirror the same nullable→backfill→NOT NULL pattern explicitly.
- **Issue:** The dual-write trigger isn't specified in detail. What if both columns are written in the same statement to inconsistent values (e.g. legacy code writing `professional_type='professional'` while new code writes `account_type='partner'`)? Need a precedence rule (recommend: new column wins).
- **Recommendation:** Rewrite §8 backfill to enumerate three legacy values explicitly. Specify trigger conflict resolution. Confirm migration step ordering in §28.1.

### §28.2 — Model updates

**Severity: LGTM** (with minor)

- The `isBrand()` shim approach works. `Professional::isBrand()` today reads `$this->professional_type` (model:132–135); after the trigger lands either column reads the same value, so the shim transition is safe.
- 49 files reference `professional_type`. Migrating them is mechanical but voluminous (see §28.11 below).

### §28.3 — AccountCapabilities registry

**Severity: SHOULD-FIX**

- **Naming collision with `AccountTypeDefaultsService`.** The existing service at `app/Services/Professional/AccountTypeDefaultsService.php` is conceptually adjacent: it answers "what sections / config does this account type get on creation?" via `partna.account_type_defaults` config. `AccountCapabilities` answers "what features can this account type access?" — overlap is real (default vs runtime gate of the same feature set).
- **Recommendation:** Either (a) namespace them clearly (`App\Services\Accounts\AccountCapabilities` vs the existing `App\Services\Professional\AccountTypeDefaultsService`) AND add a paragraph in CLAUDE.md / the plan defining the line between them, or (b) consolidate — make `AccountTypeDefaultsService` consume `AccountCapabilities` so there's one source of truth for "what brand/partner/individual means."

### §28.4 — AccountTypeTransitionService

**Severity: LGTM**

- Fits the convention. There is no `Actions/` namespace; single-shot operations live alongside services in the relevant domain folder (CLAUDE.md explicitly says so). Placing this at `App\Services\Accounts\AccountTypeTransitionService` is idiomatic.
- Wrapping in `DB::transaction`, dispatching events, dispatching jobs — matches how `BrandPartnerLinkLifecycleService` is built today.
- **Minor:** the plan should specify that the row-level lock (§12 "concurrent transitions serialized") is `lockForUpdate()` inside the transaction, not advisory. There are no advisory locks in the codebase today; introducing one would be novel.

### §28.5 — Event + listeners

**Severity: LGTM**

- We use Laravel events elsewhere (e.g. `BrandPartnerLinkEvent` model — different concept, but the pattern is familiar). One listener per side-effect is consistent with existing observers (`BrandPartnerLinkObserver` splits its work into `dispatchSync`, `bustHydrogenCaches`, `publishCreated`).

### §28.6 — SyncSubdomainToKvJob update

**Severity: LGTM** (with caveat)

- The current `else` branch in `SyncSubdomainToKvJob::handle()` (line ~55) explicitly calls `$kv->delete($current)` for the no-link case. Replacing it with `$kv->put($current, ['type' => 'individual'], null)` is a 5-line change.
- **Caveat:** the existing observer (`BrandPartnerLinkObserver::deleted`) fires `SyncSubdomainToKvJob` on hard delete. With `SoftDeletes` added in §28.16, Eloquent fires the same `deleted` event on soft-delete — so the wiring carries over. **Verified.** Worth a note in §28.16.
- **Caveat:** `Professional::isBrand()` is still the branch trigger here (line 44). After dual-write, this is fine. After eventual `professional_type` drop, it must be migrated. Track for follow-up.

### §28.7 — CloudflarePurgeService + Job

**Severity: LGTM**

- Fits the pattern alongside `CloudflareKvService` and `CloudflareDnsService` (`app/Services/Cloudflare/`).
- Decision NOT to reuse `HasCloudflareRetryPolicy` is correct — that trait is used by KV jobs (`SyncSubdomainToKvJob`, `RetireSubdomainFromKvJob`, `ProvisionBrandDnsJob`) and its tuning is KV-specific. Inlining a job-specific backoff is the right call.
- **Nit:** the plan dispatches the job from "Site observers on content change AND from AccountTypeTransitionService." Today the Site observer (`SiteObserver`) does brand-related KV sync but no Cloudflare cache purge. Worth being explicit that this is a NEW dispatch site, not piggybacking on existing logic.

### §28.8 — Public profile API endpoint

**Severity: SHOULD-FIX**

- Endpoint shape and cache pattern (`CacheLockService::rememberLocked`, 60s TTL, key includes `site.updated_at`) match how we do public reads today (e.g. `PublicSiteController`). Good.
- **Issue:** "60/min/IP throttle via Laravel `throttle`" — be aware that behind Cloudflare, every request's source IP is Cloudflare's edge unless `request->ip()` is configured to read `CF-Connecting-IP` via `TrustProxies`. Worth verifying our `TrustProxies` middleware reads the right header before relying on per-IP throttling.
- **Issue:** "truly public, no auth" is fine for the rendered profile. But: do NOT use this endpoint for the `/account/design` editor preview (Track B §31.3) — that's authenticated work. Plan should clarify the editor uses a separate authenticated endpoint.

### §28.9 + Part 6 — Brand signup code

**Severity: SHOULD-FIX**

- Schema additive change to `brand.brand_profiles` is straightforward and consistent with prior `ALTER TABLE brand.brand_profiles ADD COLUMN ...` migrations (e.g. `20260404000000_add_setup_complete_to_brand_profiles.sql`).
- **Issue:** Model path. Plan says `app/Models/Brand/BrandSignupCodeAuditEntry.php`. The `Models/Brand/` directory does NOT exist today (verified: `ls app/Models/Brand/` returns nothing). All brand-related models live at `app/Models/Core/Professional/` (BrandProfile, BrandPartnerLink, BrandAffiliateInvite, BrandPartnerLinkEvent). Two acceptable paths: (a) place at `app/Models/Core/Professional/BrandSignupCodeAuditEntry.php` (consistent with neighbours), or (b) create a new `app/Models/Brand/` namespace and update CLAUDE.md's code-organisation section. Pick one and call it out — Track A executor will otherwise default to whichever looks easier.
- **Issue:** Migration step 2 in §36 says "iterate `BrandProfile::all()` and save — each save triggers the `creating` hook for new rows OR the explicit code generator for existing ones." The `creating` hook doesn't fire on saving an EXISTING row, so this needs an explicit `BrandSignupCodeService::generate()` call inside the Artisan command. Plan acknowledges this in the code comment but it's easy to misread. Worth being explicit.
- **Nit:** `joined_professional_id` column in `signup_code_audit` is undocumented when it's NULL. Add CHECK or note: NOT NULL when `event = 'claimed'`, else NULL. Defensive.

### §28.10 + §28.11 — Capability gating

**Severity: MUST-FIX scope expansion**

- The notification dispatcher pattern is feasible — there are 10 notification jobs (`app/Jobs/Notifications/`), most are small, and adding `AccountCapabilities::for($pro)->receives_X` checks is straightforward.
- **However**: Stripe gating is significantly more involved than "wrapped in capability guard":
  - `StripeConnectController` has at least 6 places (lines 323, 331, 514, 536, 610, etc.) where `$role === 'brand'` decides between brand vs affiliate Stripe configuration. These aren't outer guards — they're interior branching that builds different `forceFill` payloads, different products lookups, different webhook routing. Replacing `$role` with a capability check is a refactor, not a wrap.
  - `CommissionPolicy` lines 87, 149, 159 hard-code `professional_type === 'brand'` for authorization. These need migrating to `account_type` reads via the dual-write column.
  - `EnsureAffiliateAccount` and `EnsureBrandAccount` middleware do gate-keeping by `professional_type` string. Capability-driven check is a clean swap once `account_type` is live.
- Webhook handlers: `StripeWebhookController`, `ProcessShopifyOrderWebhookJob` — both read `professional_type`. Need to confirm we don't process partner-only events for ex-partners (this is actually the cleanest place capability gating helps — webhook arrives for a now-individual ex-partner, capability check prevents spurious side-effects).
- **Recommendation:** Split §28.11 into a sub-bulleted list of concrete files / methods. Realistic estimate is 15–25 distinct touch points across Stripe / commission / webhook / middleware paths.

### §28.13 — Bootstrap flow update

**Severity: SHOULD-FIX**

- The existing `BootstrapController::bootstrap()` resolves `professional_type` from request input against `config('partna.professional_types')` (which is the 3-value enum). The plan needs to define: when a user signs up as `influencer`, what is their `account_type`? Implicit answer is `'individual'`. State it.
- **Issue:** §13 / §28.13 / §58 say `brand_status = 'Onboarding'` and transitions to `'Live'`. Real enum is `building | preview | live | systems_down` (per migration `20260503000000_expand_brand_status.sql`). Replace `'Onboarding'` with `'building'` and `'Live'` with `'live'` throughout. Capitalisation matters — DB CHECK constraint will reject `'Onboarding'`.
- The Shopify install completion flow today moves brand_status `building → preview → live` (not a single jump from Onboarding to Live). Plan's prose oversimplifies; doesn't change the data flow, but the language is misleading.

### §28.14 — Individual waitlist flag

**Severity: LGTM**

- We have an existing waitlist mechanism (`PublicWaitlistController`, `partna.waitlist` config). Adding `SIDEST_INDIVIDUAL_WAITLIST_ENABLED` as a defensive kill switch is consistent with how feature flags get added today.

### §28.16 — BrandPartnerLink soft-delete

**Severity: LGTM** (verified correct)

- Verified `BrandPartnerLink.php` has only `HasUuids` trait today; no `SoftDeletes`.
- Verified `BrandPartnerLinkService::disconnectBrandFromAffiliate()` at line 99 calls `$target->delete()`. With trait added, this becomes soft-delete automatically. ✓
- **Additional call sites worth flagging** that the §28.16 audit list misses:
  - `BrandPartnerLinkObserver::deleted()` — fires `SyncSubdomainToKvJob` + cache busts + notifications. Eloquent SoftDeletes fires the `deleted` event on soft-delete (NOT on force-delete). So the observer continues to work after the trait lands. Worth stating explicitly.
  - `SiteCacheService` uses `BrandPartnerLink::query()` in 3 places (lines 299, 870, 1062). Each will auto-exclude soft-deleted via the trait's global scope — the correct default for cache invalidation. No change required, but the audit should note the verification.
  - `AffiliateProductCatalogService` and `BrandAffiliateInviteService` similar pattern — default scope is correct (you don't want a soft-deleted partnership selecting catalog items). Note in audit.
  - **`SyncSubdomainToKvJob::handle()` line ~55**: `BrandPartnerLink::query()->where('affiliate_professional_id', $pro->id)->whereNotNull('site_url')->orderBy('slot')->value('site_url')` — after soft-delete, this correctly returns null for ex-partners, which then triggers the new `{type:'individual'}` write path in §28.6. Loop closes cleanly. ✓
- **Recommendation:** Expand §28.16's "Service call site audit" bullet to enumerate the three additional services + the observer above. Each is "no change required, verified," but writing them down is what makes the audit defensible.

---

## 3. Migration safety

### §28.1 account_type backfill

- `core.professionals` is small (pre-beta) — confirmed by general repo context. Plan's "no blue-green path required" is correct for now.
- **Step-ordering must be explicit.** Recommend three migration files (or three statements in one):
  1. `ADD COLUMN account_type text NULL` (no constraint yet)
  2. Backfill (handles `brand`, `professional`, `influencer` — three cases, not two)
  3. `ALTER COLUMN SET NOT NULL` + `ADD CONSTRAINT ... CHECK`
- Dual-write trigger: BEFORE INSERT OR UPDATE on `core.professionals`. Spec must include: precedence when both columns are explicitly set; behaviour for `influencer` (presumably maps to `individual`); whether the trigger fires on the backfill update itself (it will — make sure backfill writes both columns to avoid infinite recursion / unintended sync).
- Rollback path: `DROP COLUMN account_type CASCADE` is fine because no FK depends on it. Trigger drop must be paired.

### §28.16 BrandPartnerLink soft-delete

- Migration is trivial (`ADD COLUMN deleted_at TIMESTAMPTZ NULL` + index). Backfill nothing.
- Index on `(affiliate_professional_id, deleted_at)` — confirm a `WHERE deleted_at IS NOT NULL` partial index would be cheaper (typical SoftDeletes query pattern is `WHERE deleted_at IS NULL`, which a partial-NULL index serves badly). Recommend: `CREATE INDEX ... ON brand.brand_partner_links (affiliate_professional_id) WHERE deleted_at IS NULL` for the hot path + `(affiliate_professional_id, deleted_at)` for ex-partner queries. Both, not just one.
- RLS policies on `brand.brand_partner_links` (per `20260420200000_add_rls_to_remaining_tables.sql`) should be reviewed for whether they need `deleted_at IS NULL` predicates. Otherwise ex-partners could leak their (soft-deleted) link rows back into authenticated reads.
- Audit scope (§28.16 bullet "Service call site audit") needs the additions in §2 of this review above.

### Part 6 brand_signup_code migration

- Three-step pattern (§36) is correct and consistent with our migration practice. ✓
- `gen_random_uuid()` in `signup_code_audit` requires `pgcrypto`. Plan flags this. Verify before running: `SELECT extname FROM pg_extension WHERE extname = 'pgcrypto'`.
- **Concern:** the Artisan backfill runs in PHP and iterates `BrandProfile::all()`. If the application boots without the new code generator wired (because deployment timing is awkward), the backfill silently leaves codes blank. Add a final assertion in the migration's step 3 that fails if any row has NULL `signup_code` — fail-loud beats silent NULL → constraint-violation at deploy time.

---

## 4. Capability gating realism

Estimate of touch sites by inspection:

| Surface | Count | Difficulty |
|---|---|---|
| Notification jobs | 10 in `app/Jobs/Notifications/` | Low — add `AccountCapabilities::for($pro)->receives_X` check at top of `handle()` |
| Stripe controllers | ~10 `$role === 'brand'` branches in `StripeConnectController` | Medium — interior branching, not outer gates |
| Stripe services | `StripeConnectService` 3 sites, `StripeTransactionFetcher` 1 site, `ExportService` 3 sites, `CommissionVoidService` 1 site, `StripeBillingService` 1 site, `CommissionPayoutService` 1 site | Medium |
| Policies | `CommissionPolicy` 3 hard-coded string checks | Low — replace with `account_type` read |
| Webhook handlers | `StripeWebhookController` 1 site, `ProcessShopifyOrderWebhookJob` 1 site | Low — outer gate |
| Middleware | `EnsureAffiliateAccount`, `EnsureBrandAccount`, `BrandFundingGate`, `LoadCurrentProfessional` | Low — clean swap to capability check |
| Resources | `ProfessionalDashboardResource`, `ProfessionalResource`, `ProfessionalPublicResource`, `ProfessionalStaffResource` | Low — conditional field include |
| Controllers | Many (audit count: ~15 across `Api/Professional/`, `Api/Staff/`) | Mixed |

**Realistic feasibility verdict:** all are retrofittable; none require rewrite. But the surface area is ~50 distinct call sites, not the handful §28.11 implies. Plan should reflect this when allocating Track A effort.

---

## 5. Service / job pattern fit

- `AccountTypeTransitionService` — fits Service pattern. Use `lockForUpdate()` inside a `DB::transaction`. ✓
- `CloudflarePurgeService` — slots beside `CloudflareKvService` / `CloudflareDnsService`. ✓
- `BrandSignupCodeService` — fits `app/Services/Brand/` (a new folder, but consistent with how `app/Services/Stripe/`, `app/Services/Cloudflare/` are organised by vendor/domain). Alternatively `app/Services/Professional/Brand/` next to the existing `BrandPartnerLinkService` etc — probably the more consistent location given the existing siblings. Pick one.
- The CLAUDE.md note that "there is no separate `Actions/` namespace — single-shot operations live alongside other services" is being respected by all proposals. Good.

No Action/Service split needed.

---

## 6. Track A scope realism

§44 Track A list is 17 line items. Mapped to verified code reality:

**Realistic:**
- All migrations (3 new + backfill + soft-delete + signup code + audit table)
- AccountType enum, AccountCapabilities registry
- AccountTypeTransitionService + event + listeners
- SyncSubdomainToKvJob individual branch (5-line change)
- CloudflarePurgeService + Job (new but small)
- Public profile API endpoint (~150 lines counting Resource)
- Brand signup code (4 endpoints + service + audit model + dashboard aggregates + rate limiting)
- BootstrapController updates
- Waitlist flag

**Understated:**
- §28.11 feature gating across existing code — see §4 of this review. Realistically the largest single line item.
- §28.16 soft-delete call-site audit — the verified scope is wider than the plan states (see §28.16 review).
- Reconciling with existing `AccountTypeDefaultsService` (not mentioned in plan).
- Tests: §9 calls for ~60 capability tests; plus transition tests, plus soft-delete tests, plus signup-code tests, plus architecture tests. Realistically 100–150 test cases.

**Missing:**
- Update `config/partna.professional_types` (or document why it stays). Currently the source of truth for "what types can sign up" is config.
- Update `AccountTypeDefaultsService::resolveDefaults()` to handle the new account types, OR document that it continues to key off legacy `professional_type` indefinitely.
- `CommissionPolicy` migration to `account_type` (plan implies this happens via §28.11 but doesn't name it).
- Frontend coordination on `account_type` being included in `ProfessionalDashboardResource` (Track B consumes this — risk of cross-track ordering issue).

**Effort estimate:** the plan's gate-by-gate structure suggests Track A could finish in 2 weeks of parallel work. Honest estimate is 3–5 weeks of focused solo backend work, primarily because §28.11 capability gating is the largest item and the test scaffolding is non-trivial. Not a blocker — just calibrate expectations.

---

## 7. Conflicts with current work

- **MFA Foundation (planned 2026-05-18, `docs/superpowers/plans/2026-05-18-mfa-foundation.md`)** — touches `Professional` model casts and middleware. Adding `account_type` cast in §28.2 is independent but both PRs editing the same model file. Sequence carefully or expect a merge.
- **Async commission export (planned 2026-05-19)** — touches commission / payout export. §28.11 capability gating intersects with this (export endpoints are partner-only). Make sure both plans agree on which controller checks capability.
- **Handle redirect lifecycle (merged 2026-05-18)** — uses `SyncSubdomainToKvJob` + `site.professional_handle_aliases`. §28.6 changes the `else` branch of `SyncSubdomainToKvJob`; the alias-writing code path (`writeAliasEntries`) is unchanged. No conflict but worth a visual diff before commit.
- **Audit Phase 2 / open audits** in `audits/` — several flag policy coverage and feature-flag hygiene. §28.11 capability gating is the largest fix for both. Possibly closes a few of those audit items as a side-effect; worth checking before duplicating fixes.
- **No conflict with notification-preferences work** — capability filtering is additive to whatever's already there.

---

## 8. Questions for the planning author

1. **`professional_type = 'influencer'` accounts** — what `account_type` do they receive in the backfill? My read: `'individual'`. Confirm and update §8 to enumerate all three legacy values.
2. **`AccountTypeDefaultsService` reconciliation** — keep as-is (config-driven), wrap with AccountCapabilities, or merge into AccountCapabilities? §28.3 doesn't mention this service exists.
3. **`brand_status` vocabulary** — confirm plan should read `building | preview | live | systems_down` (lowercase) everywhere, replacing `'Onboarding'` / `'Live'`. Affects §13, §28.13, §58.
4. **Rule #9 enforcement** — the architecture test as specified (grep for `SUBDOMAIN_KV->put`) finds nothing in PHP because we route through `CloudflareKvService::put`. Should the test grep for `CloudflareKvService::put` usage outside `app/Jobs/Cloudflare/`? And do we exempt `RetireSubdomainFromKvJob` from rule #9 (it's a deleter, not a writer of routing entries, but it does mutate the namespace).
5. **Per-request rate limit IP source** — does `request->ip()` return the correct visitor IP behind Cloudflare? §28.8 "60/min/IP" depends on this; same for §33 signup-code rate limiting.
6. **`BrandSignupCodeAuditEntry` model location** — `app/Models/Brand/` (new dir) or `app/Models/Core/Professional/` (consistent with siblings)? Same question for `BrandSignupCodeService` (`app/Services/Brand/` or `app/Services/Professional/Brand/`).
7. **§28.13 dual-write order** — when the dual-write trigger is active, do we still write both columns explicitly from `BootstrapController`? Plan says yes ("Writes BOTH `account_type` AND `professional_type` for the dual-write period"). Confirm that's the trigger-redundant safety belt, not a contradiction.
8. **Soft-delete + RLS** — should the RLS policies on `brand.brand_partner_links` add `deleted_at IS NULL` predicates, so soft-deleted rows don't surface to non-staff queries unintentionally? Or is the model-level global scope sufficient?
9. **AccountType enum location** — `app/Enums/AccountType.php` per plan. The only existing enum is `BrandStatus`. Are we standardising on `app/Enums/` for new domain enums going forward? Worth adding to CLAUDE.md.

---

## 9. What looks right

A balanced view — these are genuinely well-considered:

- **Soft-delete migration reasoning (§28.16)** is correct. Adding `SoftDeletes` trait genuinely is the minimal intervention; Eloquent intercepts `delete()` calls cleanly and the `deleted` model event still fires (which keeps `BrandPartnerLinkObserver` working for cache busts and KV sync — verified).
- **Service Binding choice over `fetch(URL)`** dodges a real Host-header restriction; the contingency to use `X-Partna-Handle` if Host preservation fails is correctly scoped to a small fallback.
- **Explicit `caches.default.put()`** is the right pattern; the audit finding that Cache-Control alone doesn't cache is correct.
- **Brand signup code design** — PHP-side `creating` hook avoids the migration race the plan calls out. `BrandSignupCodeService::generate()` as the single source of truth for code generation is the right shape.
- **No-promotion-path simplification (Part 15 §59a)** — eliminating brand-promotion and forcing close+re-signup is a real complexity reduction. From the backend lens, it lets `AccountTypeTransitionService` be tiny (two arrow cases: `partner→individual`, `individual→partner`) instead of a state machine.
- **Capability-check defence-in-depth at dispatch layer (rule #10)** is correct architecturally — not just UI gating. Catches webhook-arrives-after-transition cases cleanly.
- **`CloudflarePurgeService` keeping its own backoff** rather than reusing `HasCloudflareRetryPolicy` is sensible: that trait is KV-tuned.
- **CacheLockService::rememberLocked for the public profile endpoint** is consistent with how every other public read in this codebase works. Good consistency.
- **Audit-resolution log (Part 15)** is unusually rigorous. The fact that v2 audit findings are tracked individually and resolved (not just "addressed") makes the plan auditable and trustworthy.

---

## 10. Bottom line

Approve in principle. Tighten:
- §8 to handle the 3-value legacy enum;
- §28.3 to reconcile with `AccountTypeDefaultsService`;
- §28.11 to be honest about Stripe / Commission surface area;
- §28.16 to enumerate the wider call-site audit;
- §13 / §28.13 / §58 to use the real `brand_status` vocabulary (`building`/`live`);
- Rule #9 enforcement to grep something that actually exists in our code;
- Migration step-ordering for `account_type` to mirror the brand_signup_code three-step pattern;
- Resolve the model/service location questions (§9 of this review).

After those tightenings, Track A is shippable. Effort is realistically 3–5 weeks solo, not the parallel-week reading you might take from §47.
