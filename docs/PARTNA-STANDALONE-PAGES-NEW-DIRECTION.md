# Partna — Individual Sitepages Architecture Plan

> **Status: planning artifact for execution (v4 / third adversarial pass).** This document supersedes all prior drafts. It bakes in audit findings from three independent reviewer passes — v1 May 2026, v2 May 2026, and the consolidated 14-lens audit May 2026 (41 findings: `PARTNA-STANDALONE-PAGES-AUDIT-CONSOLIDATED.md`). It also absorbs the backend-developer review (`BACKEND-REVIEW-OF-STANDALONE-PAGES-PLAN.md`). Part 15 lists every audit finding by ID and how it was resolved (or why it was deferred). Part 16 is the consolidated v4 resolution table covering all 41 findings.
>
> **Audience:** the user (frontend / themes / Astro / Cloudflare work), the backend developer (Comet-Backend work), and any Claude session executing on either side. This is the single source of truth — when it disagrees with anything in CLAUDE.md files or chat history, this document wins.

---

# PART 1 — Concept and architecture

## 1. What we're building

Partna supports professionals at three commercial stages. Today the platform serves the first two — brands and brand-affiliated partners — via a Hydrogen storefront on Shopify Oxygen. We are adding the third stage: **individuals**, professionals with a public profile sitepage at `<handle>.partna.au` but no commerce, no brand affiliation, and no Shopify dependency.

From the visitor's perspective, all three look like Partna profile pages with the same themes. From the inside, brand and partner traffic still flows to Hydrogen on Oxygen; individual traffic flows to a new Astro app on Cloudflare Workers Static Assets. Both apps render from one shared `@partna/themes` package.

## 2. The three account types

```
account_type ∈ { 'brand', 'partner', 'individual' }
```

| Type | Description | Hosted by | Has Shop section |
|------|-------------|-----------|------------------|
| `brand` | Shopify-connected commerce operator. Owns a brand, runs storefront, has affiliates | Hydrogen on Shopify Oxygen | Yes |
| `partner` | Professional affiliated with a brand. Sells on the brand's storefront. Earns commission | Hydrogen on Shopify Oxygen (via 301 from `<handle>.partna.au` to `<brand>.partna.au/<handle>`) | Yes |
| `individual` | Professional with a public profile sitepage. No commerce, no brand affiliation | Astro app on Cloudflare Workers Static Assets | No |

**Brand is terminal.** Once a Professional becomes a brand, they cannot transition back. The only exit from brand is full account closure (out of scope for Phase 1).

**Partner ↔ individual transitions are seamless.** A partner who leaves a brand becomes an individual with a permanent "ex-partner" panel in their dashboard preserving historical commission/payout/order data. An individual who accepts a brand invite or enters a valid brand signup code becomes a partner instantly. Their handle, site content, and theme persist across the transition.

## 3. The architecture in one diagram

```
                          visitor
                             │
                             ▼
                 *.partna.au DNS (Cloudflare)
                             │
                             ▼
            Cloudflare Worker (partna-subdomain-router)
                             │
       ┌─────────────────────┼─────────────────────┐
       ▼                     ▼                     ▼
  type:"brand"        type:"affiliate"      type:"individual"
       │                     │                     │
       ▼                     ▼                     ▼
 fetch through to     301 redirect to        caches.default.match
 Hydrogen on          <brand>.partna.au/     → hit returns cached HTML
 Shopify Oxygen       <handle> → Hydrogen    → miss invokes Service Binding
       │                     │                 to partna-pages Worker
       │                     │                 (Astro + Static Assets)
       │                     │                 then caches.default.put
       └──────┬──────────────┴─────────────────────┘
              ▼
    Comet-Backend (Laravel + Supabase)
    - Professional.account_type, .handle
    - Site.settings.design per professional
    - BrandPartnerLink (soft-delete enabled — preserves history)
              │
              ▼
    @partna/themes (GitHub Packages)
    - per-theme bundles (theme-1..5)
    - shared engines/brand/analytics/icons/motion
    - consumed by Hydrogen AND Astro
```

The Worker is the single brain. It reads a Workers KV value keyed by handle, consults the Cache API for cached HTML, and routes accordingly. Three branches; one of them is a Service Binding call into a second Worker (the Astro app); the others stay as today.

## 4. The sections that render

Hydrogen's `theme-1` (the only built theme today) has these section folders, all consumed by both apps where applicable:

| Section | Brand / Partner | Individual | Notes |
|---------|-----------------|-----------|-------|
| Bio | ✅ | ✅ | Pure presentational, no framework coupling |
| Booking | ✅ | ✅ | Non-Shopify; booking logic is independent |
| Document | ✅ | ✅ | PDF section; uses `react-pdf` |
| Newsletter | ✅ | ✅ | Currently only built in theme-1. `useNewsletterSubmit` requires refactor (see §30) |
| Services | ✅ | ✅ | Pure presentational |
| Shop | ✅ | ❌ | Lives in Hydrogen only; brings Shopify deps with it |

Gallery is a **component** (`theme-1/components/gallery/`), not a section. Footer is inline social links in `layout.tsx`. Themes 2–5 are empty placeholders in Hydrogen today (only `.gitkeep` files). They will be built in `@partna/themes` directly when designed and the Hydrogen scaffolds will be deleted (see §48).

## 5. The URL collision strategy — single namespace

Brand slugs and individual handles share **one column on one table** today: `Professional.handle`. Every professional — brand or otherwise — has exactly one handle. Uniqueness is enforced at the table level. So `jane` can be claimed exactly once, by exactly one type of account.

`SyncSubdomainToKvJob` writes the `SUBDOMAIN_KV` entry keyed by the lowercased handle. The Worker's KV lookup is the runtime source of truth for routing.

## 6. The Worker's three branches in detail

The Worker (`Comet-Backend/cloudflare-worker/`) routes `*.partna.au/*` requests. Reserved subdomains (`api`, `app`, `admin`, etc., per `RESERVED` set mirroring `config/partna.php`) short-circuit before KV lookup and pass through to default origin. For everything else:

**Branch A: `{type:"brand"}`** — pass through to default origin (Hydrogen on Oxygen). Unchanged from today.

**Branch B: `{type:"affiliate", redirect:"https://<brand>.partna.au/<handle>"}`** — 301 redirect. Unchanged from today.

**Branch C: `{type:"individual"}`** — check the Cache API; on cache miss invoke the Astro Worker via Service Binding, then populate the cache. New.

Service Bindings are Cloudflare's Worker-to-Worker call mechanism. They pass the original `Request` object (the receiving Worker observes the same headers, method, body, URL). The Astro Worker's middleware reads the host (production) or a dev fallback header (local development), extracts the subdomain, and serves the corresponding individual's profile.

```js
// Cloudflare Worker pseudocode — Branch C
if (entry.type === "individual") {
  const cache = caches.default;
  const cached = await cache.match(request);
  if (cached) return cached;

  // PARTNA_PAGES is a service binding declared in wrangler.toml.
  // The Request is forwarded to the Astro Worker.
  const response = await env.PARTNA_PAGES.fetch(request);

  if (response.ok && request.method === "GET") {
    // ctx.waitUntil keeps the cache write alive after the response is sent.
    ctx.waitUntil(cache.put(request, response.clone()));
  }
  return response;
}
```

This handoff is critical because:

1. **Cache lives in the router, not the Astro Worker.** Cloudflare Workers do NOT auto-cache responses based on `Cache-Control` headers alone — explicit `caches.default.put()` is required. The router is the natural home for this because it already owns the public route and the cache purge job targets these URLs.
2. **Cloudflare Workers cannot set arbitrary `Host` headers when calling external URLs via `fetch()`**; the platform silently overrides them. Service Bindings sidestep this — the receiving Worker observes the inbound Request directly. (Cloudflare docs do not explicitly state Host preservation on Service Bindings; this is verified by smoke test at Gate G3 before architecture lock — see §54.)

## 7. Data flow per request

```
visitor hits handle.partna.au
  ↓
Cloudflare Worker resolves "individual" type via KV
  ↓
Worker checks caches.default; HIT → return cached HTML; MISS → continue
  ↓
Worker forwards Request to partna-pages Worker via Service Binding
  ↓
Astro middleware extracts handle from Host header (prod) or X-Dev-Handle (dev)
  ↓
Astro fetches profile from Comet-Backend:
    GET /api/public/profiles/{handle}
  ↓
backend returns the existing PublicSitePayload shape
    minus brand-specific fields (placeholders, fallback_gallery)
    plus the individual's own site.settings.design
  ↓
Astro renders chosen theme; sections gated by publication state;
response carries Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=600
  ↓
Worker writes the response to caches.default (via ctx.waitUntil)
  ↓
HTML returned to visitor
```

The data model is already individual-capable. The existing `Site` model (`app/Models/Core/Site/Site.php`) is one-per-professional with no brand FK. A Professional without a `BrandPartnerLink` is structurally an individual; today they get a 404 because `SyncSubdomainToKvJob` deletes their KV entry. Changing that delete branch to write `{type:'individual'}` is the only routing-job change.

---

# PART 2 — Account model, capabilities, and transitions

## 8. The `account_type` column — and reconciliation with existing `professional_type`

Today the codebase has `Professional.professional_type` with **three** live values: `'brand'`, `'professional'`, `'influencer'` (per `config/partna.php → professional_types`). This is too coarse for three-state routing AND uses different domain language than what we want long-term. The plan adds `account_type` as the new canonical column; `professional_type` becomes a dual-written legacy column that's eventually dropped.

**Migration sequence — safe-pattern split, mirroring the established precedent in `20260515000001_validate_preferred_payout_method_check.sql`:**

Four discrete migration files (see §28.1 for filenames). The split is dictated by PostgreSQL transaction semantics: `CREATE INDEX CONCURRENTLY` cannot run inside a transaction block, so the covering index lives in its own dedicated file. Every other operation runs in `BEGIN; … COMMIT;` per project convention.

1. **`<ts1>` — additive column + same-file backfill (idempotent).** `ALTER TABLE core.professionals ADD COLUMN account_type text NULL` followed in the same migration by the backfill UPDATEs. Combining add + backfill closes the partial-application window where the column exists with all NULLs (audit MIG-4). Backfill enumerates ALL three legacy values explicitly:
   - `professional_type = 'brand'` AND `account_type IS NULL` → `account_type = 'brand'`
   - `professional_type IN ('professional', 'influencer')` AND has any **non-soft-deleted** `BrandPartnerLink` AND `account_type IS NULL` → `account_type = 'partner'`
   - `professional_type IN ('professional', 'influencer')` AND has no `BrandPartnerLink` AND `account_type IS NULL` → `account_type = 'individual'`
   - Every UPDATE branch is guarded with `WHERE account_type IS NULL` so re-running the migration is idempotent (audit MIG-7); without it, retries would clobber rows the dual-write trigger has legitimately mutated since the initial backfill.
   - The backfill writes BOTH columns in the same UPDATE so the dual-write trigger (step 3) doesn't fire recursively.
   - Header carries `-- To revert: ALTER TABLE core.professionals DROP COLUMN account_type;` (audit MIG-6).

2. **`<ts2>` — non-validated CHECK + NOT-NULL via CHECK (safe lock profile).**
   - `ALTER TABLE core.professionals ADD CONSTRAINT professionals_account_type_check CHECK (account_type IN ('brand','partner','individual')) NOT VALID;` (audit MIG-2 — `NOT VALID` skips the immediate ACCESS EXCLUSIVE scan).
   - `ALTER TABLE core.professionals ADD CONSTRAINT professionals_account_type_not_null CHECK (account_type IS NOT NULL) NOT VALID;` (audit MIG-3 — same pattern; replaces a direct `SET NOT NULL` which would hold ACCESS EXCLUSIVE for a row scan).
   - Adds the dual-write trigger (step 3 of the conceptual sequence) — see below. Trigger creation happens AFTER backfill (which already ran in `<ts1>`) so the trigger does not fire during backfill.
   - Header carries `-- To revert: ALTER TABLE core.professionals DROP CONSTRAINT professionals_account_type_check, DROP CONSTRAINT professionals_account_type_not_null; DROP TRIGGER … ;`

3. **`<ts3>` — validate both constraints and promote NOT-NULL CHECK to column SET NOT NULL.**
   - `ALTER TABLE core.professionals VALIDATE CONSTRAINT professionals_account_type_check;`
   - `ALTER TABLE core.professionals VALIDATE CONSTRAINT professionals_account_type_not_null;`
   - Defensive guard before promoting to a column-level NOT NULL:
     ```sql
     DO $$ BEGIN
       IF EXISTS (SELECT 1 FROM core.professionals WHERE account_type IS NULL) THEN
         RAISE EXCEPTION 'account_type backfill incomplete: % rows still NULL',
           (SELECT count(*) FROM core.professionals WHERE account_type IS NULL);
       END IF;
     END $$;
     ```
   - `ALTER TABLE core.professionals ALTER COLUMN account_type SET NOT NULL;` — at this point Postgres skips the table scan because the validated CHECK already guarantees no NULLs.
   - Drop the now-redundant `professionals_account_type_not_null` CHECK (`SET NOT NULL` subsumes it).

4. **`<ts4>` — covering index CONCURRENTLY (dedicated file, NO transaction wrapper).**
   - `CREATE INDEX CONCURRENTLY professionals_account_type_idx ON core.professionals (account_type);`
   - This file is the ONLY one in the sequence that does NOT wrap in `BEGIN; … COMMIT;` because `CREATE INDEX CONCURRENTLY` is incompatible with a transaction block (audit MIG-1). The plan's project convention of wrapping every migration in an explicit transaction has a documented exemption for CONCURRENTLY operations — file header explicitly notes this.
   - Header: `-- NOTE: this migration intentionally runs WITHOUT a BEGIN/COMMIT wrapper because CREATE INDEX CONCURRENTLY cannot run inside a transaction block. Do not add transaction wrapping here.`

**Dual-write trigger (created in `<ts2>`):** BEFORE INSERT OR UPDATE on `core.professionals`. Keeps `professional_type` and `account_type` in sync during the migration window. **Precedence rule (load-bearing):** when both columns are explicitly set in the same statement to inconsistent values, **the new `account_type` column wins** and `professional_type` is overwritten to match. Mapping: `account_type='brand'` → `professional_type='brand'`; `account_type='partner' OR 'individual'` → `professional_type='professional'` (NOT `'influencer'` — see below). The trigger does NOT fire recursively because the backfill step already writes both columns explicitly to consistent values.

5. **Step 5 (codebase reads migrate incrementally)** from `professional_type` to `account_type`. The dual-write keeps both columns valid; readers can switch one at a time.
6. **Step 6 (cleanup, future):** once no callers reference `professional_type`, drop the column (tracked as future cleanup; out of Phase 1).

**The `influencer` legacy value:** the dual-write trigger collapses `influencer` to `professional` when writing back from `account_type`. This is intentional and one-way — once an influencer's `account_type` is set to `partner` or `individual` and the trigger fires, their `professional_type` becomes `'professional'`. The original `'influencer'` distinction is preserved only for the duration of the migration window (until the column is dropped). If `'influencer'` carried meaningful business semantics that need to survive the migration, a separate persistence is required — verify before this migration runs. **Open: confirm with backend dev that `'influencer'` is purely a legacy label with no live business logic depending on it.**

**Why dual-write, not in-place rename:** in-place enum expansion in PostgreSQL is messy; renaming is high-risk for a widely-referenced column (49 files touch `professional_type` today). Additive migration is reversible at any point.

**The backfill IS a deliberate exception to §50 rule #5** ("account_type set explicitly at signup, never derived from absence of a relationship"). The rule applies to runtime mutation paths; the one-time backfill derives `account_type` from observed BrandPartnerLink presence by design.

**Production migration timing:** the table `core.professionals` is small at our alpha stage (well under 10K rows); backfill SQL executes in milliseconds and CHECK constraint validation is fast. No blue-green path required. If row count grows substantially before Phase 1 ships, batch the backfill via `UPDATE ... WHERE id IN (SELECT id FROM core.professionals LIMIT 1000 OFFSET ?)` to avoid long-held write locks. Rollback paths are documented in each migration's `-- To revert:` header.

## 9. The capability registry — extending what already exists

The frontend already has `Partna-Frontend/lib/account-capabilities.ts` doing capability-based routing for 2 states (brand vs. non-brand). The plan extends it to 3 states and mirrors it on the backend.

**Backend: `App\Services\Accounts\AccountCapabilities`.**

```php
class AccountCapabilities {
    public static function for(Professional $pro): AccountCapabilitySet {
        return match ($pro->account_type) {
            'brand'      => self::brandCapabilities(),
            'partner'    => self::partnerCapabilities(),
            'individual' => self::individualCapabilities($pro),
        };
    }
}
```

`AccountCapabilitySet` is a value object with boolean flags and configuration. The full capability list:

| Capability | brand | partner | individual |
|------------|-------|---------|-----------|
| `requires_stripe_connect` | ✅ | ✅ | ❌ |
| `requires_tax_info` | ✅ | ✅ | ❌ |
| `requires_payout_schedule` | ✅ | ✅ | ❌ |
| `shows_shop_section` | ✅ | ✅ | ❌ |
| `shows_commissions_dashboard` | ✅ | ✅ | ❌ |
| `shows_orders_dashboard` | ✅ | ✅ | ❌ |
| `shows_affiliates_dashboard` | ✅ | ❌ | ❌ |
| `shows_ex_partner_panel` | ❌ | ❌ | ✅ if hasHistoricalPartnerLinks |
| `receives_order_notifications` | ✅ | ✅ | ❌ |
| `receives_payout_notifications` | ✅ | ✅ | ❌ |
| `receives_payout_settlement_notifications` | ✅ | ✅ | ✅ if has pending payouts in history |
| `receives_commission_notifications` | ✅ | ✅ | ❌ |
| `receives_brand_status_notifications` | ❌ | ✅ | ❌ |
| `receives_invite_notifications` | ❌ | ✅ | ✅ |
| `can_have_brand_link` | ❌ | ✅ | ✅ |
| `can_edit_design` | ✅ | ❌ (inherits brand) | ✅ |

Plus two **configuration values** (not booleans):

| Configuration | brand | partner | individual |
|---------------|-------|---------|-----------|
| `notification_categories` | full list | full list | filtered: profile, platform, invites, payout_settlement (if applicable) |
| `worker_kv_type` | `"brand"` | `"affiliate"` | `"individual"` |

**Count: 16 boolean capabilities + 2 configuration values = 18 matrix rows. Tests: ~60 baseline cases (48 boolean × type + 6 config × type + 6 conditional ex-partner/payout combinations).**

**Call sites that MUST consult capabilities:** onboarding gate middleware, notification dispatchers, dashboard nav / route guards, public API endpoints, webhook handlers, scheduled payout/commission tasks. Registry-based, not inline ifs.

**Performance — eager-loading and denormalization (audit SCALE-1).** `shows_ex_partner_panel` derivation involves two `exists()` calls on `brandPartnerLinks` / `brandPartnerLinksAll`. Naively iterating professionals — list endpoints, notification fan-outs — produces 2N queries. The fix has three layers:

1. **Memoization at the value-object layer.** `AccountCapabilitySet` caches the ex-partner boolean on the instance so repeated reads on the same `AccountCapabilities::for($pro)` call don't re-query.
2. **Required eager-load pattern.** Controllers and jobs that iterate professionals MUST `->with(['brandPartnerLinks', 'brandPartnerLinksAll'])` before calling `AccountCapabilities::for()` per row. Architecture test `tests/Architecture/CapabilityEagerLoadTest.php` greps for `AccountCapabilities::for(` calls inside `foreach` / `->each(` / `->map(` over a Professional collection and asserts the upstream query eager-loads both relations.
3. **Denormalized boolean column for high-traffic paths.** `core.professionals.has_historical_partner_links boolean NOT NULL DEFAULT false`, maintained by `BrandPartnerLinkObserver::created`/`deleted`/`forceDeleted`. Read paths that only need the boolean (dashboard list, notification capability checks) read the column directly without joining `brand_partner_links`. The exact derivation (`brandPartnerLinksAll()->exists() && !brandPartnerLinks()->exists()`) stays in `AccountCapabilities` for correctness; the column is a denormalization shortcut.

