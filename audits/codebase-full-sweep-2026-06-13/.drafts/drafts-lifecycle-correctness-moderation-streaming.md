- [ ] **#LIFE-1** · P1 — Idempotency stamp placed before preconditions causes permanent loss of enquiry notification on transient failures
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php:32-48 and 52-73
    - **Affects:** Content creators receiving contact‑form enquiries via email. A transient error (e.g., mail‑server hiccup, DB read failure for the contact block) silently loses the notification forever because the `email_sent_at` flag is already committed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `email_sent_at` stamp to **after** `Mail::to(…)->send(…)` succeeds, so a retry can still deliver if the send throws.
        - Keep the `lockForUpdate` + `email_sent_at = null` check inside the transaction to stop concurrent workers from double‑stamping, but do not persist the stamp until the mail is actually accepted.
    - **Technical:** The job acquires a row lock, reads `email_sent_at`, and immediately sets it to `now()` – then commits the transaction. Later it resolves the contact block and tries to send. If the block lookup fails (connection drop, deleted row) or `Mail::send` throws, the method returns without sending, but the stamp already blocks any retry. The house doctrine for idempotency requires that the success marker be written **after** the side‑effect (or at least after all preconditions are confirmed) so that transient failures trigger a retry instead of a silent skip. This is especially dangerous for a notification that is the primary way a professional learns of a new lead.
    - **Plain English:** Imagine you go to a post office and the clerk stamps your envelope as “sent” before checking whether the address is valid. If they then discover the address is missing, the stamp stays – and your letter gets thrown away without you ever knowing. This job does exactly that: it marks the enquiry email as “sent” before actually sending it. A quick mail‑server stumble means the professional never sees the enquiry, and no one retries.
    - **Evidence:**
        ```php
        // Transactions stamps email_sent_at BEFORE any further checks
        $enquiry = DB::transaction(function () {
            $e = Enquiry::query()->lockForUpdate()->find($this->enquiryId);
            // …
            if ($e->email_sent_at !== null) { return false; }
            $e->forceFill(['email_sent_at' => now()])->saveQuietly();
            return $e;
        });
        // Later, resolve block and send — if any of these fail, stamp is already set
        $block = Block::query()
            ->whereKey($this->blockId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();
        if ($block === null) { /* no block -> no email, but stamp already committed */ return; }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#LIFE-2** · P1 — Idempotency stamp placed before preconditions causes permanent loss of visitor confirmation email on transient failures
    - **Where:** app/Jobs/Notifications/SendEnquiryConfirmationJob.php:33-52 and 56-74
    - **Affects:** Visitors who submit a contact form – they may never receive the “we got your enquiry” confirmation if a transient error occurs after the stamp.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `confirmation_sent_at` stamp to **after** the `Mail::to(…)->send(…)` succeeds, mirroring the fix for `SendEnquiryNotificationJob`.
        - Keep the lock‑and‑null check inside the transaction but defer the actual write until the mail has been handed off successfully.
    - **Technical:** Identical pattern: the job locks the enquiry row, stamps `confirmation_sent_at`, commits, and **then** verifies the recipient email, block settings, and rate limit. If the block or the mailer fails, the stamp remains. Retries will see the stamp and skip, permanently dropping the confirmation. For a user‑facing transactional email, dropping a confirmation is a poor experience; the cost of a double‑send is negligible compared to a missing one.
    - **Plain English:** Same post‑office example, but now it’s the visitor – they submit the form, get a “thank you” promise, and the system stamps “sent” before checking if there’s an actual letter to deliver. A brief glitch means they never see the confirmation, and no one tries again.
    - **Evidence:**
        ```php
        $enquiry = DB::transaction(function () {
            $e = Enquiry::query()->lockForUpdate()->find($this->enquiryId);
            // …
            if ($e->confirmation_sent_at !== null) { return false; }
            $e->forceFill(['confirmation_sent_at' => now()])->saveQuietly();
            return $e;
        });
        // … later, resolve block, check send_visitor_confirmation, rate limit, etc.
        // If any of those checks return early, stamp is already committed.
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#LIFE-3** · P1 — Idempotency stamp placed before preconditions causes permanent loss of subscription confirmation email on transient failures
    - **Where:** app/Jobs/Notifications/SendSubscriptionConfirmationJob.php:43-55 and 87-95
    - **Affects:** Newsletter subscribers – they may never receive the “you’re subscribed” confirmation if a transient error occurs after the stamp.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `confirmation_sent_at` stamp to **after** the `Mail::to(…)->send(…)` succeeds.
        - Keep the lock‑and‑null check inside the transaction, but defer the actual write until the mail has been dispatched.
    - **Technical:** Same structural flaw as the enquiry‑related jobs. The subscription row is stamped before the block toggle is read and the mail is sent. A transient failure in the subsequent steps (e.g., block not found, mailer timeout) leaves the stamp set, and the retry will silently skip, causing a subscriber to never receive the confirmation. For email‑based newsletter signups, this can look like a broken sign‑up process and drive complaints.
    - **Plain English:** The post‑office stamps your subscription confirmation letter as “sent” before verifying that your subscription is still active. A small error means you never get the confirmation, and the system assumes it already delivered it – so you never get a second try.
    - **Evidence:**
        ```php
        $sub = DB::transaction(function () {
            $s = EmailSubscription::query()->lockForUpdate()->find($this->subscriptionId);
            // …
            if ($s->confirmation_sent_at !== null) { return false; }
            $s->forceFill(['confirmation_sent_at' => now()])->saveQuietly();
            return $s;
        });
        // … later, check block toggle, rate limit, and send.
        // Early returns after the stamp always lose the confirmation.
        ```
    - `[DRAFT, confidence: 0.9]`
