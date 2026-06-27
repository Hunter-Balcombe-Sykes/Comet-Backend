# Full-Stack Audit — 2026-05-17

**Branch:** development
**Lens:** Full audit across 5 focused themes: security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns / read-side caching (CACHE-*), database/queue scaling — N+1/throughput (SCALE-*), and schema/RLS correctness (SCHEMA-*)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Models/Core/Staff/PartnaStaff.php
- supabase/migrations/20260513200000_harden_audit_tables.sql
- supabase/migrations/CONVENTIONS.md
- docs/superpowers/plans/2026-05-17-staff-audit-log-ops-2.md
- audits/open/audit-2026-05-08-staff-admin-coverage.md

**Adjudication notes:**
- SEC-4, CACHE-1, and SCALE-1 consolidated into SCALE-1 — same root cause (sync DB write in 429 path), different lenses.
- LIFE-4 dropped — confidence 0.6 and structurally identical to SCHEMA-2 (which carries 0.95).
- LIFE-3 dropped — evidence is from an unshipped plan doc, not verifiable against running source; the observability concern is documented in the plan's own design-decisions table.
- SEC-1 re-tiered P0→P2: no controller currently calls `$staff->update($request->validated())` — confirmed by grep across `app/Http/Controllers/Api/Staff/`. Latent risk, not today's attack surface.
- SEC-2 re-tiered P1→P2: no API endpoint currently serialises a raw `PartnaStaff` model to a client response — risk is hardening, not an active leak.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 1 of 1 complete ✓
- P2 Medium: 8 of 8 complete ✓

**Closed 2026-05-18** — SEC-1/2/3/5, LIFE-1/2, SCALE-1, SCHEMA-2 shipped in commit `a4ae23c3` (development). SCHEMA-1 addressed in the OPS-2 plan doc itself (`docs/superpowers/plans/2026-05-17-staff-audit-log-ops-2.md` Task 1.1) — split `FOR INSERT`/`FOR SELECT` policies, explicit `REVOKE UPDATE, DELETE`, and a `BEFORE UPDATE OR DELETE` rejection trigger now wired in so the eventual migration ships safe.

---

## P1 — Fix before pilot launch

- [x] **LIFE-1** · P1 — DB failure converts every 429 response into a 500 — shipped a4ae23c3
    - **Where:** app/Http/Middleware/Logging/LogLeadRateLimits.php:17–30
    - **Affects:** Any public visitor who hits a rate limit during a DB blip — "please slow down" silently becomes a server error. The 429 is never delivered; the client sees 500 and retries, compounding the load.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `LeadSubmission::query()->create([...])` call in a `try/catch (\Throwable $e)` that logs and swallows — the `return $response` with its 429 status must always reach the caller regardless of DB state.
        - Better: move the insert to a `terminate(Request $request, Response $response)` method (Laravel calls `terminate()` after FastCGI flushes the response to the client). The new `RecordStaffAuditEntry` middleware in the OPS-2 plan uses exactly this pattern — apply it here for consistency.
        - Log at `Log::warning('lead.rate_limit_log_failed', ['ip_hash' => ..., 'exception' => $e->getMessage()])` so Nightwatch has a breadcrumb, even though this path won't page on its own.
    - **Technical:** The middleware's `handle()` method calls `$next($request)` to get the response, then synchronously inserts a row before `return $response`. If `LeadSubmission::query()->create()` throws — DB connection drop, constraint violation, disk-full — the exception propagates past `return $response` and Laravel's exception handler replaces the 429 with a 500. The OPS-2 plan's `RecordStaffAuditEntry` documents the canonical fix: "`terminate()` runs in the FPM after-response phase so DB I/O doesn't block the client." The same principle applies here — analytics-log outages must not corrupt rate-limited responses.
    - **Plain English:** When Partna tells a visitor "you're submitting too fast," it first tries to write a note in the database. If the database hiccups at that exact moment, the "slow down" message vanishes and the visitor sees a server error page instead. That's like a bouncer who falls over while writing in his log book, leaving the door unattended. The fix is to deliver the "slow down" message first and write the log entry afterwards, so a dead pen never blocks the door.
    - **Evidence:**
        ```php
        public function handle(Request $request, Closure $next)
        {
            $response = $next($request);

            if ($response->getStatusCode() === 429) {
                $subdomain = (string) ($request->route('subdomain') ?? explode('.', $request->getHost())[0] ?? 'unknown');

                LeadSubmission::query()->create([
                    'occurred_at' => now(),
                    'subdomain' => $subdomain !== '' ? strtolower($subdomain) : null,
                    'ip_hash' => $this->hashIp($request->ip()),
                    'user_agent' => $request->userAgent(),
                    'referrer' => $request->headers->get('referer'),
                    'outcome' => 'rate_limited',
                    'form_started_at_ms' => null,
                ]);
            }

            return $response;
        }
        ```

