- [ ] **#API-1** · P1 — BrandAffiliateInviteController::index() exposes invite tokens in list endpoint
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php:69-73 (token in index map)
    - **Affects:** Any brand dashboard user viewing their invite list — invite tokens are single-use secrets that let anyone claim the invite. Returning them in a paginated list leaks claim URLs to every person with dashboard access.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `'token' => $invite->token` line from the index response array.
        - Keep token in the `store()` response only (the brand needs it immediately after creation to copy the invite link).
    - **Technical:** The invite token is the only credential required to call `POST /api/professional/brand/affiliate-invites/{token}/claim`. Returning it in a list endpoint means every dashboard user (or a compromised session token) can harvest all active invite links without ever calling a dedicated "copy link" action. The `store()` return is the correct place — the brand creates the invite and immediately receives the token so they can share it.
    - **Plain English:** Every invite to join a brand has a secret code. Right now the list of all invites shows every code in plain sight, like printing passwords on a company directory. Only the person who created the invite should see the code, and only at the moment they create it.
    - **Evidence:**
        ```php
        return [
            'id' => $invite->id,
            'status' => $effectiveStatus,
            'invite_type' => $invite->invite_type,
            'email' => $invite->email,
            'first_name' => $invite->first_name,
            'last_name' => $invite->last_name,
            'message' => $invite->message,
            'token' => $invite->token,          // ← leaked in list
            'created_at' => optional($invite->created_at)->toIso8601String(),
            'accepted_at' => optional($invite->accepted_at)->toIso8601String(),
            'recipient_partnered_elsewhere' => $partneredElsewhere,
        ];
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#API-2** · P2 — BrandProfileController returns raw Eloquent model bypassing Resource transformation
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandProfileController.php:26-28 (show) and :63-65 (update)
    - **Affects:** Brand dashboard users — raw model exposes all BrandProfile columns including `signup_code`, `signup_code_active`, `signup_code_rotated_at`, and any future columns added via migration without a Resource contract gate.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `BrandProfileResource` extending `JsonResource`.
        - Whitelist only the fields the brand dashboard actually renders (ABN, ACN, legal_business_name, business_type, industries, affiliate_visibility, setup_complete, etc.).
        - Omit signup-code fields — those already have a dedicated `BrandSignupCodeController`.
    - **Technical:** `$this->success(['brand_profile' => $profile])` passes the raw Eloquent model into the JSON response. Laravel calls `->toArray()` which serializes every non-hidden attribute. BrandProfile currently uses `protected $fillable` but no `$hidden` array, so a migration adding an `internal_notes` or `stripe_account_id` column would silently ship it to the frontend. A Resource class is the canonical contract gate — only fields listed in `toArray()` ever reach the wire.
    - **Plain English:** Think of a Resource class as a shipping manifest. Right now the controller is loading the whole warehouse onto the truck and hoping nothing sensitive is in the boxes. A proper manifest lists exactly what's supposed to go out, and anything not on the list stays in the warehouse even if someone adds new inventory later.
    - **Evidence:**
        ```php
        // show() — raw model return
        public function show(Request $request)
        {
            $professional = $this->currentProfessional($request);
            $profile = BrandProfile::where('professional_id', $professional->id)->first();
            return $this->success([
                'brand_profile' => $profile,   // ← raw Eloquent model
            ]);
        }

        // update() — raw model return
        return $this->success([
            'brand_profile' => $profile->fresh(),   // ← raw Eloquent model
        ]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-3** · P2 — ProfessionalServiceController returns raw Eloquent models from all CRUD endpoints
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceController.php:58-62 (index), :117 (store), :130 (show), :142 (update), :168 (restore)
    - **Affects:** Professional dashboard users — raw `Service` model serialisation exposes all columns including `square_variation_id`, `square_catalog_object_id`, and any internal sync metadata. No Resource gate on the API contract.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `ServiceResource` extending `JsonResource`.
        - Whitelist: id, category_id, title, description, price_cents, currency_code, duration_minutes, is_active, sort_order, created_at, updated_at.
        - Omit Square/Fresha sync IDs unless a specific `?include=integrations` query param is present.
    - **Technical:** Every CRUD method returns `$service`, `$service->fresh()`, or a `$services` Collection — all raw Eloquent. No `$hidden` is set on the Service model. The `square_variation_id` and related integration columns are implementation details that the frontend never renders; leaking them creates a coupling point where the frontend could accidentally depend on a Square ID format that changes upstream.
    - **Plain English:** The service list hands the frontend a full database printout — including barcodes and stockroom labels the UI doesn't need and shouldn't see. A Resource class acts like a bouncer at the door, only letting through the fields that belong on the customer-facing menu.
    - **Evidence:**
        ```php
        // index() — raw Collection return
        return $this->success([
            'services' => $services,   // ← raw Eloquent Collection
            'filters' => [ ... ],
        ]);

        // store() — raw model return
        return $this->success(['service' => $service], 201);   // ← raw Eloquent model

        // show() — raw model return
        return $this->success(['service' => $service]);   // ← raw Eloquent model
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-4** · P2 — ProfessionalServiceCategoryController returns raw Eloquent models from all CRUD endpoints
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php:34 (index), :57 (store), :68 (show), :82 (update), :168 (restore)
    - **Affects:** Professional dashboard users — same raw-model leakage pattern as API-3, this time for ServiceCategory.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `ServiceCategoryResource` and apply it consistently in index, store, show, update, and restore responses.
    - **Technical:** Same pattern as API-3. Every method returns a raw Eloquent `ServiceCategory` or Collection. Any column added to the `service_categories` table via migration automatically becomes part of the API response without an explicit opt-in.
    - **Plain English:** Same warehouse-on-a-truck problem as the services list. Categories have their own set of database columns and the same risk of accidental exposure.
    - **Evidence:**
        ```php
        // index()
        $categories = $q->orderBy('sort_order')->orderBy('created_at')->get();
        return $this->success([
            'categories' => $categories,   // ← raw Eloquent Collection
        ]);

        // store()
        return $this->success(['category' => $category], 201);   // ← raw Eloquent model
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-5** · P2 — BrandAffiliateController::index() leaks private phone numbers before public contact numbers
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:71
    - **Affects:** Affiliates whose private phone number is visible to their connected brand when they've only set a public contact number. The fallback order is backwards.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Swap the fallback: `$connectedProfessional?->public_contact_number ?? $connectedProfessional?->phone`.
        - If neither is set, omit the field or return `null`.
    - **Technical:** The current expression `$connectedProfessional?->phone ?? $connectedProfessional?->public_contact_email` — wait, let me re-read. The email line is: `'email' => $connectedProfessional?->primary_email ?? $connectedProfessional?->public_contact_email`. The phone line is: `'phone' => $connectedProfessional?->phone ?? $connectedProfessional?->public_contact_number`. The private `phone` field takes priority over `public_contact_number`. If an affiliate sets `public_contact_number` for their brand partners to see but leaves the private `phone` populated, the brand sees the private number — the exact opposite of the intent. The public field should be preferred for this audience.
    - **Plain English:** An affiliate can set a "public contact number" specifically for brands to use. But a bug in the code checks their private number first and only falls back to the public one if private is empty. It's like writing your work phone on a business card but the printer grabs your home phone instead because it found it first.
    - **Evidence:**
        ```php
        'email' => $connectedProfessional?->primary_email ?? $connectedProfessional?->public_contact_email,
        'phone' => $connectedProfessional?->phone ?? $connectedProfessional?->public_contact_number,
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#API-6** · P2 — BrandBillingSummaryController uses `response()->json()` instead of `$this->success()`, creating inconsistent response envelope
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandBillingSummaryController.php:41-50
    - **Affects:** Frontend clients consuming the billing summary — they must special-case this endpoint because the response shape differs from every other Professional API endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend `ApiController` instead of `Controller`.
        - Replace `return response()->json([...])` with `return $this->success([...])`.
    - **Technical:** The controller extends the base `Controller` class rather than `ApiController`. All other Professional endpoints use `$this->success()` which wraps the response in a consistent envelope (typically `{data: {...}}`). The `response()->json()` call returns the array directly without the envelope. Frontend middleware or response interceptors that expect every API response to have the same top-level shape will break on this endpoint.
    - **Plain English:** Every letter from the company goes out in the same branded envelope. Except the billing team prints their letter directly on the envelope itself with no branding, no return address. The mailroom software that opens and sorts envelopes chokes on it because it doesn't look like the others.
    - **Evidence:**
        ```php
        class BrandBillingSummaryController extends Controller  // ← not ApiController
        {
            public function show(Request $request): JsonResponse
            {
                // ...
                return response()->json([       // ← not $this->success()
                    'has_card' => $hasCard,
                    'masked_card' => $hasCard ? [
                        'brand' => $brand->stripe_payment_method_brand,
                        'last4' => $brand->stripe_payment_method_last4,
                    ] : null,
                    'blocked_orders_count' => (int) ($blockedData->cnt ?? 0),
                    'blocked_pending_cents' => (int) ($blockedData->pending_cents ?? 0),
                    'currency' => 'AUD',
                ]);
            }
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-7** · P2 — StatsController uses `response()->json()` instead of `$this->success()`, creating inconsistent response envelope
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/StatsController.php:32-34
    - **Affects:** Frontend analytics dashboard — same envelope inconsistency as API-6, on a high-traffic analytics endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend `ApiController` instead of `Controller`.
        - Replace `return response()->json($payload)` with `return $this->success($payload)`.
        - Replace the 403 error `response()->json(['error' => 'cross_role'], 403)` with `$this->error('cross_role', 403)`.
    - **Technical:** Same pattern as API-6. The controller extends `Controller` and uses `response()->json()`. The 403 error path also bypasses the consistent error envelope that `$this->error()` provides. All other analytics controllers (`AffiliateCommerceAnalyticsController`, `BrandCommerceAnalyticsController`, `ProfessionalAnalyticsController`) extend `ApiController` and use `$this->success()` — this endpoint is the odd one out.
    - **Plain English:** Same envelope problem as the billing endpoint. The analytics page is one of the most-used surfaces in the dashboard, and it ships its responses in a different package than every other analytics endpoint.
    - **Evidence:**
        ```php
        class StatsController extends Controller   // ← not ApiController
        {
            public function index(StatsRequest $request): JsonResponse
            {
                // ...
                return response()->json($payload);   // ← not $this->success()
            }
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-8** · P3 — BrandAffiliateController::index() has no pagination
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:26-29
    - **Affects:** Brands with many affiliates — the response grows unbounded. A brand with 500+ affiliates ships a multi-megabyte JSON payload on every dashboard load.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `->get()` with `->paginate($perPage)` on the `BrandPartnerLink` query.
        - Resolve `$sitesByProfessionalId` for the current page's affiliate IDs only (the current code already resolves by `$affiliateIds` which is global — keep that scoping but ensure the paginated subset works).
        - Return pagination metadata (`meta.current_page`, `meta.last_page`, `links.next`).
    - **Technical:** `BrandPartnerLink::query()->...->get()` fetches every link for the brand with no LIMIT. The response payload is the cartesian product of all links × site enrichment for each affiliate. The controller already has the `PaginatedResponse` trait available (used in `BrandAffiliateInviteController`), so the pagination plumbing exists — it just isn't applied here.
    - **Plain English:** The affiliate list loads every single affiliate at once, like a restaurant menu that lists every dish the kitchen has ever made instead of today's specials. For a brand with a handful of affiliates it's fine; for one with hundreds it's a loading spinner that never ends.
    - **Evidence:**
        ```php
        $links = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandId)
            ->orderByDesc('updated_at')
            ->get(['affiliate_professional_id', 'slot', 'custom_photos_enabled', 'site_url', 'updated_at']);
            // ↑ no paginate()
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-9** · P3 — ProfessionalGalleryController::index() has no pagination on image listing
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php:33-38
    - **Affects:** Professionals with large galleries — response grows unbounded. Also returns raw mapped arrays without a Resource class.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add pagination with a sensible `per_page` default (25).
        - Create a `GalleryImageResource` to formalize the field contract (currently manually mapped in a `->map()` closure).
    - **Technical:** The `.get()` without pagination pattern repeats here. Additionally, the manual `->map(fn (SiteMedia $img) => [...])` inside the controller is effectively a Resource class written inline — it belongs in `app/Http/Resources/` so the field contract is centrally testable and reusable (the gallery images shape is also consumed by the public site API).
    - **Plain English:** Same unbounded-list problem as the affiliate list. Every gallery photo loads at once. For a photographer with 200 images, that's a heavy page load every time they open their gallery settings.
    - **Evidence:**
        ```php
        $images = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_GALLERY)
            ->where('is_active', true)
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();   // ← no paginate()
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#API-10** · P3 — ProfessionalThemeController::index() returns raw Eloquent collection without Resource
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalThemeController.php:20-23
    - **Affects:** Theme selector UI — raw model serialisation means any new Theme column auto-ships to the frontend.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a `ThemeResource` with explicit field mapping.
        - Replace the raw `$themes` return with `ThemeResource::collection($themes)`.
    - **Technical:** `Theme::query()->...->get()` returns an Eloquent Collection. The `config` JSON column on Theme may contain internal configuration that the theme picker UI doesn't need. A Resource class gate prevents accidental exposure when the Theme model gains columns.
    - **Plain English:** The theme list is small and low-risk, but it's the same pattern as the bigger raw-model returns. Wrapping it in a Resource now is cheap insurance against future column additions.
    - **Evidence:**
        ```php
        public function index()
        {
            $themes = Theme::query()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'key', 'name', 'description', 'config', 'is_default']);
            return $this->success(['themes' => $themes]);   // ← raw Eloquent Collection
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#API-11** · P3 — ProfessionalSiteController returns `$site->toArray()` bypassing Resource transformation
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSiteController.php:30-33 (show) and :38-42 (update)
    - **Affects:** Site settings page — raw model serialisation of the Site model including its `settings` JSONB column.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `SiteResource` or reuse an existing one if available.
        - Ensure `settings` sub-keys are explicitly whitelisted rather than dumped wholesale.
    - **Technical:** `$site->toArray()` serializes every non-hidden column including the full `settings` JSONB bag. The `settings` column is a catch-all for site configuration and can contain internal keys (`brand_partner`, `additional_brand_partners`, raw integration metadata) that the frontend may not need. A Resource class allows selective exposure of settings sub-keys.
    - **Plain English:** The site settings response dumps the entire configuration drawer onto the frontend. Most of it is harmless, but as new features add new settings keys, they'll all appear automatically — including keys meant for server-side use only.
    - **Evidence:**
        ```php
        public function show(Request $request)
        {
            $professional = $this->currentProfessional($request);
            $site = $this->currentSite($professional);
            $siteArray = $site->toArray();   // ← raw model toArray()
            $siteArray = $this->siteCache->enrichSiteWithBrandPartnerRadius($siteArray);
            return $this->success(['site' => $siteArray]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`
