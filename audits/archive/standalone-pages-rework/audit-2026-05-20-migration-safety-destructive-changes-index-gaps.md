# Migration Safety — Destructive Changes, Index Gaps Audit — 2026-05-20

**Branch:** development
**Lens:** migration safety destructive changes index gaps
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `supabase/migrations/20260403000000_v2_baseline.sql`
- `supabase/migrations/20260404000003_rename_comet_staff_to_sidest_staff.sql`
- `supabase/migrations/20260508400000_rename_sidest_staff_to_partna_staff.sql`
- All migrations from `20260404000000` through `20260523000100`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#MIG-1** · P1 — `prevent_staff_escalation()` function body references stale table name after two renames
    - **Where:** `supabase/migrations/20260403000000_v2_baseline.sql` (function body ~line 60), `supabase/migrations/20260404000003_rename_comet_staff_to_sidest_staff.sql`, `supabase/migrations/20260508400000_rename_sidest_staff_to_partna_staff.sql`
    - **Affects:** Any UPDATE to `core.partna_staff` rows — role changes, email updates, any staff record mutation. The trigger fires on every update; in any new PostgreSQL session the PL/pgSQL body is recompiled from stored source text and immediately fails with `42P01: relation "core.comet_staff" does not exist`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a migration (e.g. `20260524000000_fix_prevent_staff_escalation_stale_ref.sql`) containing a `CREATE OR REPLACE FUNCTION core.prevent_staff_escalation()` that replaces every occurrence of `core.comet_staff` in the function body with `core.partna_staff`.
        - After applying, test by running a non-destructive UPDATE against `core.partna_staff` in the Supabase SQL editor (e.g. `UPDATE core.partna_staff SET updated_at = now() WHERE id = '<any id>'`) and confirm it does not raise `42P01`.
        - No need to update the RLS policies on the staff table itself — Postgres stores policy `USING`/`WITH CHECK` expressions as OID-based internal node trees (`pg_node_tree`), not as re-parsed text, so the OID reference survives a rename transparently. The function body is stored as raw text in `pg_proc.prosrc` and is recompiled from that text on first call in each new session — that is the only real breakage point.
    - **Technical:** `ALTER TABLE … RENAME` updates the system catalog OID for the relation. Triggers, foreign-key constraints, and indexes that are stored as OID references auto-resolve to the new name. RLS policy USING expressions stored in `pg_policy.polqual` as `pg_node_tree` also use OID references and auto-resolve. However, PL/pgSQL function bodies are stored verbatim in `pg_proc.prosrc`. PostgreSQL compiles the body on the **first call per session** by re-parsing the stored text, resolving table names to OIDs at that moment. After the first rename (`comet_staff → sidest_staff` in `20260404000003`) and then the second rename (`sidest_staff → partna_staff` in `20260508400000`), the function body text still says `FROM core.comet_staff cs`. Any new database connection (Supavisor recycles connections, so this occurs frequently) that triggers a staff-table UPDATE will see a compilation failure. The recent fix commit `2876bbcd` addressed an unrelated DATA-2 CASCADE issue but did not update this function.
    - **Plain English:** Imagine your security guard is given a new building name badge but the rulebook they consult on every shift still says "check the old building's roster." As long as the same guard works the same shift, the memorised roster works fine. The moment a new guard clocks in and opens the rulebook, they can't find the old building — and every visitor gets turned away with an error. Every time our database opens a fresh connection (which happens constantly in a pooled system), it opens the function rulebook from scratch, can't find the old staff table name, and rejects any attempt to update a staff record.
    - **Evidence:**
        ```sql
        -- core.prevent_staff_escalation() body in v2 baseline — stored as text, recompiled per-session:
        select exists (
            select 1
            from core.comet_staff cs        -- stale: table renamed twice since baseline
            where cs.auth_user_id = uid
              and cs.role = 'admin'
        ) into is_admin;
        ```
        ```sql
        -- 20260404000003_rename_comet_staff_to_sidest_staff.sql — no function update
        ALTER TABLE core.comet_staff RENAME TO sidest_staff;
        ```
        ```sql
        -- 20260508400000_rename_sidest_staff_to_partna_staff.sql — no function update
        ALTER TABLE core.sidest_staff RENAME TO partna_staff;
        ```