---

## P2 — Should fix

- [x] **SCHEMA-2** · P2 — FK constraints recreated without `NOT VALID` in `harden_audit_tables.sql` — shipped a4ae23c3
    - **Where:** supabase/migrations/20260513200000_harden_audit_tables.sql (both `wallet_currency_switch_audit` and `brand_status_history` FK recreations)
    - **Affects:** `core.wallet_currency_switch_audit` and `core.brand_status_history` at migration time — Postgres acquires `ACCESS EXCLUSIVE` and validates all existing rows before releasing the lock. At current scale (audit tables have negligible rows) this is harmless. The risk is copy-paste: this migration is the most recent example of FK-add in the codebase, and CONVENTIONS.md §4 explicitly requires `NOT VALID` first.
    - **Effort:** S (~0.5h) — add a comment block explaining the safe deviation, or split into NOT VALID + VALIDATE (two transactions).
    - **What to do:**
        - Preferred (least churn): add a comment above each `ADD CONSTRAINT` explaining why the deviation is safe: `-- Safe: <table> has <N> rows at migration time; ACCESS EXCLUSIVE duration is sub-millisecond. NOT VALID split is unnecessary. For hot commerce.* tables, follow CONVENTIONS.md §4.`
        - Alternatively, split into two files: `..._harden_audit_tables.sql` (NOT VALID) and `..._validate_audit_fks.sql` (VALIDATE CONSTRAINT) — sets the right precedent for copying.
        - Update the CONVENTIONS.md example section to note the "safe deviation" clause for provably tiny tables.
    - **Technical:** CONVENTIONS.md §4 reads: "Foreign key constraints — always `NOT VALID` first." The migration drops the existing `ON DELETE CASCADE` FK and recreates it with `ON DELETE SET NULL` in a single `ADD CONSTRAINT` without `NOT VALID`. Postgres validates all existing rows under `ACCESS EXCLUSIVE` during the FK add. For audit tables with <1K rows at migration time, this completes in microseconds. The real danger is not this migration itself but the next engineer who copies the pattern onto `commerce.orders`. A comment acknowledging the deviation prevents that.
    - **Plain English:** The migration rulebook says "when changing a database rule on a table that already has data, do it in two steps to avoid locking the table." This migration skipped to step two. On these tiny tables it's fine — a 10-page notebook takes a split second to check. The worry is that someone copies this pattern onto a 10-million-row ledger and locks it for minutes. A quick "safe here because the table is tiny" note prevents that copy-paste mistake.
    - **Evidence:**
        ```sql
        ALTER TABLE core.wallet_currency_switch_audit
            DROP CONSTRAINT IF EXISTS wallet_currency_switch_audit_professional_id_fkey;

        ALTER TABLE core.wallet_currency_switch_audit
            ADD CONSTRAINT wallet_currency_switch_audit_professional_id_fkey
            FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;
        --   ^^^ no NOT VALID — full table scan under ACCESS EXCLUSIVE
        ```

