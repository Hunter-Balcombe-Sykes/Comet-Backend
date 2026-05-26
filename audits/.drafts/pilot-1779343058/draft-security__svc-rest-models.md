- [ ] **SEC-1** · P1 — Commerce models CommissionClawback, CommissionPayoutItem, and OrderEvent missing registered authorization policies
    - **Where:** app/Providers/AppServiceProvider.php (missing Gate::policy registrations)
    - **Affects:** Any API endpoints that resolve these models via route-model binding; potential IDOR if a brand or affiliate can read/write clawback details, payout line items, or order audit events belonging to another tenant.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add policies (extending CommissionPolicy or a new policy) for CommissionClawback, CommissionPayoutItem, and OrderEvent.
        - Register each via Gate::policy() in AppServiceProvider::boot().
        - Audit controllers that resolve these models to use authorizeForUser with the resolved professional.
    - **Technical:** The Partna Authorization Doctrine requires every tenant-owned model to have a registered policy. CommissionClawback links to a payout (brand_professional_id/affiliate_professional_id) and contains sensitive financial data. Without a policy, the Gate defaults to denying access, but any code that avoids Gate (e.g., model binding without authorization) could expose data across tenants. All three models carry tenant identifiers and are plausible REST resources.
    - **Plain English:** Imagine the company safe has three drawers that hold sensitive transaction details, but only the main vault has a lock. An employee could open the other drawers simply by knowing the drawer number, because nobody installed the required locks. We need to install locks on all drawers.
    - **Evidence:**
        ```php
        // AppServiceProvider::boot() registers many policies but omits these:
        // No line for CommissionClawback, CommissionPayoutItem, or OrderEvent.
        Gate::policy(\App\Models\Commerce\CommissionPayout::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\Order::class, \App\Policies\CommissionPolicy::class);
        // ...
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **SEC-2** · P2 — Customer model exposes PII (email, phone, full_name) in default serialization
    - **Where:** app/Models/Core/Professional/Customer.php (missing $hidden)
    - **Affects:** Any serialization path (API Resource fallback, queue job payloads, log dumps) that calls toArray() on a Customer instance; potential PII leak to logs, job dashboards, or API consumers.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add email, phone, full_name to the $hidden array to prevent accidental exposure.
        - Ensure existing CustomerResource properly selects only intended fields; add regression test.
    - **Technical:** Laravel's $hidden property controls which attributes are excluded from toArray() and JSON serialization. Customer currently has no $hidden, meaning email, phone, and full_name will appear whenever the model is serialized. Even if a CustomerResource is used, a fallback to toArray() (e.g., in an exception handler or queue payload) would expose these fields. GDPR compliance requires minimising PII exposure.
    - **Plain English:** The customer file folder has a "PRIVATE" stamp on the cover, but every page inside is printed with the customer's phone number and email in the margin. Anyone who picks up the folder sees the sensitive details. We need to remove that info from the public-facing pages.
    - **Evidence:**
        ```php
        // Customer model — note the absence of a $hidden array:
        protected $fillable = [
            'professional_id',
            'email',
            'phone',
            'full_name',
            // ...
        ];
        // No protected $hidden = ['email', 'phone', ...];
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SEC-3** · P2 — Analytics models (SiteVisit, LinkClick, SectionView, LeadSubmission, CartEvent) expose visitor identifiers in default serialization
    - **Where:** app/Models/Analytics/*.php (each model missing $hidden for ip_hash, user_agent, etc.)
    - **Affects:** Analytics API endpoints that return visit/click/lead data; exposed IP hashes and user-agent strings could be used to fingerprint visitors across sites, violating user expectations and GDPR best practices.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add ip_hash, user_agent, and any other telemetry fields to $hidden on each model.
        - Ensure Analytics Resource classes explicitly select only approved public fields.
    - **Technical:** These models store visitor telemetry (ip_hash, user_agent) without setting them hidden. If a controller serializes the model directly (e.g., return response()->json($visit)), these identifiers leak. Even if current Resource classes filter, any future serialization (queue jobs, debug logs) would expose them. Since ip_hash is a one-way hash of the visitor IP, combined with user-agent it can uniquely re-identify a user across sessions.
    - **Plain English:** The site visit log book records not just that someone visited, but also a scrambled version of their home address and a detailed description of their car. If that book were left open on a desk, anyone walking by could link visits together and build a profile. We should keep those details in a locked drawer.
    - **Evidence:**
        ```php
        // Example from SectionView:
        protected $fillable = [
            'section_key',
            'occurred_at',
            'session_id',
            'visitor_id',
            'ip_hash',       // ← should be hidden
            'user_agent',    // ← should be hidden
            // ...
        ];
        // No protected $hidden declared.
        ```
    - `[DRAFT, confidence: 0.9]`
