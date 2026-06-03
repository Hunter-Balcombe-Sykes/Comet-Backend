- [ ] **#API-1** · P3 — `updateBookingSettings` returns raw array instead of Resource; shape inconsistent with sibling endpoints
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:130-134
    - **Affects:** Professional dashboard clients consuming site-update responses; they must handle two different shapes from the same controller (one wrapped in `{site: SiteResource}`, the other flat `{booking_mode, manual_booking_url}`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the booking response in a dedicated `BookingSettingsResource` or return it under a consistent key like `['booking_settings' => [...]]`.
        - Alternatively, fold booking settings into `SiteResource` (add `booking_mode` and `manual_booking_url` fields behind `$this->when(...)`) so `updateBookingSettings` can return `['site' => new SiteResource($site)]` matching `update()` and `visibility()`.
    - **Technical:** Every other action in `UserSiteController` returns `['site' => new SiteResource($site)]`. The `updateBookingSettings` method diverges by returning a raw associative array directly. Frontend clients built against the `{site: {...}}` envelope must special-case this endpoint. The raw array also bypasses the Resource layer — if `SiteResource` ever adds computed fields (e.g. `booking_mode` derived from settings), this endpoint won't pick them up.
    - **Plain English:** Imagine a store where every checkout counter gives you a printed receipt in the same format, except the "booking" counter hands you a handwritten sticky note instead. The information is correct, but the person receiving it has to handle two completely different layouts. Standardising the receipt format saves confusion.
    - **Evidence:**
        ```php
        return $this->success([
            'booking_mode' => $settings['booking_mode'] ?? 'manual',
            'manual_booking_url' => $settings['manual_booking_url'] ?? null,
        ]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-2** · P2 — Legacy `SiteCacheService::buildPayloadFromDb()` constructs and caches raw arrays with no Resource transformation
    - **Where:** app/Services/Cache/SiteCacheService.php:89-115
    - **Affects:** Any API consumer of the legacy public-site payload path (the old `PublicSiteController`). Raw arrays cached and served directly bypass the Resource layer's field allowlisting, audience scoping, and serialization guarantees.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - If the legacy public-site controller is still serving traffic, route its cache misses through a Resource class (or migrate it to the new `IndividualProfileController` path which already uses `IndividualProfileResource`).
        - If the legacy path is fully decommissioned, deprecate `buildPayloadFromDb()` and remove it to prevent accidental future use.
    - **Technical:** The new skeleton-system path (`IndividualProfileController` → `IndividualProfilePayloadBuilder` → `IndividualProfileResource`) enforces field allowlisting and audience-appropriate serialization. `SiteCacheService::buildPayloadFromDb()` predates this and manually assembles an array from the `PublicSitePayload` DB view, bypassing Resource transformation entirely. Any field the view exposes — including future columns added to the view — lands in the API response unfiltered.
    - **Plain English:** The new public profile system has a security checkpoint that inspects every field before it leaves the building. The old system has a back door where raw data gets loaded into a box and shipped straight to the customer without inspection. If the old system is still open for business, it needs the same checkpoint installed.
    - **Evidence:**
        ```php
        $data = [
            'published' => true,
            'site' => $site,
            'professional' => $payload['professional'] ?? null,
            'theme' => $payload['theme'] ?? null,
            'services' => $services,
            'links' => $links,
            'sections' => $sections,
            'blocks' => $this->buildCombinedBlocksPayload($links, $sections, $existingBlocks),
            'legal' => $payload['legal'] ?? null,
        ];

        $this->writePayloadWithStale($key, $data);

        return $data;
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#API-3** · P3 — Response envelope shape drifts across controller surfaces
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:80, app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php:41, app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:120
    - **Affects:** Any universal API client that consumes Professional, Staff, and PublicSite endpoints. Each surface wraps successful responses in a different key or omits wrapping entirely.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Adopt a single success-envelope convention across all three surfaces. Options: (a) always wrap in a `data` key, (b) always wrap in a resource-named key (`site`, `profile`), or (c) emit the Resource directly with `JsonResource::withoutWrapping()`.
        - Document the chosen convention in the API contract so future controllers follow it.
    - **Technical:** `UserSiteController` wraps SiteResource in `['site' => ...]`. `StaffSiteController` passes `StaffSiteResource` directly to `$this->success()` with no wrapping key. `IndividualProfileController` wraps in `['data' => ...]`. A client that parses `response.site` from the Professional API must switch to `response` (root) for Staff and `response.data` for PublicSite. This triples the client-side deserialization paths for what is conceptually the same platform API.
    - **Plain English:** Three different ticket counters at the same venue hand you your ticket in three different formats — one in an envelope marked "SITE," one loose, and one in an envelope marked "DATA." The tickets are valid, but your scanner needs three different settings to read them. Standardising the envelope means one scanner works everywhere.
    - **Evidence:**
        ```php
        // UserSiteController — wrapped in 'site' key
        return $this->success(['site' => new SiteResource($site)]);

        // StaffSiteController — no wrapping key, Resource passed directly
        return $this->success(new StaffSiteResource($row));

        // IndividualProfileController — wrapped in 'data' key
        return $this->success(['data' => $payload]);
        ```
    - `[DRAFT, confidence: 0.8]`
