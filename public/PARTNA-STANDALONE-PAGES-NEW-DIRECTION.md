# Partna — Individual Sitepages Architecture Plan

> **Status: planning artifact for execution.** This document supersedes the previous draft. It bakes in all audit findings from two independent reviewer passes (v1 May 2026; v2 May 2026) and the corrected verification of external claims (Astro / Cloudflare / GitHub Packages docs cross-checked May 2026). Part 15 lists every audit finding and how it was resolved.
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

Today the codebase has `Professional.professional_type` with two values: `'professional'` and `'brand'`. This is too coarse for three-state routing. The plan adds `account_type` as the new canonical column; `professional_type` becomes a dual-written legacy column that's eventually dropped.

**Migration sequence:**

1. New Supabase migration adds `account_type` column with `CHECK` constraint enforcing `{brand, partner, individual}`. Non-null with backfill.
2. Backfill SQL:
   - `professional_type = 'brand'` → `account_type = 'brand'`
   - `professional_type = 'professional'` AND has any active `BrandPartnerLink` → `account_type = 'partner'`
   - Everyone else → `account_type = 'individual'`
3. Covering index on `(account_type)` for dashboard queries.
4. A DB trigger keeps `professional_type` and `account_type` in sync during the migration period (writes to either cascade to the other).
5. Codebase migrates reads from `professional_type` to `account_type` incrementally.
6. Once no callers reference `professional_type`, drop the column (tracked as future cleanup; out of Phase 1).

**Why dual-write, not in-place rename:** in-place enum expansion in PostgreSQL is messy; renaming is high-risk for a widely-referenced column. Additive migration is reversible at any point.

**The backfill IS a deliberate exception to §50 rule #5** ("account_type set explicitly at signup, never derived from absence of a relationship"). The rule applies to runtime mutation paths; the one-time backfill derives `account_type` from observed BrandPartnerLink presence by design.

**Production migration timing:** the table `core.professionals` is small at our alpha stage (well under 10K rows); backfill SQL executes in milliseconds and CHECK constraint validation is fast. No blue-green path required. If row count grows substantially before Phase 1 ships, the backfill should be batched via `UPDATE ... WHERE id IN (SELECT id FROM core.professionals LIMIT 1000 OFFSET ?)` to avoid long-held write locks. Rollback is `DROP COLUMN account_type CASCADE` plus removing the trigger.

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
| `receives_brand_status_notifications` | ❌ (brand doesn't receive notifications about itself) | ✅ (partner receives notifications about the brand they're partnered with) | ❌ |
| `receives_invite_notifications` | ❌ | ✅ (cross-brand invites) | ✅ |
| `can_have_brand_link` | ❌ | ✅ | ✅ |
| `can_edit_design` | ✅ | ❌ (inherits brand) | ✅ |

Plus two **configuration values** (not booleans):

| Configuration | brand | partner | individual |
|---------------|-------|---------|-----------|
| `notification_categories` | full list | full list | filtered: profile, platform, invites, payout_settlement (if applicable) |
| `worker_kv_type` | `"brand"` | `"affiliate"` | `"individual"` |

**Count: 16 boolean capabilities + 2 configuration values = 18 matrix rows.**

**Tests:** 16 booleans × 3 account types = **48 baseline matrix tests**. Plus 2 configuration values × 3 types = 6 cases. Plus conditional cases around ex-partner state (`shows_ex_partner_panel`, `receives_payout_settlement_notifications`, `receives_invite_notifications`) covering both true and false branches = ~6 additional combinations. **Total ~60 cases.**

**Call sites that MUST consult capabilities:**
- Onboarding gate middleware (skip Stripe steps for individuals)
- Notification dispatchers (every job checks before enqueueing)
- Dashboard nav / route guards (frontend and backend mirror each other)
- Public API endpoints returning profile data
- Webhook handlers
- Scheduled payout/commission tasks

**The pattern is registry-based, not inline ifs.** When account_type values change, transitions happen, or new features are added, the registry is the one file to update.

## 10. Account type transitions matrix

| From | To | Trigger | Effect |
|------|----|---------|--------|
| `individual` | `partner` | Brand invites them + acceptance (existing flow), OR they enter a brand signup code (see Part 6 / §32) | Account type flips; BrandPartnerLink created; `SyncSubdomainToKvJob` writes `{type:'affiliate', redirect:...}`; capabilities flip; cache purges; Shop section becomes available; Stripe onboarding prompt appears in dashboard; partner notifications enabled |
| `partner` | `individual` | User leaves brand OR brand removes them | Account type flips; BrandPartnerLink **soft-deleted** (preserved for ex-partner panel — see §11; soft-delete migration is §28.16); KV writes `{type:'individual'}`; cache purges; Shop section disappears; Stripe Connect status remains `'active'` (no auto-disconnect — settlements still flow); partner-only notifications stop; ex-partner panel activates |
| `individual` | `brand` | User starts their own brand | See §13 — multi-step process, not a simple flip |
| `partner` | `brand` | Partner spins off as their own brand | See §14 |
| `brand` → anything | (Forbidden) | Brand is terminal; AccountTypeTransitionService rejects | — |

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
- The "ex-partner" capability is *derived*, not stored separately: a Professional is "ex-partner" if any `BrandPartnerLink` records exist for them (active or soft-deleted).
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