- [x] **LIFE-2** · P2 — `LogLeadRateLimits` creates duplicate `LeadSubmission` rows on retried requests — shipped a4ae23c3
    - **Where:** app/Http/Middleware/Logging/LogLeadRateLimits.php:22–30
    - **Affects:** `analytics.lead_submissions` accuracy — a rate-limited browser that auto-retries three times produces three identical rows. "How many rate-limited submissions this week?" overstates the real count by 2–3×.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a short-circuit using the Redis cache before the insert: `if (! Cache::add("partna:rate-limit-logged:{$ipHash}:{$subdomain}", 1, now()->addSeconds(10))) { return; }`. A 10-second dedup window eliminates the most common retry burst without silencing genuinely distinct submissions.
        - Alternatively, add a `UNIQUE` constraint on `(ip_hash, subdomain, date_trunc('minute', occurred_at))` in the analytics schema and catch `UniqueConstraintViolationException` — the canonical idempotency pattern used elsewhere in the codebase.
        - If moving to `terminate()` (per LIFE-1), combine both steps in a single refactor.
    - **Technical:** `occurred_at` uses `now()` which is non-deterministic, so a DB-level unique constraint on `(ip_hash, occurred_at)` won't catch retries within the same minute unless you truncate. A Redis `Cache::add` (atomic SETNX) is simpler: it returns `false` if the key already exists, meaning "we already logged this source in the last 10 seconds." This matches the rate-limiter's own window semantics. Alternatively, a `UNIQUE` index on `(ip_hash, subdomain, date_trunc('minute', occurred_at))` with `ON CONFLICT DO NOTHING` in the insert handles the database-layer dedup — cleaner if the Redis cache is unavailable.
    - **Plain English:** When a browser hits "too fast" and automatically retries the request two more times, the analytics table records three identical rows — making the abuse report look three times worse than it is. It's like a door counter that clicks every time someone pushes a locked door, even if they never get in. The fix is a simple "if we already logged this person in the last 10 seconds, skip it."
    - **Evidence:**
        ```php
        LeadSubmission::query()->create([
            'occurred_at' => now(),
            'subdomain' => $subdomain !== '' ? strtolower($subdomain) : null,
            'ip_hash' => $this->hashIp($request->ip()),
            'user_agent' => $request->userAgent(),
            'referrer' => $request->headers->get('referer'),
            'outcome' => 'rate_limited',
            'form_started_at_ms' => null,
        ]);
        ```

- [x] **SEC-2** · P2 — `PartnaStaff` PII fields are not hidden from model serialization — shipped a4ae23c3
    - **Where:** app/Models/Core/Staff/PartnaStaff.php:23–32
    - **Affects:** Any code path that serializes a `PartnaStaff` instance — an exception handler that dumps `$request->user()`, a log line that calls `$staff->toArray()`, or a future API endpoint that returns `$staff` directly — would broadcast `primary_email`, `name`, and `phone` in plaintext. Staff emails are the Partna admin login identity and should never appear in client payloads or log aggregators.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `'primary_email'`, `'name'`, and `'phone'` to the `$hidden` array alongside the existing `'auth_user_id'`.
        - Any staff-facing API response that legitimately needs these fields (e.g., a staff-profile endpoint) should expose them through a dedicated `StaffResource` class — never by returning the raw model.
    - **Technical:** Laravel's `$hidden` array strips attributes during `toArray()` and `toJson()` serialization, which covers both direct `response()->json($staff)` and indirect paths like exception handlers, Telescope dumps, and log context. Currently only `auth_user_id` is hidden. Adding the three PII fields is a one-line change that eliminates the entire class of "accidentally serialized staff identity" bugs. No downstream breakage risk because no current API endpoint returns a raw `PartnaStaff` model — confirmed by grep across controllers.
    - **Plain English:** The staff account record contains personal information — email, name, phone — but those fields aren't marked as private. If any piece of code accidentally prints a staff record (in an error message, a debug log, or an API response that someone adds later), those details get broadcast. It's like a corporate ID badge that shows your home address on the front. Adding three field names to a "do not display" list solves this permanently.
    - **Evidence:**
        ```php
        protected $hidden = [
            'auth_user_id',
        ];

        protected $fillable = [
            'role',
            'primary_email',
            'name',
            'phone',
        ];
        ```

