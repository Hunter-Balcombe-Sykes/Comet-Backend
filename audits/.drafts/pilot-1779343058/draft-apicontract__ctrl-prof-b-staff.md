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
