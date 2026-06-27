Evidence confirmed. Now I have everything needed to produce the final audit.

`★ Insight ─────────────────────────────────────`
- The Supabase Send Email Hook uses Standard Webhooks at-least-once semantics — a non-2xx response (including a 500 from a slow mail transport timeout) triggers a retry, meaning synchronous mail on the webhook handler creates a reliability trap: transport slowness → timeout → retry → duplicate send.
- In `AccountDeletionService::request()`, the rollback-on-mail-failure pattern is a correctness guarantee (user gets a token IFF they receive the email), so the fix must preserve this invariant, not simply queue the mail.
`─────────────────────────────────────────────────`

---

# Hot-Path Efficiency Audit — 2026-05-24

**Branch:** development
**Lens:** N+1 queries, unbounded result sets, synchronous work belonging in jobs, hot-path inefficiencies
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php`
- `app/Services/Professional/AccountDeletionService.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 1 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **PERF-1** · P1 — Supabase email hook sends mail synchronously, causing duplicate delivery on transport slowness
    - **Where:** `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:69`
    - **Affects:** All Supabase auth flows that trigger transactional email (signup confirmation, password reset, magic link). A slow or temporarily unavailable Resend transport causes the webhook to time out, Supabase retries, and the user receives duplicate auth emails.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Mail::send($mailable)` with `Mail::queue($mailable)` (or `Mail::to(...)->queue(...)` if the mailable doesn't embed the recipient).
        - Ensure the `mail` queue worker is running under Horizon (it already is — check `config/horizon.php` queue list includes `mail` or `default`).
        - Keep the surrounding `try/catch` — if dispatching to Redis fails (unlikely but possible), returning 500 will still cause a Supabase retry, so log and return 200 with `handled: false` on queue dispatch failure rather than 500.
    - **Technical:** The Supabase Send Email Hook uses Standard Webhooks at-least-once delivery: any non-2xx response triggers a retry. `Mail::send()` blocks the PHP process for the entire Resend round-trip (typically 100–400 ms, but up to several seconds under load or on first cold connection). If Resend is slow or the request is killed by PHP-FPM timeout before the response is written, Supabase retries the hook — sending the same auth email twice. Queueing the dispatch returns control to the controller in microseconds, making timeouts and retries effectively impossible under normal load. The correctness trade-off is minimal: auth emails are idempotent (the token is the same across retries) and Resend itself deduplicates on its end.
    - **Plain English:** When someone signs up or resets their password, our server has to send them an email before it can tell Supabase "OK, done." Right now it sends the email right there and then waits for the email service to respond before saying "done." If the email service is even a bit slow, Supabase thinks the whole thing failed and tries again — so the user gets the email twice. The fix is to hand the email off to a background worker (like dropping a letter in a mailbox) and immediately tell Supabase "done" — the email still goes out, just a fraction of a second later.
    - **Evidence:**
        ```php
        try {
            $mailable = $this->resolveMailable($actionType, $recipientEmail, $displayName, $verifyUrl, $token);
            if ($mailable === null) {
                Log::info('supabase.email_hook.unhandled_action', ['action' => $actionType]);
                return response()->json(['ok' => true, 'handled' => false]);
            }

            Mail::send($mailable);

            return response()->json(['ok' => true, 'handled' => true]);
        } catch (\Throwable $e) {
            Log::error('supabase.email_hook.send_failed', [
                'action' => $actionType,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to send email', 500);
        }
        ```

---

## P2 — Should fix

- [x] **PERF-2** · P2 — Account deletion emails sent synchronously, blocking the HTTP response
    - **Where:** `app/Services/Professional/AccountDeletionService.php:59` (request path) and `:175` (confirmation path)
    - **Affects:** Users requesting or confirming account deletion. A slow Resend transport makes these endpoints visibly slow. On the request path, mail failure also rolls back the deletion token, requiring the user to retry — this is intentional correctness, but it means a mail outage completely blocks account deletion initiation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - **Confirmation path (line 175):** Switch to `Mail::to($realEmail)->queue(...)`. The existing `try/catch` already tolerates failure gracefully (logs and continues) — queueing removes the blocking wait with no correctness trade-off.
        - **Request path (line 59):** The rollback-on-failure pattern is a deliberate correctness guarantee (user only holds a token if they've received the email). Preserve this by switching to a queued job that: (a) sends the mail, (b) on failure clears the token and optionally notifies staff. Alternatively, keep `send()` here and accept the synchronous cost — this is a low-frequency path (users rarely delete their account) and the correctness guarantee is valuable.
        - Do not simply queue the request-path mail without also dropping the rollback — doing so means users can end up holding a deletion token they never received, and the "retry cleanly" comment at line 66 becomes untrue.
    - **Technical:** Two `Mail::to(...)->send(...)` calls block PHP workers for the Resend round-trip. The confirmation path (`executeConfirmation`, line 175) already wraps mail in a tolerant `try/catch` so switching to `queue()` is a straight swap with no correctness impact. The request path (`request()`, line 59) intentionally couples mail delivery to token persistence: if the send fails the token is cleared, guaranteeing the user can retry. Queueing breaks this coupling — the token is written to DB before the job runs, so if the job fails the user holds a stale token. The cleanest fix is a dedicated `SendAccountDeletionRequestMailJob` that clears the token on failure and retries with a short backoff; `failed()` can clear it as a last resort. Given this is a low-frequency path, keeping `send()` here and accepting ~200–400 ms blocking is also an acceptable pragmatic choice.
    - **Plain English:** When a user asks to delete their account, the server sends them a confirmation email and waits — right there, during the web request — for the email service to respond before continuing. Most of the time this adds barely noticeable delay. But if the email service is slow, the user's browser just sits there waiting. The fix is to hand the email off to a background worker and respond immediately. One of the two email sends has a slight complication: it's deliberately designed so that if the email fails, the deletion request is cancelled (so the user can try again cleanly). Moving that one to a background worker needs a little extra care to preserve that safety net.
    - **Evidence:**
        ```php
        // request() path — rolls back token on failure
        try {
            Mail::to($professional->primary_email)->send(
                new AccountDeletionRequestedMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                    confirmationUrl: $confirmationUrl,
                )
            );
        } catch (\Throwable $e) {
            // Mail failed — roll back token so user can retry cleanly.
            $professional->update([
                'deletion_token_hash' => null,
                'deletion_requested_at' => null,
            ]);
            // ...
        }

        // executeConfirmation() path — tolerates failure
        try {
            Mail::to($realEmail)->send(
                new AccountDeletionScheduledMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                    deletesAt: $deletesAt->toDayDateTimeString(),
                    cancelUrl: $cancelUrl,
                )
            );
        } catch (\Throwable $e) {
            Log::error('Account deletion scheduled mail failed', [
                'professional_id' => $professional->id,
        ```

The final audit has two findings: PERF-1 (P1) for the Supabase webhook's synchronous mail dispatch, and PERF-2 (P2) for the account deletion service's blocking sends. PERF-3 was dropped — the R2 PUT outside the transaction is already the correct "Master Pattern 16" architecture, not a defect.
