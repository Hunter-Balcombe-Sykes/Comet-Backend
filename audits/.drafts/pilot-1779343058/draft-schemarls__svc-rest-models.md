- [ ] **SCHEMA-1** · P2 — `AuthFactorEventRepository` writes `created_at` as ISO8601 string rather than Carbon instance
    - **Where:** app/Services/Auth/AuthFactorEventRepository.php:61, 79
    - **Affects:** Query planner on `core.auth_factor_events` — time-range scans in `countRecentFailures()`. If the column is `TIMESTAMPTZ` Postgres handles the string cast correctly; if it was created as `TEXT` (plausible given the unusual insert pattern), the index on `created_at` would be unusable for `>=` comparisons and every brute-force check would sequential-scan.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify `core.auth_factor_events.created_at` column type in the migration — confirm it is `TIMESTAMPTZ` (or `TIMESTAMP WITH TIME ZONE`), not `TEXT` or `VARCHAR`.
        - If the column is already `TIMESTAMPTZ`, this is a non-issue (Postgres coerces the string). Document the pattern so future readers don't repeat the investigation.
        - If the column is `TEXT`, add a migration to `ALTER COLUMN … TYPE TIMESTAMPTZ USING created_at::timestamptz` and rebuild any indexes that depend on it.
    - **Technical:** Every other model in the codebase lets Eloquent handle `created_at` as a Carbon instance, which the query builder casts to the driver-appropriate format. `AuthFactorEventRepository` bypasses that by calling `now()->toIso8601String()` on insert, and again on the `WHERE created_at >= …` comparison in `countRecentFailures()`. Postgres accepts ISO8601 strings for `TIMESTAMPTZ` columns and uses btree indexes normally, so this is benign IF the column type is correct. The risk is that the column was defined as `TEXT` during prototyping — a string comparison `'2026-05-20T10:00:00+00:00' >= '2026-05-20T09:55:00+00:00'` is lexicographically correct but cannot use a btree index on a `TEXT` column for range scans, causing a full sequential scan on every MFA verification-hook request.
    - **Plain English:** Imagine you write all your appointment times in a notebook using complete sentences like "May 20th at 10:00 AM." If the notebook's index tabs are labeled for dates, that works fine. But if you accidentally filed those pages under "M" for "May" instead of a real date index, every time you need to find "appointments in the last 5 minutes" you have to read the whole notebook. This finding asks us to check whether the notebook is filed under real dates or just text — the code writes in full sentences, which is unusual and worth verifying.
    - **Evidence:**
        ```php
        // Insert in record():
        'created_at' => now()->toIso8601String(),

        // Query in countRecentFailures():
        ->where('created_at', '>=', now()->subSeconds($windowSeconds)->toIso8601String())
        ```
    - `[DRAFT, confidence: 0.55]`

- [ ] **SCHEMA-2** · P3 — `LeadSubmission::$fillable` includes `form_started_at_ms` without a clarifying comment; name implies timestamp but suffix `_ms` suggests duration
    - **Where:** app/Models/Analytics/LeadSubmission.php:20
    - **Affects:** Future developers reading the schema or writing analytics queries — ambiguity about whether this is an epoch-millisecond timestamp or a duration in milliseconds.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a PHPDoc comment on the `$fillable` array clarifying whether `form_started_at_ms` is an epoch timestamp (e.g. `1716240000000`) or a measured duration (e.g. `4200` ms spent filling in the form).
        - If it's a duration, rename to `form_duration_ms` in a migration to match the `*_ms` suffix convention for durations.
    - **Technical:** The `_at` suffix in the codebase conventionally denotes a point-in-time (`occurred_at`, `created_at`, `deleted_at`). The `_ms` suffix suggests milliseconds. Together they conflict: `*_at_ms` could mean "timestamp in milliseconds since epoch" or "milliseconds spent on the form." The column has no `$casts` entry, so Eloquent treats it as a raw value — a reader cannot infer the semantics from the model alone. This is a schema-design clarity gap, not a runtime bug.
    - **Plain English:** A column called `form_started_at_ms` is like labeling a box "date-duration." Anyone opening the box later has to guess whether it contains a calendar date written in milliseconds or a stopwatch reading. A quick comment or renaming would make it obvious.
    - **Evidence:**
        ```php
        protected $fillable = [
            'occurred_at',
            'subdomain',
            'site_id',
            'professional_id',
            'customer_id',
            'ip_hash',
            'user_agent',
            'referrer',
            'outcome',
            'form_started_at_ms',   // ← ambiguous name
        ];
        ```
    - `[DRAFT, confidence: 0.8]`
