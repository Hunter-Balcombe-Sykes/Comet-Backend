# Public Individual Profile Endpoint Audit — 2026-05-20

**Branch:** audit/87-public-individual-profile-endpoint
**Lens:** public individual profile endpoint — security data exposure cache key correctness rate limit bypass 404 vs 403 leak
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- routes/api.php (verified via Grep)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

> **Adjudication note — PROF-3 dropped:** DeepSeek flagged "no rate limiting visible." Verified via `Grep` on `routes/api.php:193–195` — the route carries `->middleware('throttle:public-profile')`, a dedicated named limiter. Finding was a hallucination of missing evidence. Dropped per cannot-verify rule.

---

## P1 — Fix before pilot launch

- [ ] **#PROF-1** · P1 — Block `settings` JSON column passed unfiltered to the public response
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:67–73
    - **Affects:** Any individual professional whose blocks store data beyond display configuration — e.g. embedded-code blocks storing script keys, contact blocks, or any future block type that persists form output or API credentials into its `settings` JSONB. Exposed to unauthenticated public visitors.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Define a per-block-type allow-list of safe `settings` keys (either a static map in the `Block` model or a `PublicBlockSettingsFilter` helper class).
        - Filter `$b->settings` through the allow-list before including it in the `blocks` array.
        - Add a feature test (alongside the existing `IndividualProfileResource` exclusion assertions) that seeds a block with a known-sensitive key and asserts it is absent from the public endpoint response.
    - **Technical:** `IndividualProfileResource` correctly declares explicit PII exclusions (primary_email, phone, auth_user_id, commerce fields), documented in its class docblock and enforced by the TEST-4 feature test. However, the `blocks` payload bypasses the Resource's field-level filtering entirely — it is assembled in the controller as a raw map with `$b->settings ?? []` passed wholesale. Block settings is an open-ended JSONB column. As the product adds block types (embedded code, contact capture, lead forms), the surface of what ends up in `settings` will grow. Without a filter here, every new block type that stores anything beyond render configuration silently extends the public exposure surface. This is a forward-compatibility gap, not merely a theoretical concern: the same architectural pattern that intentionally excludes PII from the Resource will be undermined the first time a block type stores non-display data.
    - **Plain English:** Think of the profile page as a shop window. You've carefully chosen what goes in the display — name, bio, design — and locked away the private filing cabinet (email, phone, payment info). But the "blocks" — the shelves and display cases in the window — have their own drawers. Right now, the code opens every drawer and tips the contents into the window without looking inside first. If any drawer ever holds a private key, a note from a customer form, or a staging flag, it goes on public display. The fix is to define in advance exactly which items from each drawer are allowed in the window, and close everything else.
    - **Evidence:**
        ```php
        ->map(fn (Block $b) => [
            'id' => $b->id,
            'block_type' => $b->block_type,
            'sort_order' => (int) $b->sort_order,
            'settings' => $b->settings ?? [],
        ])
        ```

---

## P2 — Should fix

- [ ] **#PROF-2** · P2 — Design settings sub-array passed to public response without key whitelist
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:64 and app/Http/Resources/PublicSite/IndividualProfileResource.php:45
    - **Affects:** Public visitors to individual profile pages. Lower blast radius than PROF-1 because design settings are intentionally public-facing, but the contract is informal — any key that drifts into `settings['design']` via a bug or admin operation renders publicly.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Define an explicit allow-list of expected design keys (e.g. `['theme', 'accent_color', 'font', 'layout']`) and filter `$design` through `array_intersect_key` before passing it to the Resource.
        - Document the approved surface as a constant (e.g. `IndividualProfileResource::DESIGN_KEYS`) so future additions require an intentional update rather than a silent expansion.
    - **Technical:** The controller extracts `$design = (array) ($site?->settings['design'] ?? [])` and the Resource returns it verbatim under `'design'`. The `site.settings` column is a polymorphic JSONB used across the platform — the `design` sub-key is convention, not a typed schema. If any write path (admin tooling, a migration, a future settings service) ever places a non-display key adjacent to design keys inside `settings['design']`, it leaks without any CI or type system catching it. A whitelist makes the contract explicit and machine-enforced.
    - **Plain English:** The design settings control visual appearance — colors, fonts, layout. These are meant to be public. But the code doesn't check what's actually inside the design settings bag before handing it over; it trusts that only display data ended up there. That trust is reasonable today but isn't enforced by anything. Defining an approved list of keys means that even if someone accidentally stores a private note next to the color settings, it won't show up on the public page — the list acts as a filter that only lets the right things through.
    - **Evidence:**
        ```php
        $design = (array) ($site?->settings['design'] ?? []);
        // ...
        return (new IndividualProfileResource($pro, $design, $blocks))->resolve();
        ```
        ```php
        'design' => $this->design,
        ```

