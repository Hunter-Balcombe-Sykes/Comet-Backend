# AI Slop & Low-Value Code Audit — 2026-07-12

**Branch:** HEAD
**Lens:** AI Slop & Low-Value Code — comment noise, premature abstraction, dead code, defensive cruft, copy-paste drift
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/User, app/Services/Media, app/Services/Platforms, app/Services/Feedback, app/Services/Diagnostics
- app/Mail, app/Http/Controllers/Api/User, app/Http/Resources, app/Jobs, app/Console, app/Notifications, app/Observers

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 5 complete

---

## P2 — Should fix

- [ ] **#SLOP-1** · P2 — Dead private methods left behind after an N+1 fix, re-laying the same trap
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:469 (`hasStoreKey`), :654 (`count`)
    - **Affects:** Future maintainers of the ordering-seed path — a dev who reaches for either helper reintroduces the exact per-store N+1 query pattern the eager-load rewrite (comment at :361-364) was written to eliminate.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete `hasStoreKey()` (lines 468-476) and `count()` (lines 654-657) — both are fully superseded by the eager-loaded `$existingOrdering` / `$existingStoreKeys` / `$existingCount` computed once in `seedOrdering()`.
        - Grep confirms zero call sites for either: `hasStoreKey` and `$this->count(` appear nowhere else in `app/`.
    - **Technical:** The comment directly above the eager-load block (line 361: *"Eager-load all existing ordering rows once. Without this, hasStoreKey and count() both query the table on every iteration..."*) documents that these two private methods are the exact O(N) get-then-filter pattern that was replaced — and a prior audit (`audits/archive/sweeps/2026-07-01-connection-scale-health/CONSOLIDATED.md`) already flagged `GoogleBusinessAutoSync.php:352 (hasStoreKey get-then-filter)` as an N+1. The fix landed, but the old methods were never deleted, so they sit in the class as a live trap for the next person who greps for "does this user already have this store" and finds `hasStoreKey()` instead of the eager-loaded map.
    - **Plain English:** This file used to check "has this store already been added?" by re-scanning the whole database table every single time through a loop — slow when there are many stores. Someone fixed that by loading the list once upfront. But the old, slow checking method was never deleted — it's still sitting in the file, looking like a normal helper anyone could call. If a future developer calls it instead of the new fast path, the slow bug comes back.
    - **Evidence:**
        ```php
        // Eager-load all existing ordering rows once. Without this, hasStoreKey
        // and count() both query the table on every iteration of $stores, turning
        // an N-store enrichment into 2N+1 round-trips.
        ```
        ```php
        /** Whether the user already has any ordering row for this store key. */
        private function hasStoreKey(string $userId, string $storeKey): bool
        {
            return IntegrationConnection::query()
                ->where('user_id', $userId)
                ->where('platform', Platform::OnlineOrdering->value)
                ->get()
                ->contains(fn (IntegrationConnection $row) => $this->storeKey(CardPayload::fromArray($row->payload)->url()) === $storeKey);
        }
        ```
        ```php
        private function count(string $userId, string $platform): int
        {
            return IntegrationConnection::query()->where('user_id', $userId)->where('platform', $platform)->count();
        }
        ```

## P3 — Nice to have

- [ ] **#SLOP-2** · P3 — Identical decorative "internals" banner duplicated across three scraper files
    - **Where:** app/Services/Platforms/AppleSearch.php:104, app/Services/Platforms/ShopifyScraper.php:193, app/Services/Platforms/WooCommerceScraper.php:253
    - **Affects:** Developers reading these files — zero functional impact.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete the `// ── internals ────────────────────────────────────────────────` line in all three files. Each precedes a single `private function json(...)` (or `itunes(...)`), already self-identifying via the `private` keyword.
    - **Technical:** CLAUDE.md's Commenting rule says to avoid decorative banners/ASCII separators. The exact same line is copy-pasted into three sibling scraper classes ahead of one or a small handful of private helpers already delineated by PHP visibility — a textbook case of copy-paste drift adding volume with no new information per file.
    - **Plain English:** Three different files in the same folder all have the identical dashed "internals" divider line above their private helper methods. The word "private" on the method already tells you it's internal-only; the line of dashes is decoration copied from file to file.
    - **Evidence:**
        ```php
        // ── internals ────────────────────────────────────────────────

        private function itunes(string $path): ?array
        ```

- [ ] **#SLOP-3** · P3 — Seven decorative section banners in one file
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:116, 213, 277, 326, 498, 601, 642
    - **Affects:** Developers reading the file — zero functional impact.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Delete all seven `// ── <label> ──...` banner lines; the `private function` groupings already communicate the section boundaries.
    - **Technical:** Same rule as SLOP-2 ("avoid decorative banners"). Seven banners across one 723-line file is heavier decoration than the other instances — each section is 1-6 methods, already legible from method names and visibility.
    - **Plain English:** This file has seven dashed section headers, one per feature area (reservations, booking, workplace, etc.), but each "chapter" is only a couple of methods long — the method names already say what they do, so the headers are just visual clutter repeated seven times.
    - **Evidence:**
        ```php
        // ── reservation ──────────────────────────────────────────────
        // ── booking ──────────────────────────────────────────────────
        // ── workplace ─────────────────────────────────────────────────
        // ── ordering ─────────────────────────────────────────────────
        // ── socials ──────────────────────────────────────────────────
        // ── findings ──────────────────────────────────────────────────
        // ── helpers ──────────────────────────────────────────────────
        ```

