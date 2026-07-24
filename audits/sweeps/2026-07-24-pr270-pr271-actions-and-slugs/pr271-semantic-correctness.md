# Semantic Correctness Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Semantic Correctness: code that compiles and type-checks but does the wrong thing — plausible-but-wrong logic invisible to Larastan (real API/contract, wrong mental model)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/Platforms/EventSlugSync.php
- app/Services/Site/ItemSlugAllocator.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Controllers/Api/PublicSite/PublicMenuController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/MenuItemObserver.php
- app/Console/Commands/BackfillItemSlugs.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#SEM-1** · P2 — `ensureCurrent`'s no-op guard conflates a collision suffix with a name that itself ends in digits, silently skipping legitimate slug renames
    - **Where:** app/Services/Site/ItemSlugAllocator.php:32-37 (guard), :192-195 (`stripSuffix` helper)
    - **Affects:** Any professional renaming a menu item (via `MenuItemObserver::updated()`, gated on `wasChanged('name')`) whose *previous* title slugifies to something ending in `-<digits>` (e.g. "Fish Tacos 2", "Combo Meal 3") and whose new title drops that trailing number — the public sitepage menu-item URL keeps the stale numbered slug instead of updating.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Stop inferring "was this suffix a collision disambiguator" from a blind regex strip. Either persist the un-suffixed base as its own column on `site.item_slugs` and compare against that, or change the no-op check to `$current->slug === $base` (exact match only) and let a genuine rename fall through to the existing rename/promote path — which already correctly reuses a previously-owned slug via the `$owned` lookup a few lines below, so relying on that path instead of the `stripSuffix` shortcut costs nothing.
        - Add a regression test: mint "Fish Tacos 2" (first item, base slug has no real collision), rename to "Fish Tacos", assert the current slug becomes `fish-tacos` (not left at `fish-tacos-2`).
    - **Technical:** `ensureCurrent` short-circuits with `$this->stripSuffix($current->slug) === $base` (line 35), where `stripSuffix` (line 192-195) blindly strips any trailing `/-\d+$/` regardless of whether that suffix was added by `insertUnique`'s collision loop (line 145-168, `$base.'-'.$n`) or was part of the slugified name itself (`Str::slug('Fish Tacos 2')` → `fish-tacos-2`). When an item's original name ends in a number and is later renamed to drop it, `stripSuffix('fish-tacos-2')` yields `fish-tacos`, matches the new `$base`, and the method returns the stale slug without renaming — verified by reading `insertUnique`'s suffix scheme (same file) which proves the two cases are structurally indistinguishable to this regex. `MenuItemObserver::updated()` (app/Observers/MenuItemObserver.php:28-32) confirms this path fires on every dashboard name edit.
    - **Plain English:** Imagine a dish called "Club Sandwich 2." The system turns that into a web address ending in `…/club-sandwich-2`. Later the owner renames it to just "Club Sandwich." The code checks "is this the same address, just with a disambiguating number we added stripped off?" — but it can't tell the difference between a number IT added to avoid a clash and a number that was simply part of the original dish name. So it wrongly decides nothing changed, and the web address keeps the stale "2" even though the dish name no longer has one. A visitor's bookmarked or shared link still shows the old, now-inaccurate slug.
    - **Evidence:**
        ```php
        // ensureCurrent() — the no-op guard
        $current = $this->currentRow($userId, $itemType, $itemKey);

        // Same base as the live slug (ignoring any -N suffix) → no-op.
        if ($current !== null && $this->stripSuffix($current->slug) === $base) {
            return $current->slug;
        }
        ```
        ```php
        // stripSuffix()
        private function stripSuffix(string $slug): string
        {
            return preg_replace('/-\d+$/', '', $slug);
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — item-slug rename correctness:** #SEM-1
    - **Why grouped:** Single finding, self-contained to `ItemSlugAllocator`; no other findings share this file/pattern.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
