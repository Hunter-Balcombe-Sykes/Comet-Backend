- [ ] **#SEC-1** · P3 — Inline ownership check instead of policy in Staff link-block management
    - **Where:** `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php:81-85` (update) and `:103-107` (destroy)
    - **Affects:** Staff admins managing link blocks; no practical security risk because the route binding already scopes blocks to the professional, but the inline check deviates from the authorization doctrine.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `abort_unless($linkBlock->professional_id === $professional->id, 404)` with `$this->authorizeForUser($professional, 'update', $linkBlock)` (or `delete`).
        - Register a `LinkBlockPolicy` (or generic `BlockPolicy`) in `AppServiceProvider`.
    - **Technical:** The current code performs a manual ownership check instead of delegating to a policy. While the staff context makes this safe, it duplicates logic and forgoes centralized enforcement (pending-deletion gates, 404‑vs‑403 decisions). The doctrine requires `authorizeForUser` with a policy for every tenant‑owned resource action.
    - **Plain English:** A guard is checking IDs manually at the door instead of using the building’s keycard system. It still stops the wrong person, but if the building’s rules change (like blocking someone whose account is being deleted), the manual guard won’t know.
    - **Evidence:**
        ```php
        abort_unless(
            $linkBlock->professional_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-2** · P2 — Email‑subscriber listing queries directly without a policy check
    - **Where:** `app/Http/Controllers/Api/Professional/Notifications/ProfessionalEmailSubscriptionController.php:51-69` (index method)
    - **Affects:** All professionals accessing their marketing‑subscriber lists; the endpoint bypasses the authorization policy layer.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `EmailSubscriptionPolicy` with a `viewAny` ability.
        - Register `Gate::policy(EmailSubscription::class, EmailSubscriptionPolicy::class)` in `AppServiceProvider::boot()`.
        - Call `$this->authorizeForUser($pro, 'viewAny', EmailSubscription::class)` at the start of `index`.
    - **Technical:** The controller queries `EmailSubscription::where('professional_id', $pro->id)` directly, never invoking a Gate. Under the Partna authorization doctrine every tenant‑owned model must go through a policy. Without policy coverage, a future change that relaxes the query could accidentally bypass ownership enforcement — the policy layer is the last line of defence.
    - **Plain English:** The controller grabs the subscriber list directly from the database, like a courier walking into a mailroom and collecting letters without checking in at reception. Right now they only pick up letters addressed to that person, but if the sorting were wrong, there’s no oversight.
    - **Evidence:**
        ```php
        $query = EmailSubscription::query()
            ->where('professional_id', $pro->id)
            ->where('list_key', $listKey)
            ->orderByDesc('subscribed_at')
            ->orderByDesc('created_at');
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-3** · P3 — Customer `store` action updates an existing customer without re‑authorising for update
    - **Where:** `app/Http/Controllers/Api/Professional/Customers/ProfessionalCustomerController.php:80-91`
    - **Affects:** Professionals who submit a customer creation request with an email that already exists; the subsequent update skips the `update` policy check.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - After determining that `$customer` already exists, call `$this->authorizeForUser($pro, 'update', $customer);` before `$customer->update(...)`.
    - **Technical:** The method authorises only `create` on a skeleton `Customer`. When an existing customer is found, it proceeds directly to an update without calling `authorizeForUser` for the `update` ability. Although the customer belongs to the same professional and no real privilege escalation exists, it violates the principle that every write action should be explicitly authorised. If the policy later adds conditions (e.g., blocked‑account checks), they would be bypassed here.
    - **Plain English:** Imagine you show your ID to buy a train ticket (“create”), then spot a ticket with your name on it at the counter and take it without showing ID again. It’s still yours, but the process should check each time — otherwise a future rule like “only adults can modify tickets” would be skipped.
    - **Evidence:**
        ```php
        $customer = $pro->customers()
            ->where('email', $data['email'])
            ->first();

        if ($customer) {
            // Update existing customer with new data
            $customer->update([
                ...
            ]);
        ```
    - `[DRAFT, confidence: 0.7]`
