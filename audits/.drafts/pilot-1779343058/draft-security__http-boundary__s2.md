- [ ] **SEC-1** · P2 — Access-Control-Allow-Origin: * on every response widens cross-origin attack surface
    - **Where:** app/Http/Middleware/SecureHeaders.php:19-21
    - **Affects:** All API responses, including those that may contain sensitive data (customer details, payouts, professional profiles). Any website can make cross-origin requests and read the responses, which could facilitate token-exfiltration or information leakage if a token is known or mishandled.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the wildcard `*` with an explicit allowlist of trusted origins (e.g., the Shopify admin domain, the Partna dashboard, the Hydrogen storefront).
        - Apply the header only on endpoints that genuinely require cross-origin access, not globally.
    - **Technical:** The middleware unconditionally sets `Access-Control-Allow-Origin: *` to “guarantee the header survives” (comment). Although the API uses Bearer tokens (not cookies), a wildcard CORS policy removes same-origin restrictions, allowing any origin to script requests. An attacker hosting a page visited by an authenticated user could potentially read API responses if the token is forceable into the request (e.g., if the token were exposed in a URL or through another XSS). This violates least-privilege and unnecessarily broadens the attack surface for a production API serving business data.
    - **Plain English:** It’s like putting a sign on every envelope that says “return to sender – anyone can read me”. Even though the envelopes are sealed, you’re inviting any stranger who picks one up to open it. You should only put return addresses on envelopes that actually need to be returned, and only for trusted senders.
    - **Evidence:**
        ```php
        if (! $response->headers->has('Access-Control-Allow-Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }
        ```
    - `[DRAFT, confidence: 0.9]`
