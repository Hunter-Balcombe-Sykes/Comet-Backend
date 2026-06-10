# Credentials & Log Hygiene Audit — 2026-05-24

**Branch:** development
**Lens:** env reads outside config layer, hardcoded secrets, plaintext credentials/PII leaking into logs and exception messages
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Services/Auth/SupabaseAdminService.php`
- `app/Services/Professional/AccountDeletionService.php`
- `app/Services/Media/MediaDiskResolver.php`
- `app/Services/Streaming/TwitchApiClient.php`
- `app/Services/Streaming/KickApiClient.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#CRED-1** · P2 — Supabase Admin API response body embedded in RuntimeException message
    - **Where:** `app/Services/Auth/SupabaseAdminService.php:101–103`
    - **Affects:** Any user who triggers MFA factor unenrollment. The raw Supabase Admin API response body — which may contain user objects, factor metadata, email addresses, or internal Supabase error detail — is concatenated into a `RuntimeException` message string. Laravel's exception handler propagates that message to all configured log channels and to Nightwatch. GDPR erasure cannot reach log aggregator retention windows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract only `$response->json('code')` and `$response->json('msg')` as discrete log fields.
        - Throw the `RuntimeException` with only the HTTP status code in the message — never the body.
        - Mirror the existing pattern already used in `createUser()` in the same file (`email_fingerprint`, `error_code`, `error_msg` as discrete fields).
    - **Technical:** `RuntimeException` messages are treated as safe strings by Laravel's exception handler — they surface verbatim in Nightwatch, CloudWatch, and any other configured exception-tracking sink. The Supabase Admin API's MFA unenroll endpoint (`DELETE /auth/v1/admin/factors/{id}`) can return the full factor object or user metadata on failure. Embedding `$response->body()` in the exception message means that JSON blob, which may contain `user.email`, `user.id`, or factor enrollment details, is shipped to every log sink with whatever retention policy is configured — typically 30–90 days. GDPR right-to-erasure obligations cannot reach those sinks. The same class already demonstrates the correct pattern for `createUser()`: structured log fields with `email_fingerprint` (SHA-256), never raw API bodies.
    - **Plain English:** When your app tries to remove a user's two-factor authentication setting and Supabase returns an error, the entire error message from Supabase — which can include the user's email address and account details — gets packaged into the error your system logs. Think of it like a sticky note that says "couldn't do X, here's everything we know about this person." That note then gets filed in your error-tracking system, which keeps records for months. If a user asks you to delete all their data, you can't reach into those filed notes and remove their information. The fix is simple: only write down the error code number, not the full details.
    - **Evidence:**
        ```php
        if (! $response->successful()) {
            throw new RuntimeException(
                "Supabase factor unenroll failed: HTTP {$response->status()} body={$response->body()}"
            );
        }
        ```

