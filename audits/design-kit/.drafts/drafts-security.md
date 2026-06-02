- [ ] **#SEC-1** · P2 — StaffUpdateSiteRequest omits string sanitisation that UpdateSiteRequest applies
    - **Where:** app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (entire class, no `prepareForValidation` for settings fields)
    - **Affects:** Staff-updated site settings (hero_title, hero_subtitle, primary_button_text, bio_text) — strings stored without the sanitisation that the professional-facing path applies, potentially carrying leading/trailing whitespace or other unwanted artefacts into rendered pages and search previews.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `prepareForValidation` method to `StaffUpdateSiteRequest` that mirrors `UpdateSiteRequest`’s logic — trim + `cleanString` the same four text fields (`hero_title`, `hero_subtitle`, `primary_button_text`, `bio_text`) when `settings` is an array.
        - Or extract the shared sanitisation into a trait/static helper so the two Form Requests can’t drift again.
    - **Technical:** Category 6 (input validation). `UpdateSiteRequest::prepareForValidation` trims each string and runs `static::cleanString()` on it before validation; `StaffUpdateSiteRequest` only lowercases the subdomain. The professional path therefore produces clean strings while the staff path writes raw input — any downstream renderer that trusts stored strings (public site, email templates, meta tags) could emit cruft or break layout. The information_schema filter in the controller’s `writeDesignKit` doesn’t cover `settings.*` fields.
    - **Plain English:** Imagine two tellers at a bank — one counts the cash and checks for counterfeits before putting it in the drawer, the other just drops the envelope in. The staff update path is the second teller. Any text a staff member pastes into the hero title field goes straight into the database without the cleanup that the professional’s own dashboard applies.
    - **Evidence:**
        ```php
        // UpdateSiteRequest::prepareForValidation — has sanitisation
        $settings = $this->input('settings');
        if (is_array($settings)) {
            foreach (['hero_title', 'hero_subtitle', 'primary_button_text', 'bio_text'] as $field) {
                if (! array_key_exists($field, $settings) || ! is_string($settings[$field])) {
                    continue;
                }
                $settings[$field] = static::cleanString($settings[$field]);
            }
            $merge['settings'] = $settings;
        }

        // StaffUpdateSiteRequest::prepareForValidation — subdomain only, no settings cleanup
        protected function prepareForValidation(): void
        {
            if (is_string($this->subdomain ?? null)) {
                $this->merge([
                    'subdomain' => strtolower(trim($this->subdomain)),
                ]);
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-2** · P2 — StaffUpdateSiteRequest subdomain validation skips user_handle_aliases collision check
    - **Where:** app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php:rules() subdomain closure (lines ~62–92); compare app/Http/Requests/Api/User/Site/UpdateSiteRequest.php:rules() subdomain closure
    - **Affects:** Staff subdomain assignments — a staff member could set a subdomain that collides with a handle alias preserved for redirect/SEO purposes, breaking an existing redirect and potentially causing a confusing loop.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Copy the `core.user_handle_aliases` check from `UpdateSiteRequest` into the `StaffUpdateSiteRequest` subdomain validation closure, adapting `$currentUserId` from the route-bound professional.
        - Extract the shared subdomain-uniqueness logic into a dedicated validator class or trait so the two Form Requests stay in sync.
    - **Technical:** Category 3 (tenant isolation). `UpdateSiteRequest` checks three collision sources: (a) `site.sites` subdomain, (b) `site.site_subdomain_aliases`, (c) `core.user_handle_aliases` (old handles preserved for SEO). `StaffUpdateSiteRequest` checks only (a) and (b). A staff member assigning a subdomain that matches a legacy handle alias would steal the redirect, and the original professional’s old-handle traffic would land on the wrong site. The try/catch with `report($e)` is also missing from the staff path, so a DB error during the alias check would not be logged.
    - **Plain English:** When a professional changes their public handle, the old one is kept as a "forwarding address" so visitors using the old URL still get to the right place. The professional update form checks all three sources of name conflicts before letting you claim a subdomain. The staff update form only checks two — so staff can accidentally overwrite someone’s forwarding address without realising it.
    - **Evidence:**
        ```php
        // UpdateSiteRequest — has the alias check
        try {
            $existsInUserAliases = DB::connection('pgsql')
                ->table('core.user_handle_aliases')
                ->whereRaw('LOWER(handle) = LOWER(?)', [$value])
                ->where('user_id', '!=', $currentUserId)
                ->exists();
        } catch (QueryException $e) {
            report($e);
            Log::warning('Professional alias check failed in UpdateSiteRequest', ['error' => $e->getMessage()]);
            $existsInUserAliases = false;
        }
        if ($existsInUserAliases) {
            $fail('This subdomain is already taken.');
        }

        // StaffUpdateSiteRequest — no user_handle_aliases query at all
        // Only checks reserved words, site.sites, and site.site_subdomain_aliases
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SEC-3** · P2 — IndividualProfileController has no rate limiting on the public profile endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php (entire controller)
    - **Affects:** Public profile pages — an attacker can enumerate handles at high velocity, probing which handles exist and which don’t. The resolve-cache TTL (30s) provides some buffering but not enough to stop a determined scanner.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `throttle:public_profile` middleware (or `throttle:60,1` per-IP) on the `show` route in the route definition.
        - Consider a stricter per-handle throttle if burst-enumeration of a single handle is a concern.
    - **Technical:** Category 9 (rate limiting). The controller has a 30-second resolve cache for not-found handles (the `['not_found' => true]` sentinel), which absorbs repeat lookups of the same non-existent handle. But an attacker cycling through thousands of candidate handles hits the database on every unique miss until the cache fills. No `throttle` middleware is applied to this route, so there’s no per-IP or per-handle backpressure. The existing `logIfSlow` only warns after 1 second — it doesn’t slow anyone down.
    - **Plain English:** The public profile page is the front door — anyone can knock. Right now there’s no doorman counting how many times per minute the same person knocks. A bot could try thousands of profile handles in a row, mapping out which ones exist, and the system would answer every single one without slowing down.
    - **Evidence:**
        ```php
        // No throttle attribute, no RateLimiter calls, no middleware annotation
        class IndividualProfileController extends ApiController
        {
            public function show(Request $request, string $handle): JsonResponse
            {
                $startedAt = microtime(true);
                $handleLc = strtolower(trim($handle));
                // … resolve-cache, payload build, response — no rate limiting anywhere
            }
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#SEC-4** · P3 — StaffUpdateSiteRequest validates columns that were dropped from site.design_kits plus is missing rules for columns that exist
    - **Where:** app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (rules array — spacing/padding/tablet rules for dropped columns; absent space_*/color_placeholder/color_contrasting_*/motion_* rules)
    - **Affects:** Staff site-editing UX — staff-submitted design_kit values for dropped columns are silently ignored (no 422 warning), while values for newer columns pass through without format validation before the controller’s information_schema filter applies them.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Sync `StaffUpdateSiteRequest` rules with `UpdateSiteRequest`: remove spacing_*, padding_*, *_tablet_* rules (dropped per migration 20260529053028); add missing color_placeholder, color_contrasting_bg, color_contrasting_text, space_*, space_desktop_*, typography_desktop_h1_font_size, motion_expand_duration, motion_fade_duration rules.
        - Add a fast test that diffs the design_kit rules between the two Form Requests to prevent future drift.
    - **Technical:** Category 6 (input validation). The controller’s `writeDesignKit` filters incoming keys against `information_schema.columns`, so stale rules produce silent drops rather than data corruption — this is a UX/observability gap, not a write-path vulnerability. But the *missing* rules mean staff can submit malformed values for columns that do exist (e.g., `color_placeholder` with a 200-char string), and the Form Request won’t 422 — the DB write would proceed with the raw value because the column exists in information_schema. The `max:32` guard on the user-facing path isn’t applied on the staff path.
    - **Plain English:** The staff editing form is working from an old checklist. It’s checking boxes for fields that were removed from the database months ago (so those values vanish silently), and it’s missing checkboxes for fields that were added more recently (so staff can accidentally put bad data into new fields without getting a clear error message).
    - **Evidence:**
        ```php
        // StaffUpdateSiteRequest still validates dropped columns:
        'design_kit.spacing_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        // ... plus all *_tablet_* variants

        // Migration 20260529053028 confirms these columns were dropped:
        ALTER TABLE site.design_kits
          DROP COLUMN padding_extra_small,
          DROP COLUMN padding_small,
          DROP COLUMN spacing_extra_small,
          DROP COLUMN spacing_small,
          -- ... all tablet_* columns dropped

        // Meanwhile, rules that exist in UpdateSiteRequest are MISSING in StaffUpdateSiteRequest:
        // 'design_kit.color_placeholder'          — NOT in StaffUpdateSiteRequest
        // 'design_kit.color_contrasting_bg'       — NOT in StaffUpdateSiteRequest
        // 'design_kit.color_contrasting_text'     — NOT in StaffUpdateSiteRequest
        // 'design_kit.space_xs' ... 'space_large' — NOT in StaffUpdateSiteRequest
        // 'design_kit.motion_expand_duration'     — NOT in StaffUpdateSiteRequest
        // 'design_kit.motion_fade_duration'       — NOT in StaffUpdateSiteRequest
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SEC-5** · P3 — Color fields accept arbitrary strings up to 32 characters with no format validation
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php and app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (all `design_kit.color_*` and `design_kit.button_*` rules)
    - **Affects:** Email branding CSS and public-profile design-kit rendering — a stored value like `transparent;background:url(` (under 32 chars) could break CSS rendering in the email layout or the public skeleton, though the sandboxed nature of email clients and the authenticated write path limit the blast radius.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `regex:/^#[0-9a-fA-F]{3,8}$/` (or a CSS-color allow-list) to every `design_kit.color_*` and `design_kit.button_*_bg` / `button_*_text` rule.
        - Apply the same tightening in both `UpdateSiteRequest` and `StaffUpdateSiteRequest`.
    - **Technical:** Category 6 (input validation). Color values flow into the email Blade template via `style="background-color:{{ $brand->palette->bg }};"` and into the public-site design kit response consumed by partna-pages. A value like `#fff;}` would terminate the inline style rule and could inject arbitrary CSS. Laravel’s `{{ }}` escapes HTML entities but not CSS context — you need format validation. The 32-char `max` rule provides a ceiling but doesn’t prevent valid-CSS injections under that length. The write path is authenticated (professional or staff), so the attack surface is self-sabotage or compromised-account abuse, keeping this at P3.
    - **Plain English:** The color picker in the design panel accepts any text up to 32 characters and calls it a color. If someone typed `red;display:none` instead of `#ff0000`, the email template would inject that into its stylesheet and potentially hide content. It’s like a paint store that lets you type anything into the "colour code" box and then sprays it on the wall — most people use real paint codes, but the store isn’t checking.
    - **Evidence:**
        ```php
        // Both FormRequests use this pattern for every color field:
        'design_kit.color_accent' => ['sometimes', 'nullable', 'string', 'max:32'],
        'design_kit.color_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
        'design_kit.button_primary_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
        // ... 20+ color/button fields, none with a hex/regex constraint

        // The value lands in inline CSS in the email Blade template:
        // style="background-color:{{ $brand->palette->bg }};"
        ```
    - `[DRAFT, confidence: 0.75]`
