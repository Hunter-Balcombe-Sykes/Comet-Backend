- [ ] **#SEC-1** · P2 — Full Supabase Admin API response body logged verbatim on failure
    - **Where:** app/Services/Professional/AccountDeletionService.php:335-339
    - **Affects:** Log aggregator persistence (Nightwatch / Papertrail / Cloudwatch) — any downstream system that retains Laravel log entries indefinitely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'body' => $response->body()` with `'body' => substr($response->body(), 0, 200)` or log only the status code.
        - Add a project-wide convention in CLAUDE.md that HTTP response bodies must never be logged in full from auth or admin APIs.
    - **Technical:** The `deleteSupabaseAuthUser` helper calls the Supabase GoTrue Admin API (`DELETE /auth/v1/admin/users/{id}`) and logs `$response->body()` on any non-404 failure. While GoTrue error responses today are JSON error objects (`{"code":500,"msg":"Database error"}`), logging full response bodies from an auth-service API creates a pattern that can leak tokens or PII if the upstream response schema changes, or if the pattern is copy-pasted to a more sensitive endpoint. Laravel's default log stack (single / daily / stack) writes these entries to disk without automatic redaction; log aggregators retain them indefinitely.
    - **Plain English:** When something goes wrong while deleting a user's login account on Supabase, we write the entire raw response from Supabase into our log files. Right now those responses are just error codes, but if Supabase ever changes what they return in an error — or if someone copies this pattern to a different API call — we could end up with customer emails, phone numbers, or access tokens sitting in log files forever. The fix is to log only the status code and the first few characters of the response, not the whole thing.
    - **Evidence:**
        ```php
        if (! $response->successful()) {
            Log::error('Supabase auth user deletion failed', [
                'auth_user_id' => $authUserId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }
        ```
    - `[DRAFT, confidence: 0.85]`
