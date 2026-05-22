- [ ] **#SEC-1** · P1 — Supabase Admin API response body logged on failure leaks response payload to log aggregator
    - **Where:** app/Services/Professional/AccountDeletionService.php (deleteSupabaseAuthUser method)
    - **Affects:** Supabase user data (PII) transmitted in API responses; Log aggregator / Nightwatch persistence of sensitive payloads; GDPR compliance for data retention in logs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'body' => $response->body()` with a sanitised subset: log only `status` + `auth_user_id`, never the raw response body.
        - Add a blanket log-sanitisation rule in the Supabase client wrapper (if one exists) that redacts response bodies by default.
    - **Technical:** The `deleteSupabaseAuthUser` method calls the Supabase Admin API and on non-2xx+non-404 responses logs the full `$response->body()`. Supabase Admin API responses can include user object payloads (email, phone, metadata) depending on the error context. Once written to Laravel's log channel, this data is durable in the log aggregator (Nightwatch) and is not subject to the 30-day soft-delete retention that governs the DB rows. This is a PII-leak-into-logs vector under GDPR Article 32 (security of processing).
    - **Plain English:** When the system tries to delete a user's Supabase account and the deletion fails, it writes the ENTIRE response from Supabase into the permanent logs. That response can contain the user's email, phone number, and other personal details. Think of it like shredding a customer file but leaving a photocopy taped to the wall of the server room.
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

- [ ] **#SEC-2** · P2 — Mail-send exception messages may leak the professional's email address into error logs
    - **Where:** app/Services/Professional/AccountDeletionService.php (confirm, cancel, adminCancel methods)
    - **Affects:** Professional email addresses captured in Laravel's exception messages; Nightwatch log persistence of PII.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In each `catch (\Throwable $e)` block around `Mail::to(...)->send(...)`, replace `'error' => $e->getMessage()` with a static label like `'error' => 'mail_send_failed'` (the full exception context is already captured by Laravel's exception handler — the inline log adds the raw message to the structured log payload).
        - Alternatively, wrap the mail-send path in a try/catch that re-throws a sanitised exception class that strips the recipient from the message string.
    - **Technical:** Laravel's SwiftMailer/Symfony Mailer transport exceptions routinely include the recipient email address in their exception message string (e.g., `"Expected response code 250 but got code 550, with message '550 5.1.1 <user@example.com> recipient rejected'"`). The inline `Log::error` calls in the deletion service capture `$e->getMessage()` verbatim, writing the professional's email into the structured log payload. This persists in the log aggregator outside the 30-day soft-delete window that governs the DB row.
    - **Plain English:** If sending an account-deletion confirmation email fails (e.g., the mail server rejects it), the error message often contains the recipient's email address. That raw error gets written into the permanent system logs. It's like having a customer's address accidentally printed on the outside of a sealed envelope — anyone with log access can read it.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::error('Account deletion request mail failed', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        ```
        (Same pattern repeated in `executeConfirmation`, `cancel`, and `adminCancel` methods — three instances total in this file.)