- [x] **SEC-3** · P2 — Raw `Referer` header stored without length cap or query-string stripping — shipped a4ae23c3
    - **Where:** app/Http/Middleware/Logging/LogLeadRateLimits.php:27
    - **Affects:** `analytics.lead_submissions` table — referrer URLs often contain full query strings with UTM parameters, marketing session tokens, or PII accidentally embedded by third-party tools (e.g., `?email=user@example.com`). Stored raw and indefinitely retained, these rows create GDPR subject-access and data-minimisation obligations.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Strip the query string before storage: `parse_url($request->headers->get('referer'), PHP_URL_SCHEME) . '://' . parse_url(..., PHP_URL_HOST) . parse_url(..., PHP_URL_PATH)` — origin + path only.
        - Cap at 512 characters: `Str::limit($sanitizedReferrer, 512, '')`.
        - Document the analytics column as `varchar(512)` or add an application-enforced comment in the migration so future readers know the field is intentionally truncated.
    - **Technical:** The `Referer` header is entirely attacker-controlled and can carry kilobytes of tracking data. Query strings from email marketing tools (`?mc_cid=`, `?hs_email=`, `?email=`) routinely contain subscriber identifiers — GDPR treats these as personal data. Stripping to origin + path retains the forensic value (which site referred the lead) while eliminating the retention liability. The truncation cap prevents DB row bloat from adversarially crafted headers.
    - **Plain English:** When someone hits a rate limit, Partna logs the website address they came from — including everything after the `?`. Marketing email links often include the recipient's email address or subscriber ID in that tail. Storing all of it creates a hidden list of personal data that triggers GDPR "tell me everything you have about me" requests. The fix is to keep only the website name and page path, and throw away everything after the question mark.
    - **Evidence:**
        ```php
        'referrer' => $request->headers->get('referer'),
        ```

- [x] **SCALE-1** · P2 — Synchronous DB insert in 429 response path adds write-amplification under load — shipped a4ae23c3
    - **Where:** app/Http/Middleware/Logging/LogLeadRateLimits.php:17–30
    - **Affects:** All public endpoints protected by the lead-submission rate limiter — every 429 response waits on a full Eloquent insert cycle (connection checkout → query → TCP round-trip → connection return) before the client receives the status code. Under a bot spike or crawl storm, this turns each throttled response into a DB write under load pressure, consuming connection-pool capacity needed by legitimate traffic.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Move the insert from `handle()` into a `terminate(Request $request, Response $response)` method — Laravel calls `terminate()` after `fastcgi_finish_request()` so the client receives the 429 immediately and the insert happens in the FPM after-image.
        - This fix also resolves LIFE-1 (the 500 crash on DB failure) in one refactor, since a `terminate()` exception can't affect a response already sent.
        - The new `RecordStaffAuditEntry` middleware (OPS-2 plan) uses exactly this pattern — apply it here for consistency across all logging middleware.
    - **Technical:** In Laravel, any middleware class that defines a `terminate(Request $request, Response $response): void` method has it called by the framework kernel after the response is flushed to the client (`fastcgi_finish_request()` or equivalent). Moving the insert there costs nothing — the FPM process is already alive to handle the call — but eliminates the insert from the client-facing request/response cycle entirely. This was independently identified by three lens passes (SEC-4, CACHE-1, SCALE-1), all converging on the same one-method change.
    - **Plain English:** When the system is already overwhelmed and telling visitors "slow down," it pauses to write a note about each blocked request before delivering the message. That write adds extra work at exactly the worst moment. The fix is to deliver the "slow down" instantly and write the note in the background — the same way a bank teller stamps "DECLINED" on your slip immediately, then files the paperwork after you've left the window.
    - **Evidence:**
        ```php
        public function handle(Request $request, Closure $next)
        {
            $response = $next($request);

            if ($response->getStatusCode() === 429) {
                // ...
                LeadSubmission::query()->create([...]);
            }

            return $response;
        }
        ```