---

## P3 — Nice to have

- [ ] **#PROF-3** · P3 — Cache stamp frozen at `0` when Professional has no Site record
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:59–61
    - **Affects:** Professionals in early setup who modify blocks before their Site row exists. Block changes won't surface on the public endpoint until the 60s TTL expires naturally.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Fall back to `$pro->updated_at?->timestamp` when `$site` is null: `$stamp = $site?->updated_at?->timestamp ?? $pro->updated_at?->timestamp ?? 0;`
        - Optionally, include a derived stamp from `Block::where('professional_id', $pro->id)->max('updated_at')` for block-only changes even when Site exists.
    - **Technical:** The cache key version stamp is `$site?->updated_at?->timestamp ?? 0`. When no Site row exists, the stamp is permanently `0`. The cache closure re-queries blocks on every miss, but the key itself never rotates — so a cached `0`-stamp response will be served for the full TTL after any block mutation, until either the TTL expires or a Site row is created. The controller comment notes "SiteObserver-fired mutation rolls the key forward," but SiteObserver only fires on Site model events; block changes have no path to rotate the key when Site is absent. This is a max-60s stale window, which is acceptable in most cases but confusing during onboarding.
    - **Plain English:** The cache uses the Site record's last-modified date as a version number to know when to refresh. If no Site record exists yet, the version number is stuck at zero forever. So if a professional adds or changes blocks on their profile during setup, the public page shows the old version for up to a full minute. It's a minor inconvenience — the cache is only 60 seconds — but it can cause confusion for someone checking their profile immediately after making a change.
    - **Evidence:**
        ```php
        $site = Site::query()->where('professional_id', $pro->id)->first();
        $stamp = $site?->updated_at?->timestamp ?? 0;
        $key = "public.profile:{$handleLc}:{$stamp}";
        ```

- [ ] **#PROF-4** · P3 — User-controlled handle embedded unescaped between colon delimiters in Redis key
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:61
    - **Affects:** Redis cache inspection tooling (RedisInsight, `redis-cli --scan --pattern`). Handles containing colons produce visually ambiguous key hierarchies. True key collision is not plausible given the numeric stamp suffix.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Hash the handle component in the key: `$key = "public.profile:" . md5($handleLc) . ":{$stamp}";`
        - Or enforce at the handle validation layer that colons are disallowed (if not already — check the handle regex in `Professional` validation).
    - **Technical:** Redis uses colons as the conventional namespace separator for key visualisation. The key `public.profile:{$handleLc}:{$stamp}` embeds a user-controlled string between two delimiter colons. A handle containing a colon (e.g. `alice:bob`) yields `public.profile:alice:bob:1716192000`, which renders as four segments in RedisInsight instead of the expected three, breaking namespace-tree views. If handle validation already rejects colons, this is moot — check the Form Request or model rule. If not, hashing the segment is a one-line fix that eliminates the ambiguity permanently.
    - **Plain English:** The cache filing system uses colons as dividers between folder names, like `cabinet:drawer:file`. The professional's handle — which they choose — gets placed between two of those dividers without checking whether the handle itself contains a divider. A handle like `alice:bob` would make the filing system think there are four folder levels instead of three, which confuses the cabinet management tool. It won't break anything for users, but it makes debugging the cache harder for engineers.
    - **Evidence:**
        ```php
        $key = "public.profile:{$handleLc}:{$stamp}";
        ```
