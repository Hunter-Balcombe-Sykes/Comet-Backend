# Feature Flag System (FF-1) Full Audit — 2026-05-18

**Branch:** development
**Lens:** Full audit across 5 focused themes: security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns / read-side caching (CACHE-*), database/queue scaling — N+1/throughput (SCALE-*), and schema/RLS correctness (SCHEMA-*)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- docs/superpowers/specs/2026-05-17-feature-flag-overrides-design.md
- docs/superpowers/plans/2026-05-17-feature-flag-overrides.md

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-1** · P1 — `resolveStaff` uses wrong request attribute key and falls back to null-returning `$request->user()`
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md:Task 10, `StaffFeatureFlagController` and `StaffFeatureFlagOverrideController`
    - **Affects:** All 7 staff feature-flag admin endpoints. If the code ships as written, `$pro` resolves to `null`, `authorizeForUser(null, ...)` calls `Gate::forUser(null)`, and every policy check silently passes — the admin surface becomes world-accessible to any valid Supabase JWT bearer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$request->attributes->get('professional') ?? $request->user()` with `$request->attributes->get('partna_staff')` — the exact pattern every existing staff controller uses.
        - Add a hard `abort(401)` if the result is null (fail-closed); never fall back to `$request->user()`, which always returns null under Supabase JWT.
        - The plan's Task 10 Step 1 says "read StaffCommissionAdjustmentController for the shape" — doing so would reveal the correct key immediately. Enforce that step before the controller is written.
    - **Technical:** Every existing staff controller in the project resolves the authenticated actor with `$request->attributes->get('partna_staff')` (confirmed via `app/Http/Controllers/Api/Staff/**`). The plan's stand-in `resolveStaff` uses `'professional'` (wrong key, returns null) and then falls back to `$request->user()` (also null under Supabase JWT per Partna's authorization doctrine). A null `$pro` passed to `$this->authorizeForUser(null, 'manage', FeatureFlag::class)` invokes `Gate::forUser(null)`, which silently passes all policy checks in Laravel — every staff endpoint becomes open. The plan flags this risk in a comment ("Adjust this line after reading StaffCommissionAdjustmentController") but if the stand-in is copied verbatim, the comment is invisible to a reviewer scanning the final PR.
    - **Plain English:** The plan includes a placeholder for "who is this staff member?" that references the wrong storage slot and then falls back to a "check anyone logged in" approach. In this app, "anyone logged in" returns nothing — so the system would skip the identity check entirely and approve every action. It's a bank vault that opens for anyone who waves any card in front of it. The one-line fix — look up the right attribute name from the files already in the codebase — was the plan's own stated first step.
    - **Evidence:**
        ```php
        private function resolveStaff(Request $request)
        {
            // Match the existing pattern used in staff controllers.
            // Most staff controllers use $request->user('professional') or a context middleware.
            // Adjust this line after reading StaffCommissionAdjustmentController in Step 1.
            return $request->attributes->get('professional') ?? $request->user();
        }
        ```
        Correct pattern from the real codebase (`StaffCommissionAdjustmentController.php:34`, confirmed):
        ```php
        $staff = $request->attributes->get('partna_staff');
        ```

- [ ] **#SCHEMA-1** · P1 — Migration FK targets `brand.brands` but the schema uses `brand.brand_profiles` — migration will fail on push
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md:Task 1 Step 1, `supabase/migrations/202605180000000_create_feature_flags.sql`
    - **Affects:** Deployment — `supabase db push` will fail at the `CREATE TABLE core.feature_flag_overrides` statement, rolling back both migrations. No feature flag tables get created.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `REFERENCES brand.brands(id)` to `REFERENCES brand.brand_professionals(id)` — or whatever the correct FK target is; verify with `\dt brand.*` in Supabase SQL editor before writing the migration.
        - The plan already includes a Task 1 Step 3 verification step (`rg -n "create table brand\.brands"`) — run it before writing the DDL, not after.
        - Add a comment to the FK line in the final migration recording the verified table name so future migrations don't repeat the lookup.
    - **Technical:** Inspection of `supabase/migrations/` shows the `brand` schema consistently uses `brand.brand_profiles` (e.g. `20260505000000_redesign_brand_status_stages.sql`, `20260420200000_add_rls_to_remaining_tables.sql`). The name `brand.brands` appears nowhere in the migration history. PostgreSQL will reject the FK constraint at DDL time with a relation-does-not-exist error, rolling back the `BEGIN` transaction and leaving the schema unchanged. The plan itself acknowledges this risk in a `> Note:` block in Task 1 Step 3 — the gap is that the verification step is listed *after* the DDL is already written, rather than gating it.
    - **Plain English:** The migration is like writing a cheque payable to "Brands Inc." when the company's legal name is "Brand Profiles Inc." The bank (PostgreSQL) rejects it outright — nothing gets deposited. The plan even notes "check the name first," but buries the check step after the cheque is already written. Swap the name, push, and everything proceeds normally.
    - **Evidence:**
        ```sql
        CREATE TABLE core.feature_flag_overrides (
            id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            flag_key text NOT NULL REFERENCES core.feature_flags(key) ON DELETE CASCADE,
            professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
            brand_id uuid NULL REFERENCES brand.brands(id) ON DELETE CASCADE,
        ```
        Migrations confirm `brand.brand_profiles` is the actual table:
        ```sql
        -- 20260505000000_redesign_brand_status_stages.sql
        ALTER TABLE brand.brand_profiles ...
        -- 20260420200000_add_rls_to_remaining_tables.sql
        ALTER TABLE brand.brand_profiles ENABLE ROW LEVEL SECURITY;
        ```

- [ ] **#CACHE-1** · P1 — Feature flag cache uses plain `Cache::remember` (no single-flight lock, no SWR) despite spec promising both
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md:Task 5 Step 4, `loadRegistry()`, `loadProOverrides()`, `loadBrandOverrides()`
    - **Affects:** Every authenticated request that checks a feature flag. On cold cache (deploy, Redis eviction, 5-minute expiry), concurrent requests race to repopulate three independent keys with identical DB queries. The spec also explicitly promises SWR (stale-while-revalidate) but the implementation blocks callers on every miss.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace all three `Cache::remember(...)` calls with `CacheLockService::rememberLocked(...)` — the same service already deployed in commerce analytics. This provides single-flight (one worker rebuilds, others wait) and SWR (stale value returned to all callers while the winner refreshes in background).
        - The spec explicitly says "Resolver fetches all relevant keys in a single Redis `mget`, resolves in memory" — implement this as well once the lock strategy is in place; three sequential `Cache::get` calls become one batched `Redis::mget([...])`.
        - Inject `CacheLockService` into `FeatureFlagService` constructor.
    - **Technical:** The commerce read-cache uses `CacheLockService::rememberLocked` with SWR specifically to prevent stampede and to eliminate visible latency on cache miss. `Cache::remember` has no locking — on a cold `ff:registry` key (shared across all tenants), N concurrent requests each call the closure, spawning N `FeatureFlag::all()` queries simultaneously. At 200 brands × 50 affiliates, even a moderate request burst during a deploy can generate 50–200 simultaneous DB hits on what should be a single-row registry lookup. The SWR gap means every expiry also blocks callers for the full DB roundtrip (5–50ms) rather than serving the stale value immediately. Both gaps are fixed by the same `CacheLockService::rememberLocked` call already in use elsewhere.
    - **Plain English:** The spec promises a "one fast lookup, serve stale while refreshing" design — like a restaurant that serves yesterday's menu while printing tonight's, rather than making guests wait while the printer runs. The implementation instead opens the kitchen to every guest simultaneously when the menu is out of date. The fix (using the caching tool already in the commerce system) closes the kitchen door and serves the old menu automatically while printing quietly in the background.
    - **Evidence:**
        ```php
        // Spec:
        // "Resolver fetches all relevant keys in a single Redis mget, resolves in memory.
        //  Hot path: 1 Redis roundtrip, 0 DB queries."
        // "TTL is a backstop. Pattern matches the commerce read-cache approach already documented in CLAUDE.md."

        // Implementation (three blocking Cache::remember calls, no lock, no SWR):
        private function loadRegistry(): array
        {
            return Cache::remember(self::REGISTRY_KEY, self::CACHE_TTL_SECONDS, function (): array {
                return FeatureFlag::all()
                    ->mapWithKeys(fn ($f) => [$f->key => [
                        'default_enabled' => (bool) $f->default_enabled,
                        'rollout_percent' => (int) $f->rollout_percent,
                    ]])
                    ->all();
            });
        }
        ```

---

## P2 — Should fix

- [ ] **#CACHE-2** · P2 — Fixed 300-second TTL with no jitter causes synchronized key expiry and thundering herd
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md:Task 5 Step 4, `CACHE_TTL_SECONDS = 300`
    - **Affects:** All three Redis keys (`ff:registry`, `ff:pro:{id}`, `ff:brand:{id}`). If warmed during the same deploy or request flow, all keys expire simultaneously 5 minutes later, concentrating DB load into a single second.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply ±20% jitter: `300 + random_int(-60, 60)` per key write, matching the commerce cache pattern.
        - Or use a deterministic spread: `300 + (abs(crc32($cacheKey)) % 120)` to ensure keys naturally drift apart over successive write cycles without per-request randomness.
    - **Technical:** The commerce analytics cache uses jittered TTL specifically to prevent synchronized expiry. When all hot keys expire at the same instant, the next request for each triggers a synchronous miss + repopulation. With three keys potentially loaded in a single request flow during a deploy warm-up, they expire 300 seconds later in lockstep, even with `rememberLocked` in place (lock serializes per-key, not across all keys). Jitter is a one-liner that permanently eliminates the synchronized expiry pattern.
    - **Plain English:** Setting all three timers to exactly 5 minutes means they all ring together, and you have to cook everything at once. Staggering them by a random 30–60 seconds each means they never all ring simultaneously again. The commerce system already does this; copying the same one-liner here is free insurance.
    - **Evidence:**
        ```php
        private const CACHE_TTL_SECONDS = 300;
        // Used verbatim in all three loadRegistry/loadProOverrides/loadBrandOverrides calls
        // with no jitter applied.
        ```

- [ ] **#LIFE-2** · P2 — `resolveFromDb` duplicates the full resolution logic, creating a silent drift risk on the degraded Redis path
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md:Task 5 Step 4, `FeatureFlagService::resolveFromDb()`
    - **Affects:** Any feature flag resolution during a Redis outage. If the two code paths diverge (e.g. a new scope or precedence step is added to `enabled()` but not to `resolveFromDb()`), tenants silently get wrong feature state during the worst possible time — an active incident.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract a private `resolveFromArrays(string $key, array $brandOverrides, array $proOverrides, array $registry, ?Professional $pro): bool` method that holds the single precedence decision tree.
        - Have `enabled()` populate those arrays from Redis and call it; have `resolveFromDb()` populate them from Eloquent and call the same method.
        - The two paths then differ only in the data-fetch layer, not in the resolution logic.
    - **Technical:** `enabled()` resolves brand → pro → rollout → default from Redis-cached arrays. `resolveFromDb()` repeats the identical sequence directly against Eloquent. When a new scope is added (the spec already anticipates `store_id` via the `project_stores_feature_plan`), a developer will update `enabled()` but may not realise `resolveFromDb()` needs the same change. The bug would only surface during Redis outages — exactly when debugging is hardest and when tenants are most likely to be watching.
    - **Plain English:** There are two copies of the same rulebook — one used during normal operation and one pulled out when the cache goes down. If someone adds a new rule to the main book and forgets to update the backup, the backup gives wrong answers only during emergencies. Merging them into one rulebook that both paths share means you only ever update one place.
    - **Evidence:**
        ```php
        // enabled() resolves inline from cached arrays...
        if (isset($brandOverrides[$key])) { return $brandOverrides[$key]; }
        if (isset($proOverrides[$key])) { return $proOverrides[$key]; }
        // ... rollout hash ... default ...

        // resolveFromDb() repeats the same logic via Eloquent:
        private function resolveFromDb(string $key, ?Professional $pro, ?Brand $brand): bool
        {
            if ($brand !== null) {
                $row = FeatureFlagOverride::where('flag_key', $key)
                    ->where('brand_id', $brand->id)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->first();
                if ($row !== null) { return (bool) $row->enabled; }
            }
            if ($pro !== null) {
                $row = FeatureFlagOverride::where('flag_key', $key)
                    ->where('professional_id', $pro->id)
                    ->whereNull('brand_id')
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->first();
                if ($row !== null) { return (bool) $row->enabled; }
            }
            $flag = FeatureFlag::find($key);
            if ($flag !== null && $pro !== null && $flag->rollout_percent > 0) {
                if ((crc32($key . $pro->id) % 100) < $flag->rollout_percent) { return true; }
            }
            if ($flag !== null) { return (bool) $flag->default_enabled; }
            return (bool) config('partna.features.' . $key, false);
        }
        ```

- [ ] **#LIFE-3** · P2 — Cache-unavailable warning log omits `professional_id` and `brand_id`, breaking Nightwatch correlation
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md:Task 5 Step 4, `FeatureFlagService::enabled()` catch block
    - **Affects:** Operations team debugging a Redis outage. Without tenant identifiers in the log, Nightwatch cannot correlate a spike of `feature_flags.cache_unavailable` warnings to specific tenants or requests.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Include `professional_id` and `brand_id` (both available in scope) in the log context array.
        - Add `request_id` via `request()?->header('X-Request-Id')` so Nightwatch can link the warning to the inbound HTTP request.
    - **Technical:** `$pro` and `$brand` are method parameters already in scope at the catch site. The log call emits only `['error' => $e->getMessage()]`. At the scale target, a Redis blip produces a warning storm across all tenants. Nightwatch groups by message string — without per-tenant fields, all warnings merge into one undifferentiated bucket with no way to assess which tenants experienced degraded resolution. The canonical Partna pattern requires `brand_professional_id` and `request_id` in every `Log::warning` / `Log::error` call.
    - **Plain English:** When the cache goes down and the system writes a log note, it doesn't record which customer was affected or which page triggered it. It's like a security alarm that beeps "something happened" with no timestamp or location. During an incident, you need to know which customers saw the wrong feature state — without IDs in the log, you have nothing to go on. The fix is a one-line addition to the log call.
    - **Evidence:**
        ```php
        } catch (Throwable $e) {
            Log::warning('feature_flags.cache_unavailable', ['error' => $e->getMessage()]);
            return $this->resolveFromDb($key, $pro, $brand);
        }
        ```
        `$pro` (`?Professional`) and `$brand` (`?Brand`) are in scope as method parameters but are not included in the log context.

- [ ] **#SCHEMA-2** · P2 — `feature_flags` uses hard-delete with `ON DELETE CASCADE` — no soft-delete recovery window
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md:Task 1 Step 1 (DDL); Task 2 Step 1 (`FeatureFlag` model)
    - **Affects:** Staff operators. A single `DELETE /staff/feature-flags/{key}` immediately and irreversibly destroys the flag row and every override across all tenants via cascade.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `deleted_at TIMESTAMPTZ NULL` to `core.feature_flags` and add `SoftDeletes` to the `FeatureFlag` model.
        - Decide whether `ON DELETE CASCADE` on `feature_flag_overrides.flag_key` should become `ON DELETE RESTRICT` (block deletion while overrides exist) or remain cascade-soft-delete (nullify or soft-delete overrides when flag is soft-deleted via an observer).
        - Align with the `SOFT_DELETE_RETENTION_DAYS` pattern used everywhere else in Partna.
    - **Technical:** Every other tenant-owned model in Partna uses soft deletes with 30-day retention. The `feature_flags` model omits `SoftDeletes` entirely, meaning Eloquent's `delete()` issues a hard `DELETE`. Combined with `ON DELETE CASCADE`, one mistaken API call destroys all overrides permanently — no trash bin, no recovery. This inconsistency with the project standard is the gap; adding `deleted_at` and the trait is a one-migration, one-trait change.
    - **Plain English:** Every other important record in the system goes to a recycle bin for 30 days before being permanently deleted. Feature flags skip the recycle bin entirely — one wrong button click, and all the carefully configured per-tenant toggles vanish instantly. Fifteen minutes of work now adds the same safety net that exists everywhere else.
    - **Evidence:**
        ```sql
        flag_key text NOT NULL REFERENCES core.feature_flags(key) ON DELETE CASCADE,
        ```
        ```php
        class FeatureFlag extends BaseModel
        {
            protected $table = 'core.feature_flags';
            // No SoftDeletes trait
            protected $fillable = ['key', 'description', 'default_enabled', 'rollout_percent'];
        }
        ```

---

## P3 — Nice to have

- [ ] **#SCALE-3** · P3 — Staff override list endpoint returns unbounded `->get()` with no pagination
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md:Task 10 Step 3, `StaffFeatureFlagOverrideController::index()`
    - **Affects:** Staff admin endpoint `GET /staff/feature-flags/{key}/overrides`. At full tenant scale (200 brands × 50 affiliates per brand), a popular flag with per-professional overrides could accumulate thousands of rows returned in one response.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->get()` with `->paginate(50)` and return a `FeatureFlagOverrideResource::collection($overrides)->response()` with pagination metadata.
    - **Technical:** At the scale target (200 brands × 50 affiliates = 10K professionals), a percentage-rollout flag that later accumulates per-tenant kill-switches could have 1K+ overrides. The current `->get()` loads all of them into one Eloquent collection. Staff-only endpoint with low call frequency makes this P3 — add pagination now as cheap insurance before the table fills.
    - **Evidence:**
        ```php
        $overrides = $flag->overrides()->orderBy('created_at', 'desc')->get();
        return FeatureFlagOverrideResource::collection($overrides)->response();
        ```

- [ ] **#SCHEMA-3** · P3 — `core.feature_flags.key` has no DB-level length constraint despite app validating `max:128`
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md:Task 1 Step 1; Task 8 Step 2 `CreateFeatureFlagRequest`
    - **Affects:** Data integrity — raw SQL inserts, future migrations, or Supabase dashboard edits can write keys longer than 128 characters that the API then cannot reference cleanly in URL path segments.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CONSTRAINT feature_flags_key_length CHECK (length(key) <= 128)` to the `core.feature_flags` DDL (or use `VARCHAR(128)` instead of `text`).
    - **Technical:** The FormRequest validates `'key' => ['max:128']` but the DB column is bare `text`. Schema constraints are defense-in-depth for paths that bypass the app — Supabase dashboard, direct SQL, future services. Mismatched keys (>128 chars) would cause silent failures when the API tries to match them in validation or URL routing.
    - **Evidence:**
        ```sql
        CREATE TABLE core.feature_flags (
            key text PRIMARY KEY,
        ```
        ```php
        'key' => ['required', 'string', 'min:1', 'max:128', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:core.feature_flags,key'],
        ```

- [ ] **#SCHEMA-4** · P3 — No composite index covering `(flag_key, created_at)` for the admin override list sort
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md:Task 1 Step 2 (index migration); Task 10 Step 3 (controller query)
    - **Affects:** Staff `GET /staff/feature-flags/{key}/overrides` — as overrides accumulate, Postgres must sort all matching rows in memory because the existing partial indexes on `(flag_key, professional_id)` and `(flag_key, brand_id)` don't cover the `ORDER BY created_at DESC` sort.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add to the indexes migration: `CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_flag_key_created ON core.feature_flag_overrides (flag_key, created_at DESC);`
    - **Technical:** The query shape is `WHERE flag_key = ? ORDER BY created_at DESC`. The existing partial unique indexes (`WHERE brand_id IS NULL` / `WHERE brand_id IS NOT NULL`) cover uniqueness constraints but not the sort. Postgres collects all rows via a bitmap index scan over both partial indexes, then quicksorts them. A composite index on `(flag_key, created_at DESC)` lets Postgres return rows in order directly, eliminating the sort node. P3 because this is a staff-only endpoint with low traffic — but the index is cheap and the pattern is sound.
    - **Evidence:**
        ```php
        // Controller:
        $overrides = $flag->overrides()->orderBy('created_at', 'desc')->get();
        ```
        ```sql
        -- Indexes migration (no created_at coverage):
        CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_pro_unique
            ON core.feature_flag_overrides (flag_key, professional_id)
            WHERE brand_id IS NULL;
        CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_brand_unique
            ON core.feature_flag_overrides (flag_key, brand_id)
            WHERE brand_id IS NOT NULL;
        ```

`★ Insight ─────────────────────────────────────`
Three patterns from this adjudication worth internalising for future FF-1 implementation:

1. **Plan stand-ins become prod bugs** — `resolveStaff`'s `?? $request->user()` fallback is the classic "I'll fix this later" that survives code review because it looks intentional. The correct key (`partna_staff`) is discoverable in 10 seconds with `rg attributes->get app/Http/Controllers/Api/Staff`. Running the plan's own Step 1 verification task before writing the controller body eliminates this class of bug entirely.

2. **Schema FK names are load-bearing** — `brand.brands` vs `brand.brand_profiles` is a silent deploy killer. In a multi-schema Supabase project, table names often don't match the conceptual entity name. The migration convention (`CONVENTIONS.md`) should include a "verify FK targets with `\dt schema.*` before authoring DDL" rule to make this a pre-flight step rather than a post-failure discovery.

3. **Single-flight + SWR are a package deal** — the commerce system chose `CacheLockService::rememberLocked` specifically because both concerns (stampede prevention and non-blocking stale-read) are solved by the same abstraction. Splitting them into separate tickets (CACHE-1 vs CACHE-3 in the draft) obscures that they share one fix. Always reach for `rememberLocked` when the spec says "SWR" — it's the canonical answer for this codebase.
`─────────────────────────────────────────────────`
