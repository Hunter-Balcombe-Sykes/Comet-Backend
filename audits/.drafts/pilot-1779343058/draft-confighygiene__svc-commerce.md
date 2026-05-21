- [ ] **#CFG-8** · P3 — BrandDesignImporter::THEME_HINTS hardcoded as private constant instead of config-driven
    - **Where:** app/Services/Shopify/BrandDesignImporter.php (THEME_HINTS private const)
    - **Affects:** Developers adding support for new Shopify themes — requires a code change and deploy instead of a config update.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the THEME_HINTS array into `config/partna.php` under a key like `shopify.theme_hints`.
        - Replace the `self::THEME_HINTS` reference with `config('partna.shopify.theme_hints')`.
    - **Technical:** The THEME_HINTS array is a data mapping (theme display name → settings_data.json key paths) that changes when Shopify releases new themes or renames keys. Hardcoding it as a PHP constant means adding a theme like "Sense" or "Refresh" requires editing a service class, running tests, and deploying — the same operational cost as a logic change. Placing it in `config/partna.php` allows a config-only deploy or even an env-driven override in a pinch. The existing `matchThemeHints()` method already provides the runtime resolution layer; only the data table needs relocation.
    - **Plain English:** Imagine a restaurant menu printed directly inside the kitchen's wiring diagram. Every time the chef adds a new dish, an electrician has to come out and rewire. The theme-hint mapping is the menu — it belongs on a chalkboard (the config file), not hardwired into the kitchen circuits.
    - **Evidence:**
        ```php
        private const THEME_HINTS = [
            'horizon' => [
                'radius' => ['buttons_radius', 'inputs_radius', 'variant_pills_radius', 'radius'],
                'thickness' => ['buttons_border_thickness', 'inputs_border_thickness', 'border_thickness'],
                'spacing' => ['spacing_sections', 'sections_spacing', 'section_spacing'],
            ],
            'dawn' => [
                'radius' => ['buttons_radius', 'inputs_radius', 'variant_pills_radius'],
                'thickness' => ['buttons_border_thickness', 'inputs_border_thickness'],
                'spacing' => ['spacing_sections', 'page_width'],
            ],
            // ... prestige, impact, impulse, generic ...
        ];
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-9** · P3 — BrandDesignImporter design-enum bucket thresholds hardcoded as private constants
    - **Where:** app/Services/Shopify/BrandDesignImporter.php (RADIUS_ROUNDED_MIN, RADIUS_PILL_MIN, THICKNESS_STANDARD_MIN, THICKNESS_BOLD_MIN, SPACING_DEFAULT_MIN, SPACING_SPACIOUS_MIN)
    - **Affects:** Product/design teams wanting to tune the visual mapping of Shopify pixel values to Sidest design enums (square/rounded/pill, hairline/standard/bold, tight/default/spacious).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `design_thresholds` key under `config/partna.php` (e.g. `partna.shopify.design_thresholds`) with the six threshold values.
        - Replace `self::RADIUS_ROUNDED_MIN` etc. with `config('partna.shopify.design_thresholds.radius_rounded_min', 5)`.
    - **Technical:** The comment above these constants reads "These thresholds intentionally match the design brief — change them here and the whole app follows," acknowledging they are a tuning surface, not an implementation detail. The constants are consumed by three `bucket*()` methods (`bucketRadius`, `bucketThickness`, `bucketSpacing`). Moving them to config lets the design team adjust the pixel-to-enum mapping without a code deploy, and keeps the tuning surface discoverable in one file alongside other design-related config.
    - **Plain English:** The design team decides what counts as a "rounded" vs "pill" button by picking pixel ranges. Those numbers are currently written inside the engine. Moving them to the settings panel means the designer can tweak them without asking an engineer to open the hood.
    - **Evidence:**
        ```php
        // Radius:    0-4 = square,    5-16 = rounded,    17+ = pill
        // Thickness: 0-1 = hairline,  2-3  = standard,   4+  = bold
        // Spacing:   0-32 = tight,    33-64 = default,   65+ = spacious
        private const RADIUS_ROUNDED_MIN = 5;
        private const RADIUS_PILL_MIN = 17;
        private const THICKNESS_STANDARD_MIN = 2;
        private const THICKNESS_BOLD_MIN = 4;
        private const SPACING_DEFAULT_MIN = 33;
        private const SPACING_SPACIOUS_MIN = 65;
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-10** · P3 — ShopifyCostTracker tuning constants hardcoded as private constants
    - **Where:** app/Services/Shopify/Client/ShopifyCostTracker.php (MIN_ESTIMATE, WINDOW_SIZE, EXPIRY_SECONDS)
    - **Affects:** Shopify Admin/Storefront API cost estimation accuracy. Incorrect estimates cause unnecessary local-bucket waits or premature THROTTLED exceptions.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add keys under `config/partna.php` at `partna.shopify.cost_tracker.min_estimate`, `.window_size`, `.expiry_seconds`.
        - Replace `self::MIN_ESTIMATE` with `config('partna.shopify.cost_tracker.min_estimate', 10)` and likewise for the other two.
    - **Technical:** The `MIN_ESTIMATE` (10 points) is the floor for any GraphQL query's pre-acquisition cost estimate — too high wastes budget headroom, too low risks THROTTLED responses. `WINDOW_SIZE` (20 samples) controls how many historical actual/requested cost ratios feed the sliding-window arithmetic mean. `EXPIRY_SECONDS` (86400 = 24h) governs how long stale cost samples survive in Redis. All three are operational tuning parameters, not algorithmic constants. Moving them to config lets operators adjust estimation behaviour per environment (e.g. a staging environment could use a smaller window for faster feedback) and keeps them discoverable alongside the throttle config they interact with.
    - **Plain English:** The cost tracker learns how expensive each Shopify query really is by remembering the last 20 calls. The number 20, the minimum budget it reserves, and how long it remembers — these are tuning knobs, not fixed laws of physics. Right now they're bolted to the wall. Putting them in the config file lets the team adjust them with a settings change instead of a code change.
    - **Evidence:**
        ```php
        class ShopifyCostTracker
        {
            private const MIN_ESTIMATE = 10;
            private const WINDOW_SIZE = 20;
            private const EXPIRY_SECONDS = 86400;
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#CFG-11** · P3 — AffiliateProductCatalogService::ADMIN_PRODUCTS_PER_PAGE hardcoded as private constant
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php (ADMIN_PRODUCTS_PER_PAGE = 50)
    - **Affects:** Affiliate catalog query performance and Shopify GraphQL cost budget consumption. A page size too large hits the 1000-point cost ceiling; too small causes excessive round-trips for large catalogs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a key `partna.shopify.catalog_page_size` (or `partna.store.catalog_page_size`) to `config/partna.php`.
        - Replace `self::ADMIN_PRODUCTS_PER_PAGE` with `(int) config('partna.shopify.catalog_page_size', 50)`.
    - **Technical:** The page size of 50 is a tuning compromise between Shopify's 1000-point GraphQL cost budget and HTTP round-trip overhead. It's used in both `COLLECTION_PRODUCTS_QUERY` and `ALL_PRODUCTS_QUERY` paths via the `$first` parameter. BrandCatalogService has the identical `PRODUCTS_PER_PAGE = 50` as its own private constant — if an operator changes one but not the other, the two catalog views (brand admin vs affiliate storefront) diverge in pagination behaviour. A shared config key keeps them in lockstep and makes the trade-off explicit.
    - **Plain English:** When loading a brand's product catalog, we grab 50 products at a time. That number balances speed against Shopify's rate limits. It's currently typed in two different places inside the code. Moving it to a shared settings file makes it one knob — turn it once, both places follow.
    - **Evidence:**
        ```php
        class AffiliateProductCatalogService
        {
            // ...
            private const ADMIN_PRODUCTS_PER_PAGE = 50;
        ```
        ```php
        // Same constant in BrandCatalogService:
        class BrandCatalogService
        {
            // ...
            private const PRODUCTS_PER_PAGE = 50;
        ```
    - `[DRAFT, confidence: 0.80]`