- [ ] **#CRED-2** · P2 — Supabase Admin API response body logged during auth user deletion
    - **Where:** `app/Services/Professional/AccountDeletionService.php:372–376`
    - **Affects:** Any user undergoing account deletion whose Supabase auth record fails to delete. The raw Admin API response body is persisted to the error log channel, which feeds Nightwatch in production. Supabase Admin DELETE `/auth/v1/admin/users/{id}` error responses can return user metadata. This path is particularly sensitive: it is executed precisely because the user has requested erasure, yet the raw response body may persist their data in log stores for 30–90+ days beyond the deletion attempt — directly counter to the erasure intent.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'body' => $response->body()` with two discrete fields: `'error_code' => $response->json('code')` and `'error_msg' => $response->json('msg')`.
        - Retain `auth_user_id` and `status` — those are sufficient for incident correlation and do not contain PII.
    - **Technical:** The `deleteSupabaseAuthUser()` method is called as part of a GDPR-triggered or user-initiated account deletion flow. When the Supabase Admin API returns a non-2xx, `Log::error()` persists the context array to the configured log channel. The `'body'` key contains the raw JSON response, which the Supabase Admin API may populate with user object fragments or internal error metadata. `Log::error()` context arrays are serialized to string and shipped to all configured logging channels — in production this means Nightwatch and any CloudWatch or file sinks. Because this failure occurs at the end of the deletion pipeline, the user may already be soft-deleted in the application DB, making this the last moment their data can leak into long-retention stores. The `auth_user_id` UUID already provides all necessary correlation for support investigation.
    - **Plain English:** When deleting a user's account fails at the final step — removing them from the authentication system — your code logs the full error response, which can include that user's details. The cruel irony is that this happens specifically when someone is trying to erase their data. Even after the deletion, fragments of their information can end up archived in your error logs for months. The fix is to only record the error code (a short identifier like "user_not_found") rather than the entire error package.
    - **Evidence:**
        ```php
        Log::error('Supabase auth user deletion failed', [
            'auth_user_id' => $authUserId,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        ```

---

## P3 — Nice to have

- [ ] **#CRED-3** · P3 — `MediaDiskResolver` reads `$_ENV` / `$_SERVER` superglobals directly
    - **Where:** `app/Services/Media/MediaDiskResolver.php:31–33`
    - **Affects:** Code hygiene and future auditability. No credential leak, no PII. This is a deviation from the codebase's config-only convention, but the file contains an inline comment that documents the intentional rationale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - **Option A (preferred short-term):** Strengthen the existing inline comment to reference this finding and explicitly mark it as an approved exception, so future audits and engineers do not re-flag it or silently remove it.
        - **Option B (preferred long-term):** Move the `$_ENV`/`$_SERVER` probe into a service provider or bootstrap step that sets a `partna.media_disk_runtime` config key. `MediaDiskResolver` then reads only from `config()`. This places the one superglobal read in the config layer where it belongs.
        - Do not refactor this without first validating the runtime injection behaviour on Laravel Cloud — the existing comment explains a real platform constraint.
    - **Technical:** Laravel Cloud caches the config tree at deploy time using `php artisan config:cache`. Platform-injected env vars that arrive after deploy (e.g., dynamically attached storage volumes or overrides) are not visible via `config()` or `env()` at runtime. The existing inline comment acknowledges this exactly. The superglobal read is used only as a probe to determine whether the runtime value differs from the cached config; the return value of `resolve()` is still `config('partna.media_disk')`. The deviation is real but intentional and bounded. The risk is that a future refactor removes the probe logic without understanding the constraint, silently falling back to a cached (potentially wrong) disk value.
    - **Plain English:** Your media routing code reads an environment variable in a way that bypasses your app's normal configuration system. There's a good reason: your hosting platform sometimes sets environment values after the app has already cached its settings, so the normal way of reading config wouldn't see the fresh value. The current code works correctly. The only risk is that a future developer, not knowing this context, might "clean up" the unusual code and break it. The fix is just to document this clearly enough that no one removes it without understanding why it's there.
    - **Evidence:**
        ```php
        $explicit = $_ENV['PARTNA_MEDIA_DISK'] ?? $_SERVER['PARTNA_MEDIA_DISK']
            ?? $_ENV['SIDEST_MEDIA_DISK'] ?? $_SERVER['SIDEST_MEDIA_DISK'] ?? null;
        ```

- [ ] **#CRED-4** · P3 — Streaming API clients log raw response bodies on error
    - **Where:** `app/Services/Streaming/TwitchApiClient.php:57–60` · `app/Services/Streaming/KickApiClient.php:68–71`
    - **Affects:** Log hygiene and the codebase's body-dumping precedent. Risk is lower than CRED-1/2 — Twitch and Kick are public APIs unlikely to return user PII in error bodies. The primary concern is pattern normalisation: these two call sites establish `'body' => $response->body()` as a normal logging idiom, which future engineers will copy when integrating more sensitive APIs.
    - **Effort:** S (~0.5–1h for both)
    - **What to do:**
        - Remove `'body' => $response->body()` from the `Log::error()` call in both `TwitchApiClient::getLiveHandles()` and `KickApiClient::getLiveHandles()`.
        - Retain `platform` and `status` — sufficient for incident triage.
        - If structured error detail becomes necessary, add `'error_code' => $response->json('error')` and `'error_msg' => $response->json('message')` as discrete fields, not raw body dumps.
    - **Technical:** Both `getLiveHandles()` methods issue unauthenticated or lightly authenticated public API calls. On non-2xx, `Log::error()` is called with the full response body. Twitch error responses are documented to include internal rate-limit metadata and occasionally token context. Kick is undocumented; its error shapes are unpredictable. More importantly, this pattern is structurally identical to the higher-severity CRED-1/2 findings — the only difference is the sensitivity of the data the APIs are likely to return. Keeping the pattern alive in lower-risk code guarantees it propagates to higher-risk integrations. The fix is two-line deletions.
    - **Plain English:** When your Twitch or Kick API calls fail, your app logs the entire error response rather than just the error code. These particular APIs are unlikely to include anything sensitive in their errors, so the immediate risk is low. The real concern is that this sets a habit: other developers (or future-you) will see this pattern and copy it when building integrations with APIs that *do* return sensitive information. Removing the raw response dump from these two places costs almost nothing and keeps the codebase's logging standards consistent.
    - **Evidence:**
        ```php
        // TwitchApiClient.php
        Log::error('streaming.api_error', [
            'platform' => 'twitch',
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        // KickApiClient.php
        Log::error('streaming.api_error', [
            'platform' => 'kick',
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        ```
