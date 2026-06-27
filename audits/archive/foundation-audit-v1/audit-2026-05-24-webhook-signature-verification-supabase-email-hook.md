`★ Insight ─────────────────────────────────────`
The `SupabaseEmailHookSignatureVerifier` already enforces a 300-second timestamp tolerance at line 46 (`abs($now - $ts) > self::TIMESTAMP_TOLERANCE`), which directly invalidates WH-2's premise. This is a good example of why adjudicators must read the actual source before accepting a draft finding — DeepSeek flagged the class as "not provided in audit scope" and speculated; the file exists and the check is implemented correctly.
`─────────────────────────────────────────────────`

# Webhook / Email Hook Auth Audit — 2026-05-24

**Branch:** development
**Lens:** webhook signature verification, Supabase email hook auth, third-party webhook replay risk
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php
- app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php
- app/Services/Email/SupabaseEmailHookSignatureVerifier.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **WH-1** · P1 — No webhook-id deduplication — valid webhooks can be replayed within the 5-minute tolerance window
    - **Where:** app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:30–86 and app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php:39–53
    - **Affects:** All users receiving auth emails (signup confirmations, password resets, magic links). Within the 300-second timestamp window enforced by the verifier, a captured valid webhook can be submitted multiple times — each submission calls `Mail::send()` and delivers a duplicate email. Supabase's own at-least-once retry behaviour also passes through this gap legitimately (on a transient 5xx, the same `webhook-id` is retried with the same signature and both deliveries succeed).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After signature validation passes in the middleware, attach the `webhook-id` to the request as an attribute (e.g. `$request->attributes->set('webhook_id', $webhookId)`).
        - At the top of the controller, attempt `Cache::add("email_hook:{$webhookId}", 1, now()->addSeconds(300))`. If it returns `false` (key already existed), return `200 OK` with `['ok' => true, 'handled' => false, 'duplicate' => true]` immediately — do not call `Mail::send()`.
        - Log the duplicate at `info` level with the `webhook-id` and `action` so ops can distinguish retry-noise from active replay attempts.
        - 300 seconds matches the verifier's `TIMESTAMP_TOLERANCE` constant — there's no value in a longer TTL.
    - **Technical:** `SupabaseEmailHookSignatureVerifier` correctly enforces a 300-second timestamp window (`abs($now - $ts) > self::TIMESTAMP_TOLERANCE`), which is good — it closes the indefinite-replay vector. However, within that window the `webhook-id` is extracted in the middleware only for inclusion in the HMAC-signed payload; after the signature check passes it is discarded. The controller never sees it. Standard Webhooks spec §4 explicitly requires receivers to track `webhook-id` for idempotency. `Cache::add()` (Redis `SET NX EX`) is atomic and provides the correct first-writer-wins semantic. The TTL should match `TIMESTAMP_TOLERANCE` exactly so expired entries are not kept longer than the replay window they protect against.
    - **Plain English:** Your signature checker is excellent — it only accepts letters postmarked within the last 5 minutes. But it doesn't write down each letter's tracking number after processing it. If the same signed letter is delivered twice within those 5 minutes (either by an attacker who captured it, or by Supabase's own retry on a hiccup), both deliveries get processed and the user receives two "reset your password" emails. The fix is a simple logbook: write down each tracking number when you first handle it, and if you see the same number again, bin the duplicate politely without doing any work.
    - **Evidence:**
        ```php
        // Middleware extracts webhook-id for HMAC signing but never stores or forwards it:
        $webhookId = (string) $request->header('webhook-id', '');
        $webhookTimestamp = (string) $request->header('webhook-timestamp', '');
        $webhookSignature = (string) $request->header('webhook-signature', '');

        $valid = $this->verifier->verify(
            configuredSecret: $secret,
            webhookId: $webhookId,
            webhookTimestamp: $webhookTimestamp,
            webhookSignatureHeader: $webhookSignature,
            rawBody: $rawBody,
        );

        // ...
        return $next($request);  // webhook-id discarded — controller cannot deduplicate
        ```
        ```php
        // Controller: no idempotency guard before Mail::send()
        Mail::send($mailable);

        return response()->json(['ok' => true, 'handled' => true]);
        ```

---

## P2 — Should fix

- [ ] **WH-2** · P2 — Missing deploy-time assertion for `supabase.email_hook_secret` — misconfiguration silently breaks all auth emails in production
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php:25–31
    - **Affects:** Production availability of all auth emails. If `SUPABASE_EMAIL_HOOK_SECRET` is absent from a deploy (typo in env vars, secret rotation that didn't land), the middleware correctly returns 503 — but does so silently. Users discover broken signup, password reset, and magic-link flows before the ops team notices.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an assertion in `AppServiceProvider::boot()` that throws a `\RuntimeException` (or calls `abort(500, ...)`) in the `production` environment when `config('services.supabase.email_hook_secret')` is empty.
        - Keep the middleware's 503 guard unchanged — it's the correct runtime safety net for non-production environments and as a defence-in-depth layer.
        - Consider adding `SUPABASE_EMAIL_HOOK_SECRET` to any deploy checklist or `.env.example` required-key comments.
    - **Technical:** The middleware's fail-closed 503 is the right security posture — it is far better than allowing unsigned requests through. The gap is operational: a misconfigured deploy returns 503 on every Supabase email hook call, Supabase retries and eventually stops, and users see silent failures with no server-side exception visible in Nightwatch (since the middleware returns a response rather than throwing). A boot-time assertion turns a silent runtime failure into a loud deploy-time crash, which Laravel Cloud will surface immediately.
    - **Plain English:** If someone deploys without the email-signing secret, your app quietly locks the door on every auth email — no signups, no password resets, no magic links — but it doesn't raise an alarm. The app keeps running, it just stops delivering emails, and the only way to find out is when users start complaining. Adding a startup check is like having a pre-flight checklist that refuses to let the plane take off if critical instruments are missing, rather than discovering the problem at 30,000 feet.
    - **Evidence:**
        ```php
        $secret = (string) config('services.supabase.email_hook_secret', '');
        if ($secret === '') {
            Log::warning('supabase.email_hook.misconfigured', ['reason' => 'secret_missing']);

            return response()->json([
                'error' => true,
                'message' => 'Email hook is not configured.',
            ], 503);  // Safe deny, but no boot-time alarm — deploy ships silently broken
        }
        ```
