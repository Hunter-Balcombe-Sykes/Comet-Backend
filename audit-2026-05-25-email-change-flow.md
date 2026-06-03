`★ Insight ─────────────────────────────────────`
Three cross-lens deduplication decisions shape this adjudication:
1. **LIFE-1 = SCALE-1 = CCH-1 = WHK-1** — four lenses independently flagged the same dedup-before-queue ordering bug. One finding survives under `LIFE-1`.
2. **SCHEMA-1** is dropped: it claims `brand`/`commerce`/`billing` schemas are missing from `extra_search_path`, but CLAUDE.md explicitly documents "No `brand`, `commerce`, or `billing` schemas" — the standalone strip removed them. The finding's premise is false.
3. **WHK-2** (durable events table) is dropped: the code comment *documents the intentional design* — the 300 s TTL exactly matches the Standard Webhooks signature tolerance window, and the comment notes that beyond that window replays fail signature verification anyway. This is deliberate, not fragile.
`─────────────────────────────────────────────────`

---

# Core Bundle Audit — 2026-05-25

**Branch:** development
**Lens:** Bundle 'core' across 8 themes: security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns (CACHE-*), database/queue scaling — N+1/throughput (SCALE-*), schema/RLS correctness (SCHEMA-*), caching gold-standard adherence (CCH-*), webhook idempotency & delivery (WHK-*), and transaction-boundary correctness (TXN-*)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php
- app/Mail/Auth/EmailChangeMail.php
- resources/views/emails/auth/email-change.blade.php
- supabase/config.toml

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 2 of 2 complete
- P2 Medium: 4 of 6 complete
- P3 Low: 1 of 2 complete

---

## P1 — Fix before pilot launch

- [x] **#SEC-1** · P1 — Email confirmations disabled; any email address accepted on signup _(resolved c4d9983f + dev/prod dashboards 2026-05-25)_
    - **Where:** supabase/config.toml (`[auth.email]` section)
    - **Affects:** All new user accounts — accounts can be created against addresses the registrant does not own or control.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set `enable_confirmations = true` in `supabase/config.toml` for local parity.
        - Enable email confirmations in the Supabase **dashboard** for both the dev and production projects (Settings → Auth → Email).
        - Smoke-test the `SupabaseEmailHookController` `signup` path end-to-end after enabling; the controller already handles the `signup` action and sends `EmailConfirmMail`, but it's never been exercised under the live config.
    - **Technical:** With `enable_confirmations = false`, Supabase issues a fully valid session immediately on signup without proving email ownership. The `SupabaseEmailHookController` already contains a `signup` handler that sends a branded OTP email via `EmailConfirmMail` — it exists precisely for this flow — but it's dead code until confirmations are enabled. For a platform where users will publish public profile pages and transactional services under their name, unverified accounts create a support burden (users who fat-finger their email lose access permanently) and an abuse vector (account squatting on another person's email identity).
    - **Plain English:** Right now, someone can create a Partna account claiming to own any email address — even yours. The system hands them full access without ever checking that they can actually receive mail at that address. It's like handing out membership cards without verifying ID. Before any real users come on board, every new account should require clicking a link sent to their inbox to prove they own it.
    - **Evidence:**
        ```toml
        # If enabled, users need to confirm their email address before signing in.
        enable_confirmations = false
        ```

