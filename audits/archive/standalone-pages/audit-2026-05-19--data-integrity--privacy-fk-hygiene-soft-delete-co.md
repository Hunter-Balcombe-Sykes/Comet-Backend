I have enough evidence to produce the final audit. Let me compile the verified findings.

`★ Insight ─────────────────────────────────────`
Key pattern emerging: audit/history tables that used `ON DELETE CASCADE` were created before the team established the `SET NULL` convention (established in `20260505200000` for `commission_ledger_entries`). Newer audit tables (`brand_partner_link_events` from `20260420000000`) correctly use `RESTRICT`. The gap is the two most-recently-created audit tables, both landed in the handle-lifecycle sprint.
`─────────────────────────────────────────────────`

# Data Integrity & Privacy Audit — 2026-05-19

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `supabase/migrations/20260505000001_create_brand_status_history.sql`
- `supabase/migrations/20260519100000_handle_alias_lifecycle.sql`
- `supabase/migrations/20260420000000_add_brand_partner_link_events.sql`
- `supabase/migrations/20260420200000_add_rls_to_remaining_tables.sql`
- `supabase/migrations/20260403000000_v2_baseline.sql`
- `supabase/migrations/20260505200000_commission_ledger_entries_set_null_professional_fks.sql`
- `app/Models/Core/Professional/BrandPartnerLink.php`
- `app/Console/Commands/PurgeSoftDeleted.php`
- `app/Services/Professional/AccountDeletionService.php`
- `app/Jobs/Shopify/Gdpr/RedactCustomerJob.php`
- `app/Jobs/Shopify/Gdpr/RedactShopJob.php`
- `app/Http/Controllers/Api/Webhooks/Shopify/ShopifyGdprWebhookController.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#DATA-1** · P1 — `core.brand_status_history` uses `ON DELETE CASCADE` — audit rows destroyed on professional hard-delete
    - **Where:** `supabase/migrations/20260505000001_create_brand_status_history.sql:4`
    - **Affects:** Any professional account that is hard-deleted by `PurgeSoftDeleted` after the 30-day retention window permanently loses its brand status audit trail. This is the documented production path — the purge command runs on a schedule and calls `forceDelete()` on professionals past their grace period.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Make `professional_id` nullable in the table.
        - Replace the `ON DELETE CASCADE` FK with `ON DELETE SET NULL` (same convention established in `20260505200000_commission_ledger_entries_set_null_professional_fks.sql` for financial rows — keep the row, null the reference).
        - Add a migration: `ALTER TABLE core.brand_status_history ALTER COLUMN professional_id DROP NOT NULL; ALTER TABLE core.brand_status_history DROP CONSTRAINT ...; ALTER TABLE core.brand_status_history ADD CONSTRAINT ... FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;`
    - **Technical:** The pattern is already established: `20260505200000_commission_ledger_entries_set_null_professional_fks.sql` converted the ledger-entry professional FKs from `CASCADE` to `SET NULL` for exactly this reason ("keep the row, null the reference"). `brand_status_history` was created on the same day (`20260505000001`) and missed this treatment. `PurgeSoftDeleted` calls `AccountDeletionService` which ultimately calls `forceDelete()` on the `Professional` model. With `ON DELETE CASCADE` in place, every `forceDelete()` silently wipes the corresponding status history rows — no error, no log. The history is gone. Category 1 (FK without deliberate ON DELETE rule); Category 8 (audit table loses restore integrity on partial delete).
    - **Plain English:** When a user permanently deletes their account (after a 30-day waiting period), we run a cleanup job that erases their data. The problem is, the record of what status their brand went through — "onboarding → shopify_linked → ready_for_affiliates" — is wired to automatically disappear with them. We want to keep those audit records even after the account is gone, just like we do with commission history. Think of it like shredding an employee's HR file on their last day instead of archiving it: the paper trail should outlast the person.
    - **Evidence:**
        ```sql
        CREATE TABLE core.brand_status_history (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            professional_id UUID NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
            from_status VARCHAR(50),
            to_status VARCHAR(50) NOT NULL,
            ...
        );
        ```

- [ ] **#DATA-2** · P1 — `core.handle_change_log` uses `ON DELETE CASCADE` — violates its own 7-year retention spec
    - **Where:** `supabase/migrations/20260519100000_handle_alias_lifecycle.sql:100`
    - **Affects:** Any professional who has ever renamed their handle and is subsequently hard-deleted (30-day grace period via `PurgeSoftDeleted`). Their entire handle rename history — including actor ID, IP address, and user-agent — cascades away. The migration comment explicitly states "retained per config (default 7 years)" but the FK behaviour delivers zero retention.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Make `professional_id` nullable.
        - Replace `ON DELETE CASCADE` with `ON DELETE SET NULL`. The `actor_id` column is already nullable (no FK constraint) so it does not need changes.
        - The append-only trigger (`trg_handle_change_log_append_only`) correctly blocks UPDATE/DELETE from the app role — but it does not block Postgres cascade deletes triggered by the parent FK. That path bypasses row-level triggers entirely.
        - New migration: `ALTER TABLE core.handle_change_log ALTER COLUMN professional_id DROP NOT NULL; ALTER TABLE core.handle_change_log DROP CONSTRAINT ...; ALTER TABLE core.handle_change_log ADD CONSTRAINT ... FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;`
    - **Technical:** This table was added in the most recent merge (`bbf0d6b7 Merge branch 'worktree-feat+handle-redirect-lifecycle'`). The append-only trigger (`BEFORE UPDATE OR DELETE`) prevents application-layer mutations, but Postgres executes CASCADE deletes as a referential integrity action, not as a user DELETE statement — the trigger does not fire. The effect: a professional who renamed their handle three times accumulates three rows describing the renames (with IP, user-agent, and actor). When their account is purged 30 days after deletion, all three rows silently vanish. The stated 7-year retention becomes 30 days in practice. Category 1 (FK cascade on audit table); Category 8 (append-only invariant broken on parent delete).
    - **Plain English:** We log every time someone renames their profile handle — who changed it, from which IP address, when. We said we'd keep those records for 7 years. But we accidentally wired it so that if we delete the account (which we do automatically 30 days after someone asks to leave), the rename logs disappear too. The "keep for 7 years" commitment and the "delete everything when they leave" mechanism are contradicting each other, and the deletion wins. It's like promising to keep shipping records for 7 years but shredding them when the customer closes their account.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS core.handle_change_log (
            id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
            old_handle      text,
            new_handle      text NOT NULL,
            ...
            changed_at      timestamptz NOT NULL DEFAULT now()
        );
        -- Append-only: no UPDATE/DELETE from app role. Block via trigger.
        CREATE OR REPLACE FUNCTION core.trg_handle_change_log_append_only() ...
        ```

