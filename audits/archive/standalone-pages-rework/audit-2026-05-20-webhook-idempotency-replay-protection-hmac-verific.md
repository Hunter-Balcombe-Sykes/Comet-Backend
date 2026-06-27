`★ Insight ─────────────────────────────────────`
**WEBHOOK-1 is already mitigated:** `config/partna.php` line 1238 declares `'webhook_idempotency' => (int) env('PARTNA_CACHE_TTL_WEBHOOK_IDEMPOTENCY', env('CACHE_TTL_WEBHOOK_IDEMPOTENCY', 86400))` — a hardcoded 86400-second fallback. `(int) config(...)` never returns 0. Drop the finding.

**WEBHOOK-3's evidence is fabricated:** DeepSeek invented the inline comment `// No dedup gate here`. The actual controller reads `$id` from `webhook-id` but only passes it into `verifySignature`; it is never stored or checked for replay. The underlying issue is real — the evidence must be rewritten verbatim.
`─────────────────────────────────────────────────`

# Webhook Idempotency / Replay Protection / HMAC Verification Audit — 2026-05-20

**Branch:** development
**Lens:** webhook idempotency replay protection HMAC verification
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php
- app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php
- app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyAppUninstalledWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyGdprWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyThemePublishedWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyOrderWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyOrdersUpdatedWebhookController.php
- app/Http/Controllers/Api/Webhooks/Shopify/ShopifyRefundsCreateWebhookController.php
- app/Http/Controllers/Api/Webhooks/Stripe/StripeWebhookController.php
- app/Http/Controllers/Api/Webhooks/Stripe/StripeConnectWebhookController.php
- app/Http/Controllers/Api/Webhooks/Stripe/StripePlatformWebhookController.php
- config/partna.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **WEBHOOK-3** · P2 — Supabase MFA hook records duplicate verify-failed events on webhook retry
    - **Where:** app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:42–95
    - **Affects:** Professionals using TOTP/phone MFA. A Supabase retry (triggered when the first request times out) produces a second `verify_failed` event for the same attempt. The brute-force counter `countRecentFailures()` sees N+1 failures, potentially triggering cooldown after a single wrong code.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After signature validation, store `$id` (the `webhook-id` header) in Redis with `Cache::add($id, true, $windowSeconds)`. If it returns false, return `response()->json(['decision' => 'continue'])` immediately — valid same-decision dedup response, no additional state recorded.
        - Use the existing `verify_failure_window_seconds` as the TTL (matches the brute-force window, so the dedup key expires at the same time the failure stops counting).
    - **Technical:** The controller extracts `$id` from the `webhook-id` header and passes it to `hookService->verifySignature($id, $timestamp, $signature, $rawBody)` — verifying it as part of the Standard Webhooks HMAC, not storing it for dedup. Supabase retries webhook deliveries on 5xx or timeout. If a timeout occurs after the controller has already called `$this->repo->record(... 'verify_failed' ...)` on the first delivery, the retry is treated as a fresh attempt: the same failure is recorded twice and `countRecentFailures` returns N+1. The other webhook handlers in this codebase all use `Cache::add` for delivery dedup; this controller is the only exception.
    - **Plain English:** The MFA "wrong code" webhook is like a piece of mail — if the postie thinks it wasn't delivered, they send a second copy. Right now both copies are counted as separate failed attempts. A user who types one wrong code could get locked out as though they'd typed five wrong codes in a row. All the other similar webhook handlers have a "we've seen this letter before" check; this one doesn't.
    - **Evidence:**
        ```php
        $id = (string) $request->header('webhook-id', '');
        $timestamp = (string) $request->header('webhook-timestamp', '');
        $signature = (string) $request->header('webhook-signature', '');
        $rawBody = $request->getContent();

        if (! $this->hookService->verifySignature($id, $timestamp, $signature, $rawBody)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        // $id is verified above but never stored — duplicate deliveries proceed
        // identically to first deliveries and record a new event each time.
        // ...
        $this->repo->record(
            userId: $userId,
            eventType: 'verify_failed',
            factorId: $factorId,
            factorType: $factorType,
            ip: $ip,
            userAgent: $userAgent,
        );
        ```

---

## P3 — Nice to have