The denormalization is added in the same Track A item as `BrandPartnerLink` soft-delete (§28.16) so the column and observer wiring ship together.

## 10. Account type transitions matrix

| From | To | Trigger | Effect |
|------|----|---------|--------|
| `individual` | `partner` | Brand invites them + acceptance (existing flow), OR they enter a brand signup code (see Part 6 / §32) | Account type flips; BrandPartnerLink created; `SyncSubdomainToKvJob` writes `{type:'affiliate', redirect:...}`; capabilities flip; cache purges; Shop section becomes available; Stripe onboarding prompt appears in dashboard; partner notifications enabled |
| `partner` | `individual` | User leaves brand OR brand removes them | Account type flips; BrandPartnerLink **soft-deleted** (preserved for ex-partner panel — see §11; soft-delete migration is §28.16); KV writes `{type:'individual'}`; cache purges; Shop section disappears; Stripe Connect status remains `'active'` (no auto-disconnect — settlements still flow); partner-only notifications stop; ex-partner panel activates; `has_historical_partner_links` flips to true |

**That is the entire matrix.** Only `individual ↔ partner` movements exist. All other transitions are forbidden — see §12.

**Brand status is set at signup and never changes.** A Professional is created as a brand at the signup endpoint or they never become one. There is no path to brand from `individual` or `partner` via the dashboard, via brand-invite acceptance, via signup code, or via any other route. The only way to "become a brand" is to sign up as one in the first place.

**What stays the same across every transition:**
- Their handle
- Their Site record (theme, blocks, settings, media)
- Their published content
- Their analytics history
- Their professional ID
- Their authenticated session

**What changes mechanically (single source of truth — no contradictions):**
- `professionals.account_type` column updates (dual-written to `professional_type` per §8)
- `BrandPartnerLink` row created or soft-deleted
- `professionals.has_historical_partner_links` updated by the observer if applicable
- `SyncSubdomainToKvJob` re-runs (writes the correct KV entry for the new type)
- `CloudflarePurgeService::purgeHandle()` fires
- `AccountTypeTransitionEvent` dispatched
- Listeners handle side-effects: notification subscriptions, Stripe activation toggle, tax info status

