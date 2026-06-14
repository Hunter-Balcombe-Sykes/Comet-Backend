
<!-- ═══ LENS: semantic-correctness | CHUNK: core-services ═══ -->

- [ ] **#SEM-1** · P2 — `adminCancel()` and `cancel()` use bare `DB::transaction()` instead of the explicit pgsql connection required by the project's transaction contract
    - **Where:** app/Services/User/AccountDeletionService.php:246, app/Services/User/AccountDeletionService.php:293
    - **Affects:** Admin-initiated and self-service cancellation flows; in test environments the transaction may not wrap the Eloquent writes, leading to incomplete rollback on failure.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `DB::transaction(...)` with `DB::connection('pgsql')->transaction(...)` in both `adminCancel()` and `cancel()`.
        - Add a static-analysis rule or CI guard to detect bare `DB::transaction()` calls inside services that write to pgsql models.
    - **Technical:** The `request()` method inside the same class explicitly uses `DB::connection('pgsql')->transaction()` and documents that bare `DB::transaction()` targets the default connection (which is `sqlite` in feature tests) and therefore “makes the wrapper a no-op and breaks rollback.” Both cancellation methods violate this contract, making them susceptible to the same rollback breakage.
    - **Plain English:** Imagine a safety deposit box that is supposed to lock two vaults together. The main flow uses a special key that locks both vaults at once, but the cancel path uses a regular key that only locks the front door. If something goes wrong mid-operation, only half of the valuables get re-secured. This is an inconsistency in the codebase — all other sensitive paths use the proper key.
    - **Evidence:**
        ```php
        // request() — correct pattern
        DB::connection('pgsql')->transaction(function () use (...) { ... });

        // adminCancel() — incorrect
        DB::transaction(function () use ($professional, $previousStatus) { ... });

        // cancel() — incorrect
        DB::transaction(function () use ($professional, $previousStatus) { ... });
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEM-2** · P1 — `SupabaseAdminService::unenrollMfaFactor()` reads a non-existent config key, causing all MFA unenrollment attempts to fail
    - **Where:** app/Services/Auth/SupabaseAdminService.php:210 (inside `unenrollMfaFactor`)
    - **Affects:** Staff or support-initiated MFA factor removal for a user. The endpoint will always throw “Supabase admin config missing” even when the Supabase connection is fully configured.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `config('supabase.admin.base_url')` with `$this->baseUrl` (already resolved from `config('supabase.url')` in the constructor).
        - Remove the redundant re-reading of `service_role_key`; use `$this->serviceRoleKey`.
        - Add a test that calls `unenrollMfaFactor` and verifies the correct URL is called.
    - **Technical:** The constructor sets `$this->baseUrl` from `config('supabase.url')` and `$this->serviceRoleKey` from `config('supabase.service_role_key')`. Every other method (`createUser`, `findUserByEmail`) builds URLs using `$this->baseUrl`. The `unenrollMfaFactor` method ignores both instance properties and instead reads `config('supabase.admin.base_url')`, a key that does not exist in the project’s config structure (only `supabase.url` and `supabase.service_role_key` are defined and checked for emptiness in the constructor). Because `config()` returns null for missing keys, the method always sets `$baseUrl` to an empty string and immediately throws a RuntimeException.
    - **Plain English:** Imagine a keycard reader at an office that always works except for one door. The builder installed a different reader on that door that looks for a card format the company doesn’t issue. Every badge swipe is rejected instantly, and nobody can get through that door. The fix is to make that reader accept the same cards as every other door.
    - **Evidence:**
        ```php
        // Constructor: stores correct base URL
        $this->baseUrl = rtrim((string) config('supabase.url'), '/');

        // unenrollMfaFactor: reads a different, non-existent key
        $baseUrl = rtrim((string) config('supabase.admin.base_url'), '/');
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SEM-3** · P2 — `SiteProvisioningService::tryCreateSite()` uses a bare `DB::transaction()` for savepoint emulation, which breaks when the default connection is not pgsql
    - **Where:** app/Services/User/SiteProvisioningService.php:108
    - **Affects:** Any signup flow that triggers subdomain allocation (UserBootstrapService). Under non-pgsql default connections (e.g., sqlite in tests) a unique-constraint violation on the wrong connection can abort the outer transaction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `DB::transaction(function () ...)` to `DB::connection('pgsql')->transaction(function () ...)` inside `tryCreateSite()`.
        - Enable the gated `SiteProvisioningSavepointTest` for the real pgsql environment in CI to prevent regression.
    - **Technical:** The method’s own comment explains that it uses a nested transaction to emit a `SAVEPOINT` on the pgsql connection so that a unique-violation (23505) can be caught without poisoning the outer signup transaction. It then explicitly warns: “Using bare `DB::transaction()` would target the default connection, which is 'sqlite' in feature tests — making the wrapper a no-op and breaking rollback.” The code currently uses bare `DB::transaction()`, contradicting its own warning and leaving the savepoint on the default connection, which is not the same connection the outer transaction in `UserBootstrapService` uses (it pins to `'pgsql'`). In production the default connection is also pgsql, so the bug is latent, but it represents a drift from the established transaction pattern and a lie to the test suite.
    - **Plain English:** The signup process has a safety net for when two people accidentally pick the same subdomain. That safety net is hooked up to the wrong wire — it would work in the real system by coincidence, but in tests it’s disconnected entirely. The developer even left a note saying “this will break if we ever test with the real database.” Fixing it makes the safety net bulletproof everywhere.
    - **Evidence:**
        ```php
        // Inside tryCreateSite():
        return DB::transaction(function () use ($userId, $candidate) {
            $site = new Site([...]);
            $site->user_id = $userId;
            $site->save();
            return $site;
        });
        ```
        Comment in the same class: “Using bare DB::transaction() would target the default connection, which is 'sqlite' in feature tests — making the wrapper a no-op and breaking rollback.”
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEM-4** · P1 — `DataExportPayloadBuilder::streamContentReports()` uses a case-sensitive equality comparison on `reporter_email` after lowercasing the lookup value
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php:324 (inside `streamContentReports`)
    - **Affects:** GDPR Article 15 data exports for professionals who filed moderation signals with a mixed-case email address. Those signals will be silently omitted from the export, leaving the professional unaware of that data.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `->orWhere('reporter_email', $emailLc)` with `->orWhereRaw('LOWER(reporter_email) = ?', [$emailLc])`.
        - Add a test with a mixed-case `reporter_email` row and verify it appears in the export.
    - **Technical:** The helper `normaliseEmail()` lowercases the input and the method uses this lowercased value (`$emailLc`) in a strict `WHERE reporter_email = ...`. PostgreSQL string comparison is case-sensitive; unless the column itself is constrained to store only lowercased values, a row with `Reporter@Example.com` will not match `reporter@example.com`. The same class correctly uses `where('email_lc', $emailLc)` for columns that are explicitly lowercased in the database, indicating the developer intended to match case-insensitively but forgot to handle the `reporter_email` column’s actual casing.
    - **Plain English:** Imagine you’re pulling all the letters you ever wrote from a filing cabinet. You search for them using your name in all lowercase, but the cabinet keeps some letters with the original capitalisation. Those letters get skipped, and you never know they existed. The fix is to tell the cabinet “ignore case when you compare,” just like we do for other pieces of mail.
    - **Evidence:**
        ```php
        $emailLc = $this->normaliseEmail($lookupEmail);
        // ...
        $query = DB::connection('pgsql')
            ->table('moderation.case_signals')
            ->select(...)
            ->where('reporter_user_id', $userId);

        if ($emailLc !== null) {
            $query = $query->orWhere('reporter_email', $emailLc);
        }
        ```
        Compare with correct lowercased-column usage in the same class:
        ```php
        ->where('email_lc', $emailLc)   // email_lc is always lowercased
        ```
    - `[DRAFT, confidence: 0.85]`

<!-- ═══ LENS: semantic-correctness | CHUNK: jobs-controllers ═══ -->

No semantic correctness issues were found in the provided files. All code adheres to the expected contracts, configuration usage, logic, and project idioms without evidence of plausible-but-wrong behavior.
