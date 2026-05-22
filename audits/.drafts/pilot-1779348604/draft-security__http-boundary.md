- [ ] **#SEC-1** · P1 — `SecureHeaders` sets `Access-Control-Allow-Origin: *` as fallback on all responses missing the header
    - **Where:** app/Http/Middleware/SecureHeaders.php:20-22
    - **Affects:** Every API response where `HandleCors` fails to set an origin header — authenticated endpoints included. A proxy/CDN stripping CORS headers would trigger the wildcard.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `*` fallback with either (a) the request's `Origin` header when it matches a configured allowlist, or (b) no header at all (let same-origin policy apply).
        - Remove the catch-all `Access-Control-Allow-Origin: *` setter; Laravel's `HandleCors` + `config/cors.php` is the single source of truth.
    - **Technical:** Browsers refuse `Access-Control-Allow-Origin: *` on credentialed responses per the fetch spec, so this isn't a direct cross-origin data leak today. But it's a brittle defense-in-depth failure: if a misbehaving proxy strips the proper origin header set by `HandleCors`, every API response — including those bearing `Authorization` headers — would carry `*`. Combined with a future browser relaxation or a non-browser client that ignores the credential-exclusion rule, this becomes an exploitable misconfiguration. The correct fallback is to emit no header, keeping the same-origin default.
    - **Plain English:** Imagine your office building has an electronic badge system that normally checks IDs at every door. But you've also stuck a Post-it note on the server room that says "if the badge reader is broken, just let everyone in." That's what this code does — it's a safety net that accidentally becomes a welcome mat. The fix is to remove the Post-it note: if the badge system fails, the door stays locked.
    - **Evidence:**
        ```php
        // CORS: always allow any origin. HandleCors middleware normally adds this
        // but Laravel Cloud's edge proxy can strip it on some responses. Setting it
        // here (global, appended last) guarantees the header survives.
        if (! $response->headers->has('Access-Control-Allow-Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-2** · P2 — `ProfessionalResource` exposes `primary_email` and `phone` in a general-purpose resource consumed by staff and potentially cross-professional views
    - **Where:** app/Http/Resources/ProfessionalResource.php:20-41
    - **Affects:** Any API endpoint returning `ProfessionalResource` where the viewer is not the subject professional — staff dashboard, brand viewing affiliate details, affiliate listing.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Split `ProfessionalResource` into audience-specific variants: a self-view resource (dashboard), a staff-view resource (`ProfessionalStaffResource` already exists), and a peer-view resource that omits `primary_email`, `phone`, `location_street_address`.
        - Audit every controller that instantiates `ProfessionalResource` and route it to the correct variant.
    - **Technical:** `ProfessionalResource` extends `JsonResource` and ships `primary_email`, `phone`, `location_street_address`, `location_city`, `location_state`, `location_postcode`, and `location_country` unconditionally. The companion `ProfessionalDashboardResource` is wired for `/me` endpoints and also ships these fields — that one is fine since the viewer IS the subject. But `ProfessionalResource` lacks an `$audience` discriminator: if a controller returns it for a cross-professional query (e.g. a brand listing its affiliates), the brand receives every affiliate's private email and phone. The existing `ProfessionalPublicResource` proves the team already understands this split for public traffic; the same split needs to exist inside the authenticated boundary.
    - **Plain English:** Think of `ProfessionalResource` as a printed form that gets handed to different people — the professional themselves, staff members, and other professionals on the platform. Right now that form always includes the professional's private email and phone number, no matter who's holding it. It's like a doctor's receptionist handing every patient the full medical file of the person who checked in before them. The fix is to print different versions of the form depending on who's asking.
    - **Evidence:**
        ```php
        'primary_email' => $this->primary_email,
        // ...
        'phone' => $this->phone,
        // ...
        'location_street_address' => $this->location_street_address,
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SEC-3** · P2 — `CustomerResource` leaks `professional_id` to API consumers, enabling tenant-enumeration
    - **Where:** app/Http/Resources/CustomerResource.php:16-26
    - **Affects:** Any frontend or third-party integration consuming the customer list endpoint — exposes the owning professional's UUID.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `professional_id` from the resource array, or gate it behind a `when()` clause scoped to staff-only contexts.
        - If the frontend needs it for routing, use an opaque, non-enumerable reference instead.
    - **Technical:** Internal UUIDs like `professional_id` are database foreign keys, not API contract fields. Exposing them lets an attacker with access to one customer record infer the professional's UUID and probe other endpoints (e.g. `/api/professional/{id}`) to map the tenant graph. The rest of the API uses subdomain-based resolution for public routes and JWT-claims for authenticated routes — raw professional UUIDs in response bodies weaken that abstraction. Removing the field from the resource is a one-line change; if the Vue dashboard genuinely uses it for intra-SPA navigation, replace it with a route-level parameter derived from the session, not the response body.
    - **Plain English:** Every customer record in your API response includes a "which store owns this customer" serial number. If someone gets access to one customer record (e.g. a shared link, a logged-in session at a coffee shop), they can change that serial number in the URL and peek at other stores' data. It's like printing your home address on every receipt you hand out — unnecessary and risky. The fix is to stop printing it.
    - **Evidence:**
        ```php
        'professional_id' => $this->professional_id,
        'email' => $this->email,
        'phone' => $this->phone,
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-4** · P2 — `EnquiryResource` ships full contact PII (email, phone) without audience gating
    - **Where:** app/Http/Resources/EnquiryResource.php:17-27
    - **Affects:** Enquiry listing endpoints — any brand or staff viewer sees the enquirer's email and phone in the JSON body.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Gate `email` and `phone` behind `when()` with a staff-only condition (e.g. `$request->attributes->has('partna_staff')`), or create a `StaffEnquiryResource` variant.
        - For brand viewers, return only `name`, `subject`, `message`, and metadata — the brand already has the contact details from the notification email; duplicating them in the API response is unnecessary exposure.
    - **Technical:** `EnquiryResource` unconditionally emits `email` and `phone` alongside `name`, `subject`, and `message`. The enquiry listing is rendered in the brand dashboard, where any logged-in brand team member can view all enquiries. There's no reason a brand needs the enquirer's raw email in the JSON response — it arrives via email notification already. The API response should follow the principle of least privilege: ship only what the UI renders, and let staff-only views carry the full PII payload via a dedicated resource class (`StaffEnquiryResource` — same pattern as `ProfessionalStaffResource`).
    - **Plain English:** When a customer fills out a "Contact Us" form, the store owner gets an email with all the details. But the API that powers the store's dashboard ALSO sends back the customer's email and phone number in the raw data — even though the dashboard doesn't need to display them. It's like writing the customer's phone number on a whiteboard in the break room when the manager already has it in their pocket. The fix is to leave those fields out of the dashboard response. Staff can still see them through a separate, restricted view.
    - **Evidence:**
        ```php
        'email' => $this->email,
        'phone' => $this->phone,
        'subject' => $this->subject,
        'message' => $this->message,
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-5** · P2 — `NotificationListingResource` exposes `professional_id` in notification list responses
    - **Where:** app/Http/Resources/NotificationListingResource.php:20-22
    - **Affects:** `GET /me/notifications` and staff on-behalf-of mirror — the owning professional's UUID is embedded in every notification row.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `professional_id` from the resource entirely — the authenticated professional's identity is already established by the JWT session; repeating it in the response body is redundant and aids enumeration.
        - If staff on-behalf-of views genuinely need it, gate it behind `when($request->attributes->has('partna_staff'))`.
    - **Technical:** The notification listing query already scopes to a single professional (via the controller's `where('professional_id', $pro->id)`), so the value is constant across all rows — putting it in every row is both wasteful and an information leak. An attacker who compromises a single notification row (e.g. via a shared session, XSS, or browser extension) gains the professional's UUID, which can then be used to probe other API endpoints. The ID is already in the URL path for staff routes and in the JWT for self-serve routes; the response body doesn't need to repeat it.
    - **Plain English:** Every notification in your inbox already lives inside your account — your account ID is in the envelope, not on every letter. Putting your account ID on every notification is like writing your driver's license number at the top of every email you receive. If someone reads one email over your shoulder, they now have your license number. The fix is to leave it off.
    - **Evidence:**
        ```php
        'professional_id' => $this->professional_id !== null ? (string) $this->professional_id : null,
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-6** · P3 — `AffiliatePayoutResource` conditional redaction of `failure_category` can be bypassed if `brandProfessional` relation exposes funding state through other fields
    - **Where:** app/Http/Resources/AffiliatePayoutResource.php:26-28
    - **Affects:** Affiliates viewing payout details — the intent is to hide `brand_funding` failures, but the nested `brand` relation may leak brand identity that enables side-channel inference.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - When `failure_category === 'brand_funding'`, also suppress the `brand` relation from the response, or null its `name` field.
        - Add a test asserting that a brand-funding-failed payout returns no brand-identifying information.
    - **Technical:** The resource correctly nulls `failure_category` when it equals `'brand_funding'`, preventing the affiliate from seeing the literal reason "the brand couldn't pay." However, the `brand` nested relation (loaded via `whenLoaded('brandProfessional')`) still emits `brandProfessional.id` and `brandProfessional.display_name`. A determined affiliate with two brands could cross-reference which brand's payouts consistently fail and infer the funding state. This is a low-severity side channel — the mitigation is simple (suppress brand identity on failed payouts) and aligns with the existing privacy intent.
    - **Plain English:** The system is designed so affiliates can't see WHY a payout failed — they just see "it failed, contact support." But the response still includes WHICH BRAND the failed payout was for. A savvy affiliate working with multiple brands could figure out which brand is having payment problems by noticing which payouts keep failing. It's like telling someone "I can't tell you who's broke, but here's a list of everyone you work with — one of them is." The fix is to hide the brand name when the payout fails.
    - **Evidence:**
        ```php
        $failureCategory = $this->failure_category === 'brand_funding'
            ? null
            : $this->failure_category;
        // ...
        'brand' => $this->whenLoaded('brandProfessional', fn () => [
            'id' => $this->brandProfessional->id,
            'name' => $this->brandProfessional->display_name,
        ]),
        ```
    - `[DRAFT, confidence: 0.6]`

- [ ] **#SEC-7** · P3 — `BootstrapRequest` handle-alias collision check logs raw `QueryException` message, potentially leaking schema detail
    - **Where:** app/Http/Requests/Api/BootstrapRequest.php:163-166
    - **Affects:** Production log aggregator (Nightwatch) — schema details like table/column names appear in warning logs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'error' => $e->getMessage()` with a fixed string like `'handle_alias_check_failed'` and log the exception via `report($e)` separately (Nightwatch captures stack traces from reported exceptions, not from Log::warning messages).
    - **Technical:** The `catch (QueryException $e)` block calls `Log::warning('Handle alias check failed in BootstrapRequest', ['error' => $e->getMessage()])`. `QueryException::getMessage()` includes the SQL statement and parameter bindings — while this particular query only uses the user-supplied handle (already in the log context via the request), it establishes a pattern where raw DB errors reach the log aggregator. A future schema change or edge case could expose table structures, column names, or constraint details that aid an attacker. The fix is to log a stable key and rely on `report($e)` for forensic detail.
    - **Plain English:** When the system hits a database hiccup, it writes the full raw database error into the log — including table names, column names, and the exact query that failed. This is like leaving server-room blueprints in the lobby. Most of the time nobody reads them, but if someone does, they get a map of your database layout. The fix is to log a generic "something went wrong here" tag and let the error-tracking tool capture the details separately where they're access-controlled.
    - **Evidence:**
        ```php
        } catch (QueryException $e) {
            report($e);
            Log::warning('Handle alias check failed in BootstrapRequest', ['error' => $e->getMessage()]);
        }
        ```
    - `[DRAFT, confidence: 0.7]`