- [x] **#LIFE-1** · P1 — Idempotency cache key written before `Mail::queue()`, causing silent permanent email loss on transient queue failures _(resolved a9e1205c 2026-05-25 — Cache::forget in catch + Pest regression test)_
    - **Where:** app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:63–103 (dedup block then try/catch)
    - **Affects:** All Supabase auth email recipients — signup OTP, password reset, magic link, invite, email change. When the queue connection (Redis DB 2) has a transient blip while the cache (Redis DB 0) is healthy, the email is permanently lost and Supabase stops retrying.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Cache::forget($dedupKey)` inside the `catch` block **before** returning the 500 response, so Supabase's retry sees a clean state and can re-attempt.
        - Alternatively, move the `Cache::add()` call to immediately after `Mail::queue()` succeeds — accept the narrow double-send race (a duplicate auth email) rather than the permanent-loss race.
        - Either path closes the bug; the `Cache::forget` approach is a one-line fix with minimal blast radius.
    - **Technical:** `Cache::add()` (Redis DB 0) is called atomically to record the `webhook-id` as "seen," then `Mail::queue()` dispatches to the queue (Redis DB 2 via Horizon). These are two independent Redis databases. If DB 2 is momentarily unavailable while DB 0 is healthy, `Cache::add` succeeds, `Mail::queue` throws, the `catch` block returns HTTP 500, and Supabase retries the webhook per Standard Webhooks spec. But the dedup key is already in Redis — the retry hits `! Cache::add(...)`, logs "duplicate," and returns `200 OK` with `duplicate: true`. Supabase interprets the 200 as success and stops retrying. The email is permanently gone. The idempotency marker must only be committed after the side-effect is confirmed, or rolled back on failure.
    - **Plain English:** Picture a bouncer who stamps your hand "admitted" the moment you reach the door — before you actually step inside. If you trip on the stairs and get sent back outside, the bouncer won't let you try again because your hand already has the stamp. The controller marks the webhook as "handled" before it puts the email in the outgoing queue. If the queue hiccups, the email never goes out, but the next attempt sees the stamp and gives up. The one-line fix is to erase the stamp if the queue rejected the email.
    - **Evidence:**
        ```php
        // Dedup written BEFORE queue dispatch
        $webhookId = (string) $request->header('webhook-id', '');
        if ($webhookId !== '') {
            $dedupKey = 'supabase:email_hook:seen:'.$webhookId;
            if (! Cache::add($dedupKey, 1, now()->addSeconds(300))) {
                Log::info('supabase.email_hook.duplicate', ['webhook_id' => $webhookId]);
                return response()->json(['ok' => true, 'handled' => false, 'duplicate' => true]);
            }
        }

        // ... mailable resolution ...

        try {
            Mail::queue($mailable);
            return response()->json(['ok' => true, 'handled' => true]);
        } catch (\Throwable $e) {
            // $dedupKey is already set — retry will see "duplicate" and give up
            Log::error('supabase.email_hook.send_failed', [
                'action' => $actionType,
                'error' => $e->getMessage(),
            ]);
            return $this->error('Failed to send email', 500);
        }
        ```

---

## P2 — Should fix

- [x] **#SEC-4** · P2 — Minimum password length of 6 characters falls below recommended baseline _(resolved 74ecd805 + dev/prod dashboards 2026-05-25)_
    - **Where:** supabase/config.toml (`[auth]` section)
    - **Affects:** All user accounts — passwords as short as "abc123" pass validation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Increase `minimum_password_length` to `8` in `config.toml`.
        - Apply the matching change in the Supabase dashboard for both dev and production projects.
    - **Technical:** Supabase's documented floor is 6, but NIST SP 800-63B and OWASP ASVS v4 both recommend 8 as the practical minimum for user-chosen passwords. At 6 characters, an unrestricted mixed-case alphanumeric keyspace is ~56 billion combinations — meaningful resistance in an online throttled scenario, but negligible if a password hash database is ever exposed. The `supabase/config.toml` setting governs local dev parity; the production policy is set separately in the dashboard, so both must be updated together to keep environments aligned.
    - **Plain English:** A 6-character password is a very short combination lock — modern computers can cycle through every possibility remarkably fast if they ever get hold of the scrambled password list. Bumping to 8 characters multiplies the combinations by thousands and brings the policy in line with what every major security standard recommends.
    - **Evidence:**
        ```toml
        # Passwords shorter than this value will be rejected as weak. Minimum 6, recommended 8 or more.
        minimum_password_length = 6
        ```

- [x] **#SEC-5** · P2 — No password character-class requirements; "111111" is a valid password _(resolved 9fcbcbae + dev/prod dashboards 2026-05-25)_
    - **Where:** supabase/config.toml (`[auth]` section)
    - **Affects:** All user accounts — any string of 6+ characters passes, including all-digit or all-lowercase passwords.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set `password_requirements = "lower_upper_letters_digits"` at minimum; `"lower_upper_letters_digits_symbols"` preferred for a platform handling professional identity.
        - Apply in both `config.toml` and the Supabase dashboard for dev and production.
    - **Technical:** An empty `password_requirements` string means Supabase applies no character-class diversity check. Combined with the 6-character minimum (SEC-4), passwords like `111111`, `abcdef`, or `password` all pass. OWASP ASVS 2.1.7 requires at least two character classes; Supabase natively supports three tiers (`letters_digits`, `lower_upper_letters_digits`, `lower_upper_letters_digits_symbols`). Enabling this adds zero friction for legitimate users choosing reasonable passwords and substantially raises the floor against credential-stuffing dictionaries that are all lowercase or all numeric.
    - **Plain English:** Right now, "password" is a perfectly valid password. Requiring a mix of uppercase, lowercase, and numbers forces every account to have at least some complexity — a tiny annoyance at signup that prevents the most common, most guessable passwords from ever being set.
    - **Evidence:**
        ```toml
        # Passwords that do not meet the following requirements will be rejected as weak. Supported values
        # are: `letters_digits`, `lower_upper_letters_digits`, `lower_upper_letters_digits_symbols`
        password_requirements = ""
        ```

- [x] **#SEC-2** · P2 — No CAPTCHA on Supabase auth endpoints; bot-driven bulk signup/stuffing relies solely on IP rate-limiting _(resolved 0361257b + Turnstile provisioned in dev/prod dashboards + frontend widget wired 2026-05-25)_
    - **Where:** supabase/config.toml (`[auth.captcha]` section, entirely commented out)
    - **Affects:** Signup and sign-in endpoints — a distributed bot with ~10 IPs can create 300 accounts per 5 minutes while staying under the `sign_in_sign_ups = 30` per-IP throttle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Enable Cloudflare Turnstile (already in use for the Worker) or hCaptcha in the Supabase dashboard for both dev and production.
        - Uncomment and configure `[auth.captcha]` in `config.toml` for local parity.
        - Coordinate with the frontend to add the corresponding CAPTCHA widget on the signup/signin forms.
    - **Technical:** IP-based rate limiting (`sign_in_sign_ups = 30 per 5 min`) is the only current defense against automated account creation. CAPTCHA adds a compute-cost barrier that makes bulk automation economically unviable across any number of IPs. Supabase natively supports Turnstile and hCaptcha with a single dashboard toggle plus a frontend widget. Given Partna already uses Cloudflare for the Worker, Turnstile is the zero-friction choice — same vendor, no new account required.
    - **Plain English:** The front door has a speed bump — it slows down how fast anyone can try to enter — but a fleet of bots can spread across many IP addresses and bypass it. A CAPTCHA is a "prove you're human" puzzle that bots can't reliably solve, which stops the automated account-creation problem at the source rather than just slowing it down.
    - **Evidence:**
        ```toml
        # Configure one of the supported captcha providers: `hcaptcha`, `turnstile`.
        # [auth.captcha]
        # enabled = true
        # provider = "hcaptcha"
        # secret = ""
        ```

- [ ] **#WHK-5** · P2 — Queued mailables carry no event-id; queue-level job retries can send duplicate auth emails
    - **Where:** app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:98; app/Mail/Auth/EmailChangeMail.php
    - **Affects:** Users who receive duplicate password-reset, magic-link, or email-change confirmation emails when Horizon retries a job that already successfully called Resend but crashed before the job was acknowledged as complete.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a dedicated `SendAuthEmailJob` that accepts the `webhook-id` string, mailable class, and constructor arguments. The job looks up a `processed_webhook_ids` cache key (or DB record if WHK-3's events table is built) before calling `Mail::send()`.
        - Alternatively, add a deterministic `Message-ID` header to each mailable derived from the `webhook-id` (e.g. `"auth-{$webhookId}@partna.au"`). Most mail servers and Resend deduplicate on `Message-ID`.
        - Set `$tries = 3` and `$backoff = [30, 120]` on the job to align with Resend's retry window.
    - **Technical:** `Mail::queue($mailable)` pushes a `SendQueuedMailable` job to Redis DB 2. If Horizon picks it up, calls Resend, Resend accepts and sends the email, but the worker process crashes before returning `JobProcessed` to the queue broker, Redis re-queues the job. On retry, the same mailable fires again — Resend has no way to know this is a duplicate because no shared idempotency key is threaded through. The controller's `Cache::add()` dedup prevents duplicate queue *inserts* but does nothing to protect against duplicate queue *executions*. No `SendAuthEmailJob` exists in `app/Jobs/` (verified by search), confirming this is an open gap.
    - **Plain English:** The controller drops one copy of the email in the outgoing mailbox. A postal worker picks it up, delivers it to the recipient, but then trips and forgets to sign the "delivered" register. The post office sees an unconfirmed delivery, sends a second postal worker with a copy, and the recipient gets two identical "reset your password" letters. Putting a tracking number on the envelope (a Message-ID tied to the webhook) lets the mail server recognise the duplicate and discard it.
    - **Evidence:**
        ```php
        // Controller: no event-id threaded through
        Mail::queue($mailable);

        // EmailChangeMail: no idempotency mechanism
        class EmailChangeMail extends BaseTransactionalMail
        {
            public function __construct(
                public readonly string $recipientEmail,
                public readonly ?string $displayName,
                public readonly string $verifyUrl,
            ) {}
        }
        ```

- [ ] **#WHK-3** · P2 — Raw webhook payload parsed and discarded; no forensic trail for failed auth email deliveries
    - **Where:** app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:35–47
    - **Affects:** Incident response and debugging when auth emails (password resets, magic links, signup OTPs) fail to send. Without the raw payload there is no way to replay or inspect what Supabase actually sent.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `core.supabase_email_events` table with at minimum: `event_id` (unique, the `webhook-id`), `action_type`, `recipient_email_hash` (SHA-256 — avoid storing plaintext PII in logs), `raw_payload` (JSONB), `processed_at`, `created_at`.
        - Store `$request->getContent()` verbatim into `raw_payload` before any field extraction; the DB unique constraint on `event_id` then replaces or supplements the Redis dedup key.
        - Redact `token_hash` and `token` fields from `raw_payload` at write time (replace with `"[REDACTED]"`) since they are short-lived auth credentials and should not persist beyond the webhook processing window.
    - **Technical:** The controller calls `$request->json()->all()` at line 35 to extract `user`, `email_data`, and individual fields. The raw payload is never stored — only the projected fields survive in logs, and only for failure cases. Per the webhook gold standard, the full vendor payload must be stored as JSONB on an events table to allow replay and forensic analysis. The event log should be immutable and complete. Supabase does not expose a webhook replay API for auth hooks, so without local archival, debugging a systematic delivery failure (bad Blade template, Resend outage, wrong queue configuration) requires guessing what Supabase sent, or asking every affected user to re-trigger their flow manually.
    - **Plain English:** Imagine receiving a legal letter, reading the summary, and shredding the envelope, the postmark, and the full original text. If something goes wrong later, all you have are your own handwritten notes from the summary. The controller reads the fields it needs from Supabase's incoming message and throws away the original — so when a password reset email goes missing, there's no complete record to investigate or resend from.
    - **Evidence:**
        ```php
        public function __invoke(Request $request): JsonResponse
        {
            $payload = $request->json()->all();  // Parsed, never stored

            $user = is_array($payload['user'] ?? null) ? $payload['user'] : null;
            $emailData = is_array($payload['email_data'] ?? null) ? $payload['email_data'] : null;
            // Raw payload is discarded from this point forward
        ```

- [x] **#LIFE-2** · P2 — Error log for failed mail dispatch missing `webhook_id`; failed deliveries cannot be correlated to a specific auth event _(resolved 584c9018 2026-05-25)_
    - **Where:** app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:86–91
    - **Affects:** Operations investigating a specific failed auth email delivery. Without the `webhook-id`, the error log line cannot be matched to the Supabase event that triggered it.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `'webhook_id' => $webhookId` to the context array in the `catch` block's `Log::error` call.
        - While here, also add `'webhook_id'` to the `payload_invalid` warning log — it's extractable from the header even when the payload body is malformed.
    - **Technical:** The `catch` block logs `action` and `error` but omits the `webhook-id`, which is the primary correlation key linking the controller event to Supabase's delivery attempt. At any meaningful scale (dozens of auth emails per day), a `send_failed` log line with only `action: "recovery"` is un-actionable — you can't tell which user's password reset was lost, can't match it to Supabase's delivery log, and can't trigger a manual resend. Every log entry in a webhook handler should carry the vendor's idempotency key as a first-class field.
    - **Plain English:** When the email system fails, the error log is like a cash register receipt that says "payment failed" but doesn't show which customer or which transaction. The support team can't find the event to investigate it. Adding the webhook ID to the error is like printing the transaction number — it gives anyone investigating the incident a tracking number they can look up in both Partna's logs and Supabase's dashboard.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::error('supabase.email_hook.send_failed', [
                'action' => $actionType,
                'error' => $e->getMessage(),
                // 'webhook_id' => $webhookId  ← missing
            ]);
            return $this->error('Failed to send email', 500);
        }
        ```

---

## P3 — Nice to have

- [ ] **#WHK-4** · P3 — No artisan replay command; systematic email delivery failures require manual user re-triggering
    - **Where:** app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php (entire controller — no companion replay command exists)
    - **Affects:** Recovery from systematic delivery bugs (bad Blade template, Resend outage, misconfigured queue). Every affected user must manually re-trigger their auth flow.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Once the events table from WHK-3 is built, add `php artisan supabase:replay-emails {--event-id=} {--action=} {--since=}` that reads raw payloads from `core.supabase_email_events` and re-invokes the mailable resolution + dispatch logic.
        - Guard the command with `$this->confirm()` and log every replay action with `event_id`, operator identifier, and timestamp.
        - The events table's unique `event_id` constraint prevents accidentally double-processing a record that was already delivered.
    - **Technical:** No `supabase:replay-emails` command exists in `app/Jobs/` or `app/Console/`. Without raw payload archival (WHK-3), replay is impossible anyway — this is a follow-on to that fix. Together the lack of archival and replay means the only recovery path for a systematic delivery failure is user-driven, which is especially bad for `invite` and `signup` flows where the user may not know they need to act. Supabase does not support webhook replay for auth hooks.
    - **Plain English:** If a bug in the email template causes 50 password-reset emails to bounce silently for two hours, there's no "resend" button. Each of those 50 people has to request a new reset themselves — and most of them won't know why their reset didn't arrive. The fix (after storing the raw payloads from WHK-3) is a backstage console command that lets an operator replay any stored event, like re-printing a lost ticket stub from the box-office archive.
    - **Evidence:**
        ```php
        // Entire webhook lifecycle has no replay path:
        // Controller → Cache::add (dedup) → Mail::queue → 200 OK
        // No artisan command, no companion job, no events table to replay from.
        ```

- [x] **#LIFE-3** · P3 — Log calls throughout the controller omit `webhook_id` and `request_id`; log lines cannot be correlated across services _(resolved 584c9018 2026-05-25 — bundled with LIFE-2)_
    - **Where:** app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:52–55 (`warning`), :64 (`info` duplicate), :76–78 (`info` unhandled)
    - **Affects:** Nightwatch / log aggregation — when a chain of events (webhook → mail send → user click) goes wrong, the controller's log lines cannot be stitched to other services sharing the same `X-Request-Id`.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Extract `$requestId = $request->header('X-Request-Id', '')` at the top of `__invoke()`.
        - Add `'webhook_id' => $webhookId` and `'request_id' => $requestId` to every `Log::*` context array in the controller.
    - **Technical:** The canonical log-with-context pattern for this codebase requires at minimum a correlation key on every meaningful log. The `payload_invalid` warning and `unhandled_action` info lines carry neither `webhook_id` nor a request-level correlation key. When multiple webhooks are processed concurrently, log lines from different events interleave with no way to group them. The `X-Request-Id` header is typically injected by the load balancer (Cloudflare or Laravel Cloud's reverse proxy) and provides a cross-service trace anchor.
    - **Plain English:** Think of each incoming webhook as a package arriving at a sorting facility. Right now, some of the log entries for that package don't have a tracking number on them — so if you're trying to trace a specific package through the system, some steps in its journey are invisible. Adding the webhook ID and request ID to every log line is like stamping the tracking number on every scan along the route.
    - **Evidence:**
        ```php
        Log::warning('supabase.email_hook.payload_invalid', [
            'has_user' => $user !== null,
            'has_email_data' => $emailData !== null,
            'action' => $actionType,
            // no webhook_id, no request_id
        ]);
        // ...
        Log::info('supabase.email_hook.unhandled_action', ['action' => $actionType]);
        // no webhook_id, no request_id
        ```
