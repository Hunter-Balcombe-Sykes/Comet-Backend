- [ ] **SEC-1** · P1 — Public email/phone/handle enumeration via signup-availability endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicSignupAvailabilityController.php:28-52
    - **Affects:** Any unauthenticated visitor can probe registered emails, phone numbers, and handles at scale — privacy of current users and viability of targeted phishing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the boolean `exists` fields with a uniform “check submitted” response and an out-of-band confirmation (e.g., “If that email is registered we’ll send a link”).
        - If a real-time availability widget is required, add per-IP rate limiting and consider a client-side proof-of-work or CAPTCHA.
        - Log high-frequency probe attempts so security monitoring detects enumeration sweeps.
    - **Technical:** The endpoint returns `{email: {available: false, exists: true}, phone: …, handle_lc: …}` for every request with no authentication, no throttling, and no anti-automation challenge. An attacker can POST repeatedly with candidate addresses/phones/handles and harvest the complete user list. Under GDPR principles, exposing “account exists” without user consent is a PII leak.
    - **Plain English:** This is like a membership help-desk that, when asked “Does [email] have an account here?”, answers “Yes” or “No” instantly to any stranger who asks. A scanner can try thousands of emails per minute and build a list of all registered users.
    - **Evidence:**
        ```php
        $emailExists = Professional::query()
            ->where(function ($query) use ($email) {
                $query->whereRaw('LOWER(primary_email) = ?', [$email])
                    ->orWhereRaw('LOWER(public_contact_email) = ?', [$email]);
            })
            ->exists();
        // ...
        return $this->success([
            'email' => [
                'available' => ! $emailExists,
                'exists' => $emailExists,
            ],
            // ...
        ]);
        ```
    - `[DRAFT, confidence: 0.9]`
