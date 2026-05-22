
<!-- ═══ CHUNK: ctrl-prof-a ═══ -->

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

<!-- ═══ CHUNK: ctrl-prof-b-staff ═══ -->

- [ ] **#API-1** · P2 — StaffNotificationController::store returns raw Eloquent model instead of a Resource
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:96
    - **Affects:** Staff notification creation endpoint — the full Notification model (all columns) is serialised into the JSON response, leaking internal DB defaults and any future columns added to the table.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a `NotificationResource` class (if one doesn't already exist) that explicitly allowlists the fields staff should see.
        - Replace `$this->success(['notification' => $notification], 201)` with `$this->success(['notification' => new NotificationResource($notification)], 201)`.
    - **Technical:** Partna's architecture doctrine requires all API responses to pass through Eloquent API Resource classes. Returning a raw model bypasses the Resource layer entirely — `$notification` gets serialised with all attributes including any internal flags, raw timestamps, and future columns that get added to the table. A Resource class provides a field-level allowlist that is the single source of truth for the API contract.
    - **Plain English:** This endpoint hands out the entire database row for a notification, like giving someone your whole filing cabinet when they only asked for the sticky note on top. If we add a private internal note column to the database later, it'll automatically leak into the staff dashboard without anyone noticing. A Resource class acts like a receptionist who only hands over the specific documents you're allowed to see.
    - **Evidence:**
        ```php
        return $this->success(['notification' => $notification], 201);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#API-2** · P2 — StaffAffiliateStatusController::update returns raw Professional model with PII
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffAffiliateStatusController.php:35
    - **Affects:** Staff endpoint that suspends/reactivates an affiliate — the response contains the full Professional model including `primary_email`, `phone`, `stripe_connect_account_id`, and any other columns on the table, without Resource filtering.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$this->success(['professional' => $affiliate->fresh()])` with the dedicated `ProfessionalStaffResource` already used by other staff controllers (e.g. `StaffProfessionalController::updateStatus`).
    - **Technical:** `$affiliate->fresh()` returns the full Eloquent model which serialises every non-hidden attribute. Even though this is a staff-only endpoint, the architectural policy is universal — all model returns must pass through a Resource class. `ProfessionalStaffResource` already exists and is used elsewhere in the staff surface; this controller simply isn't using it. The model's `$hidden` array provides some defence, but Resource classes are the explicit contract layer.
    - **Plain English:** When staff changes an affiliate's status, the response accidentally includes their email, phone number, and Stripe account ID — like a receptionist who announces someone's phone number to the whole office when just confirming their appointment is booked. A dedicated "staff view" template already exists for this exact purpose; this one endpoint just forgot to use it.
    - **Evidence:**
        ```php
        $affiliate->status = $data['status'];
        $affiliate->save();

        return $this->success(['professional' => $affiliate->fresh()]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-3** · P2 — StaffCustomerManagementController returns raw Customer model in show/update/restore
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCustomerManagementController.php:94,102,131
    - **Affects:** Staff customer detail view, update, and restore endpoints — raw Customer models serialised with all attributes (email, phone, notes, marketing_opt_in_cached, etc.) bypassing the `CustomerResource` class used by the professional-facing counterpart.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$this->success(['customer' => $customer])` with `$this->success(['customer' => new \App\Http\Resources\CustomerResource($customer)])` in `show()`.
        - Replace `$this->success(['customer' => $customer->fresh()])` with the Resource-wrapped version in `update()` and `restore()`.
    - **Technical:** The professional-facing `ProfessionalCustomerController` consistently uses `CustomerResource` for all responses. The staff counterpart directly returns Eloquent models, which means any new column added to the `customers` table is automatically exposed to staff without a conscious allowlist decision. Since staff and professional surfaces share the same `CustomerResource`, using it here also guarantees both surfaces stay in sync when the Resource is updated.
    - **Plain English:** The customer support team sees a different, wider view of customer data than what customers see of themselves — which is intentional. But the way it's built, every time we add a column to the customer database, it shows up in the support dashboard automatically, without anyone deciding whether support agents actually need to see it. Using the same "customer profile template" that the customer-facing side uses means both views are deliberate.
    - **Evidence:**
        ```php
        // show()
        return $this->success(['customer' => $customer]);

        // update()
        return $this->success(['customer' => $customer->fresh()]);

        // restore()
        return $this->success(['restored' => true, 'customer' => $customer->fresh()]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-4** · P3 — ProfessionalUploadController::index has no pagination on non-polling path
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:212-225
    - **Affects:** Professional dashboard media gallery view — returns all active media items in a single response. Practically bounded by pool limits (5–6 items per pool), but the architectural pattern is inconsistent with every other index endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either add `->paginate(25)` and include pagination metadata in the response, or document the intentional divergence with a prominent code comment explaining why pagination is omitted (pool limits make it unnecessary).
    - **Technical:** The non-`?ids[]` path calls `$query->get()` with no limit or offset. Every other list endpoint in the codebase uses `paginate()`. While the pool config caps items at 5 per pool (2 pools = max ~10 items), a future config change could raise this to 50 or 100 and suddenly this endpoint returns an unbounded payload. Pagination is cheap insurance.
    - **Plain English:** Most "list" endpoints in the app return results page-by-page, like a book. This one returns the whole book at once. Right now the book is only 10 pages long, so nobody notices. But if we ever decide to allow 100 pages, this endpoint suddenly dumps 100 pages onto the reader's lap with no warning. Adding page controls now costs almost nothing and prevents a surprise later.
    - **Evidence:**
        ```php
        $query->with('mediaVariants');

        $items = $query->get()->map(fn (SiteMedia $item) => $this->buildMediaPayload($item, includeVariants: true));

        return [
            'images' => $items->values()->all(),
            'limits' => [...],
        ];
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#API-5** · P3 — PlanController returns raw mapped array instead of using a Resource class
    - **Where:** app/Http/Controllers/Api/Professional/Subscription/PlanController.php:19-27
    - **Affects:** Public plan listing endpoint — returns plans without `created_at`/`updated_at` timestamps clients may need for cache-busting, and uses `response()->json()` directly instead of the project-standard `$this->success()` envelope.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a `PlanResource` class that explicitly define the public fields (id, name, description, price_cents, currency_code, billing_interval, entitlements, created_at, updated_at).
        - Replace the inline `map()` + `response()->json()` with `PlanResource::collection($plans)` returned through `$this->success()`.
    - **Technical:** The `map()` callback is a manual transformation that duplicates what a Resource class would do, but without the consistency guarantees. If the `Plan` model gains a new column (e.g. `stripe_product_id`), it won't leak here because of the explicit mapping — but there's no single source of truth for the Plan API shape. A Resource class also makes it trivial to add `created_at`/`updated_at` if a client needs them, without hunting through controllers.
    - **Plain English:** The pricing plans page works fine, but the way it's built is like handwriting each plan's details on a napkin instead of using a printed menu template. If we ever need to add "last updated" timestamps so the frontend knows when to refresh, we'd have to remember to add it to this handwritten list. A menu template means the change happens in one place and every page that shows plans gets it automatically.
    - **Evidence:**
        ```php
        $plans = Plan::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'price_cents' => $plan->price_cents,
                'currency_code' => $plan->currency_code,
                'billing_interval' => $plan->billing_interval,
                'entitlements' => $plan->entitlements,
            ]);

        return response()->json([
            'data' => $plans,
        ]);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#API-6** · P3 — ProfessionalCustomerController renames pagination metadata key inconsistently
    - **Where:** app/Http/Controllers/Api/Professional/Customers/ProfessionalCustomerController.php:82-84
    - **Affects:** Professional customer list endpoint — clients must handle `pagination` on this endpoint but `meta` on every other paginated endpoint (e.g. enquiries, orders, email subscribers).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the rename: drop `$payload['pagination'] = $payload['meta']; unset($payload['meta']);` and update the frontend to read `meta` instead of `pagination` from this endpoint.
        - Alternatively, standardise all paginated endpoints to use `pagination` instead of `meta`, but the rename on a single endpoint is the inconsistency.
    - **Technical:** `ReturnsPaginatedResponse` produces a `meta` key with `current_page`, `last_page`, `per_page`, `total`. Every other controller using this trait keeps it as `meta`. This controller renames it to `pagination`, forcing the frontend to special-case this endpoint. If the frontend already depends on `pagination` here, the fix should go the other direction (standardise on `pagination` everywhere), but the inconsistency is the bug regardless of which direction the fix takes.
    - **Plain English:** Every list endpoint in the app puts page information in a box labelled "meta." Except the customer list, which puts the exact same information in a box labelled "pagination." The frontend has to check which box the information is in depending on which page you're looking at. Pick one label and use it everywhere.
    - **Evidence:**
        ```php
        $payload = $this->paginatedResponse($paginator, 'customers', [...]);
        $payload['pagination'] = $payload['meta'];
        unset($payload['meta']);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#API-7** · P3 — StripeConnectController uses raw response()->json() bypassing ApiController success/error envelope
    - **Where:** app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php (multiple methods: status, onboard, dashboard, disconnect, createPaymentMethodCheckoutSession, createBecsCheckoutSession, syncPaymentMethodSession, removePaymentMethod, setPaymentMethodPreference, balance, upcomingPayouts)
    - **Affects:** Every Stripe Connect endpoint — response shape is `{'key': value}` instead of the project-standard `{'data': {...}}` envelope produced by `$this->success()`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `return response()->json([...])` with `return $this->success([...])` across all methods.
        - For error responses using `response()->json(['error' => ...], 422)`, use `$this->error(...)` instead.
        - Verify the frontend Stripe dashboard code doesn't depend on the unwrapped shape before changing.
    - **Technical:** `ApiController::success()` wraps the payload in a standardised `{'data': ...}` envelope with optional HTTP status. `ApiController::error()` provides consistent `{'message': ..., 'code': ...}` shape. `StripeConnectController` bypasses both, using raw `response()->json()` throughout. This means the Stripe dashboard frontend has its own contract that diverges from every other professional-facing endpoint. If a global middleware or response handler is added later (e.g. for logging or rate-limit headers), these raw responses may miss it.
    - **Plain English:** Every page in the app serves responses on a standard dinner plate. The Stripe payment settings pages serve responses on a completely different kind of plate — same food, different presentation. If we ever need to add a garnish to every plate (like a request ID header for debugging), these pages would get missed because they're not using the standard plating process.
    - **Evidence:**
        ```php
        // status()
        return response()->json([
            'connect' => $connectStatus,
            'has_payment_method' => $hasPaymentMethod,
            ...
        ]);

        // onboard()
        return response()->json(['onboarding_url' => $url]);

        // dashboard()
        return response()->json(['dashboard_url' => $url]);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#API-8** · P3 — StaffProfessionalController::index over-fetches Theme relation
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffProfessionalController.php:62
    - **Affects:** Staff professional list endpoint — every row triggers a join to the `themes` table, but only `theme.id`, `theme.key`, and `theme.name` are used in the response.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `->with(['site.theme'])` to `->with(['site.theme:id,key,name'])` to select only the needed columns.
        - Alternatively, if Theme has few columns (under ~10), document why the full load is acceptable and close this finding.
    - **Technical:** `->with(['site.theme'])` loads every column from the `themes` table for each row in the paginated result set. Only `id`, `key`, and `name` are read in the response mapping. If `themes` has a large JSON `config` column or other metadata, this wastes DB bandwidth and memory on every page load. Column-limited eager loading is a zero-cost optimisation — Laravel supports `with(['relation:col1,col2'])` syntax natively.
    - **Plain English:** When staff loads the professional list, the app asks the database for every piece of information about each theme — including things like raw configuration blobs that nobody looks at on this screen. It's like pulling a full personnel file when all you needed was their name and desk number. Asking for just the three columns actually needed makes the query faster and uses less memory.
    - **Evidence:**
        ```php
        $query = Professional::query()
            ->with(['site.theme'])   // loads all theme columns
            ->orderByDesc('created_at');
        ```
        And in the response mapping only these theme fields are used:
        ```php
        'theme' => $theme ? [
            'id' => $theme->id,
            'key' => $theme->key ?? null,
            'name' => $theme->name ?? null,
        ] : null,
        ```
    - `[DRAFT, confidence: 0.9]`

<!-- ═══ CHUNK: ctrl-public-internal ═══ -->

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

<!-- ═══ CHUNK: http-boundary ═══ -->

- [ ] **API-1** · P3 — Staff site-update endpoint accepts retired design fields that the professional endpoint has prohibited
    - **Where:** app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php (rules section) vs app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php (rules section)
    - **Affects:** Staff users can persist values to retired design keys (e.g. `settings.design.typography.heading_font`) that the brand’s own API rejects. The professional UI ignores these fields, so the data becomes invisible orphan state.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the same `prohibited` rules to `StaffUpdateSiteRequest` for keys that `UpdateSiteRequest` marks prohibited (`heading_font`, `body_font`, `font_file_name`, `font_file_path`, `font_file_url`, and legacy colour keys).
        - Add the unified design shape (e.g. `settings.design.font_family`, `settings.design.colors.accent`) to `StaffUpdateSiteRequest` so staff can edit the brand’s design through the canonical schema.
    - **Technical:** The professional `UpdateSiteRequest` prohibits half a dozen legacy design keys (e.g. `heading_font`) and enforces new normalised enums (`font_family`, `colors.accent`). The staff `StaffUpdateSiteRequest` still white-lists those legacy keys as writeable while missing the new keys entirely. This asymmetry means a staff edit can create a diverging design record that the self‑serve professional UI never displays or overwrites.
    - **Plain English:** The staff toolbox lets you paint with colours the brand’s own dashboard no longer shows — the two sides end up looking at different walls, and the extra paint sits there forever unseen.
    - **Evidence:**
        ```php
        // UpdateSiteRequest (professional) — prohibits legacy fields
        'settings.design.typography.heading_font' => ['prohibited'],
        'settings.design.typography.body_font' => ['prohibited'],
        'settings.design.typography.font_file_name' => ['prohibited'],
        'settings.design.typography.font_file_path' => ['prohibited'],
        'settings.design.typography.font_file_url' => ['prohibited'],
        // StaffUpdateSiteRequest (staff) — still allows them
        'settings.design.typography.heading_font' => ['sometimes', 'nullable', 'string', 'max:255'],
        'settings.design.typography.body_font' => ['sometimes', 'nullable', 'string', 'max:255'],
        'settings.design.typography.font_file_name' => ['prohibited'],
        'settings.design.typography.font_file_path' => ['prohibited'],
        'settings.design.typography.font_file_url' => ['prohibited'],
        // Staff site request also lacks the new unified design fields:
        // 'settings.design.font_family' is absent from StaffUpdateSiteRequest
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **API-2** · P3 — Staff site-update endpoint missing the new unified brand‑design schema
    - **Where:** app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php
    - **Affects:** Staff users cannot edit the canonical brand design properties (font family, accent colour, theme mode, radius/spacing/border enums) through the staff API.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add the new unified design rules from `UpdateSiteRequest` to `StaffUpdateSiteRequest`: `settings.design.colors.accent`, `settings.design.corner_radius`, `settings.design.border_thickness`, `settings.design.section_spacing`, `settings.design.theme_mode`, `settings.design.font_family`.
        - Prohibit the now‑derived colour keys that the professional endpoint prohibits (`colors.background`, `colors.text`, `colors.border`).
    - **Technical:** The professional `UpdateSiteRequest` already enforces the normalised design tokens introduced by the theme‑mode migration. `StaffUpdateSiteRequest` still operates on the old free‑key colour and typography fields and completely lacks the new keys, so a staff member viewing a brand’s design cannot change the current design values — they can only modify legacy keys that are ignored by the render pipeline.
    - **Plain English:** Staff can open a brand’s settings toolbox but only see old, useless dials — the real knobs that control the look and feel aren’t there for them.
    - **Evidence:**
        ```php
        // UpdateSiteRequest includes the new unified design rules:
        'settings.design.colors' => ['sometimes', 'array'],
        'settings.design.colors.accent' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
        'settings.design.corner_radius' => ['sometimes', 'nullable', 'string', Rule::in(['square', 'default', 'pill'])],
        'settings.design.border_thickness' => ['sometimes', 'nullable', 'string', Rule::in(['hairline', 'default', 'bold'])],
        'settings.design.section_spacing' => ['sometimes', 'nullable', 'string', Rule::in(['tight', 'default', 'spacious'])],
        'settings.design.theme_mode' => ['sometimes', 'nullable', 'string', Rule::in(['light', 'dark'])],
        'settings.design.font_family' => ['sometimes', 'nullable', 'string', Rule::in([...])],

        // StaffUpdateSiteRequest has none of the above — the entire
        // unified design shape is absent.
        ```
    - `[DRAFT, confidence: 0.9]`