- [ ] **WEBHOOK-4** · P3 — Shopify GDPR webhook accepts any validly-signed payload regardless of age
    - **Where:** app/Http/Controllers/Api/Webhooks/Shopify/ShopifyGdprWebhookController.php:56–106
    - **Affects:** GDPR compliance processing. A validly-signed but old Shopify GDPR webhook body (captured and replayed by a network intermediary or a debugging tool) would be accepted and processed. The DB-level `payload_hash` unique constraint prevents double-execution, but the staleness of the request itself is never checked.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After HMAC validation, read the `X-Shopify-Triggered-At` header and reject (422) if the timestamp is older than a configurable threshold (e.g., 5 minutes). Return 422 so Shopify marks the delivery failed and can retry a genuine old-request scenario rather than silently swallowing it as a 200.
        - This matches the timestamp-gate already present in `SupabaseAuthHookController` via `webhook-timestamp`.
    - **Technical:** `ShopifyGdprWebhookController::handleGdprWebhook` validates HMAC and then proceeds to `firstOrCreate` on `payload_hash`. The `firstOrCreate` + UNIQUE index provides durable idempotency against Shopify retries, so no double-execution occurs. The gap is replay: a signed request from hours or days ago would be accepted as if fresh, before the hash guard runs. The Supabase hook guards against this via `verifySignature($id, $timestamp, ...)`, where the timestamp check is built into the Standard Webhooks signature spec. Shopify's HMAC does not include a timestamp, so an explicit header check is the right mitigation.
    - **Plain English:** The GDPR handler checks that the envelope has Shopify's official seal, but doesn't check the postmark date. An old sealed envelope would be accepted. In practice nothing breaks — the downstream check already handles "we've seen this before" — but checking the date would close the gap that the login-security handler already closes, and adds one more layer of protection on a compliance-critical path.
    - **Evidence:**
        ```php
        // Shopify GDPR handler — HMAC checked, no timestamp staleness check:
        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning("Shopify GDPR webhook ({$topic}): invalid HMAC signature", [
                'shop_domain' => $shopDomain,
            ]);
            return $this->error('invalid signature', 401);
        }

        // Contrast: Supabase hook validates timestamp as part of the signature spec:
        // $timestamp = (string) $request->header('webhook-timestamp', '');
        // if (! $this->hookService->verifySignature($id, $timestamp, $signature, $rawBody)) { ... }
        ```

- [ ] **WEBHOOK-5** · P3 — Fresha HMAC implementation is an acknowledged placeholder copied from Square
    - **Where:** app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php:122–157
    - **Affects:** Any professional with a Fresha integration. All legitimate Fresha webhook deliveries will be rejected if Fresha's actual signature scheme differs from Square's (HMAC-SHA256 of `notification_url + raw_body`). The controller is fail-closed — no unauthenticated webhooks pass — but catalog sync via Fresha webhooks is silently broken.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Consult Fresha's webhook documentation and confirm whether their signature uses the same HMAC-SHA256 of `notification_url + raw_body` as Square, or a different scheme (header-based shared secret, SHA-512, different concatenation).
        - If the algorithm matches Square's, remove the NOTE comments and ship it as confirmed. If it differs, update `isValidSignature` to match.
        - Given booking/Fresha is no longer being extended (project decision 2026-05-11), the lowest-risk option is to remove the controller and its route entirely to eliminate the dead placeholder.
    - **Technical:** The `isValidSignature` method contains three explicit `NOTE:` comments stating it mirrors Square's pattern and must be updated to match Fresha's actual spec. Since the Fresha integration is no longer being extended, the code sits as acknowledged-but-unverified placeholder logic. Any real Fresha webhooks hitting this endpoint are either passing (if Fresha coincidentally uses the same scheme) or silently rejected (if not) — both outcomes are invisible without checking Fresha's docs.
    - **Plain English:** The Fresha delivery door has a lock that was copy-pasted from the Square door, with a sticky note on it saying "update this to match Fresha's actual lock." Since the Fresha feature isn't being finished, the cleanest fix is to take the door off the wall entirely rather than leave a placeholder that looks functional but may not be.
    - **Evidence:**
        ```php
        /**
         * Validate the webhook signature from Fresha.
         *
         * NOTE: Update this method based on Fresha's actual webhook signature mechanism.
         * Currently mirrors the Square HMAC-SHA256 pattern.
         */
        private function isValidSignature(Request $request, string $rawBody, string $signature): bool
        {
            // ...
            // NOTE: Update this hashing logic based on actual Fresha docs.
            // This mirrors Square's approach: HMAC-SHA256 of (notification_url + raw_body) with the signature key.
            $expectedSignature = base64_encode(
                hash_hmac('sha256', $notificationUrl.$rawBody, $signatureKey, true)
            );

            return hash_equals($expectedSignature, $signature);
        }
        ```