- [x] **SCHEMA-1** · P2 — `core.staff_audit_log` RLS policy allows `FOR ALL`, enabling UPDATE/DELETE if grants are ever widened — addressed in OPS-2 plan doc Task 1.1 (split policies + REVOKE + rejection trigger; ships with the OPS-2 migration)
    - **Where:** docs/superpowers/plans/2026-05-17-staff-audit-log-ops-2.md — Task 1.1 migration SQL
    - **Affects:** Audit integrity of `core.staff_audit_log` — the table is designed as "append-only forever," but the RLS policy permits all operations. The baseline migration (`20260403000000_v2_baseline.sql`) grants `SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA core TO app_backend` at the schema level. The table-level `GRANT SELECT, INSERT` does not revoke those schema-level privileges; `app_backend` retains UPDATE and DELETE, and the `FOR ALL` policy permits them. Any code that accidentally issues an `UPDATE` or `DELETE` against `core.staff_audit_log` will succeed silently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the RLS policy from `FOR ALL` to `FOR INSERT` (add a separate `FOR SELECT` policy for the staff-read path).
        - Add an explicit revoke: `REVOKE UPDATE, DELETE ON core.staff_audit_log FROM app_backend;` in the migration, making the intent unambiguous even if schema-level defaults change.
        - Alternatively, add a `BEFORE UPDATE OR DELETE` trigger that raises an exception unconditionally — belt-and-suspenders that survives privilege changes: `CREATE FUNCTION reject_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'staff_audit_log is append-only'; END; $$;`
    - **Technical:** `FOR ALL` in a Postgres RLS policy covers SELECT, INSERT, UPDATE, and DELETE. The migration comments say "append-only forever" and the design doc confirms it. But the schema-level baseline grants mean `app_backend` already has UPDATE/DELETE; the `GRANT SELECT, INSERT` in the migration is additive (the comment calls it "belt-and-suspenders"), not restrictive. The canonical pattern for immutable audit tables is `FOR INSERT` + explicit `REVOKE` + a rejection trigger. `commerce.order_events` uses append-only discipline per the architecture doc; `staff_audit_log` should match.
    - **Plain English:** The staff audit log is supposed to be a permanent, unchangeable record — like a security camera that only ever records and never lets you delete footage. The current setup has good "record-only" intentions, but the camera's erase button isn't physically locked. If someone accidentally grants broader permissions in a future migration, old audit entries could be quietly modified or deleted. The fix is to bolt the erase button shut at the database level so no amount of accidental permission grants can undo it.
    - **Evidence:**
        ```sql
        -- RLS policy allows ALL operations despite design doc calling this "append-only forever"
        CREATE POLICY staff_audit_log_app_backend_all
            ON core.staff_audit_log
            FOR ALL
            TO app_backend
            USING (true)
            WITH CHECK (true);

        -- GRANT is additive, not restrictive — schema-level defaults already include UPDATE/DELETE
        GRANT SELECT, INSERT ON core.staff_audit_log TO app_backend;
        ```