- **`brand → anything` is rejected at the service layer.** `AccountTypeTransitionService::transition()` throws a domain exception. No code path can bypass this.
- **Concurrent transitions are serialized.** A row-level lock on `Professional` ensures only one transition succeeds; the second attempt fails clean with a clear error.
- **Mid-flight orders during partner→individual transition:** orders are brand records; they settle normally. The ex-partner sees commission accrue in their ex-partner panel. No special pre-removal logic needed; the data model handles this. **Verification:** explicit test in §58.
- **Partner of a brand in `SystemsDown` state:** the partner stays a partner. KV still 301-redirects to the brand storefront (which shows the brand's degraded UI). The partner's dashboard shows a banner alerting them. No auto-transitions; brand SystemsDown is the brand's problem to fix.
- **Renames during transitions:** handle changes go through their own flow; transitions don't trigger renames. Two separate concerns.

## 13. Individual → brand transition — the multi-step process

Promoting to brand is not a flip. It's a multi-step onboarding flow. **Crucially: `account_type` stays at `'individual'` for the entire onboarding window**, and only flips to `'brand'` when `brand_status` reaches `'Live'`. This avoids the broken half-brand state where the KV entry says `{type:'brand'}` but Hydrogen has no storefront yet.

1. User indicates intent (dashboard action or signup choice). `account_type` remains `'individual'`.
2. Pre-flight checks: Stripe Connect onboarding initiated (sets `stripe_connect_status = 'onboarding'`), tax info collection, business name + industry capture.
3. Shopify store provisioning: user installs the Partna Hydrogen app on their Shopify store (existing flow per `Partna-Shopify-App`). `BrandProfile` row created with `setup_complete = false`, `brand_status = 'Onboarding'`. `account_type` STILL `'individual'`.
4. During this window, the visitor experience at `<handle>.partna.au` is unchanged — they continue to see the user's individual sitepage (Astro Worker). The user's dashboard shows brand-onboarding progress.
5. Once `setup_complete = true` AND `brand_status = 'Live'`, `AccountTypeTransitionService::transition($pro, AccountType::Brand)` fires:
   - `account_type` flips to `'brand'`
   - `SyncSubdomainToKvJob` writes `{type:'brand'}`
   - Cache purges
   - Worker switches to pass-through to Hydrogen Oxygen storefront
6. Brand goes Live; can start inviting partners.

**Failure handling:** if Shopify install fails or tax info isn't collected, `account_type` stays at `'individual'`. The user can resume from the dashboard. No half-brands.

**Reconciliation with §28.13 (BootstrapController):** signup writes the INITIAL `account_type`. For a "Brand" signup choice in the form, the initial value is `'individual'` and the brand-onboarding flow takes over from there. The `'brand'` value is never written by `BootstrapController` directly — only by `AccountTypeTransitionService::transition` after `brand_status='Live'`.

## 14. Partner → brand transition

Same as individual → brand (§13), plus:
- Dissolve the existing `BrandPartnerLink` (soft-deleted, preserved for the user's ex-partner history of their previous brand affiliation)
- The dissolved link is visible in their ex-partner panel
- Effectively: they're an ex-partner of brand A and an active brand themselves
- During the onboarding window, `account_type` flips `'partner' → 'individual'` first (so they no longer earn commission), then `'individual' → 'brand'` when onboarding completes. This is two distinct transitions through `AccountTypeTransitionService`.

---

# PART 3 — Hosting, routing, and infrastructure

## 15. Why Cloudflare Workers Static Assets, not Cloudflare Pages

The original plan targeted Cloudflare Pages. The independent audit (May 2026) surfaced that **the `@astrojs/cloudflare` adapter dropped Cloudflare Pages support in adapter v13**, aligned with the Astro 6 framework release. Sources:
- Adapter docs: *"The Astro Cloudflare adapter no longer supports deployment on Cloudflare Pages. For the best experience and feature support, you should migrate to Cloudflare Workers."*
- Current adapter version (May 2026): `@astrojs/cloudflare@13.5.2`, targeting Astro framework 6.x (current stable: 6.3.1, released May 2026).
- PR #15480 (merged Feb 2026 to the Astro `6-beta` branch) removed Pages support.

> **Note on version conventions:** "Astro 6" refers to the framework version. "v13" refers to the `@astrojs/cloudflare` adapter version. These are decoupled — the adapter and framework increment independently. The previous draft conflated them, writing "Astro v13"; the correct framing is "Astro 6 + @astrojs/cloudflare v13."

Reinforcing context: Cloudflare acquired The Astro Technology Company on **January 16, 2026** (Cloudflare press release of that date). Astro 6 ships first-class Workers support; `astro dev` runs on `workerd`. Direction is unambiguous.

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

The shift is a clean win.

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

**Cache purge mechanics.** On profile edit, the backend dispatches `CloudflareCachePurgeJob(handle)` which:
1. Builds the full URLs to purge: `https://<handle>.partna.au/` and any sub-paths the individual exposes.
2. Calls Cloudflare's cache purge API: `POST https://api.cloudflare.com/client/v4/zones/{zone_id}/purge_cache` with body `{"files": [...urls]}`.
3. Uses an API token scoped to `Zone.Cache Purge` for the `partna.au` zone.
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

The individual entry intentionally has no `origin` field — the Service Binding name is configured in the router's `wrangler.toml`, not in KV. Keeping KV values minimal reduces the blast radius of KV changes.

`SyncSubdomainToKvJob` is the **only** writer to `SUBDOMAIN_KV`. No other code writes to it. This is enforced by an architecture test in `tests/Architecture/SubdomainKvWritersTest.php` that greps the codebase for `SUBDOMAIN_KV->put|SUBDOMAIN_KV.put` and asserts the only matches are inside `SyncSubdomainToKvJob` (see §51).

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

**Production has no public route on the Astro Worker.** Curl-testing it directly requires either Mode A/B/C or a temporary `*.workers.dev` route that's removed before production deploy. The architecture test in `tests/Architecture/AstroWorkerRouteTest.php` confirms `partna-pages/wrangler.toml` has no `route` or `routes` declaration in production.

---

# PART 4 — The shared theme package

## 22. `@partna/themes` — repo, structure, distribution

**New standalone repo: `partna-themes`** under the same GitHub org as the other Partna repos. Not a monorepo workspace inside Hydrogen — keeping it independent means Hydrogen and the Astro app can pin different versions if needed and the package lifecycle is decoupled.

**Distribution: GitHub Packages private registry.**

For private packages, GitHub Packages free tier provides:
- 500 MB storage
- 1 GB data transfer per month

At our scale (small org, infrequent installs), this is effectively free. If we approach the limits we'd see warnings well before any hard cap and could upgrade or rotate to a different registry. **Not "free unlimited"** as a previous draft loosely claimed; it's "free at our scale, with documented limits."

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
- The Hydrogen-specific `brand-design.server.ts` engine (fetches brand-side data including placeholders and fallback gallery)

**This precise rule resolves the audit finding about ShopPayExpress.tsx importing @shopify/hydrogen-react.** That file is part of the Shop section, which stays in Hydrogen. The shared package itself contains zero Shopify imports. The "themes are Shopify-free" claim applies to the package; Hydrogen-side theme code (specifically the Shop section) keeps its Shopify dependencies because it stays in Hydrogen.

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

The data model already supports per-Site styling — `site.settings.design` JSONB is one-per-professional. Today only brands edit it (frontend gating); for individuals the same field becomes editable.

**For brand/partner storefronts (Hydrogen):**
- Hydrogen's `brand-design.server.ts` engine fetches the BRAND's `site.settings.design` (the brand's professional's site).
- Brand placeholders, fallback gallery, brand logo, brand slogan are layered under affiliate content (this layering is Hydrogen-specific; lives in `brand-context.server.ts`).

**For individuals (Astro):**
- A new backend endpoint `GET /api/public/profiles/{handle}` returns the individual's OWN `site.settings.design`.
- No brand placeholders. No fallback gallery. No brand logo concept (logo is the individual's profile image).
- The same `BrandStyleTag` component from the shared package consumes the styling and injects CSS variables — exact same rendering, different data source.

**The theme components are symmetric.** They receive `ThemeProps` and render. What differs is the data shape filled in by each consumer's engine.

---

# PART 5 — Per-repo changes

## 28. Comet-Backend changes

All migrations are Supabase SQL per the existing project convention.

**28.1. Schema migration — account_type** (`supabase/migrations/<ts>_add_account_type_to_professionals.sql`):
- Add `account_type` enum column with CHECK constraint
- Backfill per §8
- Index on `(account_type)`
- DB trigger keeping `account_type` and `professional_type` in sync (write to either cascades)

**28.2. Model updates** (`app/Models/Core/Professional/Professional.php`):
- Cast `account_type` to PHP enum
- New accessors: `isPartner()`, `isIndividual()`; existing `isBrand()` becomes a shim reading `account_type`
- New `App/Enums/AccountType.php` enum class

**28.3. AccountCapabilities registry** (`app/Services/Accounts/AccountCapabilities.php`):
- Method `for(Professional $pro): AccountCapabilitySet`
- Full capability matrix per §9
- `AccountCapabilitySet` value object (`app/Services/Accounts/AccountCapabilitySet.php`)

**28.4. AccountTypeTransitionService** (`app/Services/Accounts/AccountTypeTransitionService.php`):
- `transition(Professional $pro, AccountType $to, array $context = [])`
- Validates transition is allowed (matrix per §10); rejects `brand → anything`
- DB transaction wrapping: account_type update, BrandPartnerLink create/soft-delete, dual-write to professional_type
- Dispatches `AccountTypeTransitionEvent`
- Dispatches `SyncSubdomainToKvJob` + `CloudflareCachePurgeJob`

**28.5. AccountTypeTransitionEvent** + listeners (`app/Events/Accounts/`, `app/Listeners/Accounts/`):
- One listener per side-effect: notification subscriptions, Stripe activation toggle, tax info status, dashboard banner triggers

**28.6. SyncSubdomainToKvJob update** (`app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`):
- Three branches: brand (existing), affiliate-with-link (existing), individual (new — writes `{type:'individual'}` instead of deleting on no-link)
- Tests updated to cover all three branches

**28.7. CloudflarePurgeService + Job** (`app/Services/Cloudflare/CloudflarePurgeService.php`, `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`):
- Service wraps the Cloudflare cache purge API call
- Job declares its own backoff policy (max 3 attempts, exponential) — does NOT rely on `HasCloudflareRetryPolicy` trait (that trait lives on `SyncSubdomainToKvJob` and is targeted at KV-specific failure modes; the cache-purge Job has different retry characteristics and inlines them)
- Dispatched from Site observers on content change AND from AccountTypeTransitionService

**28.8. Public profile API endpoint** (`app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`):
- Route: `GET /api/public/profiles/{handle}`
- Resource: `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- **Auth: truly public, no authentication.** This endpoint serves data that is by definition public (anyone visiting `<handle>.partna.au` sees the rendered profile). Adding auth would add friction without security benefit. The Astro Worker fetches it unauthenticated; rate-limiting at the controller level (Laravel `throttle` middleware: 60/min/IP) handles abuse vectors.
- Returns: individual's `site.settings.design` + bio + services + booking + links + newsletter status + analytics tracking IDs
- Excludes: brand placeholders, fallback_gallery, product/cart fields, commission/order data
- Caching: 60s TTL via `CacheLockService::rememberLocked`; cache key includes handle + site updated_at

**28.9. Brand signup code mechanism** (see Part 6 / §32 for the full spec)

**28.10. Notification preferences capability filtering**:
- Endpoint serving `/me/notification-email-preferences` filters categories by `AccountCapabilities::for($pro)->notification_categories`
- Notification dispatcher jobs check capabilities before enqueueing (defence-in-depth)

**28.11. Feature gating wired through existing code paths**:
- Stripe onboarding controllers wrapped in capability guard
- Tax info collection skipped for individuals
- Webhook handlers skip enqueueing partner-only jobs for individual events
- Policy classes: individuals have no access to partner-only resources (orders, commissions, payouts) — return 404 (not 403, per existing standard)

**28.12. Brand invite acceptance** (existing endpoint, modified):
- Wire through `AccountTypeTransitionService::transition($pro, AccountType::Partner, ['brand_id' => ...])`
- All downstream effects (KV update, capability change, cache purge, notification setup) fire consistently

**28.13. Bootstrap flow update** (`app/Http/Controllers/Api/PublicSite/BootstrapController.php`):
- Explicit account_type assignment per the three signup paths defined in §32:
  - Brand path → `account_type = 'individual'` (initial value; brand onboarding flow per §13 may flip to `'brand'` later via AccountTypeTransitionService)
  - Invite path (token, brand_partner_id, brand_handle) → `account_type = 'partner'`
  - Brand signup code path → `account_type = 'partner'`
  - Default Professional path → `account_type = 'individual'`
- Writes BOTH `account_type` AND `professional_type` for the dual-write period

**28.14. Individual waitlist flag**:
- New env var `SIDEST_INDIVIDUAL_WAITLIST_ENABLED` (default off)
- When on, BootstrapController diverts individual signups to a waitlist row instead of creating a Professional
- Brand, partner-via-invite, **and partner-via-brand-signup-code** signups are unaffected. The waitlist only diverts genuine `'individual'` signups.
- Pure defensive kill switch

**28.15. Tests** for everything above. Pest 4 + PHPUnit, SQLite in-memory.

**28.16. BrandPartnerLink soft-delete migration** — REQUIRED for ex-partner mechanism to work.

Today `app/Models/Core/Professional/BrandPartnerLink.php` uses only the `HasUuids` trait. `BrandPartnerLinkService::disconnectBrandFromAffiliate()` calls `$target->delete()` — a hard delete. The ex-partner panel (§11) requires retained history. This migration adds soft-delete:

- **Migration** (`supabase/migrations/<ts>_add_soft_deletes_to_brand_partner_links.sql`): add `deleted_at TIMESTAMPTZ NULL` column to `brand.brand_partner_links`. Index on `(affiliate_professional_id, deleted_at)` for ex-partner queries.
- **Model update**: add Laravel's `SoftDeletes` trait to `BrandPartnerLink`. Add `deleted_at` to `$casts` as datetime. No `$dates` change needed in modern Laravel.
- **Service call site audit** — verified via grep:
  - `BrandPartnerLinkService::disconnectBrandFromAffiliate()` calls `$target->delete()` — automatically becomes soft-delete via the trait; no code change required.
  - `BrandPartnerLinkService::normalizeAdditionalSlots()` — operates on the result of an explicit query; default scope excludes trashed, which is correct (we never want to renormalize against deleted rows).
  - `BrandPartnerLinkLifecycleService::disconnect()` — calls into `disconnectBrandFromAffiliate`; no change.
- **Relationship queries**: add a `brandPartnerLinksAll()` relationship on `Professional` that returns `withTrashed()`, distinct from the existing `brandPartnerLinks()` (which auto-excludes trashed). Ex-partner derivation uses the `All` variant.
- **`shows_ex_partner_panel` derivation** (§9): `$pro->brandPartnerLinksAll()->exists() && !$pro->brandPartnerLinks()->exists()` — i.e., has historical links but no active ones.
- **`BrandPartnerLinkAuditor`** unchanged; the audit log already records all create/delete events independently.
- **Tests** (`tests/Feature/Services/Professional/Brand/SoftDeleteTest.php`):
  - Disconnect leaves the row visible to `withTrashed()`
  - Default queries exclude soft-deleted
  - Ex-partner derivation returns true for soft-deleted-only state
  - Reconnect to same brand creates a NEW row (does not restore the soft-deleted one) — preserves the historical audit trail of each partnership episode
  - `commerce.orders.affiliate_professional_id` queries still resolve to the affiliate Professional regardless of BrandPartnerLink soft-delete state

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
- Production deploy gated on Phase 5 (joint integration testing)

## 30. Hydrogen changes

**30.1. Newsletter hook decoupling** (`app/lib/engines/newsletter.ts`):
- Today: hard-imports `useFetcher` from `react-router`
- Change: hook accepts optional `submit` callback; default uses React Router's `useFetcher` (Hydrogen unchanged); the shared package version uses plain `fetch` (Astro path)
- Interface preserved from consumer perspective

**30.2. Shop section refactor** (`app/themes/theme-1/`):
- Move `components/expandable/ShopExpandableCard/` to `sections/Shop/`
- Create `sections/Shop/Shop.tsx` as the section wrapper composing the expandable card with the cart/checkout machinery
- Update `layout.tsx` imports
- Pure refactor; no functional change

**30.3. Layout migration to package consumption**:
- `app/themes/theme-1/layout.tsx` rewritten ONCE to import sections/components from `@partna/themes/theme-1` and add the local Shop section
- Themes 2–5 (empty `.gitkeep` placeholders in Hydrogen today) — **delete the Hydrogen scaffolds in Phase 3** once `partna-themes` ships its own scaffolds. Source of truth for themes 2–5 moves to `partna-themes`.

**30.4. File audit before extraction**:
- Verify no `react-router` imports in `app/themes/` (already audited — clean)
- Verify no `@shopify/*` imports outside Shop/cart paths
- Output a delta document: which files move to the package, which stay in Hydrogen

## 31. Frontend changes (Partna-Frontend)

The frontend UI buildout — new signup flows visual design, onboarding wizard polish, account-type switching UI — is out of scope for this plan and gets its own plan. The capability extensions and route allowlist updates ARE in scope here because they're the data layer the UI will consume.

**31.1. `lib/account-capabilities.ts` extension**:
- Extend from 2-state to 3-state (`brand` / `partner` / `individual`)
- Add capability rows per §9
- Add route allowlist entries for individuals (specifically: `/account/overview`, `/account/onepage`, `/account/contacts`, `/account/settings`, `/account/design`)
- Remove route allowlist entries for individuals where commerce-related (`/account/shop`, `/account/commerce`, `/account/affiliates`)

**31.2. Signup form adjustment** (`app/(app)/account/(auth)/sign-up/signup-form.tsx`):
- The 2-button AuthTypeGrid stays
- Add optional "brand invite code" text input on the Professional path
- Internal resolution per §32 — non-invite Professional → `account_type='individual'`; invite or code → `account_type='partner'`; Brand → `account_type='individual'` initially (per §13 brand-onboarding flow takes over)
- Submit posts `account_type` explicitly to BootstrapController

**31.3. `/account/design` made universal**:
- Existing brand-only route becomes accessible to individuals (per route allowlist update in 31.1)
- The page (`app/(app)/account/(dashboard)/design/page.tsx`) detects account_type via capabilities and conditionally renders:
  - Brand: full editor (colors, theme, placeholders, fallback gallery, brand logo, brand slogan)
  - Individual: simplified editor (colors, theme, profile image, tagline, font, spacing buckets — no placeholders, no fallback gallery, no brand-logo framing)
- Same backend endpoint serves both — the editor sends whatever fields it has; the backend writes to `site.settings.design`
- **This IS frontend work** but is in scope for this plan because it's a capability extension — not net-new UI design

**31.4. Settings section conditional rendering** (`app/(app)/account/(dashboard)/settings/settings-sections.tsx`):
- Switch existing `professional_type === 'brand'` checks to capability-based checks via `account-capabilities.ts`
- "Industries" section: brand only (unchanged behavior)
- "Brand Partnas" section: partner only (individuals have no brand to connect, even if invitable)
- "Sharing" section: all (unchanged behavior)

---

# PART 6 — Brand signup code mechanism

## 32. The concept

The brand signup code is a **per-brand shareable code** that anyone signing up can enter to immediately become a partner of that brand. Distinct from the existing per-affiliate `BrandAffiliateInvite` tokens (which are one-time and tied to a specific email/individual).

Use case: a brand wants to onboard partners en masse (e.g., a fitness apparel brand inviting all their sponsored athletes). Instead of generating dozens of individual invites, they share one code. Anyone who uses it becomes a partner.

## 33. Mechanism

**Storage:** new columns on `brand.brand_profiles`:

```sql
ALTER TABLE brand.brand_profiles
  ADD COLUMN signup_code text UNIQUE,
  ADD COLUMN signup_code_active boolean NOT NULL DEFAULT true,
  ADD COLUMN signup_code_rotated_at timestamptz;
```

- `signup_code`: opaque alphanumeric string, 16 chars. Generated in PHP via the `BrandProfile::creating` Eloquent hook using `bin2hex(random_bytes(8))`. UNIQUE across all brands. **Generated at model creation time, not via DB default** — this guarantees every new row has a code and avoids the migration race where concurrent inserts could land NULL values between backfill and NOT NULL enforcement.
- `signup_code_active`: brand can deactivate without rotating (e.g. paused onboarding).
- `signup_code_rotated_at`: tracks when the code was last rotated.

**Why an opaque code instead of just using the brand's handle:**
- Handles are public and guessable. Anyone could type `nike` and try to join Nike's brand if handle == code.
- An opaque code requires the brand to share it deliberately.
- Rotatable without losing the brand's handle.

**Signup flow integration:**

In the signup form, the optional "brand invite code" input accepts EITHER:
- A per-affiliate `BrandAffiliateInvite` token (existing flow, claimed via `BrandAffiliateInviteService::claimInvite()`)
- A per-brand `signup_code` (new flow)

The BootstrapController detects which type by querying both:
1. Try to find `BrandAffiliateInvite` with matching token → existing path
2. Else, try to find `BrandProfile` with matching `signup_code` AND `signup_code_active = true` → new path
3. Else, validation error: "Code not recognized"

For the brand_signup_code path:
- New service method: `BrandSignupCodeService::resolveCode(string $code): ?BrandProfile`
- If resolved, BootstrapController creates the Professional with `account_type = 'partner'` AND creates a `BrandPartnerLink` to that brand
- **Single-brand cap enforcement:** uses the existing `BrandPartnerLinkService::connectBrandToAffiliate()` which enforces the per-affiliate slot cap (Pilot/V1: slot 0 only; documented at `app/Services/Professional/Brand/BrandPartnerLinkService.php:11` *"Pilot/V1: each affiliate is connected to exactly one brand partner (slot 0)."*).
- **If the user is already a partner of another brand**, the connection throws `RuntimeException('You are already connected to a brand partner. Disconnect from your current brand partner before connecting to a new one.')` — the signup flow catches this and surfaces a friendly error: "You're already connected to **{existing_brand_name}**. Leave that partnership before joining a new one."

**Rate limiting (REQUIRED — abuse vector mitigation):** the signup endpoint applies tiered rate limiting on code-resolution attempts:
- 10 attempts per IP per minute
- 100 per IP per hour
- After 5 failed attempts on the same IP, the response delays 2 seconds (slowloris-style throttle)
- Each attempt is logged via `BrandSignupCodeAuditEntry` (see §34) for fraud forensics

Why this matters: 16 hex chars (64 bits) cannot realistically be brute-forced, but a botnet trying recently-leaked brand codes from a public screenshot or share is a real attack surface.

**Rotation:** brands can rotate their code from the brand dashboard. Rotation:
- Generates a new opaque string via the same `bin2hex(random_bytes(8))` mechanism
- Updates `signup_code` and `signup_code_rotated_at`
- The old code is dead immediately (no grace period)
- `BrandSignupCodeAuditEntry` records the rotation event with timestamp, staff/user actor, and (hashed) old code prefix for forensics
- Successful and failed claim attempts against the OLD code (post-rotation) are recorded as "stale code attempted" — surfaces in the brand dashboard as "X attempts to use your rotated code in the last 7 days"

**Deactivation:** sets `signup_code_active = false`. Code remains in the column for audit history. Signups using a deactivated code get the same "Code not recognized" error.

**Code visibility audit (dashboard surface):**
- "Successful claims via your current code in the last 30 days: N"
- "Attempts against your rotated/old codes in the last 7 days: M"
- "Last rotation: <date>"
- Brands can investigate if usage looks anomalous

## 34. BrandSignupCodeAuditEntry — audit log for code lifecycle

Reuses the existing audit-log pattern in Comet-Backend (`StaffAuditEntry`, `ProfessionalDeletionAuditEntry`, `WalletCurrencySwitchAudit`). New table:

```sql
CREATE TABLE brand.signup_code_audit (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  brand_profile_id uuid NOT NULL REFERENCES brand.brand_profiles(id),
  event text NOT NULL CHECK (event IN ('generated','rotated','deactivated','reactivated','claimed','failed_claim')),
  actor_type text NOT NULL CHECK (actor_type IN ('system','brand','staff','public')),
  actor_professional_id uuid REFERENCES core.professionals(id),
  staff_user_id uuid,
  source_ip text,
  code_prefix_hash text,        -- sha256(first 4 chars of code) for stale-code detection without storing the code
  joined_professional_id uuid,  -- on 'claimed' events
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX ON brand.signup_code_audit (brand_profile_id, event, created_at DESC);
```

`App\Models\Brand\BrandSignupCodeAuditEntry` follows the same shape as the existing audit-entry models (constants for `EVENT_*` and `ACTOR_TYPE_*`). The brand-dashboard endpoint reads from this table; no model-side surface beyond aggregation queries.

## 35. Brand dashboard UI for the code

Out of scope for this plan (it's UI work) but the data layer makes it trivial:
- A new endpoint `GET /api/professional/brand/signup-code` returns the brand's current code + active status + rotation timestamp + dashboard aggregates from `signup_code_audit`
- `POST /api/professional/brand/signup-code/rotate` regenerates
- `POST /api/professional/brand/signup-code/deactivate` toggles
- Frontend UI consumes these to show the code, a copy button, rotate/deactivate controls, claim/attempt metrics

## 36. Migration for existing brands

Add the column, generate codes for all existing brands via PHP (via a one-off Artisan command iterating `BrandProfile::all()` and saving — each save triggers the `creating` hook for new rows OR the explicit code generator for existing ones), then enforce NOT NULL + uniqueness.

```sql
-- step 1: add columns nullable
ALTER TABLE brand.brand_profiles
  ADD COLUMN signup_code text,
  ADD COLUMN signup_code_active boolean NOT NULL DEFAULT true,
  ADD COLUMN signup_code_rotated_at timestamptz;
```

```php
// step 2: one-off Artisan command (php artisan brand:backfill-signup-codes)
// Iterates existing brands; each one generates a fresh code via the same
// BrandSignupCodeService::generate() the model-creating hook uses.
// Single source of truth for code generation; no PHP-vs-SQL drift.
```

```sql
-- step 3: enforce NOT NULL + uniqueness
ALTER TABLE brand.brand_profiles
  ALTER COLUMN signup_code SET NOT NULL,
  ADD CONSTRAINT brand_profiles_signup_code_unique UNIQUE (signup_code);
```

**Concurrent-insert safety:** because the `creating` Eloquent hook fills the code at the application layer, new brands created during the backfill window always have a code. No race between backfill and NOT NULL enforcement.

`gen_random_uuid()` in §34's audit table requires `pgcrypto`. Supabase enables it by default, but worth verifying via `SELECT * FROM pg_extension WHERE extname = 'pgcrypto';` before this migration runs.

---

# PART 7 — Cost breakdown

## 37. The pricing model

Per page view:
- 1 inbound request to the router Worker (billable on the first cache miss; subsequent in-TTL hits served from cache without invoking the Worker)
- 1 KV read (the handle lookup, only on cache miss)
- A Service Binding call to the Astro Worker (NOT billable as a separate request; counts against subrequest budget of the router)
- Astro Worker runs (CPU time billable on cache miss)
- Subrequest from Astro to backend `/api/public/profiles/{handle}` (subrequest budget of Astro)

Cached responses (Cloudflare edge cache populated by §18) bypass the Worker entirely — $0.

**Workers Free tier (verified):**
- 100K Worker requests/day
- 10ms CPU per invocation
- KV: 100K reads/day, 1K writes/day

**Workers Paid plan ($5/month base, verified):**
- 10M requests/month included; $0.30/M after
- 30M CPU-ms/month included; $0.02/M after
- KV: 10M reads/month included; $0.50/M after; 1M writes/month included; $5.00/M after

**Subrequest limits (verified):**
- Free: 50 subrequests/invocation
- Paid: **10,000 subrequests/invocation by default**, configurable via Wrangler limits up to 10M
- Service Binding hops: max 32 Worker invocations per request chain (we use 1)

**Static asset bandwidth: free and unlimited** (verified via Cloudflare docs). This is the killer feature.

**Cache purge API: no per-call charge**; rate-limited per plan (5 req/min on free).

**Workers Builds: metered in MINUTES, not build count.** Free: 3,000 min/month, 1 concurrent. Paid: 6,000 min/month, 6 concurrent, +$0.005/min over. Profile edits don't trigger builds — only theme/code deploys do; we'd be under 50 build-minutes/month at normal pace.

**Astro SSR CPU estimate:** Astro server-rendering React + Motion + react-pdf is non-trivial. Typical Astro v6 SSR on Workers runs 10–50ms CPU per invocation depending on theme complexity. At 10ms average, the Workers Paid CPU budget (30M ms/month included) supports 3M invocations comfortably. At 50ms it covers 600K. Measure during Phase 3 to refine cost projections.

## 38. Cost by traffic tier (80% cache hit assumption applied consistently)

Per 1M page views with an 80% edge-cache hit rate: **200K Worker invocations** + 200K KV reads. Cache hits skip the chain entirely.

Free tier cap of 100K Worker invocations/day = ~3M invocations/month → with 80% cache hit, that supports **~15M page views/month** within Free tier.

| Page views/month | Worker invocations | Monthly cost |
|------------------|--------------------|--------------| 
| Up to ~15M | up to ~3M | **$0** (Free tier; 100K req/day cap × 30 days; with 80% cache hit) |
| 15M – 50M | 3M – 10M | **$5** (Workers Paid base; all within included quotas) |
| 50M – 100M | 10M – 20M | **$5 – $8** ($5 base + ~$0.30/M Worker overage over 10M; KV inside 10M included) |
| 100M – 500M | 20M – 100M | **$8 – $32** (Worker + KV overage scales linearly) |
| 500M – 1B | 100M – 200M | **$32 – $62** |

These assume the 10ms CPU/invocation budget holds. If Astro SSR averages 50ms, the CPU overage kicks in around 600K invocations/month — push Paid base cost up by a few dollars per million extra invocations.

## 39. Cost by individual user count (assuming ~500 views/user/month)

500 views/user/month is intentionally conservative (overestimates traffic; personal profiles often see 5–50 views/user/month). Real cost will trend lower.

| Users | Approx views/mo | Worker invocations (80% cache) | Monthly cost |
|-------|-----------------|--------------------------------|--------------|
| 100 | 50K | 10K | **$0** |
| 1,000 | 500K | 100K | **$0** |
| 10,000 | 5M | 1M | **$0** (still Free tier — 33K/day, under 100K cap) |
| 50,000 | 25M | 5M | **$5** (just over Free; Paid base only) |
| 100,000 | 50M | 10M | **$5** (Paid base; right at included quota) |
| 500,000 | 250M | 50M | **~$17** ($5 + 40M × $0.30/M Worker overage; KV similar) |
| 1,000,000 | 500M | 100M | **~$32** ($5 + 90M × $0.30/M) |

## 40. Comparison to alternative deploys

- **Vercel Pro** (rejected): $20/month base + 1 TB bandwidth included + $0.40/GB after. At 100M page views × ~50KB/page = ~5 TB/month = 4 TB overage × $0.40 = **~$1,640/month**.
- **AWS S3 + CloudFront** (rejected): ~$0.085/GB bandwidth. Same 5 TB = $425/month + Lambda costs for SSR.
- **Cloudflare Workers Static Assets** (chosen): unlimited static bandwidth + the table above. At 100M page views: **~$5/month**. Roughly two orders of magnitude cheaper than Vercel at scale.

## 41. Caveats

- Free tier limits are **daily** (100K Worker requests/day). A traffic spike on a single day will throttle until midnight UTC; once we cross consistently, upgrade to Workers Paid.
- KV reads are the constraint that bites first at scale — worth caching KV results at the router Worker level (`caches.default.put()` with a short TTL on the KV-lookup result) to cut KV reads by ~90%. Out of Phase 1 scope but easy follow-up.
- The 80% cache hit assumption is conservative. With profile pages rarely changing and 60s edge TTL + cache purge, 90%+ hit rates are plausible. Higher hit rate = lower cost.
- Subrequest limits (10K per request on Workers Paid by default) are generous. Our pipeline does ~3 subrequests per cache miss (KV + Service Binding to Astro + backend fetch). Well under the limit.
- **The 80% cache hit assumption depends entirely on §18's cache mechanism working.** If the router doesn't call `caches.default.put()`, hit rate is 0% and every page view invokes the chain — multiplying Worker invocations by 5×. Free tier exhausts at ~3M page views/month instead of ~15M. The §18 fix is therefore cost-critical, not just architectural.

---

# PART 8 — Existing code map

## 42. Code that becomes dead / removable

Genuinely small list. The architecture is additive.

| Item | What | When removable |
|------|------|---------------|
| `SyncSubdomainToKvJob` delete-on-no-link branch | Today it deletes a professional's KV entry when they have no brand link, causing the Worker to 404. Replaced with a write of `{type:'individual'}`. KV delete logic still exists for genuine deletion paths (professional deletion, handle changes). | Replaced in Phase 1 (backend foundation) |
| Hydrogen `app/themes/theme-2..5` placeholder folders | Empty `.gitkeep`-only directories in Hydrogen today. Source of truth for themes 2–5 moves to `partna-themes`. | Deleted in Phase 3 |
| `Professional::isBrand()` (eventually) | After all callers migrate to `account_type`, becomes a 1-line shim → deprecated → removed. | Future cleanup, post-Phase 1 |
| `core.professionals.professional_type` column | After dual-write, after all readers migrate to `account_type`. | Future cleanup, post-Phase 1. Trigger keeps them in sync indefinitely with zero behavioral impact. |

## 43. Code that shifts or extends

| File / module | Today | Change |
|---------------|-------|--------|
| `Partna-Frontend/lib/account-capabilities.ts` | 2 states (brand vs non-brand) | 3 states. Adds individual capability rows. Backend mirror added per §28.3. |
| `Comet-Backend/app/Models/Core/Professional/Professional.php` | `professional_type` column reads | Adds `account_type` cast + accessors. `isBrand()` becomes shim. Dual-write trigger. |
| `Comet-Backend/app/Models/Core/Professional/BrandPartnerLink.php` | `HasUuids` only; hard-delete | Adds `SoftDeletes` trait; `delete()` becomes soft (§28.16) |
| `Comet-Backend/app/Http/Controllers/Api/PublicSite/BootstrapController.php` | Signup; sets `professional_type` from input | Explicit `account_type` per §32. Handles brand_signup_code path. Dual-writes both columns. |
| `Comet-Backend/app/Jobs/Cloudflare/SyncSubdomainToKvJob.php` | Two branches (brand, affiliate, delete-on-no-link) | Three branches (brand, affiliate, individual) |
| `Comet-Backend/cloudflare-worker/src/index.js` | Two branches | Three branches; Service Binding for individual; cache-API population; wrangler.toml `[[services]]` block (production + staging) |
| `Partna-Hydrogen/app/lib/engines/newsletter.ts` | Hard-coupled to `useFetcher` | Accepts injected submitter; default Hydrogen behavior preserved |
| `Partna-Hydrogen/app/themes/theme-1/layout.tsx` | Local imports for sections + ShopExpandableCard | Imports from `@partna/themes/theme-1` + local Shop section |
| `Partna-Hydrogen/app/components/expandable/ShopExpandableCard/` | Lives at this path | Moves to `app/themes/theme-1/sections/Shop/` (file move + wrapper) |
| `Partna-Frontend/app/(app)/account/(auth)/sign-up/signup-form.tsx` | 2-button AuthTypeGrid; 8-step flow | Stays. Adds optional brand_signup_code input. Internal account_type resolution. |
| Notification dispatcher jobs | Sends to anyone subscribed | Adds `AccountCapabilities` check |
| `Partna-Frontend/app/(app)/account/(dashboard)/settings/settings-sections.tsx` | `professional_type === 'brand'` checks | `AccountCapabilities`-driven checks |
| Brand invite acceptance endpoint | Creates `BrandPartnerLink` | Additionally dispatches `AccountTypeTransitionService` |
| Brand-disconnect / partner-removal flow | Removes `BrandPartnerLink` (hard) | Soft-deletes the link; dispatches `AccountTypeTransitionService::transition($pro, AccountType::Individual)` |
| `Comet-Backend/app/Http/Controllers/Api/Internal/HydrogenBrandDesignController.php` | Serves brand-design payload | Unchanged — brand-partner data path stays as-is |
| `Partna-Hydrogen/app/lib/engines/brand-design.server.ts` | Fetches brand styling | Unchanged |
| `Partna-Hydrogen/app/lib/engines/brand-context.server.ts` | Layers brand placeholders / fallback gallery | Unchanged |
| `Partna-Frontend/app/(app)/account/(dashboard)/design/page.tsx` | Brand-only editor | Universal — conditional render per capability flags |
| `Comet-Backend/app/Models/Core/Site/Site.php` | One-per-professional with `settings.design` | Unchanged — data model is already standalone-capable |
| `Comet-Backend` notification preferences endpoint | Returns all categories | Filters by `notification_categories` capability |

## 44. Code that stays completely untouched

To make the blast radius crystal clear:
- All Hydrogen cart logic (`app/lib/cart/`)
- All Hydrogen product engines (`app/lib/engines/cart.server.ts`, `products.server.ts`, `share-link.server.ts`)
- Shopify Storefront API integration
- Shopify Admin API integration
- Stripe Connect onboarding, payout, refund flows (unchanged for brand & partner — gated off for individuals via capabilities)
- Brand Shopify install wizard
- Order processing, commission accrual, payout settlement
- Existing brand-partner storefronts on Oxygen (zero risk of regression)
- DNS configuration for `*.partna.au`
- Cloudflare KV namespace `SUBDOMAIN_KV` (adds a third value type only)
- Auth (Supabase) integration
- Tax info collection (gated off for individuals)
- Webhook handlers (gated for individual events that arrive somehow)
- Existing notification jobs and Email preference rows (filtering at dispatch and serving layers)
- `BrandPartnerLinkAuditor` audit log (already exists; persists independently of soft-delete)

## 45. New code being added (rollup)

**Backend (Comet-Backend):**
- `supabase/migrations/<ts>_add_account_type_to_professionals.sql`
- `supabase/migrations/<ts>_add_soft_deletes_to_brand_partner_links.sql` (§28.16)
- `supabase/migrations/<ts>_add_brand_signup_code_to_brand_profiles.sql`
- `supabase/migrations/<ts>_create_brand_signup_code_audit.sql` (§34)
- One-off Artisan command: `php artisan brand:backfill-signup-codes` (§36)
- `app/Enums/AccountType.php`
- `app/Services/Accounts/AccountCapabilities.php`
- `app/Services/Accounts/AccountCapabilitySet.php`
- `app/Services/Accounts/AccountTypeTransitionService.php`
- `app/Services/Brand/BrandSignupCodeService.php`
- `app/Models/Brand/BrandSignupCodeAuditEntry.php`
- `app/Events/Accounts/AccountTypeTransitionEvent.php`
- `app/Listeners/Accounts/*` (one per side-effect)
- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Http/Controllers/Api/Professional/Brand/BrandSignupCodeController.php`
- Architecture tests (§51): `SubdomainKvWritersTest`, `ThemePackageImportsTest`, `CapabilityDispatchTest`, etc.
- Feature tests (§28.15, §28.16)

**Cloudflare Worker:**
- One new branch in `cloudflare-worker/src/index.js` (cache + Service Binding) + Service Binding declaration in `wrangler.toml` for production + staging (~25 lines combined)

**Shared package (`partna-themes` repo — new):**
- Full repo structure per §23
- CI checks: Shopify-import grep, framework-import grep

**Astro app (`partna-pages` repo — new):**
- Full repo structure per §20 / §47
- Dev workflow per §21

**Frontend (Partna-Frontend):**
- Updates to `lib/account-capabilities.ts` (extend to 3 states)
- Updates to signup-form.tsx (brand_signup_code input)
- Adaptation of `/account/design` editor (conditional render per capability)
- Updates to `settings-sections.tsx` (capability-driven checks)

---

# PART 9 — Implementation plan, structured for two-developer parallel execution

## 46. Track ownership

Two tracks run in parallel, each with a Claude session driven by one developer.

**Track A — Backend (Comet-Backend, owned by backend developer):**
- Account type column + migration + backfill (§28.1, §28.2)
- **BrandPartnerLink soft-delete migration + call-site audit (§28.16)** — critical for ex-partner mechanism
- AccountType enum + AccountCapabilities (§28.3)
- AccountTypeTransitionService + events + listeners (§28.4, §28.5)
- SyncSubdomainToKvJob individual branch (§28.6)
- CloudflarePurgeService + Job (§28.7)
- Public profile API endpoint (§28.8)
- Brand signup code mechanism (§28.9, Part 6 / §32–§36)
- BrandSignupCodeAuditEntry model + audit migration (§34)
- Notification preferences capability filtering (§28.10)
- Feature gating wired through existing notification/Stripe/payout code (§28.11)
- Brand invite acceptance → AccountTypeTransitionService wiring (§28.12)
- Bootstrap flow update (§28.13)
- Individual waitlist flag (§28.14)
- Architecture tests + feature tests (§28.15, §51)

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

**Joint:**
- End-to-end integration testing (§58)
- Cross-track architectural decisions when they arise (§49 STOP protocol)

## 47. Phases with explicit handoff gates

Each phase ends at a gate where the producing track's work is verified and the consuming track can proceed. Both tracks work simultaneously until they hit a gate that depends on the other.

**Gate G0 — Plan locked.**
- Artifact: this plan file with all decisions resolved.
- Both tracks read the plan and acknowledge in their respective sessions.

**Phase 1 (parallel, no inter-dependency):**

| Track A | Track B |
|---------|---------|
| Account type migration + backfill | Hydrogen newsletter refactor |
| BrandPartnerLink soft-delete migration | Hydrogen Shop section refactor |
| AccountType enum + AccountCapabilities service | |
| Backend tests pass | Hydrogen tests pass after refactors |

**Gate G1 — Foundations in place.**
- Track A: migrated DB on dev, AccountCapabilities merged to development branch, soft-delete trait verified
- Track B: Hydrogen development branch with newsletter refactor + Shop section in `sections/Shop/`
- Verification: both branches typecheck and tests pass
- Cross-review: ~30 min, each track reviews the other's work

**Phase 2 (parallel):**

| Track A | Track B |
|---------|---------|
| AccountTypeTransitionService + listeners | Theme package extraction into `partna-themes` repo |
| SyncSubdomainToKvJob individual branch | GitHub Packages publish setup |
| Public profile API endpoint | Hydrogen migrates to consume `@partna/themes` v0.x |
| CloudflarePurgeService | Hydrogen still renders identically on dev |

**Gate G2 — Routing infrastructure ready, package live.**
- Track A: SyncSubdomainToKvJob writes correct individual entries on dev DB; public profile endpoint returns expected shape for test individuals
- Track B: `@partna/themes` v0.1 published to GitHub Packages; Hydrogen consumes it without regression
- Verification: backend dev demos endpoint shape; user demos Hydrogen still rendering brand-partner storefronts identically

**Phase 3 (Track B blocks on G2):**

| Track A | Track B |
|---------|---------|
| Capability gating wired through notification jobs | Astro app init in `partna-pages` repo |
| Notification preferences capability filter | Astro middleware: read Host → handle (with dev fallback per §20) |
| Brand signup code migrations + service + audit table | Astro renders theme-1 with profile fetched from public profile endpoint |
| Tests for capability gates | Cloudflare Workers Static Assets deploy succeeds (staging) |
| Architecture tests for KV writers + import checks | Hydrogen theme 2–5 scaffold deletion |

**Gate G3 — Astro renders, capability gating wired, cache verified.**
- Track A: notification dispatch sends nothing partner-only to a test individual account; preferences endpoint returns filtered list; brand signup code resolution works in BootstrapController; rate limiting active
- Track B: visit the Astro Worker's staging preview → see test theme-1 sitepage rendering with real backend data; lighthouse score acceptable
- **Critical verification:** Service Binding preserves Host header (smoke test per §16 contingency). If not, fall back to `X-Partna-Handle`.
- **Critical verification:** router's `caches.default.put()` populates and `match()` returns cached responses; cache-purge job clears them.

**Phase 4 (parallel, both block on G3):**

| Track A | Track B |
|---------|---------|
| Brand invite acceptance → AccountTypeTransitionService wiring | Cloudflare Worker individual branch + Service Binding deployed to staging |
| Brand signup code dashboard endpoints | Partna-Frontend account-capabilities.ts extended to 3 states |
| AccountTypeTransitionEvent listeners (Stripe toggle, notif setup) | Partna-Frontend signup-form.tsx adjusted (brand_signup_code input) |
| | Partna-Frontend `/account/design` universal |
| | Partna-Frontend settings-sections.tsx updated |

**Gate G4 — End-to-end pieces in place.**
- All pre-integration work done. Both tracks ready for joint integration testing.

**Phase 5 (JOINT — scheduled session):**
- Worker deploys to production with individual branch + Service Binding + cache logic
- Backend writes test individual KV entries
- End-to-end: test individual handle resolves → Worker → cache miss → Service Binding → Astro Worker → backend → renders → cache populated → second request hits cache
- Run the full §58 verification checklist
- Both Claude sessions present so issues can be fixed in real time

**Gate G5 — Production-ready.**
- §58 checklist all green
- Plan is shippable
- Phase II (frontend UI buildout — separate plan) can begin

## 48. Wait/review gates — explicit dependency points

- **Track B waits at G2** before starting Astro work. The Astro app needs the public profile API endpoint to fetch from; building against a non-existent endpoint creates throwaway mock code.
- **Track A waits at G2** for Track B's package publish before doing brand-design payload adjustments (minor — Track A doesn't strictly block on the package).
- **Both tracks wait at G4** before joint Phase 5.
- **Worker production deploy is gated on Phase 5 succeeding.** The Worker change is small and tested in staging, but production rollout doesn't happen until both tracks confirm integration is solid.

## 49. Communication protocol and the big-decision pattern

Both Claude sessions stay in sync via three mechanisms.

**49.1. Shared status file: `~/Developer/IMPLEMENTATION-STATUS.md`** (created at Phase 1 kickoff, not before). Updated by either Claude session at the end of each meaningful unit of work. Format:

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

Update rules: after completing any unit of work that produces a verifiable artifact (PR merged, endpoint live, test passing, file moved), update the file. Add "Notes for [other track]" for anything the other dev should know. Don't update for in-progress thoughts — only completed work or active blockers.

**49.2. GitHub PR cross-reviews.** For each PR either track opens, the other developer reviews — not for code quality (normal review covers that) but for "does this match the plan?". A 5-minute review confirming "matches §X of plan" or "deviates — see comment".

**49.3. claude-mem per-developer corpus.** Each developer's Claude session uses its local claude-mem for cross-session memory of THAT developer's work. The corpora are NOT synced across developers — claude-mem stores per-machine and there's no built-in cross-host sync. **Coordination across developers happens through IMPLEMENTATION-STATUS.md and PR reviews, not claude-mem.** This is a deliberate downgrade from a previous draft that implied cross-corpus sharing — that capability does not exist out of the box.

**The big-decision STOP protocol.** When a Claude session encounters a decision not covered by the plan, it MUST stop and ask. Triggers:
- Non-trivial architectural decision not covered by §50 rules or §51 forbidden patterns
- Schema change beyond what's in the plan
- Capability or transition rule ambiguous for a case the plan doesn't enumerate
- Existing code that the plan said "stays untouched" needs to change
- The change would affect the OTHER track's work
- A new dependency needs to be added
- A capability check is missing somewhere the plan implied it should exist

Format when stopping:

```
[STOP — PLAN DECISION NEEDED]

Context: <one sentence describing where in the work you are>
Decision: <one sentence describing the decision required>
Options:
  A) <option A> — implications: <…>
  B) <option B> — implications: <…>
Recommendation: <which one and one-sentence why>
Affects other track: <yes/no — if yes, what>
Plan section impacted: <e.g. "§43 table row for SyncSubdomainToKvJob">
```

The user reads it, decides, replies. If "affects other track: yes" — user pings the other dev before continuing. After the decision: the Claude session updates this plan file with the decision; IMPLEMENTATION-STATUS.md gets a note.

What NOT to do: don't make the call and proceed silently; don't burn turns silently working around the issue; don't ask vague questions like "is this OK?"; always give options + recommendation.

---

# PART 10 — Non-negotiable rules, conventions, and enforcement

## 50. The 12 non-negotiable rules

Violating any of these is a planning failure, not a coding shortcut. Each rule is paired with its enforcement mechanism in §51 below.

1. **Brand is terminal.** A Professional can become a brand. A brand cannot become anything else. The only exit is full account closure (out of Phase 1). *Enforced: service-layer rejection in `AccountTypeTransitionService` (§12) + DB-level audit assertion.*
2. **Themes don't share visual code with each other.** Each theme in `@partna/themes` is a self-contained bundle. Shared resets, shared tokens, cross-theme components are forbidden. (Preserved from existing Hydrogen rule.) *Enforced: architecture test greps for `from '../theme-N'` imports within sibling theme directories.*
3. **The shared `@partna/themes` package contains no Shopify imports.** Cart code, product engines, the Shop section, and any `@shopify/*` import stays in Hydrogen. *Enforced: CI grep in `partna-themes` repo fails build on `@shopify/` match in `src/`.*
4. **The shared package contains no framework imports.** No `react-router`, `@remix-run/*`, `astro:`, `next/*`. Components take typed props and render. Framework wiring is the consumer's job. *Enforced: CI grep parallel to #3, same failure mode.*
5. **Account type is set explicitly at signup (`BootstrapController`) and post-creation mutations go through `AccountTypeTransitionService` only.** "Has no BrandPartnerLink" alone does not imply individual. **Exception:** the one-time §8 backfill SQL derives `account_type` from BrandPartnerLink presence by design — this is a migration concern, not a runtime path. *Enforced: architecture test asserting no `Professional::query()->update(['account_type' => ...])` calls outside `AccountTypeTransitionService` and the migration files.*
6. **Per-affiliate styling overrides do not exist.** Partners inherit their brand's `site.settings.design`. Individuals use their own Site's `settings.design`. Brands set their own. *Enforced: architecture test in `partna-themes` greps for `affiliate_id` and `partner_id` references in any `design`-typed code path; fails on match.*
7. **Brand-fallback content (placeholders, fallback_gallery, brand logo, brand slogan) stays in Hydrogen's data path.** The Astro app for individuals never sees them. *Enforced: feature test against `IndividualProfileController` response asserting absence of `placeholders`, `fallback_gallery`, `brand_logo`, `brand_slogan` keys.*
8. **The shared namespace is `Professional.handle`.** Brand slugs and individual handles share one column. Uniqueness is enforced at the table level. *Enforced: DB UNIQUE constraint on `core.professionals.handle` (already exists).*
9. **`SyncSubdomainToKvJob` is the single writer of `SUBDOMAIN_KV`.** No other code writes to the namespace. *Enforced: architecture test `SubdomainKvWritersTest` greps the codebase for `SUBDOMAIN_KV->put|SUBDOMAIN_KV.put|->putValue(.*SUBDOMAIN` and asserts the only matches are inside `SyncSubdomainToKvJob`.*
10. **Capability checks happen at the dispatch layer, not just the UI layer.** Notification jobs and API responses re-check `AccountCapabilities` before acting. *Enforced: architecture test `CapabilityDispatchTest` iterates every class in `app/Jobs/Notifications/` and asserts at least one reference to `AccountCapabilities`; same for `app/Http/Controllers/Api/`.*
11. **Partner → individual transitions are NEVER blocked by pending payouts.** The ex-partner panel + soft-deleted BrandPartnerLinks preserve everything. Any text or test claiming otherwise is a bug. *Enforced: feature test in §58 verifying the transition succeeds with pending payout rows present.*
12. **`account_type` transitions FROM brand are forbidden at the service layer.** `AccountTypeTransitionService` throws a domain exception. *Enforced: same as #1, plus dedicated unit test asserting the exception.*

## 51. Forbidden patterns and architecture tests

When reviewing or writing code, these are tripwires — flag immediately if you see one. Each is paired with the architecture test or CI mechanism that catches it.

| Forbidden pattern | Where caught |
|---|---|
| Importing `@shopify/*` anywhere in `partna-themes` or `partna-pages` | CI grep in repo (per §24, rule #3) |
| Importing from `react-router`, `@remix-run/*`, `astro:`, `next/*` in `partna-themes` | CI grep in repo (per §24, rule #4) |
| Reading `professional_type` in new code (use `account_type`) | PreToolUse hook (§55) on Edit/Write in `Comet-Backend/app/` warns if added |
| Checking `! $pro->brandPartnerLinks` to mean "individual" (use `$pro->account_type === 'individual'`) | Architecture test grep |
| Writing to `SUBDOMAIN_KV` outside `SyncSubdomainToKvJob` | `SubdomainKvWritersTest` (rule #9) |
| Adding "this is the placeholder for [field] if missing" logic to themes (defaults computed in engines, not in section/component code) | Code review checklist; not auto-enforced |
| Per-affiliate styling overrides | Architecture test (rule #6) |
| `account_type` transitions from `brand` | `AccountTypeTransitionService` unit test (rule #12) |
| Notification dispatch without a capability check | `CapabilityDispatchTest` (rule #10) |
| New `/account/*` routes added without route-allowlist entries in `account-capabilities.ts` | Frontend architecture test |
| Using `fetch()` from the router Worker to call the Astro Worker (use Service Binding) | Cloudflare Worker test asserting `env.PARTNA_PAGES.fetch(...)` is the call; not `fetch("https://...")` |
| Hardcoded brand-fallback content (placeholders, fallback_gallery) in the Astro app | Feature test (rule #7) |
| **Returning cacheable Worker responses without populating `caches.default`** | Cloudflare Worker test asserting `ctx.waitUntil(cache.put(...))` is called on cacheable GET responses |

## 52. Conventions (reviewed by humans, not auto-enforced)

These aren't programmatically enforceable but matter:
- Prefer registry-pattern over `if`-chains for any account-type-dependent behavior
- Treat `IMPLEMENTATION-STATUS.md` as a living document; update at every meaningful checkpoint
- When the plan and reality diverge during execution, update the plan via the STOP protocol — don't silently work around it

---

# PART 11 — Claude tooling setup before implementation

## 53. MCPs each developer needs

Most are already installed for the user. Backend dev should install parity on shared ones.

**Both developers:**
- `claude-mem` (mcp-search) — cross-session memory (per-developer; not cross-developer)
- `context7` — current library docs
- `github` — PR creation, review, cross-repo coordination

**User-side (Hydrogen + Astro + Frontend + Cloudflare):**
- `chrome-devtools` — manual testing of the Astro app
- `vercel` — Partna-Frontend deploy verification
- `shopify-dev-mcp` — Hydrogen-side changes

**Backend-side (Comet-Backend):**
- `laravel-boost` — `tinker`, `database-query`, `database-schema`, `list-routes`, `search-docs` (NOT log tools per repo CLAUDE.md)
- `supabase` — migrations and schema management

## 54. Project-specific skills to create

Create in `~/.claude/skills/partna-individual-pages/` (NOT `~/.agents/skills/` — that path is incorrect and skills there would not load):

1. **`partna-plan-check`** — auto-triggers when Claude modifies a file related to this plan. Reads the plan file and verifies the change doesn't violate §50 rules or §51 patterns.

2. **`account-capability-audit`** — auto-triggers when adding a new notification job, API endpoint, or dashboard route. Verifies capability check is in place.

3. **`theme-portability-check`** — auto-triggers when editing any file under `@partna/themes` or its source equivalents in Hydrogen. Greps for forbidden imports.

4. **`partna-handoff-status`** — manual skill that updates `IMPLEMENTATION-STATUS.md` with completed work for cross-dev coordination.

These are `SKILL.md` files with standard skill structure. The plan-check skill is the highest leverage — prevents the most common deviation.

## 55. Hooks per repo

In `.claude/settings.local.json`:

**`Partna-Hydrogen`:**
- `PreToolUse` on Edit/Write to `app/themes/**` — grep for `react-router` imports, warn if found
- `PreToolUse` on Edit/Write to `app/lib/cart/**` — remind that cart code is Hydrogen-only, do not extract

**`partna-themes` (when created):**
- `PreToolUse` on Edit/Write across the repo — grep for `react-router|@shopify/|@remix-run/|astro:|next/`, BLOCK if found
- `PostToolUse` on Write — remind to bump package version if public-facing export changed

**`partna-pages` (when created):**
- `PreToolUse` on Edit/Write — grep for `@shopify/` imports, block
- `PostToolUse` on any new `client:` directive — remind to verify Motion/PDF still works under the new hydration mode
- `PreToolUse` on Edit/Write to the Worker entry — remind that cacheable responses must call `ctx.waitUntil(cache.put(...))`

**`Comet-Backend`:**
- `PreToolUse` on Edit/Write to notification jobs — grep for `AccountCapabilities`, warn if absent
- `PreToolUse` on Edit/Write to any controller in `Api/` — same
- `PreToolUse` on Edit/Write to `app/Jobs/Cloudflare/*` — warn if any non-`SyncSubdomainToKvJob` file touches `SUBDOMAIN_KV` (rule #9)

## 56. Per-repo CLAUDE.md additions

Every affected repo gets the §57 drop-in inserted as a new section. Plus repo-specific additions per Part 11. Backend dev's CLAUDE.md adds:

- "`Professional.account_type` is the source of truth. `professional_type` is legacy (dual-write for migration). Don't read `professional_type` in new code."
- "Notification jobs and API endpoints MUST check `AccountCapabilities::for($pro)` before acting."
- "`SyncSubdomainToKvJob` is the ONLY writer to `SUBDOMAIN_KV`. All routing changes go through it."
- "`BrandPartnerLink` uses soft-delete. To query historical links, use `withTrashed()`."

User's frontend CLAUDE.md adds:
- "`lib/account-capabilities.ts` returns three-state capabilities (brand/partner/individual). Every new dashboard route MUST be added to the route allowlist."
- "`/account/design` is universal — same route for all account types, conditional UI via capability flags."

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

The Worker has ONE writer: `SyncSubdomainToKvJob`. Never write KV elsewhere.

Both apps render from `@partna/themes` (GitHub Packages, per-theme bundles +
shared engines/brand/analytics/icons/motion). The package is Shopify-free
and framework-free. Hydrogen adds the Shop section locally; Astro doesn't.

Account capabilities (frontend: `lib/account-capabilities.ts`; backend:
`App\Services\Accounts\AccountCapabilities`) are the source of truth for
what features each type sees. Every notification dispatcher, route guard,
and API response checks capabilities before acting. Defence in depth.

Brand-partner transitions go through `AccountTypeTransitionService`.
Brand → anything is forbidden. Partner ↔ individual is seamless;
historical brand-partner data persists indefinitely in an "ex-partner panel"
via SOFT-DELETED `BrandPartnerLink` rows (Laravel SoftDeletes trait).
Transitions are NEVER blocked by pending payouts. The
individual→brand transition keeps `account_type='individual'` until
`brand_status='Live'`; AccountTypeTransitionService then flips to 'brand'.

Per-individual styling uses the existing per-Site `settings.design` JSONB.
Partners inherit brand styling (no per-affiliate overrides). Brand fallback
content (placeholders, fallback gallery) lives ONLY in Hydrogen's data path.

Worker responses are NOT auto-cached from Cache-Control alone. The router
Worker MUST call `caches.default.put(request, response.clone())` to
populate the edge cache. The cache-purge job invalidates by URL.

Full plan: `~/Developer/PARTNA-STANDALONE-PAGES-NEW-DIRECTION.md`.
```

---

# PART 13 — End-to-end verification

## 58. Verification checklist (run before Gate G5)

**Individual path:**
- [ ] Create a test individual professional via DB seed
- [ ] Their handle appears in `SUBDOMAIN_KV` with `{type:'individual'}`
- [ ] First visit to `<handle>.partna.au` → Worker checks cache (miss) → Service Binding → Astro renders theme-1 → all individual-applicable sections render
- [ ] **Second visit hits the cache** (verified via Worker debug log or `cf-cache-status` header)
- [ ] Shop section is absent; no Shopify API calls in network tab
- [ ] Edit the test profile → cache purge fires → next visit shows updated content
- [ ] Visit an unknown handle → Worker 404s cleanly
- [ ] Analytics events from the individual page appear in backend dashboard
- [ ] **Host header is preserved in Astro middleware via Service Binding** (verified via instrumentation) — if not, fall back to `X-Partna-Handle` per §16 contingency

**Brand-partner path (regression check):**
- [ ] Existing brand-partner storefronts render identically to before this work
- [ ] Visit `<affiliate>.partna.au` → 301 redirect to `<brand>.partna.au/<affiliate>` → Hydrogen renders with Shop section live
- [ ] Cart/checkout flow works
- [ ] All existing Hydrogen tests pass

**Transitions:**
- [ ] individual → partner: account type updates, KV entry switches, Shop now appears, order notifications enabled
- [ ] partner → individual: account type updates, KV entry switches, Shop disappears, partner-only notifications disable, ex-partner panel becomes visible
- [ ] **Partner with pending payouts CAN still transition to individual** (must not be blocked)
- [ ] **BrandPartnerLink rows are soft-deleted on partner→individual** (visible via `withTrashed()`, not visible via default scope)
- [ ] **Re-joining the same brand creates a NEW BrandPartnerLink row** (does not restore soft-deleted)
- [ ] Concurrent transition attempts fail safely (no partial state)
- [ ] Brand → partner attempt fails with clean domain exception
- [ ] individual → brand stays at `account_type='individual'` until `brand_status='Live'`; then flips atomically

**Capability gating:**
- [ ] Individual user: API responses don't include order/commission fields
- [ ] Individual user: no Stripe Connect prompts in onboarding
- [ ] Individual user: doesn't receive partner-only notifications
- [ ] Partner user: full feature set works as today
- [ ] Brand user: brand features work as today
- [ ] `IndividualProfileController` response contains NO `placeholders`, `fallback_gallery`, `brand_logo`, `brand_slogan` keys (rule #7)

**Brand signup code path:**
- [ ] Brand can view their signup_code in the dashboard
- [ ] Brand can rotate the code; old code stops working immediately
- [ ] Brand can deactivate the code; new signups with that code are rejected
- [ ] Signup with active brand signup code creates Professional with `account_type='partner'` + BrandPartnerLink
- [ ] Single-brand cap is respected for code-based signups; friendly error if user already has a brand
- [ ] Rate limiting kicks in after 10 attempts/min/IP
- [ ] `BrandSignupCodeAuditEntry` rows are written for all events (generated, rotated, claimed, failed_claim)
- [ ] Brand-signup-code path is **exempt from individual waitlist** when waitlist flag is on

**Infrastructure:**
- [ ] Cloudflare Workers Static Assets deploy succeeds for the Astro app (production + staging)
- [ ] Worker handles all three KV types correctly in production
- [ ] Service Binding from router to Astro Worker works in production
- [ ] Service Binding from router to staging Astro Worker works in staging environment
- [ ] Cache purge API token works and rate limits are respected
- [ ] **Astro Worker has no public route in production** (architecture test in `partna-pages/wrangler.toml`)
- [ ] **`caches.default.put()` is called for individual-branch cacheable responses** (Worker test)

**Architecture tests pass:**
- [ ] `SubdomainKvWritersTest` — only `SyncSubdomainToKvJob` writes `SUBDOMAIN_KV`
- [ ] `ThemePackageImportsTest` — no Shopify or framework imports in `partna-themes/src/`
- [ ] `CapabilityDispatchTest` — every notification job + every Api/ controller references `AccountCapabilities`
- [ ] `AstroWorkerRouteTest` — `partna-pages` has no public route
- [ ] Frontend route-allowlist coverage test

**Data integrity:**
- [ ] `account_type` and `professional_type` stay in sync via the dual-write trigger
- [ ] Soft-deleted `BrandPartnerLink` rows are retained (no purge job runs against them)
- [ ] Ex-partner panel shows accurate historical data for ex-partners
- [ ] `commerce.orders.affiliate_professional_id` queries resolve correctly regardless of BrandPartnerLink soft-delete state

---

# PART 14 — Open questions resolved

All architectural questions have been resolved through iterative review:

1. ✅ Account types: `brand` / `partner` / `individual`
2. ✅ Pending payouts on partner→individual: NEVER blocked. Ex-partner state preserves history forever via soft-delete.
3. ✅ Brand-downgrade: never allowed; brand is terminal
4. ✅ Package distribution: GitHub Packages private registry (free at our scale within 500MB storage / 1GB transfer/month limits)
5. ✅ Shop section refactor: bundle into theme extraction work
6. ✅ `/account/design`: universal route with capability-driven UI
7. ✅ Brand invite code: per-brand opaque code stored on `brand_profiles` (see Part 6)
8. ✅ Brand-removes-partner with live data: trust architecture (ex-partner panel handles it), verify in integration testing
9. ✅ Partner of SystemsDown brand: stay as partner, show dashboard banner
10. ✅ Individual waitlist flag: add `SIDEST_INDIVIDUAL_WAITLIST_ENABLED`, default off; exempts partner-via-invite AND partner-via-signup-code
11. ✅ Hosting target: Cloudflare Workers Static Assets (NOT Pages — `@astrojs/cloudflare` v13 adapter dropped Pages support; Astro framework is 6.x)
12. ✅ Worker → Astro handoff: Service Binding (preserves Request; Host preservation verified at G3 smoke test)
13. ✅ Cache mechanism: explicit `caches.default.put()` in router Worker; purge by URL invalidates
14. ✅ BrandPartnerLink soft-delete: required migration (§28.16); without it, ex-partner mechanism does not work
15. ✅ Individual → brand transition: keeps `account_type='individual'` until brand_status='Live', then atomic flip
16. ✅ Public profile endpoint auth: truly public (no auth), rate-limited at 60/min/IP
17. ✅ Astro Worker local dev: three modes (Astro alone with X-Dev-Handle, both Workers locally, staging environment)
18. ✅ claude-mem cross-developer sync: not used; coordination via IMPLEMENTATION-STATUS.md + PR reviews
19. ✅ Skill directory: `~/.claude/skills/` (NOT `~/.agents/skills/`)
20. ✅ Stripe state on partner→individual: `stripe_connect_status` stays at current value (typically 'active'); enum has no 'inactive' value

---

# PART 15 — Audit findings resolution log

This appendix documents every finding from the v2 audit (`AUDIT-OF-STANDALONE-PAGES-PLAN-V2.md`) and how the rebuild addressed it. For findings where the rebuild deviated from the audit's recommendation, the rationale is given.

## 59. Resolution table

| Audit ref | Finding | Resolution in rebuild | Section |
|-----------|---------|----------------------|---------|
| v2 §3.1 | Astro version: rebuild said "Astro v13", audit said "Astro 6.3.1" | **Both partially right.** Verified via docs.astro.build: framework is Astro 6.3.1; `@astrojs/cloudflare` adapter is v13.5.2. Clarified the conflation; the plan now distinguishes framework version from adapter version. | §15 |
| v2 §3.1 | Cloudflare/Astro acquisition date wrong (Feb vs Jan 2026) | Verified via Cloudflare press release: Jan 16, 2026. Corrected. | §15 |
| v2 §3.1 | PR #15480 number/version | Verified PR exists on `6-beta` branch. The "v13.0.0-beta.8" framing was the adapter beta version. Plan now references it correctly. | §15 |
| v2 §3.2 | Service Bindings not double-billed | Verified. Confirmed as written. | §16 |
| v2 §3.2 | Subrequest limit on Workers Paid: audit said 1,000, plan said 50 | **Both wrong.** Verified via Cloudflare Workers Platform Limits docs: Workers Free is 50; **Workers Paid is 10,000 by default**, configurable to 10M. Plan now states 10,000. The v2 audit was off by 10×; previous plan was off by 200×. | §16, §37 |
| v2 §3.2 | Service Bindings Host preservation undocumented | Acknowledged as a smoke test at G3 with `X-Partna-Handle` fallback documented. | §16, §47 G3 |
| v2 §3.3 | Cache mechanism: Cache-Control headers alone don't cache Worker responses | Verified via "How the Cache Works" docs. Rewrote §18 with explicit `caches.default.put()` pattern. Added forbidden pattern to §51. | §18, §51 |
| v2 §3.4 | Workers Paid pricing | Verified all numbers correct. No change needed. | §37 |
| v2 §3.5 | Workers Builds metric: minutes not 500/month | Verified. Corrected to 3,000 min/month Free, 6,000 min/month Paid + $0.005/min. | §37 |
| v2 §3.6 | Workers Static Assets bandwidth free/unlimited | Verified. Confirmed. | §15, §37 |
| v2 §3.7 | Cache purge rate limits | Verified. Confirmed including "bucket" terminology. | §18 |
| v2 §3.8 | GitHub Packages free tier | Verified (500 MB / 1 GB). Removed misleading "50 GB" parenthetical. | §22 |
| v2 §3.9 | BrandPartnerLink soft-delete not supported in code | **MUST-FIX applied.** Verified via Bash: model has only HasUuids; `BrandPartnerLinkService::disconnectBrandFromAffiliate` calls `$target->delete()` (hard). Added §28.16 migration + trait + call-site audit. Track A owns it. Added to verification checklist (§58). | §28.16, §43, §46, §58 |
| v2 §4.1 | §10 / §27.9 cross-references to "§29" | Fixed both to point at Part 6 / §32. | §10, §28.9 |
| v2 §4.2 | §9 capability count off | Recounted: 16 boolean capabilities + 2 configuration values = 18 total rows. Plan now states 16 + 2 explicitly; total ~60 test cases. | §9 |
| v2 §4.3 | §13 vs §27.13 brand interim state inconsistency | Resolved: `account_type` stays `'individual'` during brand onboarding; flips to `'brand'` only when `brand_status='Live'`. Applied consistently across §13, §28.13, §10 (transition matrix), §31.2 (signup form), §58 (verification). | §13, §28.13, §10, §31.2, §58 |
| v2 §4.4 | §36 cost table understated free tier (3M vs actual 15M) | Rebuilt §38 table applying 80% cache hit consistently. Free tier supports ~15M page views/month. | §38 |
| v2 §4.5 | §37 cost table math wrong ($5 at 10K users) | Rebuilt §39 table from unit economics. 10K users (5M views) = 1M invocations = still Free tier ($0). | §39 |
| v2 §4.6 | §49 #5 vs §8 backfill conflict | Rule rephrased with explicit exception: backfill SQL is the migration-time deliberate exception; runtime mutations go through `AccountTypeTransitionService`. | §8, §50 #5 |
| v2 §4.7 | Themes 2-5 duplication (Hydrogen + partna-themes) | Resolved: Hydrogen scaffolds are deleted in Phase 3. Source of truth for themes 2-5 moves to `partna-themes`. | §4, §30.3, §42, §47 Phase 3 |
| v2 §4.8 | §27.14 waitlist + brand-signup-code interaction ambiguous | Explicitly exempted brand-signup-code path from waitlist. | §28.14, §58 |
| v2 §5.1 | BrandPartnerLink soft-delete missing | (Same as v2 §3.9 — addressed in §28.16.) | §28.16 |
| v2 §5.2 | Cache mechanism broken | (Same as v2 §3.3 — addressed in §18.) | §18 |
| v2 §5.3 | Skill directory path wrong | Fixed from `~/.agents/skills/` to `~/.claude/skills/`. | §54 |
| v2 §5.3 | Staging Service Binding config missing | Added `[env.staging]` block to wrangler.toml example. | §16, §29.2 |
| v2 §5.4 | Astro dev workflow not documented | Added §21 covering three modes (Astro alone, both Workers, staging). | §21 |
| v2 §5.5 | Signup-code rate limiting missing | Added tiered rate limiting spec to §33 + audit logging via `BrandSignupCodeAuditEntry`. | §33, §34 |
| v2 §5.6 | Audit log infra for signup-code lifecycle | Verified existing pattern via Bash: `StaffAuditEntry`, `ProfessionalDeletionAuditEntry`, `BrandPartnerLinkAuditor` already exist. Added §34 spec for `BrandSignupCodeAuditEntry` following same pattern. | §34 |
| v2 §5.7 | claude-mem cross-developer sync unspecified | Removed cross-corpus framing. Plan now states per-developer corpora; coordination via IMPLEMENTATION-STATUS.md + PR reviews. | §49.3 |
| v2 §5.10 | Public profile endpoint auth ambiguous | Picked: truly public, no auth. Rate-limited 60/min/IP at controller. | §28.8 |
| v2 §5.11 | Astro Worker observability not addressed | Not yet expanded; deferred to Phase 3 (since the Astro Worker is built then) but flagged for the implementer. **Partial resolution** — this is acknowledged as out-of-scope for plan v3 because observability decisions (Sentry? Logflare? plain console?) depend on cost / tooling choices that haven't been made yet. To be answered at Phase 3 via STOP protocol. | (deferred) |
| v2 §6 #2 | Rule #2 (themes don't share visual code) honor system | Added enforcement: architecture test grepping for cross-theme imports. | §50 #2, §51 |
| v2 §6 #4 | Rule #4 (no framework imports) lacks CI mechanism | Added: CI grep parallel to rule #3. | §24, §50 #4 |
| v2 §6 #6/#7/#9/#10 | Honor-system rules | Added enforcement mechanisms: architecture tests for KV writers, capability dispatch, no per-affiliate styling, no brand-fallback in Astro response. | §50, §51 |
| v2 §7.1 | Brand signup code rate limiting missing | Added per §33. | §33 |
| v2 §7.2 | Code visibility audit on rotation | Added: BrandSignupCodeAuditEntry records rotation events; dashboard surfaces "attempts against rotated code." | §33, §34 |
| v2 §7.3 | Successful claim audit log entry | Added: `'claimed'` event written to BrandSignupCodeAuditEntry. | §33, §34 |
| v2 §7.4 | "Already a partner of another brand" friendly error | Added: signup flow catches `BrandPartnerLinkService` exception and surfaces friendly message naming the existing brand. | §33 |
| v2 §7.5 | Single-brand cap citation | Added: cites `app/Services/Professional/Brand/BrandPartnerLinkService.php:11` Pilot/V1 comment. | §33 |
| v2 §7.6 | Migration safety: §32 PHP vs §34 SQL generation | Resolved: code generation lives in `BrandSignupCodeService::generate()` called from `BrandProfile::creating` hook (PHP only). Backfill uses an Artisan command that calls the same generator. Single source of truth. | §33, §36 |
| v2 §7.7 | Concurrent insert safety in migration | Resolved: `creating` hook fills code at app layer for all new rows during backfill window. No DB default needed; no race. | §36 |
| v2 §8.1 | Workers Paid pricing | Verified. | §37 |
| v2 §8.2 | Workers Builds wrong metric | Corrected. | §37 |
| v2 §8.3 | Subrequest limit wrong | Corrected (10,000 — see v2 §3.2 resolution). | §16, §37 |
| v2 §8.4 | §36 cost table contradictory | Rebuilt. | §38 |
| v2 §8.5 | §37 cost table math doesn't compute | Rebuilt. | §39 |
| v2 §8.6 | Vercel comparison outdated | Updated to current Vercel Pro pricing ($20 + $0.40/GB after 1 TB). 5 TB scenario = ~$1,640. | §40 |
| v2 §8.7 | Traffic assumption 500 views/user/month overstated | Acknowledged as intentionally conservative. Noted in §39. | §39 |
| v2 §8.8 | Astro SSR CPU-ms not estimated | Added estimate (10–50ms typical) with caveat to measure in Phase 3. | §37, §38 |
| v2 §9 #5 (open question) | BrandPartnerLink soft-delete migration timing | Resolved: Phase 1, Track A. (See §28.16.) | §28.16, §46 |
| v2 §9 #6 (open question) | Brand interim state | Resolved per §13. | §13 |
| v2 §9 #8 (open question) | claude-mem sync | Resolved: not used cross-developer. | §49.3 |
| v2 §9 #9 (open question) | Astro dev workflow | Resolved per §21. | §21 |
| v2 §9 #17 (open question) | Brand audit log infra | Resolved per §34. | §34 |
| v2 §9 #18 (open question) | DB migration on live professionals | Addressed in §8: small table, no blue-green needed, batching path documented if scale grows. | §8 |
| v1 §4.8 (original, missed by v2) | BrandPartnerLink soft-delete asserted but unverified | Now addressed via §28.16. | §28.16 |
| v1 §5.2 (original) | Stripe "inactive" state assumption | Resolved: there is no "inactive" enum value (`stripe_connect_status ∈ {not_connected, onboarding, active, restricted, disconnected}`). Plan now correctly states status stays at current value (typically 'active'). | §11 |

## 60. Audit findings the rebuild challenged or corrected

A small number of v2 audit findings were inaccurate on closer inspection. Documenting for the next reviewer pass:

| Audit claim | Rebuild's finding | Evidence |
|-------------|-------------------|----------|
| v2 §3.2: "Subrequest limit on Workers Paid is 1,000 default" | **10,000**, not 1,000. | Cloudflare Workers Platform Limits docs explicitly state 10,000 default on Paid, configurable to 10M. |
| v2 §3.1: "Astro v13 doesn't exist; current Astro is v6.3.1" | True for the framework, but "v13" was the adapter version (`@astrojs/cloudflare@13.5.2`). The previous plan conflated them; the v2 audit treated "v13" as fully wrong rather than misattributed. | docs.astro.build adapter docs list v13.5.2 as current adapter version targeting Astro 6. |
| v2 §3.2: "Service Bindings Host header preservation undocumented" | True. Acknowledged. But in practice, Service Bindings DO pass the Request intact — the Host-rewrite restriction in the docs targets outbound `fetch()` to external URLs, not Service Bindings within zone. The plan handles this with G3 smoke test + `X-Partna-Handle` fallback rather than depending on undocumented behavior. | Same source — docs simply don't address this explicitly. |
| v2 §10.2 (proposed text): "X-Partna-Handle as the primary mechanism" | The rebuild keeps Host-header as primary and falls back to X-Partna-Handle only if smoke test fails. Saves complexity in the common case. | Architectural simplicity tradeoff. |

---

**End of plan.**
