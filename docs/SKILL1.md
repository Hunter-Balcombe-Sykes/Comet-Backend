   ---
name: theme-portability-check
description: Use when editing files under Partna-Hydrogen/app/themes/ or partna-themes/src/ - greps for forbidden imports that would break theme portability between Hydrogen (React Router) and Astro consumers. The shared theme package must be framework-agnostic and Shopify-free. Shop section is the only Shopify-coupled exception and stays in Hydrogen-only files.
---

# Theme Portability Check

Theme files must not import framework-specific or Shopify-specific code (except inside the Shop section, which stays in Hydrogen). The shared `@partna/themes` package is consumed by both Hydrogen (React Router 7) and Astro (Cloudflare Workers Static Assets) — themes must work in both.

## When to use

Triggers when about to Write or Edit:
- Any file under `Partna-Hydrogen/app/themes/` (excluding `sections/Shop/` and `components/expandable/ShopExpandableCard/` which are Hydrogen-only)
- Any file under `partna-themes/src/` (when that repo exists)
- Any file under `partna-pages/src/` (when that repo exists)

## Forbidden imports (flag if found)

- `from 'react-router'` or `from '@remix-run/*'` — themes are framework-free; React-Router-specific code lives in `app/lib/cart/` or `app/lib/engines/` (Hydrogen-only) or in the consumer's app
- `from '@shopify/hydrogen'` or `from '@shopify/hydrogen-react'` — Shopify imports stay in the Shop section only; the shared package contains zero `@shopify/*` references
- `from 'astro:*'` or `from 'next/*'` — themes don't know which framework consumes them
- Cross-theme imports (`from '../theme-N/...'`) — themes are self-contained per existing Hydrogen rule "Each theme built from scratch — no shared reset, no shared tokens"

## Forbidden content patterns (flag if found)

- Inline "this is the placeholder for [field] if missing" logic — defaults are computed in `engines/` (Hydrogen) or the Astro data fetch, NEVER in section/component code
- Hardcoded brand-fallback content (`placeholders`, `fallback_gallery`, `brand_logo`, `brand_slogan` defaults) in any Astro-consumable file
- Per-affiliate styling overrides — partners inherit brand styling; individuals use their own Site's `settings.design`

## What to do if found

1. Identify which forbidden import or pattern was added.
2. Suggest the correct location:
   - React Router-specific code → `app/lib/cart/` or `app/lib/engines/` (Hydrogen-only)
   - Shopify imports → Shop section files only (`app/themes/theme-N/sections/Shop/`)
   - Framework helpers → consumer's app, not the shared package
   - Brand-fallback content → Hydrogen's `brand-context.server.ts` engine, not theme code
3. Reference the relevant plan rule (§49 #2/#3/#4 in the plan's Part 12).

## Output

Either:
- "Theme portability OK — no forbidden imports or patterns detected"
- "VIOLATION: [file] line [N] imports/uses [forbidden]. Move [Y] to [Z]. Plan rule §49 #[N]."

## Reference

Plan at `~/Developer/PARTNA-STANDALONE-PAGES-NEW-DIRECTION.md`. §23 (package structure), §49 #2/#3/#4 (rules), §51 (CI enforcement: `ThemePackageImportsTest` greps for forbidden imports).