- [x] **SEC-5** · P2 — `PartnaStaff` has no registered Policy, leaving staff-record mutation ungoverned — shipped a4ae23c3
    - **Where:** app/Models/Core/Staff/PartnaStaff.php (no policy exists); app/Providers/AppServiceProvider.php (no `Gate::policy` registration)
    - **Affects:** Any current or future controller that reads or mutates a `PartnaStaff` record — there is no central authorisation gate preventing, for example, a future staff-profile endpoint from allowing one staff member to modify another's attributes. Verified: `app/Policies/` contains 13 policies; `PartnaStaffPolicy` is not among them. No `Gate::policy(PartnaStaff::class, ...)` line exists in `AppServiceProvider`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `app/Policies/PartnaStaffPolicy.php` extending `BasePolicy` with `view` (self only), `update` (admin-only, targeting a different staff member — not self-promotion), and `delete` (admin-only).
        - Register via `Gate::policy(PartnaStaff::class, PartnaStaffPolicy::class)` in `AppServiceProvider::boot()`.
        - The `PolicyCoverageTest` CI sweep will enforce this going forward — add `PartnaStaff` to coverage assertions if it's not already there.
        - Any future controller that writes to a `PartnaStaff` row must call `$this->authorizeForUser($actorStaff, 'update', $targetStaff)` — never inline role checks.
    - **Technical:** Under the Partna authorisation doctrine, every model needs a Policy registered in `AppServiceProvider`. `PartnaStaff` currently has none. Combined with `role` in `$fillable` (SEC-1 below), the absence of a policy means there is no single, testable authorisation surface governing staff record mutations. A `PartnaStaffPolicy` with `update` restricted to admin role and prohibited when `$actor->id === $target->id` provides a structural guard that survives the addition of new staff-management endpoints.
    - **Plain English:** Every other type of account in the system has a rulebook that defines who can change what. The staff account itself has no rulebook — it relies on each individual endpoint remembering to check permissions by hand. That's fragile: the next developer who adds a "edit your staff profile" screen might forget the check. Writing the rulebook once, centrally, means the check is automatic everywhere.
    - **Evidence:**
        ```
        app/Policies/
        ├── AffiliateProductPolicy.php
        ├── BasePolicy.php
        ├── BrandPartnerLinkPolicy.php
        ├── BrandResourcePolicy.php
        ├── CommissionPolicy.php
        ├── CustomerPolicy.php
        ├── GdprPolicy.php
        ├── IntegrationPolicy.php
        ├── NotificationPolicy.php
        ├── ProfessionalSelfPolicy.php
        ├── ServicePolicy.php
        ├── SitePolicy.php
        └── SubscriptionPolicy.php
        // PartnaStaffPolicy.php — does not exist
        ```

- [x] **SEC-1** · P2 — `role` in `PartnaStaff::$fillable` enables silent privilege escalation via mass-assignment — shipped a4ae23c3
    - **Where:** app/Models/Core/Staff/PartnaStaff.php:27–32
    - **Affects:** Any current or future endpoint that calls `$staff->update($request->validated())` or `$staff->fill(...)` with caller-supplied data — if `role` is in the validated array, the ORM will write it without further authorisation checks. No current controller exploits this path (confirmed by grep), but the model-level gate is absent and any staff-management endpoint added during B-series work could activate it unintentionally.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `role` from `$fillable`. Role is not a user-settable attribute; it is an administrative state machine transition.
        - Add a dedicated method for role transitions: `public function promoteToAdmin(): void { $this->role = self::ROLE_ADMIN; $this->save(); }` (called only from gated, audited endpoints, never from a generic update path).
        - If any Form Request currently allows `role` in its `rules()`, remove it and add a test asserting the field is rejected.
    - **Technical:** Laravel's mass-assignment guard is a model-layer control — it blocks `fill()` and `update()` from setting fields not in `$fillable`. `role` being in `$fillable` means any code path that passes a request-derived array to `fill()` or `update()` will set it without a policy gate. Because `PartnaStaff` has no Policy (SEC-5 above), there is no central catch. The `professional_type` field on `Professional` — the structural analogue — is not fillable; type transitions go through dedicated service methods. Apply the same pattern here.
    - **Plain English:** A staff member's "role" field is like a keycard that controls which rooms they can enter. Right now, the database record accepts whatever role value is handed to it through a generic update. If someone adds a "staff profile edit" screen and forgets to strip the role field out, any support-tier staff member could promote themselves to admin by sending `"role": "admin"` in the request. The fix is to treat role changes like a safe combination — only accessible through a specific, purpose-built operation, never through a general-purpose form.
    - **Evidence:**
        ```php
        protected $fillable = [
            'role',
            'primary_email',
            'name',
            'phone',
        ];
        ```