- [ ] **#DATA-3** · P1 — `BrandPartnerLink` has no `SoftDeletes` — ex-partner mechanism is architecturally broken, and `brand_partner_link_events` ON DELETE RESTRICT will silently block professional hard-deletes
    - **Where:** `app/Models/Core/Professional/BrandPartnerLink.php:17` (missing trait); `supabase/migrations/20260420000000_add_brand_partner_link_events.sql:6-7` (RESTRICT FKs)
    - **Affects:** Two separate failure modes. (A) Any professional who leaves a brand has their `BrandPartnerLink` row hard-deleted, permanently erasing historical commission, payout, and partnership data from the link record. The ex-partner panel (described as "non-negotiable" in the architecture plan) cannot function without soft-deleted rows. (B) Any professional with partnership history (`brand_partner_link_events` rows) hits the `ON DELETE RESTRICT` FK when the `PurgeSoftDeleted` command attempts to hard-delete them — the deletion silently fails with a FK violation, leaving the professional in a permanently-soft-deleted state rather than being fully purged.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - **Part A — Soft-delete on `BrandPartnerLink`:**
            - Add migration: `ALTER TABLE brand.brand_partner_links ADD COLUMN deleted_at TIMESTAMPTZ NULL;`
            - Add partial index for hot path: `CREATE INDEX CONCURRENTLY ON brand.brand_partner_links (affiliate_professional_id) WHERE deleted_at IS NULL;`
            - Add composite index for ex-partner queries: `CREATE INDEX CONCURRENTLY ON brand.brand_partner_links (affiliate_professional_id, deleted_at);`
            - Add `use SoftDeletes;` and cast `deleted_at` in the model.
            - **Critical:** Update the `partner_links_party_select` RLS policy in `20260420200000_add_rls_to_remaining_tables.sql` to add `AND deleted_at IS NULL` for authenticated (non-staff) reads — without this, soft-deleted links remain visible via PostgREST direct queries. Staff policy retains full access. Also update `brand_profiles_affiliate_select` and `store_settings_affiliate_select` which join through `brand_partner_links` without a `deleted_at` filter.
        - **Part B — Fix `brand_partner_link_events` RESTRICT:**
            - Convert `brand_professional_id` and `affiliate_professional_id` FKs from `ON DELETE RESTRICT` to `ON DELETE SET NULL` (matching the `commission_ledger_entries` pattern). This allows professional hard-deletes while preserving audit rows — the event records are kept but the professional reference is nulled.
            - Also add `AccountDeletionService::checkObligations()` to gate deletion for professionals with pending payouts before the purge path hits these constraints.
    - **Technical:** The v2 baseline creates `brand_partner_links` without `deleted_at` and the model only has `HasUuids`. `BrandPartnerLinkService::disconnectBrandFromAffiliate()` calls `$target->delete()` which hard-deletes the row. The architecture plan (`PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md §28.16`) is explicit that this migration is required and describes it as non-negotiable. The `brand_partner_link_events` RESTRICT issue compounds this: the purge command enumerates models in `PurgeSoftDeleted::handle()` but does not reference `BrandPartnerLinkEvent` — so after the 30-day grace period, `forceDelete()` on any professional with events throws a FK violation. The try/catch in `purgeModel()` catches it, logs it, and increments a `$failed` counter but the professional is never hard-deleted. Category 2 (model missing SoftDeletes); Category 1 (RESTRICT blocking purge path).
    - **Plain English:** When a partner leaves a brand today, we permanently delete the record of their partnership — like burning the employment contract. That means we have no way to show them their commission history or pending payouts from that era. We've designed a "previous partnerships" panel for the app, but it relies on keeping those records. Additionally, there's a separate bug: if a professional who was ever in a partnership tries to fully delete their account, our cleanup job crashes silently — their account stays stuck in a half-deleted state. They believe they've deleted their account, but we're still holding their data.
    - **Evidence:**
        ```php
        // app/Models/Core/Professional/BrandPartnerLink.php
        class BrandPartnerLink extends BaseModel
        {
            use HasUuids;  // SoftDeletes trait missing
        ```
        ```sql
        -- supabase/migrations/20260420000000_add_brand_partner_link_events.sql
        brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
        affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
        ```
        ```sql
        -- supabase/migrations/20260420200000_add_rls_to_remaining_tables.sql
        CREATE POLICY partner_links_party_select ON brand.brand_partner_links FOR SELECT TO authenticated
            USING (
                affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
                OR brand_professional_id = ...
                OR EXISTS (SELECT 1 FROM core.sidest_staff ...)
            );
        -- No deleted_at IS NULL filter — will expose soft-deleted rows once column is added
        ```

