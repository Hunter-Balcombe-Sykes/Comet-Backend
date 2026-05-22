---
name: partna-plan-check
description: Use when editing code in the blast radius of the standalone-pages plan (Professional model, BrandPartnerLink, SyncSubdomainToKvJob, AccountCapabilities, AccountTypeTransitionService, account_type column, signup-form.tsx, BootstrapController, BrandSignupCode*, files under @partna/themes or partna-pages, Cloudflare Worker routing) - reads the plan's non-negotiable rules and forbidden patterns and flags violations before the edit lands. Auto-triggers on these file paths.
---

# Partna Standalone Pages — Plan Check

The standalone-pages plan locks specific architectural rules. When editing code in its blast radius, verify the change doesn't violate one before the edit lands.

## When to use

Triggers when about to Write or Edit any of:
- `Comet-Backend/app/Models/Core/Professional/Professional.php` or related casts
- `Comet-Backend/app/Models/Core/Professional/BrandPartnerLink.php`
- `Comet-Backend/app/Services/Professional/Brand/BrandPartnerLink*Service.php`
- `Comet-Backend/app/Jobs/Cloudflare/SyncSubdomainToKvJob.php` or `RetireSubdomainFromKvJob.php`
- `Comet-Backend/app/Services/Accounts/*` (AccountCapabilities, AccountTypeTransitionService, AccountCapabilitySet)
- `Comet-Backend/app/Http/Controllers/Api/PublicSite/BootstrapController.php`
- Any new migration adding/altering `account_type`, `professional_type`, or `brand_partner_links.deleted_at`
- `Comet-Backend/cloudflare-worker/src/index.js`
- Any file under `partna-themes/` (when that repo exists)
- Any file under `partna-pages/` (when that repo exists)
- `Partna-Hydrogen/app/themes/*` or `app/lib/cart/*` or `app/lib/engines/newsletter.ts`
- `Partna-Frontend/app/(app)/account/(auth)/sign-up/signup-form.tsx`
- `Partna-Frontend/lib/account-capabilities.ts`

## What to do

1. Read the plan at `~/Developer/PARTNA-STANDALONE-PAGES-NEW-DIRECTION.md` — focus on §49 (non-negotiable rules) and §50 / §51 (forbidden patterns and architecture tests).
2. For the proposed change, walk through each rule that could apply:
   - **Brand transitions forbidden in both directions.** Code must not call `AccountTypeTransitionService::transition($pro, AccountType::Brand)` from anywhere; must not create paths from `brand` to any other type. Brand is set at signup ONLY by `BootstrapController`.
   - **SUBDOMAIN_KV writes/deletes only from `app/Jobs/Cloudflare/`.** No controller, service, listener, or other job may call `CloudflareKvService::put()` or `->delete()`.
   - **No `@shopify/*` imports in `partna-themes` or `partna-pages`.** Shop section and its Shopify deps stay in Hydrogen.
   - **No `react-router`, `@remix-run/*`, `astro:`, `next/*` imports in `partna-themes`.** Themes are framework-agnostic; consumers wire the framework.
   - **`account_type` is the source of truth.** New code reads `account_type`, not `professional_type`. "Has no BrandPartnerLink" alone does NOT imply individual.
   - **Capability checks at dispatch layer.** Every new notification job, controller, policy, middleware consults `AccountCapabilities::for($pro)` before acting — defence in depth.
   - **Brand-fallback content stays Hydrogen-only.** Don't add `placeholders`, `fallback_gallery`, `brand_logo`, `brand_slogan` to the Astro app's data path.
   - **Per-affiliate styling overrides don't exist.** Partners inherit brand styling.
   - **Soft-delete `BrandPartnerLink`.** Don't add new hard-delete call sites; existing `$target->delete()` becomes soft-delete via the trait.

3. If a potential violation is found, flag it BEFORE the edit lands. Reference the specific rule number.

## Output

Either:
- "Plan-check passes — no violations" + brief justification of which rules were checked
- "VIOLATION (rule #X): [description]. Proposed change [...]. Recommend: [alternative]."

## Reference

Plan at `~/Developer/PARTNA-STANDALONE-PAGES-NEW-DIRECTION.md`. Part 12 contains rules; Part 15 / 16 contain the audit-resolution log showing what's already been decided across three review passes.