- [ ] **#SLOP-4** · P3 — Decorative ASCII-art block banner in a controller
    - **Where:** app/Http/Controllers/Api/User/Content/ContentController.php:236-238
    - **Affects:** Developer reading the file — no user-facing impact.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Delete lines 236-238 (the `/* ---...--- */` banner + `/*  Internals */` label). The `private` methods below are already self-identifying.
    - **Technical:** CLAUDE.md: "Avoid decorative banners." A three-line ASCII-art block separator adds scroll distance and zero information beyond what the `private` keyword on the methods below already states.
    - **Plain English:** There's a boxed-in decorative label reading "Internals" made of dashes and asterisks, sitting above some private helper methods. The word "private" already tells the reader that; the box is just decoration.
    - **Evidence:**
        ```php
            /* ------------------------------------------------------------------ */
            /*  Internals */
            /* ------------------------------------------------------------------ */
        ```

- [ ] **#SLOP-5** · P3 — Dead vestigial variable from removed account-type section gating
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php:36
    - **Affects:** No user or system impact — purely a maintainer reading the method body.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Delete line 36: `$allSections = $allowedSections;`.
    - **Technical:** `$allSections` is assigned once and never read again anywhere in the file or the wider `app/` tree (confirmed via `grep -n '\$allSections' app/` — the only hit is the assignment itself). The comment above ("All accounts are individual; all configured section types are allowed") explains why the old account-type-gated `$allSections`-vs-`$allowedSections` split collapsed, but the leftover variable wasn't cleaned up with it.
    - **Plain English:** It's like leaving a spare key on the counter after selling the car it opened — the key does nothing now, but anyone who finds it will wonder what it unlocks. This variable was part of an old feature (checking which sections an account type could use) that got simplified away; the line itself was never removed.
    - **Evidence:**
        ```php
            // All accounts are individual; all configured section types are allowed.
            $allowedSections = config('partna.section_block_types', []);
            $allSections = $allowedSections;
            $unavailableSections = [];
        ```

- [ ] **#SLOP-6** · P3 — Inconsistent empty-object coercion patterns in the same resource
    - **Where:** app/Http/Resources/PublicSite/IndividualProfileResource.php:75-88 (three verbose blocks) vs. lines 127, 140 (concise inline casts)
    - **Affects:** No runtime behaviour — all five produce `{}` in JSON when empty. A maintainer reading the file sees two different patterns for the identical operation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the three verbose blocks with inline `(object)` casts matching the existing `popularity`/`ordering` pattern:
          ```php
          'designKit' => (object) ($this->sections['design_kit'] ?? []),
          'publicConfig' => (object) ($this->sections['public_config'] ?? []),
          'siteImages' => (object) ($this->sections['site_images'] ?? []),
          ```
        - Delete the three intermediate variables (`$designKitOut`, `$publicConfigOut`, `$siteImagesOut`) and their four accompanying comment blocks; fold the "empty must serialize as `{}`" note into a single one-line comment kept above the returned array once.
    - **Technical:** The resource converts empty PHP arrays to empty JSON objects in five places: two use a concise `(object) (...)` cast inline (`popularity`, `ordering`), three use a verbose `$x === [] ? new stdClass : $x` ternary with a dedicated intermediate variable and a multi-line comment. Two of the three comments exist only to point at the first ("Same empty-object coercion as $designKit above") — a mild form of "comments that restate the next line" plus unnecessary ceremony (category 5: needless intermediate variables used once). All five expressions produce identical output, so the divergence is pure copy-paste drift, not a functional distinction.
    - **Plain English:** The same small formatting job — making sure an empty list looks like `{}` instead of `[]` in the browser — is done two different ways in the same file. Three spots use a long-winded recipe with its own prep bowl and a comment pointing back to an earlier comment; two spots do it in one line. Picking the shorter form everywhere makes the file shorter and removes the inconsistency.
    - **Evidence:**
        ```php
        // Empty designKit must serialize as `{}` (object), not `[]` (array).
        // PHP's array → JSON encoder emits `[]` for any empty associative
        // array; cast to stdClass when there are no stored vars so the wire
        // payload matches the spec contract (designKit is always an object).
        $designKit = $this->sections['design_kit'] ?? [];
        $designKitOut = $designKit === [] ? new stdClass : $designKit;

        // Same empty-object coercion as $designKit above.
        $publicConfig = $this->sections['public_config'] ?? [];
        $publicConfigOut = $publicConfig === [] ? new stdClass : $publicConfig;

        // Same empty-object coercion as $designKit above.
        $siteImages = $this->sections['site_images'] ?? [];
        $siteImagesOut = $siteImages === [] ? new stdClass : $siteImages;
        ```
        vs. the concise pattern used elsewhere in the same method:
        ```php
        'popularity' => (object) ($this->sections['popularity'] ?? []),
        ```
        ```php
        'ordering' => (object) ($this->sections['ordering'] ?? []),
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Decorative banner removal:** #SLOP-2, #SLOP-3, #SLOP-4
    - **Why grouped:** Same root cause (decorative ASCII-art section dividers violating CLAUDE.md's "avoid decorative banners" rule) across `app/Services/Platforms` and `app/Http/Controllers/Api/User` — purely mechanical deletes, no logic touched.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (combine plan+impl given S effort).

- **Bundle 2 — Dead code & drift cleanup:** #SLOP-1, #SLOP-5, #SLOP-6
    - **Why grouped:** Same "cleanup pass" theme — a dead-code removal, a dead-variable removal, and a copy-paste-drift consolidation, each isolated to one file with no cross-file coupling.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet. Escalate implement → Opus for #SLOP-1 only if the reviewer wants extra scrutiny that no other caller relies on `hasStoreKey`/`count` (grep already confirms zero call sites, so this is likely unnecessary).

## Standalone — do NOT bundle

None.