## P2 — Should fix

- [ ] **#DATA-4** · P2 — Soft-delete purge command covers 5 models; `FeatureFlag` and `Block` also use `SoftDeletes` but are not purged
    - **Where:** `app/Console/Commands/PurgeSoftDeleted.php:33-37`
    - **Affects:** `core.feature_flag_overrides` has a dedicated `PruneExpiredFeatureFlagOverridesCommand`. However, `site.blocks` and any future model added with `SoftDeletes` will silently accumulate soft-deleted rows forever unless explicitly added to the purge command. There is no test asserting that every model with `SoftDeletes` is enumerated in `PurgeSoftDeleted`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Block::class` to the `PurgeSoftDeleted::handle()` enumeration (or confirm intentionally excluded with a code comment explaining why).
        - Add a CI or architecture test that discovers every model with `use SoftDeletes` via reflection and asserts it is either listed in `PurgeSoftDeleted`, has its own prune command, or appears in an explicit `PURGE_EXEMPT` list with a justification. This mirrors the existing `PolicyCoverageTest` pattern.
    - **Technical:** `PurgeSoftDeleted::handle()` explicitly lists `Customer`, `Service`, `SiteMedia`, `Enquiry`, `ServiceCategory`. `Block` (in `app/Models/Core/Site/Block.php`) uses `SoftDeletes` but is absent. A deleted block's row will never be purged. At low row counts this is invisible, but blocks accumulate for every site edit — over years this becomes meaningful table bloat. The omission also means the pattern is not self-enforcing: adding `SoftDeletes` to a new model does not automatically enrol it in retention enforcement.
    - **Plain English:** We have a scheduled job that permanently removes "soft-deleted" records after 30 days — like a recycling bin that empties itself. But the job only empties specific named bins. One bin (website section blocks) isn't on the list, so deleted blocks pile up forever. More importantly, whenever we add a new "soft-deletable" thing in the future, it'll silently miss the auto-cleanup unless someone remembers to add it to the list — and right now there's no safety net to catch that omission.
    - **Evidence:**
        ```php
        // app/Console/Commands/PurgeSoftDeleted.php
        $total += $this->purgeModel(Customer::class, $cutoff);
        $total += $this->purgeModel(Service::class, $cutoff);
        $total += $this->purgeModel(SiteMedia::class, $cutoff);
        $total += $this->purgeModel(Enquiry::class, $cutoff);
        $total += $this->purgeModel(ServiceCategory::class, $cutoff);
        // Block::class absent — SoftDeletes used but never purged
        ```
        ```php
        // app/Models/Core/Site/Block.php (confirmed via grep)
        use Illuminate\Database\Eloquent\SoftDeletes;
        // ...
        use HasUuids, SoftDeletes;
        ```
