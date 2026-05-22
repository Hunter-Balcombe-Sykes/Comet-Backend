- [ ] **#API-1** · P2 — Raw Eloquent `Site` model returned in `BootstrapController::bootstrap()` response, bypassing Resource transformation
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php (inside `DB::transaction` return, line ~340)
    - **Affects:** Every professional who completes signup or re-bootstraps — the full `Site` model is serialized into the JSON response, exposing all DB columns including the `settings` JSONB blob (site configuration, brand_partner mappings, design tokens).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a `SiteResource` (or reuse an existing one if already defined) that exposes only the fields the frontend needs (`id`, `subdomain`, `published`, `created_at`).
        - Replace `'site' => $site->fresh()` with `'site' => new SiteResource($site->fresh())` so the response is consistent with `'professional' => new ProfessionalDashboardResource(...)` on the very next line.
    - **Technical:** `response()->json($site->fresh())` calls `->toArray()` on the Eloquent model, emitting every column: `professional_id`, `settings` (a large JSONB object), `deleted_at`, etc. The same response already uses `ProfessionalDashboardResource` for the professional key — the asymmetry is a pattern violation. Category 1 (raw model return).
    - **Plain English:** When someone signs up, the server sends back their account info bundled with a "site" object. That site object is being sent raw — like handing someone your entire filing cabinet when they only asked for the folder label. It includes internal configuration settings the frontend doesn't need and shouldn't see.
    - **Evidence:**
        ```php
        return [
            'professional' => new ProfessionalDashboardResource($professional->fresh()),
            'site' => $site->fresh(),   // ← raw Eloquent model
            'shopify_integration_id' => $shopifyIntegrationId,
        ];
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#API-2** · P2 — Raw Eloquent `Site` model returned in `SiteVisibilityController::update()`, exposing full DB row
    - **Where:** app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php:30
    - **Affects:** Authenticated professionals toggling their site's publish state — the response includes the entire `Site` model serialized.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Return a dedicated Resource class (or a hand-crafted array) instead of `$site->fresh()`.
        - At minimum, return `['published' => $site->published]` or use the same `SiteResource` created for API-1 so the contract is consistent.
    - **Technical:** Same raw-model-via-`response()->json()` path as API-1. The `$site->fresh()` Eloquent model is passed directly to `$this->success()`, which forwards it to `response()->json()`, which calls `->toArray()` on it. Every column on the `sites` table — including `professional_id`, `settings` (JSONB), `deleted_at` — is emitted. Category 1 (raw model return).
    - **Plain English:** The "publish my site" toggle sends back the entire site record as confirmation. That's like asking "is the light on?" and getting handed the building's electrical blueprint. The frontend just needs a yes/no — not the full database row.
    - **Evidence:**
        ```php
        $site->published = (bool) $request->validated('published');
        $site->save();

        return $this->success([
            'site' => $site->fresh(),   // ← raw Eloquent model
        ]);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#API-3** · P3 — Inconsistent response envelope: some endpoints wrap in `data`, most return flat objects
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:46 vs app/Http/Controllers/Api/PublicSite/BootstrapController.php (and most other controllers)
    - **Affects:** Any client (Astro Worker, Hydrogen, Next.js frontend) that consumes multiple Partna API surfaces — they must handle two different response shapes for the same conceptual operation (resource retrieval).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Decide on one envelope convention (e.g. always `{'data': ...}` or always flat) and apply it consistently.
        - If the Astro Worker expects `{'data': ...}`, standardize all public-site endpoints to use that wrapper. `ApiController::success()` could apply the wrap automatically.
        - If flat is the standard, fix `IndividualProfileController` to match.
    - **Technical:** `IndividualProfileController::show()` returns `$this->success(['data' => $payload])` — the payload is nested under a `data` key. Meanwhile `BootstrapController::bootstrap()` returns `$this->success($result)` where `$result` is a flat object with keys `professional` and `site`. `EmbeddedSetupController::brandProfile()` returns flat keys (`name`, `logo_url`, `brand_slug`, …). Clients parsing these responses need conditional logic: `resp.data?.name` vs `resp.name`. Category 5 (response shape inconsistency).
    - **Plain English:** Imagine a vending machine where pressing A1 sometimes drops the snack directly into the tray, and sometimes drops it into a box first that you then have to open. The customer has to check every time which style they got. Same snack, same button — inconsistent delivery. That's what's happening with the API responses: sometimes the data is handed straight to you, sometimes it's inside a `data` wrapper.
    - **Evidence:**
        ```php
        // IndividualProfileController.php — wraps in data
        return $this->success(['data' => $payload]);

        // BootstrapController.php — flat object
        return $this->success($result);  // $result = ['professional' => ..., 'site' => ...]

        // EmbeddedSetupController.php:brandProfile() — flat keys
        return $this->success([
            'name' => ...,
            'logo_url' => ...,
            ...
        ]);
        ```
    - `[DRAFT, confidence: 0.90]`
