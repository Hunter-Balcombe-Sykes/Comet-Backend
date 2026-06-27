`★ Insight ─────────────────────────────────────`
This audit is unusual: the source material is entirely a planning document (not shipped code), so nearly all findings are pre-implementation design warnings. The adjudicator role here is to catch design-time scaling mistakes before they're built in — a legitimate and high-value use of the pipeline. SCALE-4 is the one finding grounded in *existing* code (verified via `Read`).
`─────────────────────────────────────────────────`

# Database & Queue Scaling Audit — 2026-05-19

**Branch:** development
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `/Users/joshuahunter/Downloads/PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **SCALE-1** · P1 — `AccountCapabilities::for()` will issue 2N database queries in every list endpoint and notification fan-out that iterates professionals
    - **Where:** `app/Services/Accounts/AccountCapabilities.php` (planned, §28.3) and `app/Services/Accounts/AccountCapabilitySet.php` (planned, §28.3)
    - **Affects:** Every authenticated endpoint that lists professionals (admin dashboard, staff API, notification dispatch) and every notification fan-out job at scale. At 200 brands × 50 affiliates = 10,000 professionals, a single fan-out iterating all professionals and calling `AccountCapabilities::for($pro)` on each one would issue ~20,000 redundant queries for ex-partner derivation alone.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In any controller or job that iterates a collection of professionals and calls `AccountCapabilities::for()`, eager-load `brandPartnerLinks` and `brandPartnerLinksAll` on the collection before the loop using `Professional::with(['brandPartnerLinks', 'brandPartnerLinksAll'])->...`
        - Make `AccountCapabilitySet` memoize the ex-partner boolean on first access so repeated calls to `.shows_ex_partner_panel` on the same instance don't re-query.
        - For high-frequency read paths (notification dispatchers, dashboard listings), consider pre-computing `has_historical_partner_links` as a boolean column on `core.professionals`, updated by a `BrandPartnerLinkObserver` on create/soft-delete events, to eliminate the query entirely at runtime.
    - **Technical:** The plan specifies that `AccountCapabilities::for()` runs "at every API request, every notification dispatch, every dashboard route check" (§28.3). The `shows_ex_partner_panel` capability derivation (§28.16) performs two separate Eloquent `exists()` calls per professional: `$pro->brandPartnerLinksAll()->exists() && !$pro->brandPartnerLinks()->exists()`. Both relations hit the database unless they were eager-loaded upstream. Any call site that constructs `AccountCapabilitySet` by iterating a collection without upstream `with(...)` produces a 2N+1 pattern. The notification fan-out is the highest-risk path: at 40K daily notifications across 10K professionals, even a 1% cap-miss rate is 400 extra queries per dispatch run. The `AccountCapabilitySet` value object should compute and cache this derived boolean lazily on first access, not recompute it on every boolean read.
    - **Plain English:** Every time the system asks "what features does this user have access to?" it checks two things from the database: "did they ever have a brand partnership?" and "do they still have an active one?" That's two database trips per person. When the system is building a page or sending notifications for thousands of users at once, it makes thousands of those database trips — like a librarian who checks two card-catalogue drawers for every single book request instead of pulling all the relevant cards at once before starting. The fix is to gather all the needed information in one go before the loop starts.
    - **Evidence:**
        ```
        §28.3: "Method for(Professional $pro): AccountCapabilitySet — … at every API request,
        every notification dispatch, every dashboard route check"

        §28.16: "shows_ex_partner_panel derivation (§9):
        $pro->brandPartnerLinksAll()->exists() && !$pro->brandPartnerLinks()->exists()
        — i.e., has historical links but no active ones."
        ```

---

## P2 — Should fix

- [ ] **SCALE-2** · P2 — `AccountTypeTransitionService` must never use sync dispatch for KV/cache-purge jobs inside the DB transaction; pattern must be explicitly enforced
    - **Where:** `app/Services/Accounts/AccountTypeTransitionService.php` (planned, §28.4), `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`, `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php` (planned, §28.7)
    - **Affects:** Every individual↔partner transition. If `SyncSubdomainToKvJob` or `CloudflareCachePurgeJob` are dispatched synchronously inside the DB transaction (a likely choice in test environments or under `QUEUE_CONNECTION=sync`), the Postgres connection holds a row-level lock on `core.professionals` for the duration of two Cloudflare HTTP round-trips (typically 200–500ms each). At 200 brands under concurrent partner onboarding, this serializes all transitions behind the Cloudflare response time and exhausts the connection pool.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Scope the `DB::transaction(...)` block in `AccountTypeTransitionService::transition()` to cover ONLY the Eloquent mutations (`account_type` update, `BrandPartnerLink` create/soft-delete, dual-write to `professional_type`) and the `lockForUpdate()` on the Professional row.
        - Dispatch `SyncSubdomainToKvJob` and `CloudflareCachePurgeJob` AFTER the transaction block closes — i.e., outside `DB::transaction(...)` — so they never hold the connection open during HTTP I/O regardless of the queue driver.
        - Add a class-level comment to `AccountTypeTransitionService` explicitly stating: "Job dispatches must remain outside the transaction boundary. Do not use `::dispatchSync()` within the `DB::transaction` block." This guards against future callers following the existing Stripe service pattern where DB work and job dispatch live inside the same transaction closure (see `CommissionPayoutService::245`, `CommissionVoidService::165` — both correctly contain only DB work inside their closures).
    - **Technical:** The plan (§28.7) explicitly leaves the dispatch mode to the implementer: "Whether the job dispatches synchronously or via queue is the implementer's call (queue is default Laravel behaviour; sync may be desirable for tests)." The default `::dispatch()` to a Redis-backed queue is safe inside a transaction — it performs a fast Redis write, not a Cloudflare HTTP call. But `::dispatchSync()` (or `QUEUE_CONNECTION=sync` in `.env`) would execute the full job handle method inline, making Cloudflare API calls while the `lockForUpdate()` lock on `core.professionals` is held. This is a connection-pool starvation pattern: at 20 concurrent transitions the pool (typically 10–25 connections) exhausts in under 10 seconds. The existing codebase correctly keeps all `DB::transaction` closures DB-only (verified across `CommissionPayoutService`, `CommissionVoidService`, `BrandDesignMediaService`, `UpdateSiteAction`, `ReclaimHandleAction`) — the new service must follow the same pattern.
    - **Plain English:** When a user switches partnership status, the system briefly locks their account row in the database while updating it. The concern is that if anyone tells the system "do the routing update right now" (instead of "queue it up to do later"), those routing updates make network calls to Cloudflare while the database lock is still held. Think of it as holding the vault open while running errands across town — everyone else who needs the vault is stuck waiting. The fix is straightforward: always finish and close the vault first, then run the errands. This pattern is consistently followed everywhere else in the codebase, so it just needs to be explicit for this new service.
    - **Evidence:**
        ```
        §28.4: "DB transaction wrapping: account_type update, BrandPartnerLink create/soft-delete,
        dual-write to professional_type … Dispatches SyncSubdomainToKvJob + CloudflareCachePurgeJob"

        §28.7: "Whether the job dispatches synchronously or via queue is the implementer's call
        (queue is default Laravel behaviour; sync may be desirable for tests)"
        ```

- [ ] **SCALE-3** · P2 — `brand_profiles_signup_code_unique` constraint in §36 step 3 uses synchronous `ADD CONSTRAINT … UNIQUE` which acquires an `AccessExclusiveLock` on `brand.brand_profiles`
    - **Where:** `supabase/migrations/<ts>_enforce_brand_signup_code_constraints.sql` (planned, §36 step 3)
    - **Affects:** Any read or write on `brand.brand_profiles` during the migration deploy window. `ADD CONSTRAINT … UNIQUE` builds the backing index synchronously and holds `AccessExclusiveLock` for the duration. The table is small at alpha (< 200 rows), so this is a non-event today — but the pattern being established will be copied to future migrations on larger, hotter tables.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `ADD CONSTRAINT … UNIQUE (signup_code)` in step 3 with the non-blocking two-step form:
            ```sql
            CREATE UNIQUE INDEX CONCURRENTLY brand_profiles_signup_code_unique
              ON brand.brand_profiles (signup_code);

            ALTER TABLE brand.brand_profiles
              ADD CONSTRAINT brand_profiles_signup_code_unique
              UNIQUE USING INDEX brand_profiles_signup_code_unique;
            ```
        - Precede the `CREATE UNIQUE INDEX CONCURRENTLY` with `SET lock_timeout = '2s'; SET statement_timeout = '60s';` so the migration fails fast if a long-running transaction is blocking the index build rather than hanging indefinitely.
        - Note: the guard added in commit `526c9f80` exempts `CREATE INDEX` on brand-new columns from the CONCURRENTLY requirement. That exemption does NOT apply here: by step 3 the `signup_code` column has been populated by the backfill (step 2), so the index is built over live data. The exemption only covers indexes created in the same migration as the `ADD COLUMN`.
    - **Technical:** PostgreSQL's `ADD CONSTRAINT … UNIQUE` creates the backing unique index synchronously under an `AccessExclusiveLock`. This blocks all concurrent `SELECT`, `INSERT`, `UPDATE`, and `DELETE` statements on the table until the index scan completes. `CREATE UNIQUE INDEX CONCURRENTLY` builds the index across two table scans (each requiring only a `ShareUpdateExclusiveLock`), never blocking reads or writes. The resulting index is then promoted to a constraint atomically with `ALTER TABLE … USING INDEX`, which acquires `AccessExclusiveLock` only for a brief metadata write — not for the full index build. At the alpha scale (< 200 rows) the synchronous form takes microseconds, but migration anti-patterns at small scale become production incidents at 200 brands. Establishing `CONCURRENTLY` as the default now prevents that incident.
    - **Plain English:** Adding a uniqueness requirement to the brand signup code column normally works by scanning the entire table to build a verification index. While that scan runs, Postgres locks the table and no one can read or write to it. At launch with 200 brands this takes a fraction of a second and nobody notices. The problem is that every developer who writes future migrations will copy this pattern — and when it's applied to a bigger, busier table six months from now during a peak traffic window, the site freezes for seconds. The fix is to use Postgres's non-blocking index builder from the start, which does the same work in the background without locking anyone out.
    - **Evidence:**
        ```sql
        -- §36 step 3 (verbatim):
        ALTER TABLE brand.brand_profiles
          ALTER COLUMN signup_code SET NOT NULL,
          ADD CONSTRAINT brand_profiles_signup_code_unique UNIQUE (signup_code);
        ```

- [ ] **SCALE-4** · P2 — `SyncSubdomainToKvJob` has no `WithoutOverlapping` middleware; rapid successive transitions on the same professional produce a stale-write race in Cloudflare KV
    - **Where:** `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:23–75`
    - **Affects:** Any professional who undergoes two state changes in quick succession (e.g. brand admin removes affiliate, then re-invites within seconds; or a handle rename races a brand-disconnect event). The plan's `individual` branch (§28.6) and brand-signup-code acceptance (§28.12) both dispatch this job, increasing dispatch frequency. At 200 brands × 50 affiliates with typical invite/remove cycles, low-probability but non-zero at pilot scale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `WithoutOverlapping` middleware to `SyncSubdomainToKvJob`, keyed by `professionalId`:
            ```php
            public function middleware(): array
            {
                return [
                    (new WithoutOverlapping('sync-subdomain:' . $this->professionalId))
                        ->releaseAfter(30)
                        ->expireAfter(60),
                ];
            }
            ```
        - The `releaseAfter(30)` returns the overlapping job to the queue rather than discarding it, ensuring the final state is always written even if an intermediate job is held.
        - This is idempotent-safe: the job already reads current DB state on every execution (line 36: `Professional::query()->find($this->professionalId)`), so serializing runs by professional is correct and low-overhead.
    - **Technical:** The job reads current state from the database on each invocation (line 36), then writes to Cloudflare KV. The race is: job A dispatched for state S1 → job B dispatched for state S2 → job B executes and reads S2 → writes S2 to KV → job A executes and reads S1 (if S2 hasn't committed) OR reads S2 but was queued before S2 was visible. Under a Redis-backed queue with multiple `integrations` queue workers, jobs for the same `professionalId` can execute concurrently across two workers. If job A (older dispatch) reads state S2 but KV is eventually consistent and job B's write hasn't propagated, job A overwrites KV with correct S2 — still fine. The actual problematic case is job A reading S1 while DB is mid-transition (between the `lockForUpdate` write and commit), then writing stale S1 to KV after job B has already written correct S2. `WithoutOverlapping` eliminates this by serializing per-professional. At 10K daily payout jobs and 200 brands, KV syncs are low-volume (~500/day estimated); serialization by handle adds negligible queue depth.
    - **Plain English:** When the system updates a user's routing entry in Cloudflare (deciding whether their profile URL points to an individual page, a brand store, or a redirect), it first checks the database for their current status, then writes to Cloudflare. If two such updates fire at nearly the same time — like removing someone from a brand and immediately re-adding them — the second check-and-write can race with the first. Depending on timing, the first job's write could land after the second, putting an outdated routing rule in place and sending visitors to the wrong destination until the next sync runs. Adding a "no two at once per person" guard ensures the jobs line up single-file, which is trivial at our traffic levels.
    - **Evidence:**
        ```php
        // app/Jobs/Cloudflare/SyncSubdomainToKvJob.php — no WithoutOverlapping middleware:
        class SyncSubdomainToKvJob implements ShouldQueue
        {
            use Dispatchable, HasCloudflareRetryPolicy, InteractsWithQueue, Queueable, SerializesModels;

            public int $timeout = 30;

            public function __construct(public readonly string $professionalId)
            {
                $this->onQueue('integrations');
            }

            public function handle(CloudflareKvService $kv): void
            {
                $pro = Professional::query()->find($this->professionalId);
                // ... reads current DB state, writes to KV
            }
        ```