**Writers of `account_type` — the precise rule:**
- `BootstrapController` writes the initial value at signup (§28.13). Backfill SQL (§8) writes during one-time migration.
- After signup, **all post-creation mutations of `account_type` go through `AccountTypeTransitionService` only** (§50 rule #5).

## 11. Ex-partner state — preserving brand-partnership history forever

When a partner becomes an individual, they don't lose access to anything from their brand-partner era. The transition is instant; pending payouts and historical brand data persist indefinitely in a permanent "Previous brand partnership" panel in their dashboard.

**Mechanics:**
- `account_type = 'individual'` is the single source of truth — they ARE individual now.
- The "ex-partner" capability is *derived*, not stored separately: a Professional is "ex-partner" if any `BrandPartnerLink` records exist for them (active or soft-deleted). The denormalized boolean `has_historical_partner_links` (§9) is a read-only shortcut maintained by the observer; the canonical derivation runs through the relation queries.
- `BrandPartnerLink` rows use Laravel's `SoftDeletes` trait (migration in §28.16). Soft-deleted rows are retained forever; no purge job runs against them. **This is non-negotiable — without soft-delete, the ex-partner mechanism does not work.**
- Historical commerce data (`commerce.orders`, `commerce.commission_movements`, `commerce.commission_payouts`) is keyed on `affiliate_professional_id` — stays accessible regardless of current account type or BrandPartnerLink state.
- The ex-partner panel renders when `AccountCapabilities::for($pro)->shows_ex_partner_panel === true`.
- The `BrandPartnerLinkAuditor` service (already exists at `app/Services/Professional/Brand/BrandPartnerLinkAuditor.php`) records creation and removal events. These persist independently of soft-delete state and serve as a secondary audit trail.

**Pending payouts after transition:** transition is **NEVER blocked** by pending payouts. This is the explicit rule and applies everywhere in the plan and tests; any text suggesting otherwise is a bug.

- Pending payouts continue to settle to the ex-partner's existing Stripe Connect account on the existing schedule.
- The ex-partner panel surfaces "pending payout of $X expected on date Y."
- When payout settles, the settlement notification fires (gated by `receives_payout_settlement_notifications`, true while any pending payout exists in the history).
- `stripe_connect_status` stays at its current value (typically `'active'`). The enum values today are `not_connected | onboarding | active | restricted | disconnected` — there is no `'inactive'`. The professional simply has no live BrandPartnerLink to earn new commissions through.

**Re-joining a brand later:** historical data is still there in the same panel. Tax info if already collected doesn't need re-collection. Stripe Connect already `'active'`. A new BrandPartnerLink row is created (not a restore of the soft-deleted one) — this preserves the audit trail of each partnership episode. A Professional can have many partnership episodes over their lifetime.

## 12. Forbidden transitions and edge cases

- **All transitions TO `brand` are rejected at the service layer.** `AccountTypeTransitionService::transition()` throws `InvalidAccountTypeTransition` if `$to === AccountType::Brand`, regardless of the from-type.
- **All transitions FROM `brand` are rejected at the service layer.** Same exception class. Brand is terminal.
- **The only allowed transitions are `individual ↔ partner`** (both directions).
- **Concurrent transitions are serialized.** A row-level lock (`$pro->lockForUpdate()`) on `Professional` inside the transition's `DB::transaction` ensures only one transition succeeds; the second attempt fails clean with a clear error.
- **Mid-flight orders during partner→individual transition:** orders are brand records; they settle normally. The ex-partner sees commission accrue in their ex-partner panel. No special pre-removal logic needed; the data model handles this. **Verification:** explicit test in §58.
- **Partner of a brand in `systems_down` state:** the partner stays a partner. KV still 301-redirects to the brand storefront (which shows the brand's degraded UI). The partner's dashboard shows a banner alerting them. No auto-transitions; brand `systems_down` is the brand's problem to fix.
- **Renames during transitions:** handle changes go through their own flow; transitions don't trigger renames. Two separate concerns.

## 13. Brand signup — created as brand, never promoted

Because no transition path leads INTO `brand`, the only way a Professional becomes a brand is by signing up as one. `BootstrapController` is the sole writer of `account_type = 'brand'`.

**Brand signup flow:**

1. User picks "Brand" on the AuthTypeGrid in the signup form.
2. `BootstrapController` creates the Professional row with `account_type = 'brand'` AND `professional_type = 'brand'` (dual-write per §8) in a single transaction.
3. `BrandProfile` is created with `setup_complete = false`, `brand_status = 'building'` (the initial value of the live `brand_status` enum: `building | preview | live | systems_down` per `20260503000000_expand_brand_status.sql`).
4. `Site` is provisioned (one per professional, always).
5. Free `Subscription` is seeded.
6. `SyncSubdomainToKvJob` runs, writing `{type:'brand'}` to KV. The Worker routes `<handle>.partna.au` to Hydrogen on Oxygen from this point on.
7. The user proceeds through the Shopify install wizard (existing flow per `Partna-Shopify-App`). Until the storefront is reachable, Hydrogen renders the existing brand-onboarding placeholder for that brand. `brand_status` walks through `building → preview → live` over the course of install (multi-step, not a single jump).
8. Once Shopify install completes and validation passes, `brand_status = 'live'` and the storefront is fully functional. **No `account_type` change happens here — it was `'brand'` from row creation.**

**No promotion path exists.** A user who signs up as `individual` and later decides they want to run a brand must close their existing Professional account and sign up afresh as a brand. The plan does not provide a self-serve migration path. This is by design — the user explicitly chose this constraint to keep the account model simple and the data lifecycle clean.

**No `partner → brand` path exists either.** A partner who wants to spin off their own brand must leave their current brand (transition `partner → individual`), then close that account and sign up afresh as a brand. The ex-partner panel still preserves their commission/payout history from their partner era on the original (now-closed) account.

**Failure handling during brand signup:** if Shopify install fails or stalls, the Professional row still exists with `account_type = 'brand'`, `brand_status = 'building'` (or `'preview'`). The user can resume install from the dashboard. The brand never "downgrades" to individual or partner.

**Operational state during pre-`live`:** while `brand_status ∈ {building, preview}`, the brand's `<handle>.partna.au` still routes through the Worker to Hydrogen (because `account_type='brand'`). Hydrogen detects the non-live status and renders the existing onboarding placeholder.

---

# PART 3 — Hosting, routing, and infrastructure

## 15. Why Cloudflare Workers Static Assets, not Cloudflare Pages

The original plan targeted Cloudflare Pages. The independent audit (May 2026) surfaced that **the `@astrojs/cloudflare` adapter dropped Cloudflare Pages support in adapter v13**, aligned with the Astro 6 framework release. Sources:
- Adapter docs: *"The Astro Cloudflare adapter no longer supports deployment on Cloudflare Pages. For the best experience and feature support, you should migrate to Cloudflare Workers."*
- Current adapter version (May 2026): `@astrojs/cloudflare@13.5.2`, targeting Astro framework 6.x (current stable: 6.3.1, released May 2026).
- PR #15480 (merged Feb 2026 to the Astro `6-beta` branch) removed Pages support.

> **Note on version conventions:** "Astro 6" refers to the framework version. "v13" refers to the `@astrojs/cloudflare` adapter version. These are decoupled — the adapter and framework increment independently.

Reinforcing context: Cloudflare acquired The Astro Technology Company on **January 16, 2026** (Cloudflare press release of that date). Astro 6 ships first-class Workers support; `astro dev` runs on `workerd`.

**The shift: Astro app deploys to Cloudflare Workers Static Assets.**

This is not a downgrade. Workers Static Assets is Cloudflare's newer hosting primitive positioned as the recommended path for new projects. Static asset bandwidth is unlimited and free. The only meaningful differences:

| Aspect | Pages (old plan) | Workers Static Assets (new plan) |
|--------|-----------------|----------------------------------|
| Deployment | Git-based auto-deploy via Pages | Wrangler CLI or Workers Builds |
| Worker → app handoff | `fetch(pages.dev URL)` with Host header workaround | **Service Binding** (preserves Request intact) |
| SSR | Pages Functions | Worker invocations |
| Static asset bandwidth | Unlimited, free | Unlimited, free |
| Custom domains | Wildcard NOT supported on Pages | N/A — Worker is the public entry point; Astro Worker is internal-only |
| Future feature access | Limited; new Cloudflare features ship to Workers first | Full access (Durable Objects, Cron Triggers, etc.) |

## 16. The Service Binding handoff — how the Worker reaches the Astro app

The router Worker (`partna-subdomain-router`) declares a Service Binding to the Astro Worker (`partna-pages`) in its `wrangler.toml`. The configuration is environment-aware:

```toml
# partna-subdomain-router wrangler.toml

# Production
[[services]]
binding = "PARTNA_PAGES"
service = "partna-pages"
environment = "production"

# Staging
[env.staging]
[[env.staging.services]]
binding = "PARTNA_PAGES"
service = "partna-pages-staging"
```

In the router's code, calling `env.PARTNA_PAGES.fetch(request)` invokes the Astro Worker with the inbound `Request`. The Astro Worker reads `request.headers.get('host')` in production and routes accordingly. No header rewriting, no manual proxying.

**Billing — verified:** Service Bindings are described by Cloudflare as a "zero-cost abstraction." The Service Binding subrequest into the Astro Worker does NOT add a second billable Worker request. It DOES count against the inbound Worker's subrequest budget.

**Subrequest limits — verified:**
- Workers Free: 50 subrequests per invocation.
- Workers Paid: **10,000 subrequests per invocation by default**, configurable via Wrangler limits up to 10M.
- A separate limit caps Service Binding hops at 32 Worker invocations per single request chain — far more than this architecture uses (we use 1 hop: router → Astro).

The router needs ~3 subrequests per cache miss: KV read + Service Binding fetch + (transitively) Astro's own subrequest to backend. Well under any tier limit.

**Host preservation — caveat:** the Cloudflare Service Bindings docs do not explicitly document Host header preservation. In practice the Request is passed intact, but this is verified by smoke test at Gate G3 before the architecture locks. If Host is NOT preserved, the fallback is for the router to set `X-Partna-Handle: <handle>` and the Astro middleware reads from there. This is a 3-line change in two places — small contingency.

## 17. DNS configuration

Already in place; no changes required.

- `*.partna.au` resolves to Cloudflare IPs (`172.67.x.x`, `104.21.x.x` — verified via `dig`).
- Nameservers: `elias.ns.cloudflare.com`, `brenda.ns.cloudflare.com`.
- The Cloudflare Worker `partna-subdomain-router` is registered to the route `*.partna.au/*` in zone `partna.au`.
- The Astro Worker (`partna-pages`) lives in the same `partna.au` zone (so the same cache-purge API token scopes work) but is registered with NO public route — it's only reachable via Service Binding from the router.

Specific subdomain overrides (existing):
- `api.partna.au`, `dev-api.partna.au` → Laravel Cloud
- `integration.partna.au` → Vercel (Partna-Shopify-App)

These remain unchanged; the router's `RESERVED` set ensures they bypass KV lookup.

## 18. Cache invalidation — explicit and end-to-end

**Cloudflare Workers do NOT auto-cache responses from Cache-Control headers alone.** Per Cloudflare docs: *"For projects where there is no backend (that is, the entire project is on Workers), the Cache API is the only option to customize caching."* The router Worker explicitly populates the edge cache after each Astro Worker invocation.

**Pattern — implemented in the router (already shown in §6):**

```js
if (entry.type === "individual") {
  const cache = caches.default;
  const cached = await cache.match(request);
  if (cached) return cached;

  const response = await env.PARTNA_PAGES.fetch(request);

  if (response.ok && request.method === "GET") {
    ctx.waitUntil(cache.put(request, response.clone()));
  }
  return response;
}
```

The Astro Worker's response carries `Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=600`. The router puts the response into `caches.default` keyed by URL. The TTL is derived from `s-maxage` (Cloudflare cache uses this when present). Subsequent matching requests within the TTL hit `cache.match` and skip the Service Binding invocation entirely.

**Why the router, not the Astro Worker:** the router owns the public URL the cache-purge job targets. Caching at the router means one cache layer, one purge target, one TTL policy.

**Forbidden anti-pattern (added to §51):** returning cacheable Worker responses without populating `caches.default`. Without explicit `.put()`, headers alone do nothing for Worker output — the cache stays cold and every request invokes the chain.

**Cache purge mechanics.** On profile edit (any `Site` mutation — content, theme, design, gallery, media upload) and on account-type transition, the backend dispatches `CloudflareCachePurgeJob(handle)` which:
1. Builds the full URLs to purge: `https://<handle>.partna.au/` and any sub-paths the individual exposes.
2. Calls Cloudflare's cache purge API: `POST https://api.cloudflare.com/client/v4/zones/{zone_id}/purge_cache` with body `{"files": [...urls]}`.
3. Uses an API token scoped to `Zone.Cache Purge` for the `partna.au` zone (loaded from `config('services.cloudflare.cache_purge_token')` — never read `env()` directly; see §28.7 / CFG-2).
4. Retries on transient failures using its own declared backoff policy (max 3 attempts, exponential).

The next visitor after the purge triggers a fresh fetch through the chain; the router re-populates the cache for the next TTL period.

**Cache purge API limits (verified):**
- Free plan: 5 requests/minute (bucket 25)
- Pro: 5 requests/second (bucket 25)
- Business: 10 requests/second (bucket 50)
- No per-call charge.

Profile edits don't happen frequently enough at our scale for this to be a constraint, but the job respects rate limits with backoff.

## 19. KV namespace usage

The existing `SUBDOMAIN_KV` namespace is reused. Three value shapes (discriminated union by `type`):

```jsonc
// brand pass-through
{ "type": "brand" }

// affiliate redirect (existing)
{ "type": "affiliate", "redirect": "https://layrite.partna.au/jane" }

// individual (new)
{ "type": "individual" }
```

The individual entry intentionally has no `origin` field — the Service Binding name is configured in the router's `wrangler.toml`, not in KV.

**Writes and deletes against `SUBDOMAIN_KV` are confined to the `app/Jobs/Cloudflare/` directory.** Today there are two jobs that touch the namespace: `SyncSubdomainToKvJob` (writes brand / affiliate / individual entries) and `RetireSubdomainFromKvJob` (deletes entries when a handle is retired or a Professional is hard-deleted). Both go through `CloudflareKvService::put()` and `->delete()` respectively. Architecture test `tests/Architecture/SubdomainKvWritersTest.php` greps for calls to `CloudflareKvService::put` and `CloudflareKvService::delete` across the codebase and asserts every match's file is inside `app/Jobs/Cloudflare/`.

## 20. Astro Worker — how it serves the per-handle page

The Astro Worker is a regular Cloudflare Worker built by `@astrojs/cloudflare` v13. It receives the Request from the router via Service Binding, extracts the handle from the Host header in middleware, and renders the appropriate theme.

```typescript
// src/middleware.ts
export const onRequest: MiddlewareHandler = async ({ request, locals }, next) => {
  let handle: string | undefined;

  // Dev fallback first (X-Dev-Handle header or ?handle= query param)
  if (import.meta.env.DEV) {
    const url = new URL(request.url);
    handle = request.headers.get('x-dev-handle') ?? url.searchParams.get('handle') ?? undefined;
  }

  // Production: extract from Host header
  if (!handle) {
    const host = request.headers.get('host') ?? '';
    handle = host.split('.')[0]; // 'jane.partna.au' → 'jane'
  }

  if (!handle || handle === 'localhost:8787') {
    return new Response('Handle required', { status: 400 });
  }

  locals.handle = handle;
  return next();
};

// src/pages/index.astro
const handle = Astro.locals.handle;
const profile = await fetchProfile(handle);
if (!profile) return new Response(null, { status: 404 });
---
<Theme1Layout {...profile} />
```

Static assets (CSS, fonts, images bundled with Astro) are served directly by Workers Static Assets without Worker invocation. The dynamic `index.astro` route does invoke the Worker per cache-miss request.

## 21. Local development workflow

The Astro Worker has no public route. Two supported dev modes:

**Mode A — Astro Worker alone (most iterations):** `wrangler dev` runs the Astro Worker locally. The middleware's dev fallback handles handle extraction via the `X-Dev-Handle` header or `?handle=jane` query param. Reach it at `http://localhost:8787/?handle=jane`. Fast inner loop for layout/render work.

**Mode B — Both Workers via Service Binding (integration test):** `wrangler dev --local` in both Worker directories with the router declaring its Service Binding to the local Astro Worker. The router serves on `http://localhost:8787`; requests with `Host: jane.partna.au.localhost` (or use `--host`) exercise the full chain. Slower; reserve for integration smoke tests.

**Mode C — Staging environment:** Wrangler `--env staging` deploys both Workers to a staging zone (`*.partna-staging.au`) with the staging Service Binding wired per §16. This is where Gate G3 verification runs.

**Production has no public route on the Astro Worker.** The architecture test in `tests/Architecture/AstroWorkerRouteTest.php` confirms `partna-pages/wrangler.toml` has no `route` or `routes` declaration in production.

---

# PART 4 — The shared theme package

## 22. `@partna/themes` — repo, structure, distribution

**New standalone repo: `partna-themes`** under the same GitHub org as the other Partna repos. Not a monorepo workspace inside Hydrogen — keeping it independent means Hydrogen and the Astro app can pin different versions if needed and the package lifecycle is decoupled.

**Distribution: GitHub Packages private registry.**

For private packages, GitHub Packages free tier provides:
- 500 MB storage
- 1 GB data transfer per month

At our scale (small org, infrequent installs), this is effectively free. If we approach the limits we'd see warnings well before any hard cap.

Both Hydrogen and the Astro app authenticate via:
- Developer machines: GitHub Personal Access Token with `read:packages` scope
- CI: the auto-issued `GITHUB_TOKEN` (no extra setup needed for repos in the same org)

Per-repo `.npmrc`:
```
@partna:registry=https://npm.pkg.github.com
//npm.pkg.github.com/:_authToken=${NODE_AUTH_TOKEN}
```

## 23. Package structure

```
partna-themes/
├── package.json              # exports map per entry point
├── tsconfig.json
├── src/
│   ├── theme-1/              # extracted from Hydrogen app/themes/theme-1/
│   │   ├── layout.tsx
│   │   ├── sections/
│   │   ├── components/
│   │   └── styles/
│   ├── theme-2/              # scaffolded (placeholder layout re-export only)
│   ├── theme-3/
│   ├── theme-4/
│   ├── theme-5/
│   ├── engines/              # extracted from Hydrogen app/lib/engines/ (non-cart subset)
│   ├── brand/                # BrandStyleTag, brand-tokens.css, font registry
│   ├── analytics/            # IntersectionObserver tracking
│   ├── icons/                # SVG icon registries
│   ├── motion/               # motion library wrapper + LazyMotion
│   ├── pdf/                  # react-pdf wrapper
│   └── types/                # ThemeProps + shared shapes
├── tests/
└── README.md
```

`package.json` exports map per entry point so consumers only bundle what they import:
```json
"exports": {
  ".": "./dist/index.js",
  "./theme-1": "./dist/theme-1/index.js",
  "./theme-1/layout": "./dist/theme-1/layout.js",
  "./engines": "./dist/engines/index.js",
  "./brand": "./dist/brand/index.js",
  "./analytics": "./dist/analytics/index.js",
  "./icons": "./dist/icons/index.js",
  "./motion": "./dist/motion/index.js",
  "./pdf": "./dist/pdf/index.js",
  "./types": "./dist/types/index.js"
}
```

Build tool: `tsup` (simple, fast, ESM + .d.ts emission, handles CSS Modules).

## 24. What's in the package vs what's not — the precise rule

**The shared package is Shopify-free.** No `@shopify/*` imports anywhere in `src/`. CI enforces this with a grep check that fails the build on any match.

**The shared package is framework-agnostic.** No `react-router`, `@remix-run/*`, `astro:`, `next/*` imports. Components take typed props and render. Framework wiring (data loaders, form actions, routing primitives) is the consumer's job. **CI enforces this with a second grep check, parallel to the Shopify check — same pattern, same failure mode.**

**What stays Hydrogen-only:**
- The Shop section (`Partna-Hydrogen/app/themes/theme-1/sections/Shop/`)
- Cart hooks (`Partna-Hydrogen/app/lib/cart/`)
- Cart engine (`Partna-Hydrogen/app/lib/engines/cart.server.ts`)
- Product engine (`Partna-Hydrogen/app/lib/engines/products.server.ts`)
- Shopify-specific components: `ShopExpandableCard`, `ShopPayExpress`, anything that imports `@shopify/hydrogen-react` or `@shopify/hydrogen`
- The Hydrogen-specific `brand-design.server.ts` engine

## 25. Hydrogen's consumption — composition layer

Hydrogen's `theme-1/layout.tsx` is rewritten once to:
1. Import the universal sections + components from `@partna/themes/theme-1`
2. Import the Shop section from its own `Partna-Hydrogen/app/themes/theme-1/sections/Shop/Shop.tsx`
3. Compose them into the full storefront layout

```tsx
// Partna-Hydrogen/app/themes/theme-1/layout.tsx (post-extraction)
import { Bio, Booking, Document, Newsletter, Services, BrandStyleTag, AnalyticsProvider } from '@partna/themes/theme-1';
import { Shop } from './sections/Shop/Shop'; // Hydrogen-local Shop section
// ... compose with Shop section included
```

The Astro app's equivalent doesn't import Shop:
```tsx
// partna-pages/src/theme-renderer.tsx
import { Bio, Booking, Document, Newsletter, Services, BrandStyleTag, AnalyticsProvider } from '@partna/themes/theme-1';
// No Shop import — composition without commerce sections
```

Composition is the consumer's job. The package provides building blocks.

## 26. Versioning and migration

- Semver. v1.x.x for theme-1 era. Major bump when new theme is added or a public API breaks.
- Hydrogen pins a version (e.g., `"@partna/themes": "^1.2.0"`).
- The Astro app pins the same version (initially).
- Breaking changes are coordinated across both consumers (per §47 communication protocol).
- Patch and minor releases auto-publish on git tag push (CI workflow in the partna-themes repo).

## 27. Per-individual styling vs per-brand styling

The data model already supports per-Site styling — `site.settings.design` JSONB is one-per-professional.

**For brand/partner storefronts (Hydrogen):**
- Hydrogen's `brand-design.server.ts` engine fetches the BRAND's `site.settings.design`.
- Brand placeholders, fallback gallery, brand logo, brand slogan are layered under affiliate content (Hydrogen-specific; lives in `brand-context.server.ts`).

**For individuals (Astro):**
- New backend endpoint `GET /api/public/profiles/{handle}` returns the individual's OWN `site.settings.design`.
- No brand placeholders. No fallback gallery. No brand logo concept (logo is the individual's profile image).
- The same `BrandStyleTag` component from the shared package consumes the styling and injects CSS variables — exact same rendering, different data source.

The theme components are symmetric. They receive `ThemeProps` and render.

---

# PART 5 — Per-repo changes

## 28. Comet-Backend changes

All migrations are Supabase SQL per the existing project convention. Every destructive or constraint-adding migration carries a `-- To revert: …` header (audit MIG-6) so an operator hitting an incident has the rollback command at hand without grepping the plan.

**28.1. Schema migration — account_type** — four migration files implementing the §8 sequence:

| File | Contents | Transaction wrapper |
|------|----------|---------------------|
| `<ts1>_add_account_type_column_and_backfill.sql` | `ADD COLUMN account_type text NULL` + idempotent backfill UPDATEs enumerating brand / professional / influencer (each guarded `WHERE account_type IS NULL`) | `BEGIN; … COMMIT;` |
| `<ts2>_add_account_type_constraints_and_trigger.sql` | `ADD CONSTRAINT … CHECK NOT VALID` + `ADD CONSTRAINT account_type_not_null CHECK (… IS NOT NULL) NOT VALID` + create dual-write trigger function + create trigger on `core.professionals` | `BEGIN; … COMMIT;` |
| `<ts3>_validate_and_promote_account_type.sql` | `VALIDATE CONSTRAINT` both checks + DO-block NULL-assertion guard + `ALTER COLUMN account_type SET NOT NULL` + drop the now-redundant `account_type_not_null` CHECK | `BEGIN; … COMMIT;` |
| `<ts4>_add_account_type_covering_index.sql` | `CREATE INDEX CONCURRENTLY professionals_account_type_idx ON core.professionals (account_type);` — file header explicitly states "no transaction wrapper because CONCURRENTLY is incompatible with BEGIN/COMMIT" | **NO wrapper** |

**Sequencing rules:**
- `<ts1>` adds + backfills in one file (audit MIG-4 — closes the partial-application window).
- Backfill UPDATEs are guarded with `WHERE account_type IS NULL` for re-run safety (audit MIG-7).
- `<ts2>` creates the dual-write trigger AFTER backfill is complete (`<ts1>` already ran). The trigger does NOT fire during backfill because the backfill writes both columns explicitly.
- `<ts2>` uses `NOT VALID` for both CHECK constraints (audit MIG-2 + MIG-3). The validation pass runs in `<ts3>`.
- `<ts3>` includes the DO-block NULL-assertion guard before promoting to `SET NOT NULL` — fail loudly if backfill is incomplete.
- `<ts4>` runs CONCURRENTLY in its own file (audit MIG-1).
- Every file header carries `-- To revert: …` (audit MIG-6). For `<ts4>` the revert is `DROP INDEX CONCURRENTLY IF EXISTS professionals_account_type_idx;`.

**Coordination with concurrent work:** the `MFA Foundation` work planned for 2026-05-18 also edits `Professional` model casts and middleware. Sequence: do the `account_type` migration + model update FIRST, then merge MFA work on top.

**28.2. Model updates** (`app/Models/Core/Professional/Professional.php`):
- Cast `account_type` to PHP enum
- Cast `has_historical_partner_links` to boolean (denormalized column from §9 / SCALE-1)
- New accessors: `isPartner()`, `isIndividual()`; existing `isBrand()` becomes a shim reading `account_type`
- New `App/Enums/AccountType.php` enum class. **Standardise on `app/Enums/` for new domain enums going forward** (existing precedent: `App/Enums/BrandStatus.php`). Update `Comet-Backend/CLAUDE.md` to note this convention.

**28.3. AccountCapabilities registry** (`app/Services/Accounts/AccountCapabilities.php`):
- Method `for(Professional $pro): AccountCapabilitySet`
- Full capability matrix per §9
- `AccountCapabilitySet` value object (`app/Services/Accounts/AccountCapabilitySet.php`) — memoizes ex-partner boolean per audit SCALE-1
- **Reconciliation with existing `AccountTypeDefaultsService`** (`app/Services/Professional/AccountTypeDefaultsService.php`): both services exist, they answer different questions and stay separate.

| Service | Question it answers | When it runs |
|---------|---------------------|--------------|
| `AccountTypeDefaultsService` | "What sections / blocks / config should this account type get **seeded with at creation**?" | At signup, once per Professional, via `BootstrapController` → `SiteProvisioningService` |
| `AccountCapabilities` (new) | "Can this Professional access feature X **right now at runtime**?" | At every API request, every notification dispatch, every dashboard route check |

Both classes reference the shared `AccountType` enum so values can't drift.

**Action items for §28.3:**
- Create `AccountCapabilities` as specified
- Audit `AccountTypeDefaultsService::resolveDefaults()` to confirm its returned defaults are consistent with `AccountCapabilities` for each `account_type`
- Add a Comet-Backend CLAUDE.md note explaining which to use when

**28.4. AccountTypeTransitionService** (`app/Services/Accounts/AccountTypeTransitionService.php`):
- `transition(Professional $pro, AccountType $to, array $context = [])`
- Validates transition is allowed; rejects ALL transitions to or from `brand` with `InvalidAccountTypeTransition` domain exception (subclass of `DomainException`)
- **Transaction discipline (audit SCALE-2 — load-bearing):** `DB::transaction(...)` wraps ONLY the Eloquent mutations (account_type update, BrandPartnerLink create/soft-delete, `has_historical_partner_links` flip, dual-write to professional_type) + the `lockForUpdate()` on the Professional row.
- **Job dispatches happen AFTER the transaction closes**, never inside `DB::transaction(...)`. `SyncSubdomainToKvJob`, `CloudflareCachePurgeJob`, and the `AccountTypeTransitionEvent` dispatcher are called once the transaction has committed (using Laravel's `DB::afterCommit()` or by structuring the dispatch outside the closure).
- **`::dispatchSync()` is forbidden inside the transaction.** Class-level comment in the service file states this rule verbatim:
  ```
  // DB::transaction is scoped to Eloquent mutations only.
  // Jobs (KV sync, cache purge, event dispatch) MUST be dispatched AFTER the
  // transaction closes. Do NOT use ::dispatchSync() inside the DB::transaction()
  // closure — Cloudflare HTTP I/O under a row lock starves the connection pool.
  ```
- **Architecture test** (`tests/Architecture/TransitionServiceTransactionBoundaryTest.php`) parses the service file and asserts no `dispatchSync` token appears between the opening `DB::transaction(function` and its matching close brace.
- **Concurrency control: `$pro->lockForUpdate()` inside the transaction.** Pessimistic row-level lock.
- Dispatches `AccountTypeTransitionEvent` (after transaction commits)
- Dispatches `SyncSubdomainToKvJob` + `CloudflareCachePurgeJob` (after transaction commits)

**28.5. AccountTypeTransitionEvent** + listeners (`app/Events/Accounts/`, `app/Listeners/Accounts/`):
- One listener per side-effect: notification subscriptions, Stripe activation toggle, tax info status, dashboard banner triggers

**28.6. SyncSubdomainToKvJob update** (`app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`):

Today the job has three states:
- `isBrand()` → write `{type:'brand'}`
- Has BrandPartnerLink with `site_url` → write `{type:'affiliate', redirect:…}`
- **No link AND not brand** → currently calls `$kv->delete($current)` for every handle (verified at `SyncSubdomainToKvJob.php:69-85`). After this plan, that third branch writes `{type:'individual'}` instead.

**Changes:**
- Replace the `$kv->delete($current)` branch with `$kv->put($handle, ['type' => 'individual'])` for each handle in the alias chain (audit CACHE-3). Keep the existing `try/catch` + `Log::warning` shape around `$kv->put` so transient failures don't crash the job.
- Genuine deletes (handle retirement, professional hard-deletion) stay in `RetireSubdomainFromKvJob` — that's the legitimate delete path.
- **One-off backfill (audit CACHE-3):** Artisan command `php artisan partna:backfill-individual-kv-entries` iterates every non-brand, non-affiliate professional whose KV entry is currently absent and writes `{type:'individual'}`. Idempotent. Run once after §28.1 migration but before the §28.6 code change deploys to production (so existing individuals don't experience a 404 window during the rollout).

**28.6a. SyncSubdomainToKvJob uniqueness (audit JOB-2 / SCALE-4):**

Add `implements ShouldBeUnique` with `$uniqueFor = 45` keyed by `$this->professionalId`. Pattern matches `CreateShopifyMetafieldsJob` and `CreateShopifyAffiliateDiscountJob` (existing precedent). The job is dispatched on handle change, brand-partner-link change, brand URL change, AND now individual-branch dispatches from `AccountTypeTransitionService` (§28.4) and signup-code acceptance (§28.12) — rapid back-to-back dispatches are real. Without serialization, two workers can both read DB state, the older job's write to Cloudflare KV can land after the newer one's, and visitor traffic for that handle is routed wrong until the next dispatch overwrites it.

**Tests updated to cover all three KV branches plus the uniqueness lock.**

**28.7. CloudflarePurgeService + Job** (`app/Services/Cloudflare/CloudflarePurgeService.php`, `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`):
- Service wraps the Cloudflare cache purge API call (`POST /zones/{zone_id}/purge_cache`)
- Job declares its own backoff policy (max 3 attempts, exponential) — does NOT rely on `HasCloudflareRetryPolicy` (that trait is KV-specific)
- **Configuration (audit CFG-2 — REQUIRED):**
  - `.env.example` additions:
    ```
    CLOUDFLARE_CACHE_PURGE_TOKEN=   # Cloudflare API token — Zone.Cache Purge permission on the partna.au zone
    CLOUDFLARE_ZONE_ID=             # partna.au zone ID
    ```
  - `config/services.php` additions:
    ```php
    'cloudflare' => [
        'cache_purge_token' => env('CLOUDFLARE_CACHE_PURGE_TOKEN'),
        'zone_id'           => env('CLOUDFLARE_ZONE_ID'),
    ],
    ```
  - `CloudflarePurgeService` reads `config('services.cloudflare.cache_purge_token')` / `config('services.cloudflare.zone_id')` — never `env()` directly (so `php artisan config:cache` is respected). Same pattern as `CloudflareKvService`.
- **Dispatch sites — explicit because this is NEW dispatch wiring (audit CACHE-2):** today `SiteObserver` handles brand-related KV sync but does NOT call Cloudflare cache purge. This plan adds cache-purge dispatching to:
  - `SiteObserver::saved()` — fires `CloudflareCachePurgeJob($pro->handle)` on every save (not only on subdomain change), AFTER the existing Redis `invalidateSite()` call
  - `AccountTypeTransitionService::transition()` — fires after the transition commits
  - Any other site-mutation paths discovered during implementation (block edits, media uploads, theme changes — see §43 for the full list)
- **Failure observability (audit JOB-1 / OBS-2):** `failed()` calls `report($e);` as the first line before any `Log::error()` so Nightwatch sees the exception with full stack trace.

**28.8. Public profile API endpoint** (`app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`):
- Route: `GET /api/public/profiles/{handle}`
- Resource: `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- **Auth: truly public, no authentication.**
- **Rate limiting (audit CFG-3): configured via `config/sidest.php` so values can be tuned at runtime without redeploy.**
  ```php
  'rate_limits' => [
      'public_profile' => env('SIDEST_RATE_LIMIT_PUBLIC_PROFILE', '60,1'), // "max,minutes"
      'signup_code'    => [
          'per_minute' => env('SIDEST_RATE_LIMIT_SIGNUP_CODE_PER_MIN', 10),
          'per_hour'   => env('SIDEST_RATE_LIMIT_SIGNUP_CODE_PER_HOUR', 100),
          'slowdown_after_failures' => env('SIDEST_RATE_LIMIT_SIGNUP_CODE_SLOWDOWN', 5),
      ],
  ],
  ```
  The `throttle` middleware reads `config('sidest.rate_limits.public_profile')`. The rate-limiter key uses `request->header('CF-Connecting-IP') ?? request->ip()` (defensive against TrustProxies misconfiguration).
- Returns: individual's `site.settings.design` + bio + services + booking + links + newsletter status + analytics tracking IDs
- **Excludes (audit TEST-4 — feature test asserts these keys are ABSENT):** `placeholders`, `fallback_gallery`, `brand_logo`, `brand_slogan`, product/cart fields, commission/order data
- Caching: 60s TTL via `CacheLockService::rememberLocked`; cache key includes handle + site updated_at
- **DO NOT reuse this endpoint for the `/account/design` editor preview.** The editor is authenticated work and needs its own authenticated endpoint.

**28.8a. Resource capability gating (audit API-1 + API-2):**

`ProfessionalDashboardResource` and `ProfessionalResource` change in the same PR that lands §28.1:
- Add `'account_type' => $this->account_type?->value` (audit API-2 — required by Track B's `account-capabilities.ts`). Keep `professional_type` in parallel during the dual-write window.
- Wrap `stripe_connect_status` with `$this->when(AccountCapabilities::for($this->resource)->requires_stripe_connect, …)` (audit API-1). Once Track B's `account-capabilities.ts` is on 3-state logic it never receives a meaningless `stripe_connect_status: null` for individuals.

**28.9. Brand signup code mechanism** (see Part 6 / §32 for the full spec)

**28.10. Notification preferences capability filtering**:
- Endpoint serving `/me/notification-email-preferences` filters categories by `AccountCapabilities::for($pro)->notification_categories`
- Notification dispatcher jobs check capabilities before enqueueing (defence-in-depth)

**28.11. Feature gating wired through existing code paths** — substantial surface area, ~40–55 touch sites. NOT a simple "wrap in guard" task.

| Surface | Files | Difficulty | Notes |
|---------|-------|-----------|-------|
| Notification jobs | 10 in `app/Jobs/Notifications/` | Low | Add `AccountCapabilities::for($pro)->receives_X` check at top of `handle()`. Outer gate. |
| Stripe controllers | `StripeConnectController` ~6 `$role === 'brand'` interior branches (lines 323, 331, 514, 536, 610, etc.) | Medium | Interior branches; refactor each to read capabilities. |
| Stripe services | `StripeConnectService` (3), `StripeTransactionFetcher` (1), `ExportService` (3), `CommissionVoidService` (1), `StripeBillingService` (1), `CommissionPayoutService` (1) | Medium | Migrate from `professional_type` reads. |
| Policies | `CommissionPolicy` lines 87, 149, 159 hard-code `professional_type === 'brand'` | Low | Migrate to `account_type` reads. |
| Webhook handlers | `StripeWebhookController` (1 site), `ProcessShopifyOrderWebhookJob` (1 site) | Low | Outer gate; prevents spurious side-effects for ex-partners. |
| Middleware | `EnsureAffiliateAccount`, `EnsureBrandAccount`, `BrandFundingGate`, `LoadCurrentProfessional` | Low | Clean swap. |
| Resources | `ProfessionalDashboardResource`, `ProfessionalResource`, `ProfessionalPublicResource`, `ProfessionalStaffResource` | Low | See §28.8a; conditional field include. |
| Controllers | ~15 across `Api/Professional/`, `Api/Staff/` | Mixed | Audit during implementation. |

**Total estimate: 40–55 distinct call sites.**

**Specific items called out explicitly:**
- `EnsureAffiliateAccount` and `EnsureBrandAccount` middleware: swap `professional_type` string check for `AccountCapabilities` check
- `CommissionPolicy`: replace all `professional_type === 'brand'` with the appropriate capability flag
- Stripe Connect webhook router: confirm partner-only events skip when `account_type='individual'`
- Tax info collection: skipped for individuals (capability: `requires_tax_info`)
- Order/commission notifications: gated by `receives_order_notifications`, `receives_commission_notifications`
- 404 (not 403) when individuals attempt partner-only resources

**Test coverage for affected policies (audit TEST-2 — scoped subset):** every policy method that §28.11 touches (specifically the migrated branches in `CommissionPolicy` lines 87, 149, 159) gets dedicated ability tests asserting allowed + denied paths for each account type. Other untouched policies named in TEST-2 (`AffiliateProductPolicy`, `BrandResourcePolicy`, `GdprPolicy`, `ProfessionalSelfPolicy`, `SubscriptionPolicy`, `PartnaStaffPolicy`, `FeatureFlagPolicy`) are flagged in Part 15 §59c as out-of-scope for separate PRs.

**Coordination with concurrent backend work:**
- The `Async commission export` work planned for 2026-05-19 touches commission/payout export. Export endpoints are partner-only and intersect §28.11.
- Audit Phase 2 outstanding items (in the backend's `audits/` directory) include policy coverage and feature-flag hygiene. §28.11 may close several side-effect.

**28.12. Brand invite acceptance** (existing endpoint, modified):
- Wire through `AccountTypeTransitionService::transition($pro, AccountType::Partner, ['brand_id' => ...])`
- All downstream effects fire consistently

**28.13. Bootstrap flow update** (`app/Http/Controllers/Api/PublicSite/BootstrapController.php`):
- Explicit `account_type` assignment per the three signup paths defined in §32:
  - Brand path → `account_type = 'brand'` (written directly at signup; ONLY way to become a brand)
  - Invite path → `account_type = 'partner'`
  - Brand signup code path → `account_type = 'partner'`
  - Default Professional path → `account_type = 'individual'`
- Writes BOTH `account_type` AND `professional_type` for the dual-write period
- `BootstrapController` is the ONLY writer of `account_type = 'brand'` anywhere in the codebase.

**28.14. Individual waitlist flag (audit CFG-1):**
- New env var `SIDEST_INDIVIDUAL_WAITLIST_ENABLED` (default `false` — fail-closed):
  ```
  # .env.example
  SIDEST_INDIVIDUAL_WAITLIST_ENABLED=false  # When true, divert non-invite/non-signup-code individual signups to a waitlist row instead of creating a Professional
  ```
- `config/sidest.php`:
  ```php
  'individual_waitlist_enabled' => env('SIDEST_INDIVIDUAL_WAITLIST_ENABLED', false),
  ```
- `BootstrapController` reads `config('sidest.individual_waitlist_enabled')` — never `env()` directly so `config:cache` is respected.
- When on, BootstrapController diverts individual signups to a waitlist row instead of creating a Professional. Brand, partner-via-invite, **and partner-via-brand-signup-code** signups are unaffected.

**28.15. Tests** for everything above. Pest 4 + PHPUnit, SQLite in-memory.

**28.15a. Constraint-rejection tests (audit TEST-3) — explicitly named:**
- `_add_account_type_constraints_and_trigger.sql`: INSERT with `account_type = 'invalid'` fails the CHECK (test exercises against actual Postgres, not SQLite — uses the existing `OrdersSchemaMigrationTest` pattern).
- `_add_soft_deletes_to_brand_partner_links.sql`: orphan `affiliate_professional_id` fails FK.
- `_enforce_brand_signup_code_constraints.sql`: duplicate `signup_code` fails UNIQUE.
- `_create_brand_signup_code_audit.sql`: `event = 'invalid_event'` fails CHECK; `event = 'claimed'` with NULL `joined_professional_id` fails compound CHECK.

**28.15b. Model creation tests (audit TEST-5):** `BrandProfile::factory()->create()` asserts `signup_code` is non-null + 16 alphanumeric chars. Negative test: `BrandProfile::create(['signup_code' => null, …])` produces a generated code via the `creating` hook (not null). Documents `createQuietly()` skips model events as a known gotcha; backfill tests use `saveQuietly()` only after explicitly calling the generator.

**28.16. BrandPartnerLink soft-delete migration** — REQUIRED for ex-partner mechanism to work.

**Verified state (May 2026):** `app/Models/Core/Professional/BrandPartnerLink.php` uses only `HasUuids` (no SoftDeletes). `BrandPartnerLinkService::disconnectBrandFromAffiliate()` at line 99 calls `$target->delete()` (hard). `brand.brand_partner_link_events` (per `20260420000000_add_brand_partner_link_events.sql:6-7`) has `brand_professional_id` and `affiliate_professional_id` FKs with `ON DELETE RESTRICT`.

The migration adds soft-delete, fixes the FK RESTRICT problem, AND updates the three named RLS policies — all atomically in the same migration window.

**Migration A** (`supabase/migrations/<ts>_add_soft_deletes_to_brand_partner_links.sql`):
  - `ALTER TABLE brand.brand_partner_links ADD COLUMN deleted_at TIMESTAMPTZ NULL`
  - **Dual indexes (both required):**
    - `CREATE INDEX CONCURRENTLY brand_partner_links_active_idx ON brand.brand_partner_links (affiliate_professional_id) WHERE deleted_at IS NULL` — partial index for active partnerships (the hot path)
    - `CREATE INDEX CONCURRENTLY brand_partner_links_all_idx ON brand.brand_partner_links (affiliate_professional_id, deleted_at)` — composite index for ex-partner queries needing all historical partnerships
  - Header: `-- To revert: DROP INDEX CONCURRENTLY IF EXISTS brand_partner_links_active_idx, brand_partner_links_all_idx; ALTER TABLE brand.brand_partner_links DROP COLUMN deleted_at;`
  - **NO transaction wrapper** because CONCURRENTLY is incompatible with BEGIN/COMMIT.

**Migration B** (`supabase/migrations/<ts+1>_brand_partner_link_events_set_null_fks.sql`) — audit DATA-3 part B:
  - Drop the existing `ON DELETE RESTRICT` constraints on `brand_partner_link_events.brand_professional_id` and `affiliate_professional_id`.
  - Make both columns nullable (they currently are NOT NULL).
  - Re-add as `ON DELETE SET NULL`.
  - Otherwise `PurgeSoftDeleted::forceDelete()` throws FK violations on the audit table when a professional with link-event history is hard-deleted at the end of the grace period — the professional stays permanently soft-deleted, thinking they've deleted their account.
  - Wrapped in `BEGIN; … COMMIT;`. Header: `-- To revert: see migration body for restoration to RESTRICT.`

**Migration C** (`supabase/migrations/<ts+2>_update_brand_partner_link_rls_for_soft_delete.sql`) — audit SCHEMA-2:
  - Drop and recreate the three RLS policies named in the audit, adding `deleted_at IS NULL` predicates on the non-staff branches. Verified current predicates and line numbers via `20260420200000_add_rls_to_remaining_tables.sql`:
    - `partner_links_party_select` (line 135): add `AND deleted_at IS NULL` to the affiliate-side and brand-side equality predicates; staff branch unchanged.
    - `brand_profiles_affiliate_select` (line 116): add `AND l.deleted_at IS NULL` to the EXISTS subquery.
    - `store_settings_affiliate_select` (line 186): same pattern.
  - All three changes ship in this single migration so there is no window between "soft-deletes exist" and "RLS hides them" — the policies are atomically updated when soft-delete becomes possible.
  - Wrapped in `BEGIN; … COMMIT;`.

**Model update**: add Laravel's `SoftDeletes` trait to `BrandPartnerLink`. Add `deleted_at` to `$casts` as datetime.

**Service call site audit — full enumeration (verified):**
- `BrandPartnerLinkService::disconnectBrandFromAffiliate()` line 99 calls `$target->delete()` — automatically becomes soft-delete via the trait; **no code change required.** ✓
- `BrandPartnerLinkService::normalizeAdditionalSlots()` — operates on the result of an explicit query; default scope excludes trashed, which is correct.
- `BrandPartnerLinkLifecycleService::disconnect()` — calls into `disconnectBrandFromAffiliate`; no change.
- `BrandPartnerLinkObserver::deleted()` — Eloquent SoftDeletes fires the `deleted` model event on soft-delete (NOT on force-delete), so the observer continues to fire `SyncSubdomainToKvJob` + cache busts + notifications. **No change required, verified.** ✓
- `SiteCacheService` uses `BrandPartnerLink::query()` in 3 places (lines 299, 870, 1062) — default scope auto-excludes trashed.
- `AffiliateProductCatalogService` and `BrandAffiliateInviteService` — same pattern; default scope is correct.
- `SyncSubdomainToKvJob::handle()` line 63-67: after soft-delete, this correctly returns null for ex-partners, which then triggers the new `{type:'individual'}` write path. **Loop closes cleanly. ✓**

**Relationship queries**: add a `brandPartnerLinksAll()` relationship on `Professional` that returns `withTrashed()`, distinct from the existing `brandPartnerLinks()` (which auto-excludes trashed). Ex-partner derivation uses the `All` variant.

**`shows_ex_partner_panel` derivation** (§9): `$pro->brandPartnerLinksAll()->exists() && !$pro->brandPartnerLinks()->exists()`.

**`has_historical_partner_links` denormalized column (audit SCALE-1):**
- Add as part of Migration A: `ALTER TABLE core.professionals ADD COLUMN has_historical_partner_links boolean NOT NULL DEFAULT false;`
- Backfill: `UPDATE core.professionals SET has_historical_partner_links = true WHERE id IN (SELECT DISTINCT affiliate_professional_id FROM brand.brand_partner_links);`
- Observer: `BrandPartnerLinkObserver::created` sets `true`; `BrandPartnerLinkObserver::deleted` / `forceDeleted` re-evaluate via `brandPartnerLinksAll()->exists()` and update if changed.

**`BrandPartnerLinkAuditor`** unchanged.

**Tests** (`tests/Feature/Services/Professional/Brand/SoftDeleteTest.php`):
- Disconnect leaves the row visible to `withTrashed()`
- Default queries exclude soft-deleted
- Ex-partner derivation returns true for soft-deleted-only state
- Reconnect to same brand creates a NEW row
- `commerce.orders.affiliate_professional_id` queries still resolve to the affiliate Professional regardless of BrandPartnerLink soft-delete state
- **RLS predicate test:** a non-staff authenticated user querying `brand.brand_partner_links` directly via Supabase REST does NOT see soft-deleted rows; same for `brand_profiles` and `brand_store_settings` via their respective `_affiliate_select` policies (defence-in-depth)
- `has_historical_partner_links` flips true on create, stays true on soft-delete, stays true on reconnect, flips false only on `forceDelete()` of the last link

**28.17. Track A absorbed bugs (existing-code 🅲 findings in this plan's blast radius):**

The audit surfaced existing-code defects in code paths this plan touches. Absorbing them keeps the work atomic.

- **DATA-1 (audit verified):** `core.brand_status_history.professional_id` uses `ON DELETE CASCADE` (per `20260505000001_create_brand_status_history.sql:4`). After 30-day soft-delete purge → professional hard-delete, all status-transition audit rows vanish. Migration `<ts>_brand_status_history_set_null_professional_fk.sql` makes the column nullable and converts the FK to `ON DELETE SET NULL`, matching the precedent in `20260505200000_commission_ledger_entries_set_null_professional_fks.sql`. Wrapped in `BEGIN; … COMMIT;`. Header: `-- To revert: ALTER TABLE … DROP CONSTRAINT … ; ALTER TABLE … ADD CONSTRAINT … ON DELETE CASCADE;` (full SQL inline).

- **DATA-2 (audit verified — see Part 16 caveat):** The audit cites `supabase/migrations/20260519100000_handle_alias_lifecycle.sql:100` for a `core.handle_change_log` table with an `ON DELETE CASCADE` FK that contradicts a stated 7-year retention. **Verification result (May 2026):** that migration file does NOT exist in the codebase; `grep -r handle_change_log` against `/Users/tobiasbalcombeehrlich/Developer/Comet-Backend/` returns zero matches. The table appears not to have been merged into `development` yet (or has a different filename than the audit cites). Treatment: Track A includes a placeholder action item to (a) locate the actual migration/table when it lands, and (b) apply the same CASCADE → SET NULL fix at that point with a matching `-- To revert:` header. Until the migration lands, this finding is **on-hold**, not actionable. See Part 16 verification-failed column.

- **DATA-4 (audit verified):** `app/Console/Commands/PurgeSoftDeleted.php` lines 33–37 enumerate Customer, Service, SiteMedia, Enquiry, ServiceCategory but omit `Block` (which uses `SoftDeletes`). Track A adds `Block::class` to the purge enumeration AND adds a sweep test (`tests/Feature/SoftDeletePurgeCoverageTest.php`) that discovers every model with `use SoftDeletes` via reflection and asserts each is either listed in `PurgeSoftDeleted`, has its own prune command, or appears in a `PURGE_EXEMPT` allowlist with a justification.

- **JOB-1 / OBS-2 (audit verified at the cited line numbers — 5 jobs):** add `report($e);` as the first line of each `failed()` method:
  - `app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php:107` (no `report` call before `Log::error` — audit line 122 was approximate, real line is 107-114; finding confirmed)
  - `app/Jobs/Notifications/SendBrandStatusNotificationJob.php:73`
  - `app/Jobs/Notifications/NudgeStuckOnboardingJob.php:133`
  - `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:113` (payout/commission email path — most important)
  - `app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php:194` (`failed()` mutates integration metadata but never reports — confirmed; audit cited approximately the right line)
  - Plus: **every new job in this plan** (CloudflareCachePurgeJob, SyncSubdomainToKvJob is already correct at line 92-99, all new notification jobs) carries the same `report($e);` as line 1 of `failed()`. Added to §51 forbidden-pattern checklist: "new job's `failed()` does not call `report($e)` first."

- **LIFE-1 (audit verified — line numbers shifted slightly):** `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php::resolveAffiliateIdFromPayload` (around lines 595–613) extracts only `_partna_affiliate_id`? Verification shows the current code extracts the legacy `'affiliate'` cart-attribute key (handle-based lookup via `Professional::where('handle_lc', …)`) — NOT the UUID-direct lookup that newer Hydrogen carts set. Out-of-order webhook stub inserts for carts placed through current Hydrogen lose the affiliate ID and silently skip. Fix: try `_partna_affiliate_id` (UUID direct-lookup) first, then fall back to the legacy `'affiliate'` handle-based lookup. Match `ProcessShopifyOrderWebhookJob` lines 94-101 canonical pattern. Fix together with §28.6 since both touch the same handle-lookup surface.

- **LIFE-2 (audit verified):** `app/Services/Professional/Brand/BrandStatusService::sync()` (lines 105–155) is an unguarded read-modify-write that produces duplicate audit rows under concurrent callers. Fix: wrap `sync()` body in `DB::transaction()` with `BrandProfile::where(...)->lockForUpdate()->first()` as the first read. Belt-and-suspenders: add `UNIQUE (professional_id, from_status, to_status, created_at::date)` on `core.brand_status_history` (combined into Track A's DATA-1 migration above; index is fine to add concurrently if size demands but the table is small today).

- **SEC-1 (audit cited PolicyCoverageTest.php:38 stale entry — VERIFICATION shows audit is partially OUTDATED):** Re-reading `tests/Feature/Security/PolicyCoverageTest.php` (May 2026) shows POLICY_EXEMPT already contains `\App\Models\Commerce\CommissionPayoutItem::class` (line 38 — correctly namespaced, NOT `Retail`) AND already exempts `CommissionClawback` (line 49) AND `SectionView` (line 34) plus other analytics models. **The CI gate is NOT broken.** Track A still verifies the test passes locally before §28.11 capability migration lands (since several §28.11 changes touch policies registered there). No code change needed for this audit item. See Part 16 verification-failed/audit-was-wrong column.

- **API-1, API-2 (audit verified, both 🅸):** addressed in §28.8a above as part of the same PR that lands §28.1.

- **JOB-2 / SCALE-4 (audit verified):** addressed in §28.6a above.

## 29. Cloudflare Worker changes

File: `Comet-Backend/cloudflare-worker/src/index.js`.

**29.1. Add the `individual` branch with explicit cache:**

```js
if (entry.type === "individual") {
  const cache = caches.default;
  const cached = await cache.match(request);
  if (cached) return cached;

  const response = await env.PARTNA_PAGES.fetch(request);

  if (response.ok && request.method === "GET") {
    ctx.waitUntil(cache.put(request, response.clone()));
  }
  return response;
}
```

**29.2. Update `wrangler.toml`** (production + staging Service Bindings per §16):

```toml
[[services]]
binding = "PARTNA_PAGES"
service = "partna-pages"
environment = "production"

[env.staging]
[[env.staging.services]]
binding = "PARTNA_PAGES"
service = "partna-pages-staging"
```

**29.3. Tests:**
- Local Wrangler dev test with mocked Service Binding
- Cache-hit / cache-miss path tested via `caches.default.match` mocks
- Staging deploy with real Service Binding
- Production deploy gated on Phase 5

## 30. Hydrogen changes

**30.1. Newsletter hook decoupling** (`app/lib/engines/newsletter.ts`):
- Today: hard-imports `useFetcher` from `react-router`
- Change: hook accepts optional `submit` callback; default uses React Router's `useFetcher`; the shared package version uses plain `fetch` (Astro path)

**30.2. Shop section refactor** (`app/themes/theme-1/`):
- Move `components/expandable/ShopExpandableCard/` to `sections/Shop/`
- Create `sections/Shop/Shop.tsx` as the section wrapper composing the expandable card with the cart/checkout machinery
- Update `layout.tsx` imports

**30.3. Layout migration to package consumption**:
- `app/themes/theme-1/layout.tsx` rewritten ONCE to import sections/components from `@partna/themes/theme-1` and add the local Shop section
- Themes 2–5 (empty `.gitkeep` placeholders in Hydrogen today) — **delete the Hydrogen scaffolds in Phase 3** once `partna-themes` ships its own scaffolds

**30.4. File audit before extraction**:
- Verify no `react-router` imports in `app/themes/`
- Verify no `@shopify/*` imports outside Shop/cart paths
- Output a delta document: which files move to the package, which stay in Hydrogen

## 31. Frontend changes (Partna-Frontend)

The frontend UI buildout is out of scope for this plan. The capability extensions and route allowlist updates ARE in scope.

**31.1. `lib/account-capabilities.ts` extension**:
- Extend from 2-state to 3-state
- Add capability rows per §9
- Add route allowlist entries for individuals (`/account/overview`, `/account/onepage`, `/account/contacts`, `/account/settings`, `/account/design`)
- Remove commerce-related route allowlist entries for individuals (`/account/shop`, `/account/commerce`, `/account/affiliates`)
- Consumes `account_type` from `ProfessionalDashboardResource` (§28.8a)

**31.2. Signup form adjustment** (`app/(app)/account/(auth)/sign-up/signup-form.tsx`):
- The 2-button AuthTypeGrid stays
- Add optional "brand invite code" text input on the Professional path
- Internal resolution per §32
- Submit posts `account_type` explicitly to BootstrapController

**31.3. `/account/design` made universal**:
- Existing brand-only route becomes accessible to individuals
- Conditional render: brand gets the full editor, individual gets the simplified version
- Same backend endpoint serves both

**31.4. Settings section conditional rendering** (`app/(app)/account/(dashboard)/settings/settings-sections.tsx`):
- Switch existing `professional_type === 'brand'` checks to capability-based checks via `account-capabilities.ts`
- "Industries" section: brand only
- "Brand Partnas" section: partner only
- "Sharing" section: all

---

# PART 6 — Brand signup code mechanism

## 32. The concept

The brand signup code is a **per-brand shareable code** that anyone signing up can enter to immediately become a partner of that brand. Distinct from the existing per-affiliate `BrandAffiliateInvite` tokens (which are one-time and tied to a specific email/individual).

Use case: a brand wants to onboard partners en masse. Instead of generating dozens of individual invites, they share one code. Anyone who uses it becomes a partner.

## 33. Mechanism

**Storage:** new columns on `brand.brand_profiles`:

```sql
ALTER TABLE brand.brand_profiles
  ADD COLUMN signup_code text,
  ADD COLUMN signup_code_active boolean NOT NULL DEFAULT true,
  ADD COLUMN signup_code_rotated_at timestamptz;
```

(`signup_code` is added nullable here; uniqueness and NOT NULL are enforced in step 3 of §36 using the non-blocking CONCURRENTLY pattern.)

- `signup_code`: opaque alphanumeric string, 16 chars. Generated in PHP via the `BrandProfile::creating` Eloquent hook using `bin2hex(random_bytes(8))`. UNIQUE across all brands.
- `signup_code_active`: brand can deactivate without rotating.
- `signup_code_rotated_at`: tracks when the code was last rotated.

**Why an opaque code instead of just using the brand's handle:** handles are public and guessable; opaque codes require deliberate sharing; rotatable without losing handle.

**Signup flow integration:**

In the signup form, the optional "brand invite code" input accepts EITHER:
- A per-affiliate `BrandAffiliateInvite` token (existing flow)
- A per-brand `signup_code` (new flow)

The BootstrapController detects which:
1. Try to find `BrandAffiliateInvite` with matching token → existing path
2. Else, try to find `BrandProfile` with matching `signup_code` AND `signup_code_active = true` → new path
3. Else, validation error: "Code not recognized"

For the brand_signup_code path:
- **`BrandSignupCodeService` lives at `app/Services/Professional/Brand/BrandSignupCodeService.php`** (consistent with `BrandPartnerLinkService` siblings).
- `BrandSignupCodeService::resolveCode(string $code): ?BrandProfile`
- `BrandSignupCodeService::generate(): string` — single source of truth for code generation. Called from `BrandProfile::creating` hook and from the Artisan backfill command.
- If resolved, BootstrapController creates the Professional with `account_type = 'partner'` AND creates a `BrandPartnerLink` to that brand
- **Single-brand cap enforcement:** uses existing `BrandPartnerLinkService::connectBrandToAffiliate()` (per Pilot/V1 doctrine at `BrandPartnerLinkService.php:11`).
- **If the user is already a partner of another brand**: `RuntimeException('You are already connected to a brand partner. Disconnect from your current brand partner before connecting to a new one.')` — surfaced as: "You're already connected to **{existing_brand_name}**. Leave that partnership before joining a new one."

**Rate limiting (audit CFG-3 — values live in `config/sidest.php` per §28.8):**
- 10 attempts per IP per minute
- 100 per IP per hour
- After 5 failed attempts on the same IP, response delays 2 seconds
- Rate-limiter key: `request->header('CF-Connecting-IP') ?? request->ip()`
- Each attempt logged via `BrandSignupCodeAuditEntry` (see §34) for forensics

**Rotation:** brands rotate from the dashboard. Generates a new opaque string; old code is dead immediately; rotation event recorded.

**Deactivation:** sets `signup_code_active = false`. Code remains in column for audit history.

**Code visibility audit (dashboard surface):**
- "Successful claims via your current code in the last 30 days: N"
- "Attempts against your rotated/old codes in the last 7 days: M"
- "Last rotation: <date>"

## 34. BrandSignupCodeAuditEntry — audit log for code lifecycle

Reuses the existing audit-log pattern (`StaffAuditEntry`, `ProfessionalDeletionAuditEntry`, `WalletCurrencySwitchAudit`). New table:

```sql
-- To revert: DROP TABLE IF EXISTS brand.signup_code_audit;
CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA extensions;  -- audit MIG-5 guard

CREATE TABLE brand.signup_code_audit (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  brand_profile_id uuid NOT NULL REFERENCES brand.brand_profiles(id),
  event text NOT NULL CHECK (event IN ('generated','rotated','deactivated','reactivated','claimed','failed_claim')),
  actor_type text NOT NULL CHECK (actor_type IN ('system','brand','staff','public')),
  actor_professional_id uuid REFERENCES core.professionals(id),
  staff_user_id uuid,
  source_ip text,
  code_prefix_hash text,
  joined_professional_id uuid,
  created_at timestamptz NOT NULL DEFAULT now(),
  CHECK ((event = 'claimed' AND joined_professional_id IS NOT NULL) OR (event <> 'claimed'))
);

CREATE INDEX ON brand.signup_code_audit (brand_profile_id, event, created_at DESC);
```

The `CREATE EXTENSION IF NOT EXISTS pgcrypto` guard (audit MIG-5) ensures the migration is portable to fresh CI databases or restored backups where pgcrypto may not yet be enabled.

**Model location:** `App\Models\Core\Professional\BrandSignupCodeAuditEntry` (sibling of `BrandProfile`, `BrandPartnerLink`, `BrandAffiliateInvite`, `BrandPartnerLinkEvent`).

## 35. Brand dashboard UI for the code

Out of scope for this plan (UI work). Data layer:
- `GET /api/professional/brand/signup-code` returns code + active status + rotation timestamp + dashboard aggregates
- `POST /api/professional/brand/signup-code/rotate` regenerates
- `POST /api/professional/brand/signup-code/deactivate` toggles

## 36. Migration for existing brands

Three discrete steps. The `creating` Eloquent hook does NOT fire when saving existing rows, so backfill MUST call the generator explicitly.

**Step 1: add columns nullable** (`<ts1>_add_brand_signup_code_to_brand_profiles.sql`):

```sql
-- To revert: ALTER TABLE brand.brand_profiles DROP COLUMN signup_code, DROP COLUMN signup_code_active, DROP COLUMN signup_code_rotated_at;
ALTER TABLE brand.brand_profiles
  ADD COLUMN signup_code text,
  ADD COLUMN signup_code_active boolean NOT NULL DEFAULT true,
  ADD COLUMN signup_code_rotated_at timestamptz;
```

**Step 2: Artisan backfill** (`app/Console/Commands/BackfillBrandSignupCodes.php`):

```php
// php artisan brand:backfill-signup-codes
foreach (BrandProfile::whereNull('signup_code')->cursor() as $brand) {
    $brand->signup_code = app(BrandSignupCodeService::class)->generate();
    $brand->saveQuietly();
}
```

**Step 3: enforce NOT NULL + uniqueness via CONCURRENTLY pattern (audit SCALE-3)** (`<ts2>_enforce_brand_signup_code_constraints.sql`):

```sql
-- To revert: see migration body for restoration.
-- This file is split into two phases because CREATE UNIQUE INDEX CONCURRENTLY
-- cannot run inside a transaction block. Phase A runs without wrapper; phase B
-- (constraint promotion + NOT NULL) runs in a transaction.

SET lock_timeout = '2s';
SET statement_timeout = '60s';

-- Phase A: build the unique index without ACCESS EXCLUSIVE.
-- NO transaction wrapper for this statement.
CREATE UNIQUE INDEX CONCURRENTLY brand_profiles_signup_code_unique
    ON brand.brand_profiles (signup_code);
```

The `ADD CONSTRAINT … UNIQUE USING INDEX` promotion and the NOT NULL guard run in a separate follow-up migration (`<ts3>_promote_brand_signup_code_constraints.sql`) wrapped in a transaction:

```sql
-- To revert: see header — drop the unique constraint and the NOT NULL.
BEGIN;

DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM brand.brand_profiles WHERE signup_code IS NULL) THEN
    RAISE EXCEPTION 'backfill incomplete: % rows have NULL signup_code',
      (SELECT count(*) FROM brand.brand_profiles WHERE signup_code IS NULL);
  END IF;
END $$;

ALTER TABLE brand.brand_profiles
  ALTER COLUMN signup_code SET NOT NULL,
  ADD CONSTRAINT brand_profiles_signup_code_unique
    UNIQUE USING INDEX brand_profiles_signup_code_unique;

COMMIT;
```

The two-phase pattern matches the v2 audit's SCALE-3 recommendation: `CREATE UNIQUE INDEX CONCURRENTLY` first (no ACCESS EXCLUSIVE row scan), then promote the index into a constraint cheaply. The `commit 526c9f80` exemption for brand-new-column indexes does NOT apply because by step 3 the column has been populated by the backfill.

**Concurrent-insert safety:** because the `creating` Eloquent hook fills the code at the application layer, new brands created during the backfill window always have a code.

---

# PART 7 — Cost breakdown

## 37. The pricing model

Per page view:
- 1 inbound request to the router Worker (billable on the first cache miss; cached hits served without invoking the Worker)
- 1 KV read (handle lookup, only on cache miss)
- A Service Binding call to the Astro Worker (NOT billable as a separate request)
- Astro Worker runs (CPU time billable on cache miss)
- Subrequest from Astro to backend `/api/public/profiles/{handle}`

Cached responses bypass the Worker entirely — $0.

**Workers Free tier:** 100K Worker requests/day; 10ms CPU/invocation; KV 100K reads/day, 1K writes/day.

**Workers Paid ($5/month base):** 10M requests/month included ($0.30/M after); 30M CPU-ms/month included ($0.02/M after); KV 10M reads/month included ($0.50/M after); KV 1M writes/month included ($5.00/M after).

**Subrequest limits:** Free 50/invocation; **Paid 10,000/invocation by default**, configurable up to 10M. Service Binding hops: max 32 per request chain (we use 1).

**Static asset bandwidth: free and unlimited.**

**Cache purge API: no per-call charge**; rate-limited per plan.

**Workers Builds:** metered in MINUTES. Free: 3,000 min/month, 1 concurrent. Paid: 6,000 min/month, 6 concurrent, +$0.005/min over.

**Astro SSR CPU estimate:** 10–50ms typical. At 10ms, Paid CPU budget supports 3M invocations comfortably; at 50ms, 600K. Measure during Phase 3.

## 38. Cost by traffic tier (80% cache hit applied consistently)

Per 1M page views with 80% edge-cache hit rate: 200K Worker invocations + 200K KV reads.

| Page views/month | Worker invocations | Monthly cost |
|------------------|--------------------|--------------|
| Up to ~15M | up to ~3M | **$0** (Free tier; 100K req/day × 30 days; with 80% cache hit) |
| 15M – 50M | 3M – 10M | **$5** (Workers Paid base; all within included quotas) |
| 50M – 100M | 10M – 20M | **$5 – $8** ($5 base + ~$0.30/M Worker overage over 10M) |
| 100M – 500M | 20M – 100M | **$8 – $32** |
| 500M – 1B | 100M – 200M | **$32 – $62** |

## 39. Cost by individual user count (assuming ~500 views/user/month — conservative)

| Users | Approx views/mo | Worker invocations (80% cache) | Monthly cost |
|-------|-----------------|--------------------------------|--------------|
| 100 | 50K | 10K | **$0** |
| 1,000 | 500K | 100K | **$0** |
| 10,000 | 5M | 1M | **$0** (still Free tier) |
| 50,000 | 25M | 5M | **$5** |
| 100,000 | 50M | 10M | **$5** |
| 500,000 | 250M | 50M | **~$17** |
| 1,000,000 | 500M | 100M | **~$32** |

## 40. Comparison to alternative deploys

- **Vercel Pro:** $20/month + 1 TB included + $0.40/GB after. At 100M page views × ~50KB/page = ~5 TB/month = **~$1,640/month**.
- **AWS S3 + CloudFront:** ~$0.085/GB. Same 5 TB = $425/month + Lambda costs.
- **Cloudflare Workers Static Assets (chosen):** **~$5/month** at 100M views. Roughly two orders of magnitude cheaper.

## 41. Caveats

- Free tier limits are daily (100K Worker requests/day). Traffic spike on a single day throttles until midnight UTC.
- KV reads are the constraint that bites first at scale — worth caching KV results at the router Worker level (out of Phase 1 scope).
- The 80% cache hit assumption is conservative. With profile pages rarely changing, 90%+ is plausible.
- **The 80% cache hit assumption depends entirely on §18's cache mechanism working.** If the router doesn't call `caches.default.put()`, hit rate is 0% and every page view invokes the chain — multiplying Worker invocations by 5×.

---

# PART 8 — Existing code map

## 42. Code that becomes dead / removable

| Item | What | When removable |
|------|------|---------------|
| `SyncSubdomainToKvJob` delete-on-no-link branch | Replaced with a write of `{type:'individual'}` | Phase 1 |
| Legacy `'affiliate'` cart-attribute key (only) lookup in `ProcessShopifyOrderUpdatedWebhookJob` | Replaced with primary `_partna_affiliate_id` lookup + legacy fallback (LIFE-1) | Phase 1 |
| Hydrogen `app/themes/theme-2..5` placeholder folders | Source of truth moves to `partna-themes` | Phase 3 |
| `Professional::isBrand()` (eventually) | After all callers migrate to `account_type` | Future cleanup, post-Phase 1 |
| `core.professionals.professional_type` column | After dual-write, after all readers migrate | Future cleanup, post-Phase 1 |

## 43. Code that shifts or extends

| File / module | Today | Change |
|---------------|-------|--------|
| `Partna-Frontend/lib/account-capabilities.ts` | 2 states | 3 states |
| `Comet-Backend/app/Models/Core/Professional/Professional.php` | `professional_type` reads | Adds `account_type` cast + accessors + `has_historical_partner_links` cast |
| `Comet-Backend/app/Models/Core/Professional/BrandPartnerLink.php` | `HasUuids` only | Adds `SoftDeletes` trait (§28.16) |
| `Comet-Backend/app/Http/Controllers/Api/PublicSite/BootstrapController.php` | Signup; sets `professional_type` | Explicit `account_type` per §32. Dual-writes both columns. |
| `Comet-Backend/app/Jobs/Cloudflare/SyncSubdomainToKvJob.php` | Two/three branches, delete-on-no-link | Three branches; adds `ShouldBeUnique` keyed by professionalId (JOB-2) |
| `Comet-Backend/app/Observers/Core/SiteObserver.php` | Brand-related KV sync only | Adds `CloudflareCachePurgeJob` dispatch on every save (CACHE-2) |
| `Comet-Backend/cloudflare-worker/src/index.js` | Two branches | Three branches; Service Binding; cache-API population; staging block |
| `Partna-Hydrogen/app/lib/engines/newsletter.ts` | Hard-coupled to `useFetcher` | Accepts injected submitter |
| `Partna-Hydrogen/app/themes/theme-1/layout.tsx` | Local imports | Imports from `@partna/themes/theme-1` + local Shop section |
| `Partna-Hydrogen/app/components/expandable/ShopExpandableCard/` | This path | Moves to `app/themes/theme-1/sections/Shop/` |
| `Partna-Frontend/app/(app)/account/(auth)/sign-up/signup-form.tsx` | 8-step flow | Adds optional brand_signup_code input |
| Notification dispatcher jobs (10 in `app/Jobs/Notifications/`) | Sends to anyone subscribed | Adds `AccountCapabilities` check + `report($e)` in `failed()` for the 4 missing ones (JOB-1) |
| `Partna-Frontend/app/(app)/account/(dashboard)/settings/settings-sections.tsx` | `professional_type` checks | `AccountCapabilities`-driven |
| Brand invite acceptance endpoint | Creates `BrandPartnerLink` | Additionally dispatches `AccountTypeTransitionService` |
| Brand-disconnect / partner-removal flow | Hard-deletes `BrandPartnerLink` | Soft-deletes; dispatches `AccountTypeTransitionService::transition($pro, AccountType::Individual)` |
| `Comet-Backend/app/Services/Professional/Brand/BrandStatusService.php` | Unguarded read-modify-write (LIFE-2) | Wraps `sync()` in `DB::transaction` with `lockForUpdate()` |
| `Comet-Backend/app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php` | Legacy-only affiliate lookup (LIFE-1) | Try `_partna_affiliate_id` first, fall back to legacy handle key |
| `Comet-Backend/app/Console/Commands/PurgeSoftDeleted.php` | Missing Block (DATA-4) | Adds `Block::class` + sweep test |
| `Comet-Backend/app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php` (JOB-1) | `Log::error` only in `failed()` | Adds `report($e);` as first line |
| `Comet-Backend/app/Jobs/Notifications/SendBrandStatusNotificationJob.php` | Same | Same |
| `Comet-Backend/app/Jobs/Notifications/NudgeStuckOnboardingJob.php` | Same | Same |
| `Comet-Backend/app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php` | Same | Same |
| `Comet-Backend/app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php` | `failed()` mutates metadata, no report | Adds `report($e);` as first line |
| `Comet-Backend/app/Http/Resources/ProfessionalDashboardResource.php` | `professional_type` only | Adds `account_type` (API-2); gates `stripe_connect_status` via capability (API-1) |
| `Comet-Backend/app/Http/Resources/ProfessionalResource.php` | Same | Same |

## 44. Code that stays completely untouched

- All Hydrogen cart logic (`app/lib/cart/`)
- All Hydrogen product engines (`cart.server.ts`, `products.server.ts`, `share-link.server.ts`)
- Shopify Storefront API integration; Shopify Admin API integration
- Stripe Connect onboarding, payout, refund flows (gated off for individuals via capabilities)
- Brand Shopify install wizard
- Order processing, commission accrual, payout settlement
- Existing brand-partner storefronts on Oxygen
- DNS configuration for `*.partna.au`
- Cloudflare KV namespace `SUBDOMAIN_KV` (adds third value type only)
- Auth (Supabase) integration
- Tax info collection (gated off for individuals)
- Webhook handlers (gated for individual events that arrive somehow)
- Existing notification jobs and Email preference rows
- `BrandPartnerLinkAuditor` audit log

## 45. New code being added (rollup)

**Backend (Comet-Backend) — migrations:**
- `<ts1>_add_account_type_column_and_backfill.sql` (§28.1)
- `<ts2>_add_account_type_constraints_and_trigger.sql`
- `<ts3>_validate_and_promote_account_type.sql`
- `<ts4>_add_account_type_covering_index.sql` (CONCURRENTLY, no transaction wrapper — audit MIG-1)
- `<ts>_add_soft_deletes_to_brand_partner_links.sql` (§28.16 Migration A, dual indexes + has_historical_partner_links column)
- `<ts>_brand_partner_link_events_set_null_fks.sql` (§28.16 Migration B — audit DATA-3 part B)
- `<ts>_update_brand_partner_link_rls_for_soft_delete.sql` (§28.16 Migration C — audit SCHEMA-2)
- `<ts>_brand_status_history_set_null_professional_fk.sql` (audit DATA-1)
- `<ts>_brand_status_history_audit_unique.sql` (LIFE-2 belt-and-suspenders unique index — fine to add inline if small table)
- `<ts1>_add_brand_signup_code_to_brand_profiles.sql` (§36)
- `<ts2>_enforce_brand_signup_code_constraints.sql` (CONCURRENTLY phase — audit SCALE-3)
- `<ts3>_promote_brand_signup_code_constraints.sql` (constraint promotion + NOT NULL)
- `<ts>_create_brand_signup_code_audit.sql` (§34, with pgcrypto IF NOT EXISTS guard per audit MIG-5)
- **DATA-2 follow-up migration: deferred pending verification of the missing migration file** (see Part 16)

**Backend — new application code:**
- `app/Enums/AccountType.php`
- `app/Services/Accounts/AccountCapabilities.php`
- `app/Services/Accounts/AccountCapabilitySet.php`
- `app/Services/Accounts/AccountTypeTransitionService.php`
- `app/Services/Professional/Brand/BrandSignupCodeService.php`
- `app/Models/Core/Professional/BrandSignupCodeAuditEntry.php`
- `app/Console/Commands/BackfillBrandSignupCodes.php`
- `app/Console/Commands/BackfillIndividualKvEntries.php` (audit CACHE-3 one-off backfill)
- `app/Events/Accounts/AccountTypeTransitionEvent.php`
- `app/Exceptions/InvalidAccountTypeTransition.php`
- `app/Listeners/Accounts/*` (one per side-effect)
- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Http/Controllers/Api/Professional/Brand/BrandSignupCodeController.php`
- `app/Observers/Core/Professional/BrandPartnerLinkObserver.php` (extend existing — adds `has_historical_partner_links` maintenance per SCALE-1)
- Architecture tests (§51): `SubdomainKvWritersTest`, `ThemePackageImportsTest`, `CapabilityDispatchTest`, `CapabilityEagerLoadTest`, `AccountTypeTransitionRulesTest`, `TransitionServiceTransactionBoundaryTest`, `AstroWorkerRouteTest`
- Feature tests (§28.15) — realistic estimate ~100–150 test cases total

**Cloudflare Worker:**
- `cloudflare-worker/src/index.js` — individual branch with cache + Service Binding
- `wrangler.toml` — production + staging Service Binding declarations

**Shared package (`partna-themes` — new):**
- Full repo structure per §23
- CI checks: Shopify-import grep, framework-import grep

**Astro app (`partna-pages` — new):**
- Full repo structure per §20 / §47
- Dev workflow per §21

**Frontend (Partna-Frontend):**
- Updates per §31

---

# PART 9 — Implementation plan, structured for two-developer parallel execution

## 46. Track ownership

Two tracks run in parallel, each with a Claude session driven by one developer.

**Track A — Backend (Comet-Backend, owned by backend developer):**

The standalone-pages scope:
- Account type column + migration + backfill — 4 discrete migration files per §28.1 (audit MIG-1..7)
- **BrandPartnerLink soft-delete migration** + dual indexes + `brand_partner_link_events` FK fix + RLS policy update (§28.16; audit DATA-3 / DINT-1 / SCHEMA-1 / SCHEMA-2)
- `has_historical_partner_links` denormalized column + observer (§28.16; audit SCALE-1)
- AccountType enum at `app/Enums/AccountType.php` (§28.2) + standardise `app/Enums/` location in CLAUDE.md
- AccountCapabilities registry + reconciliation with existing `AccountTypeDefaultsService` (§28.3) + memoization (audit SCALE-1)
- AccountTypeTransitionService with `lockForUpdate()` + transaction-boundary architecture test (§28.4; audit SCALE-2) + events + listeners (§28.5)
- SyncSubdomainToKvJob individual branch (§28.6) + `ShouldBeUnique` lock (§28.6a; audit JOB-2/SCALE-4)
- One-off backfill command `BackfillIndividualKvEntries` (audit CACHE-3)
- CloudflarePurgeService + Job (§28.7) + `.env.example` + `config/services.cloudflare` entries (audit CFG-2) + NEW dispatch sites in `SiteObserver` / `AccountTypeTransitionService` (audit CACHE-2) + `report($e)` in `failed()` (audit JOB-1)
- Public profile API endpoint with `CF-Connecting-IP` rate limiting (§28.8); rate-limit values in `config/sidest.php` (audit CFG-3)
- Resource capability gating + `account_type` field added (§28.8a; audit API-1, API-2)
- Brand signup code mechanism (§28.9, Part 6 / §32–§36) — at `app/Services/Professional/Brand/BrandSignupCodeService.php`
- BrandSignupCodeAuditEntry model + audit migration (§34) with `pgcrypto` IF NOT EXISTS guard (audit MIG-5)
- Artisan command `BackfillBrandSignupCodes` (§36)
- Brand signup code unique-index promotion using CONCURRENTLY + ADD CONSTRAINT USING INDEX pattern (audit SCALE-3)
- Notification preferences capability filtering (§28.10)
- **Feature gating wired through existing notification/Stripe/Commission/webhook/middleware/resource/controller code (§28.11)** — ~40–55 distinct touch sites; LARGEST line item
- Test coverage for affected policies (audit TEST-2 scoped subset — only policies §28.11 touches)
- `config/sidest.individual_waitlist_enabled` (audit CFG-1) + `.env.example` entry + BootstrapController integration
- `config/partna.professional_types` — verify update needed or document why it stays as-is
- `AccountTypeDefaultsService::resolveDefaults()` audit (§28.3)
- `CommissionPolicy` migration to `account_type` reads (subset of §28.11)
- `ProfessionalDashboardResource` includes `account_type` field (cross-track coordination)
- Brand invite acceptance → AccountTypeTransitionService wiring (§28.12)
- Bootstrap flow update (§28.13)
- Individual waitlist flag (§28.14)
- Architecture tests + feature tests (§28.15, §51) — realistic estimate ~100–150 test cases total
- Constraint-rejection tests (audit TEST-3) explicitly named in §28.15a
- Model creation hook tests (audit TEST-5) explicitly named in §28.15b
- Migration headers carry `-- To revert: …` (audit MIG-6) — checklist item

**Track A absorbed bugs (existing-code 🅲 findings in the plan's blast radius — §28.17):**
- DATA-1: `brand_status_history` CASCADE → SET NULL migration ✓
- DATA-3 part B: `brand_partner_link_events` RESTRICT → SET NULL (paired with §28.16 Migration B) ✓
- DATA-4: Add `Block` to `PurgeSoftDeleted`; add sweep test ✓
- JOB-1 / OBS-2: 5 jobs missing `report($e)` in `failed()` — fix the 4 unverified + verify-then-fix the 5th ✓
- JOB-2 / SCALE-4: `SyncSubdomainToKvJob` `ShouldBeUnique` (already absorbed via §28.6a) ✓
- LIFE-1: `ProcessShopifyOrderUpdatedWebhookJob` affiliate-id lookup priority ✓
- LIFE-2: `BrandStatusService::sync()` transaction + lockForUpdate ✓
- TEST-2 (scoped): policies §28.11 touches get dedicated ability tests ✓
- API-1, API-2: Resource capability gating ✓
- DATA-2: ON-HOLD until the migration file referenced by the audit actually lands in the codebase (see Part 16)
- SEC-1: VERIFIED NOT BROKEN — re-reading PolicyCoverageTest shows the audit was outdated; no Track A code change needed (see Part 15 §59c + Part 16)

**Out of Track A scope (flagged for separate PRs in Part 15 §59c):** SEC-2 (`SUPABASE_JWKS_FAIL_CLOSED`), SEC-3 (JWKS key warming), OBS-1 (Square/Fresha — deferred per "booking/Fresha/Square being dropped" project note), OBS-3 (`ReconcileStuckPayoutsJob`), JOB-3 (`SyncCustomerMarketingOptInJob`), TEST-1 (Shopify secondary webhook HMAC tests), TEST-2 (the seven policies §28.11 doesn't touch).

**Track A realistic effort: 5–7 weeks of focused solo backend work.** Earlier "3–5 weeks" estimate from the prior backend review pre-dated this audit pass. The added Track A items raise the realistic effort:
- The capability-gating surface area (~40–55 sites) was already the long pole;
- The migration safety re-split (MIG-1..7) plus the new RLS migration plus the FK-cascade fixes plus the denormalized column + observer maintenance plus the `report($e)` retrofit plus the LIFE-1/LIFE-2 fixes plus the constraint-rejection / hook tests collectively add ~1.5–2 weeks of careful sequential work that mostly can't be parallelized with the existing Track A items (because they share migration ordering or the same model files).

**Track B — Themes / Astro / Worker / Cloudflare / Frontend (owned by user):**

- Hydrogen prep: newsletter refactor (§30.1), Shop section refactor (§30.2), file audit (§30.4)
- Shared theme package extraction → `partna-themes` repo (§23, §24, §25, §26)
- CI grep checks in `partna-themes` for Shopify + framework imports (§24)
- Astro app build → `partna-pages` repo (§20, §21)
- Cloudflare Worker individual branch + cache + Service Binding (§29)
- Cloudflare Workers Static Assets project setup (production + staging)
- Hydrogen theme 2–5 scaffold cleanup (§30.3)
- Partna-Frontend account-capabilities.ts extension (§31.1)
- Partna-Frontend signup-form.tsx adjustment (§31.2)
- Partna-Frontend `/account/design` universal adaptation (§31.3)
- Partna-Frontend settings-sections.tsx update (§31.4)
- Consume `account_type` from `ProfessionalDashboardResource` (cross-track dependency on Track A; addressed in §28.8a)

**Joint:**
- End-to-end integration testing (§58)
- Cross-track architectural decisions (§49 STOP protocol)

**Cross-track coordination with other in-flight backend work:**
- **MFA Foundation** (planned 2026-05-18): same model file as §28.2. Sequence: do §28.1 + §28.2 FIRST.
- **Async commission export** (planned 2026-05-19): touches §28.11. Sync before either lands.
- **Handle redirect lifecycle** (merged 2026-05-18): shares `SyncSubdomainToKvJob`. §28.6 changes only the `else` branch.
- **Open backend audits** (`audits/` directory): policy coverage + feature-flag hygiene overlap with §28.11.

## 47. Phases with explicit handoff gates

**Gate G0 — Plan locked + both tracks ready to start.**

Before either track touches code, both confirm the following readiness items. Continuous execution mode — both Claude sessions running in parallel until G5, no scheduled breaks between phases. Coordination happens in real time via §49.

**Both tracks confirm:**
- Latest plan pulled (this file, v4 with all 16 parts and the §60 / Part 16 resolution log)
- The consolidated audit read (`PARTNA-STANDALONE-PAGES-AUDIT-CONSOLIDATED.md`)
- Their repo's `CLAUDE.md` updated with the architectural ground-truth section
- 4 plan-specific skills installed at `~/.claude/skills/` (`partna-plan-check`, `account-capability-audit`, `theme-portability-check`, `partna-handoff-status`)
- Cloudflare MCP installed via `/plugin install cloudflare@cloudflare` (OAuth-authed to the partna.au account)
- Per-repo `.claude/settings.local.json` hooks active (PreToolUse nudges on plan-blast-radius files)
- `IMPLEMENTATION-STATUS.md` sync mechanism agreed and operational (see below)

**`IMPLEMENTATION-STATUS.md` sync — REQUIRED before Phase 1:**

Both developers' Claude sessions update this file via the `partna-handoff-status` skill. Sync mechanism (pick one, lock at G0):
- **Option A (recommended):** new small shared repo `partna-implementation-status` with a single `IMPLEMENTATION-STATUS.md` file. Both devs clone, both commit-push on update, both pull at session start. Lowest ambiguity.
- **Option B:** commit to `Comet-Backend/docs/IMPLEMENTATION-STATUS.md`. Reuses an existing shared repo but couples coordination to a backend repo, awkward for Track B updates.
- **Option C:** shared Google Doc + manual updates. Non-Claude, fastest to set up, no commits, but the `partna-handoff-status` skill needs to be tuned (or skipped).

The skill auto-detects which mechanism is in use based on whether `~/Developer/partna-implementation-status/` exists.

**Phase 1 (parallel, no inter-dependency):**

| Track A | Track B |
|---------|---------|
| Account type migration set (4 files per §28.1) | Hydrogen newsletter refactor |
| BrandPartnerLink soft-delete + RLS + FK fix + denormalized column | Hydrogen Shop section refactor |
| DATA-1 brand_status_history FK fix | |
| AccountType enum + AccountCapabilities service | |
| JOB-1 `report($e)` retrofit on 5 jobs | |
| DATA-4 PurgeSoftDeleted + sweep test | |
| Backend tests pass | Hydrogen tests pass after refactors |

**Gate G1 — Foundations in place.**

**Phase 2 (parallel):**

| Track A | Track B |
|---------|---------|
| AccountTypeTransitionService + listeners (+ transaction-boundary test) | Theme package extraction into `partna-themes` repo |
| SyncSubdomainToKvJob individual branch + ShouldBeUnique | GitHub Packages publish setup |
| BackfillIndividualKvEntries one-off command + run | Hydrogen migrates to consume `@partna/themes` v0.x |
| Public profile API endpoint + Resource capability gating | Hydrogen still renders identically on dev |
| CloudflarePurgeService + CFG-2 config | |
| LIFE-1, LIFE-2 fixes | |

**Gate G2 — Routing infrastructure ready, package live.**

**Phase 3 (Track B blocks on G2):**

| Track A | Track B |
|---------|---------|
| Capability gating wired through notification jobs | Astro app init in `partna-pages` repo |
| Notification preferences capability filter | Astro middleware: read Host → handle |
| Brand signup code migrations + service + audit table | Astro renders theme-1 with profile fetched |
| Tests for capability gates | Cloudflare Workers Static Assets deploy succeeds (staging) |
| §28.11 Stripe / CommissionPolicy / middleware migrations | Hydrogen theme 2–5 scaffold deletion |
| Architecture tests for KV writers + import checks + eager-load | |

**Gate G3 — Astro renders, capability gating wired, cache verified.**
- Service Binding preserves Host header (smoke test per §16). If not, fall back to `X-Partna-Handle`.
- Router's `caches.default.put()` populates and `match()` returns cached responses; cache-purge job clears them.

**Phase 4 (parallel, both block on G3):**

| Track A | Track B |
|---------|---------|
| Brand invite acceptance → AccountTypeTransitionService wiring | Cloudflare Worker individual branch + Service Binding deployed to staging |
| Brand signup code dashboard endpoints | Partna-Frontend account-capabilities.ts extended to 3 states |
| AccountTypeTransitionEvent listeners | Partna-Frontend signup-form.tsx adjusted |
| | Partna-Frontend `/account/design` universal |
| | Partna-Frontend settings-sections.tsx updated |

**Gate G4 — End-to-end pieces in place.**

**Phase 5 (JOINT — scheduled session):**
- Worker production deploy with individual branch + Service Binding + cache logic
- Backend writes test individual KV entries
- End-to-end verification via §58 checklist

**Gate G5 — Production-ready.**

## 48. Wait/review gates — explicit dependency points

- **Track B waits at G2** before starting Astro work.
- **Both tracks wait at G4** before joint Phase 5.
- **Worker production deploy gated on Phase 5.**

## 49. Communication protocol and the big-decision pattern

**49.1. Shared status file: `~/Developer/IMPLEMENTATION-STATUS.md`** (created at Phase 1 kickoff).

```markdown
# Implementation Status — Individual Sitepages

Updated: <ISO timestamp> by <track>

## Track A (Backend)
### Current phase
### Last completed
### In progress
### Blocked on
### Notes for Track B

## Track B (Frontend / Themes / Astro / Worker)
### Current phase
### Last completed
### In progress
### Blocked on
### Notes for Track A
```

**49.2. GitHub PR cross-reviews.** "Does this match the plan?"

**49.3. claude-mem per-developer corpus.** Per-machine; coordination via IMPLEMENTATION-STATUS.md + PR reviews, not claude-mem.

**The big-decision STOP protocol.** When a Claude session encounters a decision not covered by the plan, it MUST stop and ask.

```
[STOP — PLAN DECISION NEEDED]

Context: <one sentence>
Decision: <one sentence>
Options:
  A) <option A> — implications: <…>
  B) <option B> — implications: <…>
Recommendation: <which one and one-sentence why>
Affects other track: <yes/no>
Plan section impacted: <e.g. "§43 row">
```

---

# PART 10 — Non-negotiable rules, conventions, and enforcement

## 50. The 12 non-negotiable rules

Violating any of these is a planning failure, not a coding shortcut.

1. **Brand status is set at signup only — no promotion path, no demotion path.** `AccountTypeTransitionService` rejects ALL transitions where `from === 'brand'` AND ALL where `to === 'brand'`. *Enforced: service-layer rejection + dedicated unit tests + architecture test asserting only `BootstrapController` writes `account_type='brand'` outside backfill.*
2. **Themes don't share visual code with each other.** *Enforced: architecture test greps for `from '../theme-N'` imports within sibling theme directories.*
3. **The shared `@partna/themes` package contains no Shopify imports.** *Enforced: CI grep.*
4. **The shared package contains no framework imports.** No `react-router`, `@remix-run/*`, `astro:`, `next/*`. *Enforced: CI grep parallel to #3.*
5. **Account type is set explicitly at signup (`BootstrapController`) and post-creation mutations go through `AccountTypeTransitionService` only.** **Exception:** the one-time §8 backfill SQL derives `account_type` from BrandPartnerLink presence by design. *Enforced: architecture test asserting no `Professional::query()->update(['account_type' => ...])` outside `AccountTypeTransitionService` and the migration files.*
6. **Per-affiliate styling overrides do not exist.** *Enforced: architecture test in `partna-themes` greps for `affiliate_id` / `partner_id` references in any `design`-typed code path.*
7. **Brand-fallback content stays in Hydrogen's data path.** *Enforced: feature test against `IndividualProfileController` response asserting absence of `placeholders`, `fallback_gallery`, `brand_logo`, `brand_slogan` keys.*
8. **The shared namespace is `Professional.handle`.** *Enforced: DB UNIQUE constraint.*
9. **All writes and deletes to `SUBDOMAIN_KV` go through jobs in `app/Jobs/Cloudflare/`.** *Enforced: architecture test `SubdomainKvWritersTest` greps every PHP file for calls to `CloudflareKvService::put(` / `CloudflareKvService::delete(` and asserts each match's file path begins with `app/Jobs/Cloudflare/`.*
10. **Capability checks happen at the dispatch layer, not just the UI layer.** *Enforced: architecture test `CapabilityDispatchTest` iterates every class in `app/Jobs/Notifications/` and asserts at least one reference to `AccountCapabilities`; same for `app/Http/Controllers/Api/`.*
11. **Partner → individual transitions are NEVER blocked by pending payouts.** *Enforced: feature test in §58.*
12. **`account_type` transitions TO brand and FROM brand are both forbidden at the service layer.** *Enforced: same as #1.*

## 51. Forbidden patterns and architecture tests

| Forbidden pattern | Where caught |
|---|---|
| Importing `@shopify/*` anywhere in `partna-themes` or `partna-pages` | CI grep (rule #3) |
| Importing from `react-router`, `@remix-run/*`, `astro:`, `next/*` in `partna-themes` | CI grep (rule #4) |
| Reading `professional_type` in new code (use `account_type`) | PreToolUse hook (§55) on Edit/Write in `Comet-Backend/app/` |
| Checking `! $pro->brandPartnerLinks` to mean "individual" | Architecture test grep |
| Calling `CloudflareKvService::put()` or `->delete()` outside `app/Jobs/Cloudflare/` | `SubdomainKvWritersTest` (rule #9) |
| Per-affiliate styling overrides | Architecture test (rule #6) |
| `account_type` transitions FROM `brand` OR TO `brand` | `AccountTypeTransitionService` unit tests (rule #12) |
| Notification dispatch without a capability check | `CapabilityDispatchTest` (rule #10) |
| Iterating professionals with `AccountCapabilities::for()` without eager-loading `brandPartnerLinks`/`brandPartnerLinksAll` | `CapabilityEagerLoadTest` (audit SCALE-1) |
| `::dispatchSync()` inside `AccountTypeTransitionService::transition()`'s `DB::transaction()` closure | `TransitionServiceTransactionBoundaryTest` (audit SCALE-2) |
| Hardcoded brand-fallback content in the Astro app | Feature test (rule #7) |
| Returning cacheable Worker responses without populating `caches.default` | Cloudflare Worker test asserting `ctx.waitUntil(cache.put(...))` is called on cacheable GET responses |
| New job's `failed()` method does not call `report($e)` as the first line | Architecture test `JobReportingDisciplineTest` greps every `app/Jobs/**/*.php` for `function failed` and asserts the next non-whitespace line is `report(` |
| `SyncSubdomainToKvJob` (and other rapid-fire KV jobs) not declaring `ShouldBeUnique` | Architecture test asserts `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php` implements `ShouldBeUnique` |
| Migration file containing `CREATE INDEX CONCURRENTLY` AND a `BEGIN`/`COMMIT` wrapper | Architecture test `MigrationTransactionBoundaryTest` greps the migrations directory for files with both patterns; fails on match |
| `ADD CONSTRAINT … CHECK` on `core.professionals` or `brand.brand_profiles` without `NOT VALID` (when adding to a non-empty table) | Migration review checklist; flagged in `partna-plan-check` skill |
| Backfill UPDATE in a `<ts1>_…_backfill_…` migration without `WHERE … IS NULL` guard | Migration review checklist |
| Adding a destructive or constraint-adding migration without a `-- To revert:` header comment | Architecture test `MigrationRevertHeaderTest` greps the migrations directory for migrations modifying schema and asserts a `-- To revert:` header line exists in the first 30 lines |
| New model with `use SoftDeletes` that isn't listed in `PurgeSoftDeleted` or `PURGE_EXEMPT` | Sweep test (audit DATA-4) |

## 52. Conventions (reviewed by humans, not auto-enforced)

- Prefer registry-pattern over `if`-chains for any account-type-dependent behavior
- Treat `IMPLEMENTATION-STATUS.md` as a living document
- When the plan and reality diverge, update the plan via the STOP protocol

---

# PART 11 — Claude tooling setup before implementation

## 53. MCPs each developer needs

**Both developers:**
- `claude-mem` (mcp-search) — cross-session memory (per-developer)
- `context7` — current library docs
- `github` — PR creation, review, cross-repo coordination

**User-side:**
- `chrome-devtools` — manual testing of the Astro app
- `vercel` — Partna-Frontend deploy verification
- `shopify-dev-mcp` — Hydrogen-side changes

**Backend-side:**
- `laravel-boost` — `tinker`, `database-query`, `database-schema`, `list-routes`, `search-docs`
- `supabase` — migrations and schema management

## 54. Project-specific skills to create

Create in `~/.claude/skills/partna-individual-pages/`:

1. **`partna-plan-check`** — auto-triggers when Claude modifies a file related to this plan. Reads the plan and verifies the change doesn't violate §50 / §51.
2. **`account-capability-audit`** — auto-triggers when adding a new notification job, API endpoint, or dashboard route.
3. **`theme-portability-check`** — auto-triggers when editing any file under `@partna/themes` or its source equivalents.
4. **`partna-handoff-status`** — manual skill that updates `IMPLEMENTATION-STATUS.md`.

## 55. Hooks per repo

In `.claude/settings.local.json`:

**`Partna-Hydrogen`:**
- `PreToolUse` on Edit/Write to `app/themes/**` — grep for `react-router` imports
- `PreToolUse` on Edit/Write to `app/lib/cart/**` — remind cart is Hydrogen-only

**`partna-themes`:**
- `PreToolUse` on Edit/Write across the repo — grep for `react-router|@shopify/|@remix-run/|astro:|next/`, BLOCK if found
- `PostToolUse` on Write — remind to bump package version if public-facing export changed

**`partna-pages`:**
- `PreToolUse` on Edit/Write — grep for `@shopify/` imports, block
- `PreToolUse` on Edit/Write to the Worker entry — remind that cacheable responses must call `ctx.waitUntil(cache.put(...))`

**`Comet-Backend`:**
- `PreToolUse` on Edit/Write to notification jobs — grep for `AccountCapabilities`, warn if absent
- `PreToolUse` on Edit/Write to any controller in `Api/` — same
- `PreToolUse` on Edit/Write to `app/Jobs/Cloudflare/*` — warn if any non-sibling file touches `CloudflareKvService::put`/`::delete` (rule #9)
- `PreToolUse` on Edit/Write to `supabase/migrations/*.sql` — check for the patterns flagged in §51 (CONCURRENTLY-inside-transaction, missing `-- To revert:`, missing `NOT VALID` on CHECK constraint, missing `WHERE … IS NULL` on backfill UPDATE)
- `PreToolUse` on Edit/Write to any `app/Jobs/**/*.php` `failed()` method — assert `report($e)` is the first non-whitespace line

## 56. Per-repo CLAUDE.md additions

Backend dev's CLAUDE.md adds:
- "`Professional.account_type` is the source of truth. `professional_type` is legacy. Don't read `professional_type` in new code."
- "Notification jobs and API endpoints MUST check `AccountCapabilities::for($pro)` before acting."
- "`SyncSubdomainToKvJob` and `RetireSubdomainFromKvJob` are the ONLY KV writers/deleters. All KV mutations go through `app/Jobs/Cloudflare/`."
- "`BrandPartnerLink` uses soft-delete. To query historical links, use `withTrashed()` via `brandPartnerLinksAll()`."
- "Every job's `failed()` method calls `report($e)` as its first line — Nightwatch only sees exceptions that way."
- "Account-type-iterating controllers / jobs MUST eager-load `brandPartnerLinks` and `brandPartnerLinksAll` before calling `AccountCapabilities::for()` per row."
- "Job dispatches that touch external HTTP (Cloudflare KV, cache purge) MUST happen AFTER `DB::transaction(...)` closes. Never use `::dispatchSync()` inside a transaction."
- "Use `app/Enums/` for new domain enums (precedents: `BrandStatus`, `AccountType`)."

User's frontend CLAUDE.md adds:
- "`lib/account-capabilities.ts` returns three-state capabilities. Every new dashboard route MUST be in the route allowlist."
- "`/account/design` is universal — same route for all account types, conditional UI via capability flags."
- "Consume `account_type` from `ProfessionalDashboardResource.account_type`, not from inferred state."

---

# PART 12 — Conceptual map drop-in for CLAUDE.md files

## 57. The drop-in insert (paste verbatim into each repo's CLAUDE.md)

```markdown
## Individual sitepages — architectural ground truth

Partna supports three account types stored as `Professional.account_type`:
- `brand` — Shopify-connected commerce operator (terminal; cannot transition away)
- `partner` — professional affiliated with a brand; sells on brand's storefront
- `individual` — professional with public profile sitepage; no commerce

All `<handle>.partna.au` requests route through one Cloudflare Worker
(`Comet-Backend/cloudflare-worker/`) that reads `SUBDOMAIN_KV` and uses
the Cache API:
- `{type:"brand"}` → pass-through to Hydrogen on Shopify Oxygen
- `{type:"affiliate", redirect}` → 301 to `<brand>.partna.au/<handle>` (Hydrogen)
- `{type:"individual"}` → caches.default.match; on miss, Service Binding to
  Astro app on Cloudflare Workers Static Assets; on success, caches.default.put

The Worker has TWO writers: `SyncSubdomainToKvJob` (writes), `RetireSubdomainFromKvJob`
(deletes). All KV mutations go through `app/Jobs/Cloudflare/`. Never write KV elsewhere.
`SyncSubdomainToKvJob` is `ShouldBeUnique` keyed by professionalId.

Both apps render from `@partna/themes` (GitHub Packages, per-theme bundles +
shared engines/brand/analytics/icons/motion). The package is Shopify-free
and framework-free. Hydrogen adds the Shop section locally; Astro doesn't.

Account capabilities (frontend: `lib/account-capabilities.ts`; backend:
`App\Services\Accounts\AccountCapabilities`) are the source of truth for
what features each type sees. Every notification dispatcher, route guard,
and API response checks capabilities before acting. Defence in depth.

The ONLY allowed `account_type` transitions are `individual ↔ partner`
(both directions), via `AccountTypeTransitionService`. Brand is set at
signup only by `BootstrapController` and never changes. Partner ↔ individual
is seamless; historical brand-partner data persists indefinitely in an
"ex-partner panel" via SOFT-DELETED `BrandPartnerLink` rows. RLS policies
on `brand_partner_links`, `brand_profiles`, `brand_store_settings` filter
`deleted_at IS NULL` on non-staff branches. Transitions are NEVER blocked
by pending payouts. Job dispatches happen AFTER `DB::transaction(...)`
closes — never inside.

Per-individual styling uses the existing per-Site `settings.design` JSONB.
Partners inherit brand styling (no per-affiliate overrides). Brand fallback
content lives ONLY in Hydrogen's data path.

Worker responses are NOT auto-cached from Cache-Control alone. The router
Worker MUST call `caches.default.put(request, response.clone())`. The
cache-purge job (`CloudflareCachePurgeJob`) invalidates by URL.

Every job's `failed()` method calls `report($e)` as its first line.

Full plan: `~/Developer/PARTNA-STANDALONE-PAGES-NEW-DIRECTION.md`.
```

---

# PART 13 — End-to-end verification

## 58. Verification checklist (run before Gate G5)

**Individual path:**
- [ ] Create a test individual professional via DB seed
- [ ] Their handle appears in `SUBDOMAIN_KV` with `{type:'individual'}`
- [ ] First visit to `<handle>.partna.au` → Worker checks cache (miss) → Service Binding → Astro renders theme-1 → all individual-applicable sections render
- [ ] Second visit hits the cache (verified via `cf-cache-status` header)
- [ ] Shop section is absent; no Shopify API calls in network tab
- [ ] Edit the test profile → cache purge fires → next visit shows updated content
- [ ] Visit an unknown handle → Worker 404s cleanly
- [ ] Analytics events from the individual page appear in backend dashboard
- [ ] Host header is preserved via Service Binding (smoke test per §16; fall back to `X-Partna-Handle` if not)

**Brand-partner path (regression check):**
- [ ] Existing brand-partner storefronts render identically to before
- [ ] Visit `<affiliate>.partna.au` → 301 redirect → Hydrogen with Shop section
- [ ] Cart/checkout flow works
- [ ] All existing Hydrogen tests pass

**Transitions:**
- [ ] individual → partner: account type updates, KV switches, Shop appears, order notifications enabled, `has_historical_partner_links` may flip
- [ ] partner → individual: account type updates, KV switches, Shop disappears, partner-only notifications disable, ex-partner panel becomes visible, `has_historical_partner_links` true
- [ ] **Partner with pending payouts CAN still transition to individual**
- [ ] **BrandPartnerLink rows are soft-deleted** (visible via `withTrashed()`, not via default scope)
- [ ] **Re-joining the same brand creates a NEW BrandPartnerLink row**
- [ ] Concurrent transition attempts fail safely (lockForUpdate)
- [ ] `AccountTypeTransitionService` rejects all four forbidden transitions with `InvalidAccountTypeTransition`
- [ ] Brand signup via `BootstrapController` correctly creates Professional with `account_type='brand'` directly
- [ ] Hydrogen renders existing brand-onboarding placeholder for brands with `brand_status ∈ {building, preview}`
- [ ] No `::dispatchSync()` inside `AccountTypeTransitionService::transition`'s `DB::transaction` (architecture test)

**Capability gating:**
- [ ] Individual user: API responses don't include order/commission fields
- [ ] Individual user: no Stripe Connect prompts; `stripe_connect_status` field absent for individuals (API-1)
- [ ] Individual user: `account_type` field present in dashboard resource (API-2)
- [ ] Individual user: doesn't receive partner-only notifications
- [ ] Partner user: full feature set works as today
- [ ] Brand user: brand features work as today
- [ ] `IndividualProfileController` response contains NO `placeholders`, `fallback_gallery`, `brand_logo`, `brand_slogan` keys (rule #7 + audit TEST-4)
- [ ] Eager-load test: controller list endpoints iterating professionals load `brandPartnerLinks` + `brandPartnerLinksAll` (SCALE-1)

**Brand signup code path:**
- [ ] Brand can view their signup_code in the dashboard
- [ ] Brand can rotate the code; old code stops working immediately
- [ ] Brand can deactivate the code
- [ ] Signup with active brand signup code creates Professional with `account_type='partner'` + BrandPartnerLink
- [ ] Single-brand cap respected; friendly error if user already has a brand
- [ ] Rate limiting kicks in after 10 attempts/min/IP (using `CF-Connecting-IP`)
- [ ] `BrandSignupCodeAuditEntry` rows are written for all events
- [ ] Brand-signup-code path is **exempt from individual waitlist**
- [ ] Constraint-rejection test: duplicate `signup_code` fails the unique constraint (TEST-3)
- [ ] Constraint-rejection test: `event='claimed'` with NULL `joined_professional_id` fails the compound CHECK (TEST-3)
- [ ] Model creation test: `BrandProfile::factory()->create()` generates a non-null 16-char code (TEST-5)

**Infrastructure:**
- [ ] Cloudflare Workers Static Assets deploy succeeds (production + staging)
- [ ] Worker handles all three KV types correctly
- [ ] Service Binding from router to Astro Worker works in production and staging
- [ ] Cache purge API token works and rate limits are respected
- [ ] Astro Worker has no public route in production (architecture test)
- [ ] `caches.default.put()` is called for individual-branch cacheable responses
- [ ] `CloudflarePurgeService` reads `config('services.cloudflare.cache_purge_token')` not direct `env()` (CFG-2)

**Migration safety:**
- [ ] All migrations in the §28.1 sequence have `-- To revert:` headers (audit MIG-6)
- [ ] `<ts4>` covering-index migration has NO transaction wrapper (audit MIG-1)
- [ ] CHECK constraints use `NOT VALID` + later `VALIDATE` (audit MIG-2, MIG-3)
- [ ] Backfill UPDATEs are guarded with `WHERE account_type IS NULL` (audit MIG-7)
- [ ] `<ts1>` adds column + backfills in one file (audit MIG-4)
- [ ] Signup-code audit migration includes `CREATE EXTENSION IF NOT EXISTS pgcrypto` (audit MIG-5)
- [ ] Signup-code unique constraint uses CONCURRENTLY + ADD CONSTRAINT USING INDEX pattern (audit SCALE-3)
- [ ] BrandPartnerLink soft-delete migration: dual indexes present (partial WHERE deleted_at IS NULL + composite); `brand_partner_link_events` FKs are SET NULL; three RLS policies updated atomically

**Architecture tests pass:**
- [ ] `SubdomainKvWritersTest` — only `app/Jobs/Cloudflare/` files call `CloudflareKvService::put/delete`
- [ ] `ThemePackageImportsTest` — no Shopify or framework imports in `partna-themes/src/`
- [ ] `CapabilityDispatchTest` — every notification job + every Api/ controller references `AccountCapabilities`
- [ ] `CapabilityEagerLoadTest` — iterating callers eager-load BPL relations
- [ ] `AstroWorkerRouteTest` — `partna-pages` has no public route
- [ ] `TransitionServiceTransactionBoundaryTest` — no `dispatchSync` inside `DB::transaction` block in `AccountTypeTransitionService`
- [ ] `MigrationTransactionBoundaryTest` — no migration has BOTH `CONCURRENTLY` and `BEGIN`/`COMMIT`
- [ ] `MigrationRevertHeaderTest` — every destructive/constraint migration has a `-- To revert:` header
- [ ] `JobReportingDisciplineTest` — every `failed()` method calls `report($e)` as first line
- [ ] `SoftDeletePurgeCoverageTest` (DATA-4) — every SoftDeletes model is in PurgeSoftDeleted or PURGE_EXEMPT
- [ ] RLS predicate test: non-staff Supabase REST query against `brand_partner_links` does NOT see soft-deleted rows; same for `brand_profiles`, `brand_store_settings`

**Data integrity:**
- [ ] `account_type` and `professional_type` stay in sync via the dual-write trigger
- [ ] Soft-deleted `BrandPartnerLink` rows are retained (no purge job runs against them)
- [ ] Ex-partner panel shows accurate historical data
- [ ] `commerce.orders.affiliate_professional_id` queries resolve correctly regardless of BPL soft-delete state
- [ ] `core.brand_status_history` retains rows after professional hard-delete (DATA-1)
- [ ] `brand_partner_link_events` retains rows after professional hard-delete with FKs set NULL (DATA-3 part B)
- [ ] Hard-delete a professional with link-event history: `PurgeSoftDeleted::forceDelete()` succeeds without FK violation

---

# PART 14 — Open questions resolved

All architectural questions have been resolved through iterative review:

1. ✅ Account types: `brand` / `partner` / `individual`
2. ✅ Pending payouts on partner→individual: NEVER blocked. Ex-partner state preserves history via soft-delete.
3. ✅ Brand transitions: NEITHER direction allowed. Brand is set at signup only.
4. ✅ Package distribution: GitHub Packages private registry.
5. ✅ Shop section refactor: bundle into theme extraction work.
6. ✅ `/account/design`: universal route with capability-driven UI.
7. ✅ Brand invite code: per-brand opaque code stored on `brand_profiles` (Part 6).
8. ✅ Brand-removes-partner with live data: trust architecture; verify in integration testing.
9. ✅ Partner of `systems_down` brand: stay as partner, show dashboard banner.
10. ✅ Individual waitlist flag: `SIDEST_INDIVIDUAL_WAITLIST_ENABLED`, default off; exempts partner-via-invite AND partner-via-signup-code (audit CFG-1).
11. ✅ Hosting target: Cloudflare Workers Static Assets.
12. ✅ Worker → Astro handoff: Service Binding.
13. ✅ Cache mechanism: explicit `caches.default.put()` in router Worker.
14. ✅ BrandPartnerLink soft-delete: REQUIRED migration (§28.16).
15. ✅ Brand promotion path: REMOVED.
16. ✅ Public profile endpoint auth: truly public, rate-limited 60/min/IP using `CF-Connecting-IP ?? request->ip()`.
17. ✅ Astro Worker local dev: three modes per §21.
18. ✅ claude-mem cross-developer sync: not used; coordination via IMPLEMENTATION-STATUS.md + PR reviews.
19. ✅ Skill directory: `~/.claude/skills/`.
20. ✅ Stripe state on partner→individual: stays at current value; enum has no 'inactive'.
21. ✅ `professional_type` legacy enum has THREE values; backfill enumerates all three.
22. ✅ `brand_status` enum vocabulary is lowercase.
23. ✅ `AccountTypeDefaultsService` already exists — keep separate from `AccountCapabilities`.
24. ✅ Rule #9 enforcement scope: `CloudflareKvService::put/delete` outside `app/Jobs/Cloudflare/`.
25. ✅ Rate limit IP source: `request->header('CF-Connecting-IP') ?? request->ip()`.
26. ✅ Model locations: `BrandSignupCodeAuditEntry` → `app/Models/Core/Professional/`; `BrandSignupCodeService` → `app/Services/Professional/Brand/`.
27. ✅ Dual-write trigger precedence: when both columns set inconsistently, new `account_type` wins.
28. ✅ Soft-delete + RLS: both layers — model global scope AND RLS predicates atomically updated with the soft-delete migration.
29. ✅ `AccountType` enum location: `app/Enums/AccountType.php`.
30. ✅ §28.11 capability gating scope: ~40–55 distinct touch sites.
31. ✅ Track A effort: 5–7 weeks solo backend work (revised from 3–5 weeks once 41-finding audit absorbed).
32. ✅ Cross-track coordination notes in Track A.
33. ✅ Migration sequencing (v4): 4 files per §28.1 (audit MIG-1..7 satisfied).
34. ✅ `BrandPartnerLink` events FK fix bundled with soft-delete migration (audit DATA-3 part B).
35. ✅ Three RLS policy updates explicitly named and atomically migrated (audit SCHEMA-2).
36. ✅ Denormalized `has_historical_partner_links` column (audit SCALE-1) added with observer maintenance.
37. ✅ Job dispatches always outside `DB::transaction` (audit SCALE-2) with architecture-test enforcement.
38. ✅ Existing 4 jobs miss `report($e)` in `failed()` — Track A retrofit (audit JOB-1).
39. ✅ Audit's claim about SEC-1 PolicyCoverageTest:38 verified-against-codebase as outdated; no fix needed (see Part 16).
40. ✅ Audit's DATA-2 (`handle_change_log` migration) refers to a file that doesn't exist in the codebase; ON-HOLD (see Part 16).
41. ✅ `config/sidest.php` houses rate-limit values (audit CFG-3) so they're tunable without redeploy.

---

# PART 15 — Audit findings resolution log (prior passes)

This appendix documents findings from the v2 audit and the backend developer review. Part 16 contains the consolidated v4 resolution table for the 14-lens consolidated audit (the 41 findings driving this rewrite).

## 59. Resolution table — v2 audit

(Preserved from the v3 plan; superseded only where Part 16 overrides.)

| Audit ref | Finding | Resolution | Section |
|-----------|---------|-----------|---------|
| v2 §3.1 | Astro version + Cloudflare acquisition date | Framework 6.3.1; adapter v13.5.2; acquisition Jan 16 2026 | §15 |
| v2 §3.2 | Subrequest limit on Workers Paid | 10,000 default, configurable to 10M | §16, §37 |
| v2 §3.2 | Service Bindings Host preservation | Smoke test at G3 with `X-Partna-Handle` fallback | §16, §47 |
| v2 §3.3 | Cache mechanism: Cache-Control alone | Rewrote §18 with explicit `caches.default.put()` | §18, §51 |
| v2 §3.5 | Workers Builds metric | 3000/6000 min/month | §37 |
| v2 §3.8 | GitHub Packages free tier | 500 MB / 1 GB | §22 |
| v2 §3.9 | BrandPartnerLink soft-delete not supported | §28.16 migration + trait + audit | §28.16, §43, §46, §58 |
| v2 §4.1 | "§29" cross-references | Fixed to Part 6 / §32 | §10, §28.9 |
| v2 §4.2 | §9 capability count | 16 + 2 = 18 rows; ~60 cases | §9 |
| v2 §4.3 | §13 brand interim state | `account_type='brand'` written directly at signup; `brand_status` walks `building → preview → live` | §13, §28.13 |
| v2 §4.4 | §36 cost table understated | Free tier ~15M page views/month | §38 |
| v2 §4.7 | Themes 2–5 duplication | Hydrogen scaffolds deleted in Phase 3 | §30.3, §47 |
| v2 §4.8 | Waitlist + brand-signup-code | Exempted explicitly | §28.14, §58 |
| v2 §5.3 | Skill directory path | `~/.claude/skills/` | §54 |
| v2 §5.5 | Signup-code rate limiting | Added (tiered, IP, audit logged) | §33, §34 |
| v2 §5.6 | Audit log infra | `BrandSignupCodeAuditEntry` model | §34 |
| v2 §5.10 | Public profile endpoint auth | Truly public; rate-limited 60/min/IP | §28.8 |
| v2 §6 #2/#4/#6/#7/#9/#10 | Honor-system rules | Concrete architecture tests added | §50, §51 |

## 59a. Post-plan-v3 product decision

Brand promotion path removed (`individual → brand`, `partner → brand` no longer supported). Brand is created at signup ONLY. Users wanting to become brands close their existing account and re-sign up.

## 59b. Post-backend-review changes (v3)

Resolutions to the backend-developer review pre-dating the 41-finding audit. Preserved verbatim from the v3 plan for traceability. Notably:
- §8 rewritten to enumerate 3 legacy `professional_type` values
- §28.3 reconciles `AccountTypeDefaultsService` and `AccountCapabilities`
- §28.4 specifies `$pro->lockForUpdate()` explicitly
- §28.7 explicit about NEW dispatch wiring
- §28.8 uses CF-Connecting-IP defensive pattern
- §28.16 expanded with full call-site enumeration
- §28.16 + RLS update bundled atomically
- §28.13 / §13 vocabulary fixed to lowercase `building/preview/live/systems_down`
- Track A scope clarified with realistic estimate

## 59c. Out-of-scope flagged for separate PRs (v4 consolidated audit)

The following audit findings concern code outside this plan's blast radius or were deferred per explicit project decision. Each requires its own PR (or — for OBS-1 — explicit decision to delete the affected controllers entirely).

| Audit ID | Reason for deferral |
|----------|---------------------|
| SEC-2 | `SUPABASE_JWKS_FAIL_CLOSED` production boot guard — security-hygiene work orthogonal to standalone-pages scope. |
| SEC-3 | JWKS key warming algorithm-derivation fix — dormant today; relevant only during a planned key rotation. Out of scope. |
| OBS-1 | Square/Fresha webhook inline-sync error reporting — per project memory note "booking/Fresha/Square being dropped." Worth doing OR worth deleting the controllers; the planning author's decision is to defer until that drop-or-keep decision is made. |
| OBS-3 | `ReconcileStuckPayoutsJob` swallowed per-payout Stripe errors — Stripe degradation-mode telemetry, orthogonal to the standalone-pages routing/capability work. |
| JOB-3 | `SyncCustomerMarketingOptInJob` missing `failed()` entirely — customer-marketing telemetry; the in-scope `report($e)` retrofit covers the 5 jobs in this plan's blast radius, not the marketing-sync job. |
| TEST-1 | Shopify secondary webhook HMAC sign-fail tests — webhook hardening work; out of standalone-pages scope. |
| TEST-2 (residual) | Seven untouched policies (`AffiliateProductPolicy`, `BrandResourcePolicy`, `GdprPolicy`, `ProfessionalSelfPolicy`, `SubscriptionPolicy`, `PartnaStaffPolicy`, `FeatureFlagPolicy`) — §28.11 covers the subset of policies it touches; the rest are policy-coverage hygiene that belongs in its own audit-closeout PR. |

---

# PART 16 — Consolidated audit findings resolution log (v4 / third pass)

This table covers every one of the 41 findings in `PARTNA-STANDALONE-PAGES-AUDIT-CONSOLIDATED.md`. Audit IDs are preserved verbatim. Tier and action class match the source audit. The Resolution column states the action taken; where the v4 plan deviates from the audit's recommendation or where verification disagrees with the audit, the rationale is given.

| Audit ID | Tier | Class | Finding (1-line summary) | Resolution | Section |
|----------|------|-------|--------------------------|------------|---------|
| MIG-1 | P1 | 🅿 | `CREATE INDEX CONCURRENTLY` inside transaction-wrapped migration will fail | Split into dedicated `<ts4>_add_account_type_covering_index.sql` containing nothing else; header notes no-transaction-wrapper requirement; `MigrationTransactionBoundaryTest` enforces | §28.1, §45, §51 |
| MIG-2 | P2 | 🅿 | `ADD CONSTRAINT … CHECK` without `NOT VALID` holds ACCESS EXCLUSIVE | `<ts2>` adds CHECK with `NOT VALID`; `<ts3>` runs `VALIDATE CONSTRAINT` separately, matching `20260515000001_validate_preferred_payout_method_check.sql` precedent | §28.1 |
| MIG-3 | P2 | 🅿 | `SET NOT NULL` holds ACCESS EXCLUSIVE on `core.professionals` | `<ts2>` adds `account_type IS NOT NULL` as a `NOT VALID` CHECK; `<ts3>` validates, then promotes to `SET NOT NULL` (which skips the row scan because the constraint is already enforced) and drops the redundant CHECK | §28.1 |
| MIG-4 | P2 | 🅿 | Three-file split creates a partial-application window with all NULLs | Merged `<ts1>` (add column) + backfill into ONE file; defensive `WHERE … IS NULL` guards on each UPDATE branch | §28.1 |
| MIG-5 | P3 | 🅸 | `brand.signup_code_audit` uses `gen_random_uuid()` without pgcrypto guard | Added `CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA extensions;` at top of `<ts>_create_brand_signup_code_audit.sql` | §34, §45 |
| MIG-6 | P3 | 🅸 | No rollback comments in migration SQL | Every destructive/constraint-adding migration carries `-- To revert: …` header; `MigrationRevertHeaderTest` enforces | §28.1, §28.16, §34, §36, §51 |
| MIG-7 | P3 | 🅸 | Backfill UPDATE not idempotent on retry | Every UPDATE branch guarded `WHERE account_type IS NULL` | §8, §28.1 |
| DATA-1 | P1 | 🅲 | `core.brand_status_history` CASCADE wipes audit on hard-delete | Absorbed into Track A: new migration `<ts>_brand_status_history_set_null_professional_fk.sql` makes `professional_id` nullable + converts FK to SET NULL, matching `20260505200000_commission_ledger_entries_set_null_professional_fks.sql` precedent | §28.17, §43, §45, §58 |
| DATA-2 | P1 | 🅲 | `core.handle_change_log` CASCADE violates 7-year retention | **VERIFICATION-FAILED.** `grep -r handle_change_log` against the Comet-Backend repo returns zero matches; the cited migration `20260519100000_handle_alias_lifecycle.sql` does not exist in the codebase. The table appears not to have been merged yet (or has a different filename). Track A placeholder action item: locate the actual migration when it lands and apply the same CASCADE → SET NULL fix; until then the finding is ON-HOLD, not actionable. | §28.17, §45 |
| DATA-3 | P1 | 🅲+🅿 | BrandPartnerLink no SoftDeletes + brand_partner_link_events RESTRICT FKs | Verified at the exact cited lines: `BrandPartnerLink.php` has only `HasUuids`; service line 99 calls `$target->delete()`; `20260420000000_add_brand_partner_link_events.sql:6-7` shows RESTRICT FKs. Resolution: §28.16 Migration A (soft-delete + dual indexes) + Migration B (FK RESTRICT → SET NULL with column made nullable) + Migration C (RLS update) — three migrations shipped atomically. Service auto-handles via the trait. | §28.16, §43, §46, §58 |
| DATA-4 | P2 | 🅲 | `PurgeSoftDeleted` misses `Block`; no sweep test | Absorbed: Track A adds `Block::class` to the purge enumeration; new `SoftDeletePurgeCoverageTest` discovers every `SoftDeletes` model via reflection and asserts each is purged or `PURGE_EXEMPT`-listed | §28.17, §43, §51, §58 |
| DINT-1 | P1 | 🅲 | (Duplicate of DATA-3) | See DATA-3 | §28.16 |
| SCHEMA-1 | P1 | 🅲 | (Duplicate of DATA-3) | See DATA-3 | §28.16 |
| SCHEMA-2 | P1 | 🅿 | Three RLS policies leak soft-deleted rows post-DATA-3 | Verified at the exact cited line numbers: `partner_links_party_select` (line 135), `brand_profiles_affiliate_select` (line 116), `store_settings_affiliate_select` (line 186). §28.16 Migration C updates all three with `deleted_at IS NULL` predicates on non-staff branches; staff branch unchanged. Same migration window as DATA-3 → no exposure window. RLS predicate test in §58. | §28.16, §58 |
| SCALE-1 | P1 | 🅸 | `AccountCapabilities::for()` causes 2N queries in list endpoints | Applied audit recommendation in full: (1) memoization on `AccountCapabilitySet`; (2) eager-load pattern with `CapabilityEagerLoadTest` architecture enforcement; (3) denormalized `has_historical_partner_links` boolean on `core.professionals`, maintained by `BrandPartnerLinkObserver` (added in the same migration as soft-delete). | §9, §28.16, §51 |
| SCALE-2 | P2 | 🅸 | TransitionService must not use sync dispatch inside DB transaction | §28.4 specifies: `DB::transaction` scoped to Eloquent mutations only; jobs dispatched AFTER transaction closes; `::dispatchSync()` forbidden inside the closure. Class-level comment encodes the rule verbatim. New `TransitionServiceTransactionBoundaryTest` architecture test parses the service file and asserts no `dispatchSync` token between the transaction's opening `function(` and matching close brace. | §28.4, §51 |
| SCALE-3 | P2 | 🅿 | `brand_profiles_signup_code_unique` synchronous ADD CONSTRAINT UNIQUE | Replaced with `CREATE UNIQUE INDEX CONCURRENTLY` + `ADD CONSTRAINT … UNIQUE USING INDEX` two-phase pattern. Preceded by `SET lock_timeout='2s'; SET statement_timeout='60s';`. Splits into one CONCURRENTLY file (no transaction wrapper) and one promotion file (with transaction wrapper + NULL-assertion guard). | §36 |
| SCALE-4 | P2 | 🅲 | (Duplicate of JOB-2 — SyncSubdomainToKvJob missing ShouldBeUnique) | See JOB-2 | §28.6a |
| CFG-1 | P2 | 🅿 | `SIDEST_INDIVIDUAL_WAITLIST_ENABLED` without config entry | `.env.example` entry added; `config/sidest.php` reads `env(…, false)` with fail-closed default; `BootstrapController` reads via `config()` not `env()` so `config:cache` is respected | §28.14, §45 |
| CFG-2 | P2 | 🅿 | `CloudflarePurgeService` planned without env/config keys | `.env.example` adds `CLOUDFLARE_CACHE_PURGE_TOKEN` + `CLOUDFLARE_ZONE_ID`; `config/services.php` exposes them under `cloudflare`; service reads via `config()` matching `CloudflareKvService` pattern | §28.7, §45 |
| CFG-3 | P3 | 🅿 | Rate-limit literals not in `config/sidest.php` | New `config/sidest.php` `rate_limits` key — values for `public_profile` (60/min) and `signup_code` (10/min, 100/hr, slowdown-after-5) live in config with env-overridable defaults. Both throttle middlewares read from `config()`. | §28.8, §33 |
| JOB-1 | P2 | 🅲+🅸 | 5 jobs missing `report($e)` in `failed()` | **VERIFIED at near-exact line numbers** for 4 jobs (FanOut at 107, SendBrand at 73, Nudge at 133, SendTransactional at 113 — all `Log::error`-only). CreateShopifyAffiliateDiscountJob at 194 has `failed()` that mutates integration metadata but never reports — confirmed. Track A retrofits all 5. Also: every NEW job in this plan adds `report($e)` as the first line of `failed()`. New `JobReportingDisciplineTest` architecture test enforces for future jobs. | §28.17, §43, §51 |
| JOB-2 | P2 | 🅲 | `SyncSubdomainToKvJob` missing ShouldBeUnique | Added `implements ShouldBeUnique` with `$uniqueFor=45` keyed by professionalId, matching `CreateShopifyMetafieldsJob` / `CreateShopifyAffiliateDiscountJob` precedent. Architecture test asserts the implementation. | §28.6a, §43, §51 |
| JOB-3 | P3 | 🅲 | `SyncCustomerMarketingOptInJob` has no `failed()` | **OUT-OF-SCOPE** per Part 15 §59c — customer-marketing telemetry, outside standalone-pages blast radius. | §59c |
| SEC-1 | P2 | 🅲 | `PolicyCoverageTest` stale POLICY_EXEMPT entry breaks CI gate | **AUDIT WAS OUTDATED.** Re-reading the test file (May 2026): line 38 is `\App\Models\Commerce\CommissionPayoutItem::class` (already correctly namespaced — audit said "Retail" but file says "Commerce"); `CommissionClawback` is exempt at line 49; `SectionView` is exempt at line 34. Test passes as-is. No Track A change needed. Track A still runs the test locally before §28.11 lands as a sanity check. | §28.17, Part 16 |
| SEC-2 | P2 | 🅲 | `SUPABASE_JWKS_FAIL_CLOSED` production boot guard | **OUT-OF-SCOPE** per Part 15 §59c — security-hygiene, orthogonal to standalone-pages scope. | §59c |
| SEC-3 | P3 | 🅲 | JWKS key warming algorithm-derivation | **OUT-OF-SCOPE** per Part 15 §59c — dormant today, relevant only during planned key rotation. | §59c |
| OBS-1 | P1 | 🅲 | Square/Fresha webhook inline-sync silent failures | **OUT-OF-SCOPE** per Part 15 §59c — booking/Fresha/Square being dropped per project memory; defer to drop-or-keep decision. | §59c |
| OBS-2 | P2 | 🅲 | (Duplicate of JOB-1) | See JOB-1 | §28.17 |
| OBS-3 | P2 | 🅲 | `ReconcileStuckPayoutsJob` swallows Stripe errors | **OUT-OF-SCOPE** per Part 15 §59c — Stripe degradation-mode telemetry. | §59c |
| LIFE-1 | P1 | 🅲 | Legacy affiliate cart-attribute key in webhook stub | Verified at the cited line range: `resolveAffiliateIdFromPayload` (lines ~595-613) extracts the legacy `'affiliate'` cart-attribute key and looks up via `handle_lc`. Track A absorbs: try `_partna_affiliate_id` (UUID direct-lookup) first, then fall back to the legacy `'affiliate'` handle key, matching the canonical pattern in `ProcessShopifyOrderWebhookJob` lines 94-101. Fix together with §28.6 (same handle-lookup surface). | §28.17, §43 |
| LIFE-2 | P2 | 🅲 | `BrandStatusService::sync()` unguarded RMW → duplicate audit rows | Track A wraps `sync()` body in `DB::transaction()` with `lockForUpdate()` as the first read. Belt-and-suspenders unique index `(professional_id, from_status, to_status, created_at::date)` on `core.brand_status_history` (added in same Track A item as DATA-1's CASCADE→SET-NULL migration since both touch the same table). | §28.17, §43, §45 |
| CACHE-2 | P2 | 🅿 | `SiteObserver` doesn't push-invalidate Cloudflare edge cache | §28.7 explicitly wires NEW dispatch sites: `SiteObserver::saved()` (every save, after Redis `invalidateSite()`) AND `AccountTypeTransitionService::transition()` (after transaction commit). Service is built as `CloudflarePurgeService`; job is `CloudflareCachePurgeJob` with its own inlined backoff (NOT `HasCloudflareRetryPolicy`, which is KV-specific). | §28.7, §43 |
| CACHE-3 | P2 | 🅿 | `SyncSubdomainToKvJob` hard-deletes for individuals instead of writing routing record | Verified at cited lines 58-71: current code calls `$kv->delete($current)` in a foreach when `$siteUrl` is null. §28.6 replaces with `$kv->put($handle, ['type' => 'individual'])` per handle. Genuine deletes remain in `RetireSubdomainFromKvJob`. One-off `BackfillIndividualKvEntries` Artisan command writes `{type:'individual'}` for existing affected professionals before the code change deploys. | §28.6, §45 |
| API-1 | P3 | 🅸 | `stripe_connect_status` unconditionally in resources | §28.8a wraps with `$this->when(AccountCapabilities::for($this->resource)->requires_stripe_connect, …)` — done as part of the same PR that lands §28.1, so the field gates correctly from day one of dual-state existence. | §28.8a, §43 |
| API-2 | P3 | 🅸 | `ProfessionalDashboardResource` missing `account_type` field | §28.8a adds `'account_type' => $this->account_type?->value` in the same PR as §28.1. Keeps `professional_type` in parallel during dual-write window. Track B consumes this field for 3-state capability routing. | §28.8a, §43 |
| TEST-1 | P2 | 🅲 | Shopify secondary webhook controllers missing HMAC tests | **OUT-OF-SCOPE** per Part 15 §59c — webhook hardening, orthogonal to standalone-pages. | §59c |
| TEST-2 | P2 | 🅲 | Seven policies have no ability-coverage tests | **SCOPED ABSORPTION.** Track A adds ability tests for the policy methods §28.11 actually touches (`CommissionPolicy` lines 87, 149, 159 migrated to `account_type`). The other six policies named in the audit (`AffiliateProductPolicy`, `BrandResourcePolicy`, `GdprPolicy`, `ProfessionalSelfPolicy`, `SubscriptionPolicy`, `PartnaStaffPolicy`, `FeatureFlagPolicy`) are flagged out-of-scope in §59c — separate policy-coverage PR. | §28.11, §59c |
| TEST-3 | P2 | 🅸 | Constraint-rejection tests not in plan | §28.15a names tests for `account_type` CHECK, `signup_code` UNIQUE, `signup_code_audit` event CHECK + compound CHECK, `brand_partner_links` FK orphan. Follows the `OrdersSchemaMigrationTest` pattern. | §28.15a, §58 |
| TEST-4 | P2 | 🅸 | `IndividualProfileResource` needs field-exclusion snapshot test | §28.8 verification + §58 checklist line: response asserts ABSENCE of `placeholders`, `fallback_gallery`, `brand_logo`, `brand_slogan`. Encodes rule #7. | §28.8, §58 |
| TEST-5 | P3 | 🅸 | `BrandProfile::creating` hook needs model creation test | §28.15b names the tests: factory create produces non-null 16-char code; explicit `BrandProfile::create(['signup_code' => null, …])` generates a code via the hook (not null); documents `createQuietly()` gotcha for backfill tests. | §28.15b, §58 |

**Total: 41 findings.**
- **Absorbed into v4 plan: 31** (MIG-1..7, DATA-1, DATA-3, DATA-4, DINT-1, SCHEMA-1, SCHEMA-2, SCALE-1, SCALE-2, SCALE-3, SCALE-4, CFG-1, CFG-2, CFG-3, JOB-1, JOB-2, OBS-2, LIFE-1, LIFE-2, CACHE-2, CACHE-3, API-1, API-2, TEST-3, TEST-4, TEST-5, TEST-2 scoped subset).
- **Verification-failed / on-hold: 1** (DATA-2 — referenced migration not in codebase; placeholder action item).
- **Audit was outdated on closer inspection: 1** (SEC-1 — `PolicyCoverageTest` not broken; current file already has the correct exemptions).
- **Out-of-scope flagged for separate PRs: 8** (SEC-2, SEC-3, OBS-1, OBS-3, JOB-3, TEST-1, TEST-2 residual seven policies).

(DINT-1, SCHEMA-1 are explicit duplicates of DATA-3; SCALE-4 is an explicit duplicate of JOB-2; OBS-2 is an explicit duplicate of JOB-1 per the audit appendix — counted once in the absorbed category but listed individually in the table for traceability.)

---

**End of plan v4.**
