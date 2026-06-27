# Semantic Correctness Audit — 2026-06-13

**Branch:** development
**Lens:** Semantic Correctness — code that compiles and type-checks but does the wrong thing
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Services/User/AccountDeletionService.php`
- `app/Services/User/SiteProvisioningService.php`
- `app/Services/User/DataExport/DataExportPayloadBuilder.php`
- `app/Services/Auth/SupabaseAdminService.php`
- `app/Services/Moderation/ContentReportService.php`
- `app/Http/Controllers/Api/User/Account/MfaController.php`
- `config/supabase.php`

> **Dropped:** Draft SEM-2 (`unenrollMfaFactor` config key) — `config('supabase.admin.base_url')` is a valid key defined in `config/supabase.php:38` with default `{SUPABASE_URL}/auth/v1/admin`. The method's URL path `/users/{id}/factors/{factorId}` is correct relative to that compound base URL. The `$baseUrl === ''` guard is redundant (constructor already validates `SUPABASE_URL`) but not a bug. Finding drops on evidence.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEM-1** · P1 — GDPR Article 15 miss: `reporter_email` stored without normalisation but queried with lowercased input
    - **Where:** `app/Services/User/DataExport/DataExportPayloadBuilder.php:462`; root write at `app/Services/Moderation/ContentReportService.php:79` via `app/Http/Requests/PublicSite/PublicReportRequest.php:45`
    - **Affects:** Any professional who filed a content report using a mixed-case email address (e.g. `Reporter@Example.com`). Those signals are silently omitted from their GDPR Article 15 data export, leaving the person unaware that Partna holds them. Also affects GDPR Article 17 erasure: `AccountDeletionService::purgeCaseSignalPii()` (line 680) targets `reporter_user_id`, so unauthenticated reports keyed only by email rely on the same lookup path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `streamContentReports()`, replace the case-sensitive `->orWhere('reporter_email', $emailLc)` with `->orWhereRaw('LOWER(reporter_email) = ?', [$emailLc])`.
        - Ideally also add a `LOWER()` index on `moderation.case_signals.reporter_email` in a Supabase migration for query performance, but this is not blocking.
        - Add a test: create a `case_signals` row with `reporter_email = 'Reporter@Example.com'`, run `streamContentReports` with lookup email `reporter@example.com`, and assert the row is present in the output.
    - **Technical:** `PublicReportRequest::toDto()` passes `$this->input('reporter_email')` directly into the `PublicReportDto` — no lowercasing applied. `ContentReportService::submit()` stores it verbatim: `'reporter_email' => $dto->reporterEmail` (line 79). At export time, `normaliseEmail()` lowercases the lookup value, then the query does `->orWhere('reporter_email', $emailLc)` — a case-sensitive PostgreSQL equality comparison. A row with `Reporter@Example.com` never matches `reporter@example.com`. Contrast with the correctly-designed `email_lc` columns elsewhere in the same class (e.g. `->where('email_lc', $emailLc)` in `streamEmailSubscriptions`), which use a dedicated lowercase-constrained column. The `reporter_email` column has no such constraint. Category (1) — wrong contract: the query treats `reporter_email` as if it were an `_lc` column, but it isn't.
    - **Plain English:** Imagine you filled out a complaint form at a shop and wrote your email address as `Reporter@Example.com`. Later you ask the shop "what information do you hold about me?" Their system searches for `reporter@example.com` — lowercase — but your complaint is filed under `Reporter@Example.com`. They tell you they have nothing on record. Your data is there; they just can't find it. Data protection law requires they hand over everything they hold.
    - **Evidence:**
        ```php
        // PublicReportRequest.php:45 — email stored without lowercasing
        reporterEmail: $this->input('reporter_email'),

        // ContentReportService.php:79 — written verbatim to DB
        'reporter_email' => $dto->reporterEmail,

        // DataExportPayloadBuilder.php:460-463 — queried with lowercase vs. potentially mixed-case column
        $emailLc = $this->normaliseEmail($lookupEmail);   // lowercased
        if ($emailLc !== null) {
            $query = $query->orWhere('reporter_email', $emailLc);  // case-sensitive miss
        }

        // Correct pattern used elsewhere in the same class (streamEmailSubscriptions):
        $q2->where('email_lc', $emailLc)   // email_lc is constrained lowercase
        ```

---

## P2 — Should fix

- [ ] **#SEM-2** · P2 — `adminCancel()` and `cancel()` use bare `DB::transaction()` breaking the pgsql-pinned transaction contract
    - **Where:** `app/Services/User/AccountDeletionService.php:357` (`adminCancel`) and `app/Services/User/AccountDeletionService.php:430` (`cancel`)
    - **Affects:** Admin-initiated and self-service cancellation during the 30-day deletion grace period. In test environments the transaction wrapper targets the sqlite connection (the suite's default), making it a no-op for the Eloquent writes and `lockForUpdate` on pgsql — rollback on failure is silently lost. In production (where the default connection is pgsql) this works by coincidence, but it creates a silent inconsistency that will break if connection topology changes (read replica, second DB, etc.).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In both `adminCancel()` and `cancel()`, replace `DB::transaction(function () ...` with `DB::connection('pgsql')->transaction(function () ...`.
        - This matches the explicit, documented pattern already used in `request()` (line 74) and `executeConfirmation()` (line 189) in the same class.
    - **Technical:** The `request()` method carries a comment (lines 69–73) explaining exactly why `DB::connection('pgsql')` is required: models extending `BaseModel` force the pgsql connection, and bare `DB::transaction()` targets the default connection, which is `sqlite` in the feature test suite. Both `adminCancel()` and `cancel()` include `Site::query()->lockForUpdate()` calls inside the transaction that must be on the same connection as the `BEGIN` to actually acquire a row lock. Because the `Site` model extends `BaseModel` (pgsql), the lock succeeds in production regardless of the transaction wrapper's connection, but the rollback protection is broken in test environments. Category (5) — codebase-idiom drift: the correct idiom is documented and used in the sibling methods, but not followed here.
    - **Plain English:** This service has a careful rule: when doing sensitive operations on the database, always use a named database connection so that if something goes wrong mid-operation, everything rolls back together. Two of the cancel operations follow a different, looser pattern that accidentally uses the right database in production but the wrong one during automated testing — so if a failure happens partway through a cancellation in a test, the partial changes don't get cleaned up. Production works today, but it's a trap waiting to trip up a future change.
    - **Evidence:**
        ```php
        // request() — documented correct pattern (line 74):
        // "Pin the transaction to 'pgsql' explicitly so it shares the connection
        //  with the Eloquent writes inside ... Using bare DB::transaction() would
        //  target the default connection, which is 'sqlite' in feature tests —
        //  making the wrapper a no-op and breaking rollback."
        DB::connection('pgsql')->transaction(function () use (...) { ... });

        // adminCancel() — violates the documented pattern (line 357):
        DB::transaction(function () use ($professional, $previousStatus) {
            $professional->update([...]);
            $site = Site::query()->where('user_id', $professional->id)->lockForUpdate()->first();
            ...
        });

        // cancel() — same violation (line 430):
        DB::transaction(function () use ($professional, $previousStatus) {
            $professional->update([...]);
            $site = Site::query()->where('user_id', $professional->id)->lockForUpdate()->first();
            ...
        });
        ```

- [ ] **#SEM-3** · P2 — `SiteProvisioningService::tryCreateSite()` uses bare `DB::transaction()` for a savepoint that must be on the pgsql connection
    - **Where:** `app/Services/User/SiteProvisioningService.php:100`
    - **Affects:** Signup flow subdomain allocation (`UserBootstrapService`). The savepoint strategy exists specifically to catch PostgreSQL `23505` unique-constraint violations without poisoning the outer signup transaction. In test environments (sqlite default), the nested `DB::transaction()` wraps sqlite rather than pgsql, so no savepoint is created on the pgsql connection; a unique-constraint violation would abort the outer pgsql transaction. In production (default=pgsql), both connections resolve to the same handle and nesting works correctly — the bug is latent and test-invisible.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `return DB::transaction(function () ...` to `return DB::connection('pgsql')->transaction(function () ...` inside `tryCreateSite()`.
        - Enable or extend `SiteProvisioningSavepointTest` (currently gated to real pgsql) to cover this path.
    - **Technical:** The method's own comment (lines 88–99) explains that a nested transaction is used to emit a `SAVEPOINT`/`ROLLBACK TO SAVEPOINT` pair on pgsql so that a `23505` violation doesn't abort the outer signup transaction. The comment also documents that "SQLite doesn't abort on statement error, which is why this bug is invisible in the SQLite test suite." The irony is that the fix itself uses the bare `DB::transaction()` the pattern comment warns against elsewhere in the codebase. `UserBootstrapService` pins its outer transaction to `DB::connection('pgsql')` — for savepoint nesting to work, the inner call must resolve to the same connection object. Category (5) — codebase-idiom drift: the correct idiom is used by `UserBootstrapService` (outer) and documented in `AccountDeletionService`, but not applied to the inner call here.
    - **Plain English:** The signup system has a smart trick to avoid a nasty failure: when it tries to claim a subdomain and finds a conflict, it uses a database "bookmark" (called a savepoint) so it can undo just that one step and try a different name — without cancelling the whole signup. The bookmark is being set on the wrong database connection, like putting a sticky note in the wrong book. In real usage it works by luck because both "books" happen to be the same database. But in automated tests, they're different books, and the trick silently stops working. The fix is to make sure the bookmark always goes in the right place.
    - **Evidence:**
        ```php
        // tryCreateSite() — uses bare DB::transaction(), not DB::connection('pgsql')->transaction()
        return DB::transaction(function () use ($userId, $candidate) {
            $site = new Site([
                'subdomain' => $candidate,
                'is_published' => true,
                'settings' => [],
            ]);
            $site->user_id = $userId;
            $site->save();
            return $site;
        });

        // Comment in the same method documenting WHY this should be pgsql-pinned
        // (lines 88–99):
        // "SQLite doesn't abort on statement error, which is why this bug is
        //  invisible in the SQLite test suite (see SiteProvisioningSavepointTest,
        //  gated to real pgsql)."
        ```
