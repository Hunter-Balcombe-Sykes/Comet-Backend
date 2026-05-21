- [ ] **SEC-1** · P1 — Professional model exposes PII fields in serialisation fallback
    - **Where:** app/Models/Core/Professional/Professional.php (class-level $hidden array)
    - **Affects:** Any code path that calls `$professional->toArray()` or `$professional->toJson()` — queue job payloads, broadcast events, logging contexts, or future API endpoints — would leak `primary_email`, `phone`, `first_name`, `last_name`, `public_contact_email`, and `public_contact_number`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `primary_email`, `phone`, `first_name`, `last_name`, `public_contact_email`, and `public_contact_number` to Professional's `$hidden` array.
        - Audit ProfessionalResource and ProfessionalStaffResource to confirm they access these fields via direct attribute access (unaffected by `$hidden`).
    - **Technical:** Laravel's `$hidden` array is the defence-in-depth control that prevents accidental PII exposure through `toArray()`. The Professional model correctly hides Stripe IDs and the deletion token hash but omits the six contact-PII fields that are fillable and stored in the database. Any serialisation that doesn't go through a Resource class would leak these fields into JSON payloads, including queue job serialisation (Laravel's default job payload includes the serialised model state) and log contexts that call `$model->toArray()`. This is category 10 (PII exposure in responses & logs).
    - **Plain English:** Think of the Professional model's `$hidden` list as a privacy screen. Right now the screen is blocking bank account numbers and security tokens from accidentally showing up, but it has holes where the person's email, phone number, and legal name are — those can slip through if any code path asks to "show everything." Adding those fields to the blocklist closes the gaps without affecting the dashboard screens that are supposed to show them (those use a different, explicit path).
    - **Evidence:**
        ```php
        protected $hidden = [
            'auth_user_id',
            'stripe_connect_account_id',
            'stripe_billing_customer_id',
            'stripe_payment_method_id',
            'stripe_commission_funding_mode',
            'deletion_token_hash',
        ];

        protected $fillable = [
            // ...
            'primary_email',
            'first_name',
            'last_name',
            'phone',
            'public_contact_email',
            'public_contact_number',
            // ...
        ];
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SEC-2** · P1 — Customer model exposes email, phone, and full_name in serialisation fallback
    - **Where:** app/Models/Core/Professional/Customer.php (class-level $hidden array)
    - **Affects:** Any serialisation of a Customer model outside a Resource class would expose customer PII — `email`, `phone`, and `full_name`. Queue job payloads, broadcast events, log contexts with `$customer->toArray()`, or any future endpoint that returns `new CustomerResource($customer)` (which calls `toArray()` internally unless the Resource explicitly whitelists fields).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `email`, `phone`, and `full_name` to Customer's `$hidden` array.
        - Audit CustomerResource to confirm it accesses these fields via explicit attribute access rather than relying on `toArray()` to surface them.
    - **Technical:** Customer's `$hidden` guards `external_id` but omits the three core PII fields that are in `$fillable`. Under GDPR, customer email, phone, and full name are personal data requiring protection. The `$hidden` array is the model-level defence against accidental exposure through Laravel's serialisation system. CustomerResource should be the sole gateway for surfacing these fields to authenticated API consumers. Category 10 (PII exposure in responses & logs).
    - **Plain English:** The Customer address book has a privacy lock on the "external reference number" field but leaves the person's name, email, and phone number unlocked. If any part of the system asks to "serialise and send" a customer record without going through the proper display formatter, that personal information would travel with it. Adding these three fields to the lock list prevents that, while the dashboard screens that need to show customer details can still reach in and grab them intentionally.
    - **Evidence:**
        ```php
        protected $hidden = [
            'external_id',
        ];

        protected $fillable = [
            'professional_id',
            'email',
            'phone',
            'full_name',
            'source',
            'notes',
            'external_id',
            'marketing_opt_in_cached',
            'redacted_at',
        ];
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SEC-3** · P2 — HandleChangeLog exposes IP address and user agent without serialisation protection
    - **Where:** app/Models/Core/HandleChangeLog.php (class-level — no $hidden array)
    - **Affects:** If any API endpoint or staff dashboard serialises a HandleChangeLog row, visitor IP addresses and user-agent strings would leak. IP addresses are personal data under GDPR.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$hidden = ['ip_address', 'user_agent'];` to HandleChangeLog.
        - If a staff-facing Resource needs these fields for fraud investigation, access them via explicit attribute access on a dedicated Resource class.
    - **Technical:** HandleChangeLog is an append-only audit table that records `ip_address` and `user_agent` for every handle rename/reclaim. It has no `$hidden` array, so `toArray()` would expose both fields. While this table may not currently be serialised by any API endpoint, adding `$hidden` is defence-in-depth — a future staff-audit endpoint that forgets to use a Resource would otherwise leak visitor IPs. Category 10 (PII exposure).
    - **Plain English:** Every time someone changes their profile handle, the system writes down their IP address and browser details for fraud investigation. Those notes aren't protected from accidental exposure — if any future screen or report dumps the whole record without filtering, the IP address would be visible to whoever sees it. Adding a privacy label on those two fields now prevents that future accident.
    - **Evidence:**
        ```php
        protected $fillable = [
            'professional_id',
            'old_handle',
            'new_handle',
            'reason',
            'actor_id',
            'ip_address',
            'user_agent',
            'changed_at',
        ];
        // No $hidden array declared — ip_address and user_agent are unprotected.
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **SEC-4** · P1 — CommissionClawback model has no authorization policy registered
    - **Where:** app/Providers/AppServiceProvider.php (no Gate::policy for CommissionClawback); app/Models/Commerce/CommissionClawback.php (tenant-owned via brand_professional_id)
    - **Affects:** Any controller or endpoint that reads or writes CommissionClawback records — these represent real money reversals and must never be visible across tenant boundaries.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `CommissionClawbackPolicy` (or extend `CommissionPolicy` if the ownership model matches) that gates view/update on `brand_professional_id` and `affiliate_professional_id`.
        - Register it via `Gate::policy(CommissionClawback::class, CommissionClawbackPolicy::class)` in `AppServiceProvider::boot()`.
        - Sweep any controller that touches CommissionClawback and ensure it calls `$this->authorizeForUser($pro, 'view', $clawback)`.
    - **Technical:** The authorization doctrine requires every tenant-owned model to have a registered Policy. CommissionClawback carries `brand_professional_id` and is linked to a CommissionPayout (which has a Policy). Without a registered Policy, `Gate::allows('view', $clawback)` would return true for any authenticated professional, breaking tenant isolation. Category 2 (authorization/policy completeness).
    - **Plain English:** The CommissionClawback table records when money gets pulled back from an affiliate after a refund. It's connected to a brand's account but doesn't have its own door lock. The CommissionPayout table has a lock, but the clawback records sitting next to it don't — yet they contain the same sensitive financial data. Adding the missing lock ensures a brand can only see their own clawback records, never another brand's.
    - **Evidence:**
        ```php
        // AppServiceProvider::boot() — no line for CommissionClawback:
        Gate::policy(\App\Models\Commerce\CommissionPayout::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\CommissionMovement::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\Order::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\OrderItem::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\BrandAffiliateRollup::class, \App\Policies\CommissionPolicy::class);
        // CommissionClawback is absent.
        ```
        ```php
        // CommissionClawback.php — tenant-owned, no policy:
        class CommissionClawback extends BaseModel
        {
            use HasFactory;
            use HasUuids;

            protected $table = 'commerce.commission_clawbacks';
            // ...
            protected $guarded = ['*'];
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SEC-5** · P2 — Four analytics models (CartEvent, LinkClick, SectionView, SiteVisit) lack registered authorization policies
    - **Where:** app/Providers/AppServiceProvider.php (no Gate::policy for CartEvent, LinkClick, SectionView, or SiteVisit); app/Models/Analytics/*.php (all four carry professional_id)
    - **Affects:** Any controller that exposes these analytics models via route model binding would return data across tenant boundaries. Current risk is mitigated if analytics are read only through aggregate queries (`DB::table(...)->where('professional_id', ...)->sum()`), but a future per-row endpoint would be unprotected.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a single `AnalyticsPolicy` (extending `BasePolicy`) that gates view on `professional_id` ownership for all four models.
        - Register `Gate::policy(CartEvent::class, AnalyticsPolicy::class)` (and the other three) in `AppServiceProvider::boot()`.
        - Sweep analytics controllers and verify reads use `authorizeForUser` if they perform per-model lookups, or are aggregate-only (no policy needed if never model-bound).
    - **Technical:** Each analytics model has `professional_id` directly on the row and is therefore tenant-owned. Without a registered Policy, `Gate::allows('view', $cartEvent)` always returns true. If these models are only read through aggregate queries (e.g. `LinkClick::where('professional_id', $pro->id)->count()`), the missing policy is dormant; if any controller uses route model binding or `Model::find($id)`, tenant isolation is absent. The authorization doctrine says register the policy — defence-in-depth even for aggregate-queried models. Category 2 (authorization/policy completeness).
    - **Plain English:** The analytics tables store page views, link clicks, and shopping cart activity — each row tagged with which professional it belongs to. Unlike the main business tables, these four analytics tables don't have a lock registered. Right now they're probably safe because the analytics dashboard reads them through summarised queries that already filter by owner, but if anyone later adds a "drill down to individual click" feature without adding the lock, one professional could see another's visitor data. Adding the locks now closes that future gap.
    - **Evidence:**
        ```php
        // AppServiceProvider::boot() — no analytics model registrations:
        // (LeadSubmission is registered via SitePolicy; the four below are absent.)
        Gate::policy(\App\Models\Analytics\LeadSubmission::class, \App\Policies\SitePolicy::class);
        // CartEvent, LinkClick, SectionView, SiteVisit — all missing.
        ```
        ```php
        // SiteVisit.php — representative; all four carry professional_id:
        class SiteVisit extends BaseModel
        {
            // ...
            protected $fillable = [
                'occurred_at', 'session_id', 'visitor_id', 'ip_hash',
                'user_agent', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign',
                'country_code', 'device_type',
            ];
            // professional_id is set on the row but not in $fillable (DB-level assignment).
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SEC-6** · P2 — CloudflareDnsService accepts unvalidated $name and $target parameters for DNS record provisioning
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php:55-91 (ensureCname), :99-148 (upsertCname), :154-192 (upsertTxt)
    - **Affects:** Any call site that provisions DNS records for a brand's Hydrogen storefront. If callers pass unsanitised values for `$name` or `$target`, an attacker could inject a CNAME pointing to a malicious origin or pollute the zone with unintended records.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Validate `$name` against a strict subdomain pattern (`/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/`) at the service boundary.
        - Validate `$target` against an allow-list of known Hydrogen/Shopify Oxygen hostname suffixes (e.g. `*.myshopify.com`, `*.oxygen.dev`).
        - Apply the same validation in the caller (the Shopify install pipeline) as defence-in-depth.
    - **Technical:** The service's `ensureCname()`, `upsertCname()`, and `upsertTxt()` methods pass `$name` and `$target` directly to Cloudflare's API without validation. The zone is scoped to `partna.au` (appended by Cloudflare for zone-relative names), so an arbitrary `$name` becomes `{name}.partna.au` rather than a fully-arbitrary domain — this limits the blast radius but doesn't eliminate it. A malicious `$target` pointing to an attacker-controlled server could redirect storefront traffic. The attack requires the caller to pass unsanitised input, so the primary fix is at the call site; adding validation inside the service is defence-in-depth. Category 7 (SSRF / URL parsing / open redirect).
    - **Plain English:** The DNS provisioning service creates the internet address records that point `mybrand.partna.au` to the brand's actual storefront. Right now, it creates whatever record it's told to without checking whether the destination looks legitimate. If a bug in the setup flow let a brand type in a malicious destination server, their storefront traffic could be redirected. Adding a check that the destination matches the expected pattern (Shopify or Partna's hosting) prevents that.
    - **Evidence:**
        ```php
        public function ensureCname(string $name, string $target, bool $proxied = true): ?string
        {
            if (! $this->hasCredentials()) {
                return null;
            }

            $existing = $this->findRecord('CNAME', $name);
            if ($existing !== null) {
                return $existing['id'];
            }

            $response = Http::withToken($this->apiToken)
                ->post($this->zonesUrl('/dns_records'), [
                    'type' => 'CNAME',
                    'name' => $name,        // no validation
                    'content' => $target,   // no validation
                    'proxied' => $proxied,
                    'ttl' => 1,
                ]);
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **SEC-7** · P2 — OrderEvent model has no authorization policy; append-only but carries order-level financial data
    - **Where:** app/Providers/AppServiceProvider.php (no Gate::policy for OrderEvent); app/Models/Commerce/OrderEvent.php (tenant-owned via order_id FK)
    - **Affects:** Any controller reading OrderEvent rows outside the Order relationship would lack tenant isolation. OrderEvents contain amount deltas and metadata about financial state transitions.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Register `Gate::policy(OrderEvent::class, CommissionPolicy::class)` if CommissionPolicy's `brand_professional_id`-based ownership works (OrderEvents are scoped to an Order, which is scoped to a brand).
        - Alternatively, create a lightweight `OrderEventPolicy` that gates view on the parent Order's ownership.
    - **Technical:** OrderEvent is protected at the write level by `$guarded = ['*']` (all writes are server-side) and at the read level only through its parent Order relationship. If any controller ever binds an OrderEvent directly (e.g. `OrderEvent::find($id)` in a route), there is no Policy gate. The model contains `amount_delta_cents` and `metadata` that could leak financial state across tenants. Category 2 (authorization/policy completeness) — lower priority than SEC-4/5 because the write surface is already guarded and reads likely go through the Order relation.
    - **Plain English:** The order audit trail records every state change on a purchase — when it was paid, refunded, and how the money moved. It's locked against writes (only the system can add entries) but doesn't have a read lock of its own. It's usually only accessed by first loading the parent Order (which does have a lock), so this is mostly about future-proofing: registering the lock now prevents someone from later adding a direct lookup that accidentally shows one brand's order history to another.
    - **Evidence:**
        ```php
        // AppServiceProvider::boot() — OrderEvent is absent:
        Gate::policy(\App\Models\Commerce\Order::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\OrderItem::class, \App\Policies\CommissionPolicy::class);
        // No OrderEvent registration.
        ```
        ```php
        // OrderEvent.php — append-only but carries financial data:
        class OrderEvent extends BaseModel
        {
            use HasUuids;
            protected $table = 'commerce.order_events';
            protected $guarded = ['*'];  // writes protected
            protected $casts = [
                'amount_delta_cents' => 'integer',
                'metadata' => 'array',
                // ...
            ];
            public function order(): BelongsTo
            {
                return $this->belongsTo(Order::class, 'order_id');
            }
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **SEC-8** · P2 — HandleChangeLog model has no authorization policy registered
    - **Where:** app/Providers/AppServiceProvider.php (no Gate::policy for HandleChangeLog); app/Models/Core/HandleChangeLog.php (carries professional_id)
    - **Affects:** If any staff or self-service endpoint exposes handle-change history, the rows would not be gated by tenant ownership. Handle changes can be sensitive in impersonation/fraud investigations.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Register `Gate::policy(HandleChangeLog::class, ProfessionalSelfPolicy::class)` since HandleChangeLog carries `professional_id` directly (simple direct ownership, same shape as other ProfessionalSelfPolicy models).
    - **Technical:** HandleChangeLog has `professional_id` directly on the row and calls to `$this->authorizeForUser($pro, 'view', $log)` would pass through a null Gate unless a Policy is registered. The model is append-only at the DB level (UPDATE/DELETE blocked by trigger), so only the `view` ability matters. Category 2 (authorization/policy completeness).
    - **Plain English:** Every time a user changes their profile handle, a log entry is created — this is important for fraud investigations and trademark disputes. That log doesn't have a lock registered, so if a dashboard screen is later built to show "your handle change history," it wouldn't verify that the person viewing it actually owns those entries. Adding the lock takes a few minutes and prevents future cross-account snooping.
    - **Evidence:**
        ```php
        // HandleChangeLog.php — tenant-owned:
        class HandleChangeLog extends BaseModel
        {
            use HasUuids;
            protected $table = 'core.handle_change_log';
            protected $fillable = [
                'professional_id',
                'old_handle',
                'new_handle',
                'reason',
                'actor_id',
                'ip_address',
                'user_agent',
                'changed_at',
            ];
            // ...
        }
        // AppServiceProvider::boot() — HandleChangeLog is absent from Gate::policy registrations.
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SEC-9** · P3 — SquareApiClient and FreshaApiClient pass client_secret as HTTP Authorization header during token revocation
    - **Where:** app/Services/Square/SquareTokenService.php:171-183; app/Services/Fresha/FreshaTokenService.php:169-181
    - **Affects:** If HTTPS is not enforced at the network layer for outbound API calls, the Square/Fresha client secret would be transmitted in cleartext. Low risk (both services are dropped integrations; HTTPS is standard for API calls).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm that the Square and Fresha revocation endpoints use `https://` URLs (they do — `connect.squareup.com` and `partner-api.fresha.com` with the `https://` scheme from `baseUrl()`).
        - If the revocation pattern is reused for a future integration, ensure the secret is sent in the request body (OAuth2 standard) rather than as a header, unless the provider's documentation explicitly requires the `Client {secret}` header pattern.
    - **Technical:** Both `SquareTokenService::revokeToken()` and `FreshaTokenService::revokeToken()` use `Http::withHeaders(['Authorization' => 'Client '.$clientSecret])` to authenticate the revocation request. The Square API docs do specify this pattern for token revocation (it's not a bearer token — it's client-credential auth where the secret acts as the password). The risk is theoretical: if these URLs were ever switched to `http://` (unlikely) or if a proxy mishandles TLS. Category 5 (secrets handling). This is P3 because both integrations are dropped per the audit scope, and HTTPS is standard.
    - **Plain English:** When disconnecting a Square or Fresha account, the system sends the API secret key to tell those platforms "revoke this connection." Both services do this over encrypted connections, so the key isn't exposed in transit. The code pattern is correct per each provider's documentation. This is noted as a reference point — if a future integration copies this pattern, the engineer should verify whether the new provider wants the secret in the header or in the body.
    - **Evidence:**
        ```php
        // SquareTokenService.php:
        public function revokeToken(ProfessionalIntegration $integration): void
        {
            $clientId = trim((string) config('services.square.application_id', ''));
            $clientSecret = trim((string) config('services.square.client_secret', ''));
            $accessToken = trim((string) ($integration->access_token ?? ''));

            if ($clientId === '' || $clientSecret === '' || $accessToken === '') {
                return;
            }

            Http::acceptJson()
                ->asJson()
                ->timeout(10)
                ->withHeaders(['Authorization' => 'Client '.$clientSecret])
                ->post($this->baseUrl().'/oauth2/revoke', [
                    'client_id' => $clientId,
                    'access_token' => $accessToken,
                ]);
        }
        ```
    - `[DRAFT, confidence: 0.70]`
