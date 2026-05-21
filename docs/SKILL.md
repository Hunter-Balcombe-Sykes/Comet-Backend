---
name: account-capability-audit
description: Use when adding or modifying a notification dispatcher job, API endpoint, controller, policy, middleware, or scheduled task in Comet-Backend - verifies the code consults AccountCapabilities before acting. The standalone-pages plan requires defence-in-depth capability gating; this skill catches "I added a new feature but forgot the capability gate" before commit.
---

# Account Capability Gating Audit

For new or modified code in the notification / API / policy / middleware / scheduled-task surface, verify capability gating is in place. The plan rule (§49 #10): capability checks happen at the dispatch layer, not just the UI layer. Even if frontend gates it, backend re-checks.

## When to use

Triggers when about to Write or Edit:
- A new file under `Comet-Backend/app/Jobs/Notifications/`
- A new file under `Comet-Backend/app/Http/Controllers/Api/Professional/`, `Api/Staff/`, `Api/PublicSite/` (non-public endpoints)
- A new file under `Comet-Backend/app/Policies/`
- A new file under `Comet-Backend/app/Http/Middleware/`
- A new scheduled task in `app/Console/Commands/` that runs against professionals
- Modifications to an existing such file that adds a new branch or capability assumption

## What to check

1. Does the code path apply to all account types, or only some? (brand / partner / individual)
2. If only some, does it consult `AccountCapabilities::for($pro)->relevant_capability` before acting?
3. If it dispatches a notification, does it check the appropriate `receives_X_notifications` capability?
4. If it's a partner-only or brand-only endpoint, does the policy / middleware return **404 (not 403)** for ineligible accounts per the project doctrine (`Comet-Backend/CLAUDE.md`)?
5. If it's a webhook handler, does it skip enqueueing partner-only jobs for events arriving for an individual (the ex-partner edge case where a Stripe event arrives after a transition)?

## Reference capabilities (from plan §9)

Boolean flags on `AccountCapabilitySet`:
- `requires_stripe_connect`, `requires_tax_info`, `requires_payout_schedule`
- `shows_shop_section`, `shows_commissions_dashboard`, `shows_orders_dashboard`, `shows_affiliates_dashboard`, `shows_ex_partner_panel`
- `receives_order_notifications`, `receives_payout_notifications`, `receives_payout_settlement_notifications`, `receives_commission_notifications`, `receives_brand_status_notifications`, `receives_invite_notifications`
- `can_have_brand_link`, `can_edit_design`

Configuration values:
- `notification_categories` (filtered list)
- `worker_kv_type` (returns `"brand"`, `"affiliate"`, or `"individual"`)

## What to do if missing

1. Identify which capability flag the new code path corresponds to (see matrix in plan §9).
2. If the capability doesn't exist yet, propose adding it to `AccountCapabilitySet` + matrix entries for all 3 account types.
3. Add the check at the appropriate gate — outer gate for entry-point controllers/jobs; per-branch for interior branches in Stripe controllers (which have ~10 `$role === 'brand'` interior branches that need migrating to capability reads).

## Output

Either:
- "Capability gating present and correct" + cite the line in the new code
- "MISSING: this [job/endpoint/policy/middleware] should check `AccountCapabilities::for($pro)->X` before [action]. Recommend snippet: `if (! AccountCapabilities::for($pro)->X) { return; }`"

## Reference

Plan at `~/Developer/PARTNA-STANDALONE-PAGES-NEW-DIRECTION.md`. §9 (capability matrix), §28.11 (full call-site catalogue including the ~40–55 touch points across Stripe / Commission / webhook / middleware / resource / controller paths), §51 (architecture test `CapabilityDispatchTest` enforces).
