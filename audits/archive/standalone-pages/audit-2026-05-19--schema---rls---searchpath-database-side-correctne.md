`★ Insight ─────────────────────────────────────`
Two cross-file patterns matter here: (1) The `BrandPartnerLink` model confirms hard-delete is live today — any partner disconnection permanently destroys the row. (2) The RLS migration shows `brand_profiles_affiliate_select` and `store_settings_affiliate_select` join through `brand_partner_links` without a `deleted_at IS NULL` guard — creating a second-order exposure that will open the moment the planned soft-delete migration lands.
`─────────────────────────────────────────────────`

# Schema / RLS / search_path Audit — 2026-05-19

**Branch:** development
**Lens:** Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Models/Core/Professional/BrandPartnerLink.php`
- `app/Services/Professional/Brand/BrandPartnerLinkService.php`
- `supabase/migrations/20260420200000_add_rls_to_remaining_tables.sql`
- `PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md` (planning artifact — used for context only)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **SCHEMA-1** · P1 — `BrandPartnerLink` hard-deletes on disconnect — permanent data loss today
    - **Where:** `app/Models/Core/Professional/BrandPartnerLink.php` (no `SoftDeletes` trait) + `app/Services/Professional/Brand/BrandPartnerLinkService.php:99`
    - **Affects:** Every brand–partner disconnection. When either party removes the link today, the `BrandPartnerLink` row is permanently gone. Commission/payout history in `commerce.orders` and `commerce.commission_payouts` references `affiliate_professional_id` on the `Professional` row (which survives), but the link record itself — including slot assignment, audit trail, and the foundation for the planned ex-partner panel — is irrecoverably deleted.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add Laravel's `SoftDeletes` trait to `BrandPartnerLink` and cast `deleted_at` as `datetime`.
        - Create migration `supabase/migrations/<ts>_add_soft_deletes_to_brand_partner_links.sql` with:
            - `ALTER TABLE brand.brand_partner_links ADD COLUMN deleted_at TIMESTAMPTZ NULL;`
            - `CREATE INDEX CONCURRENTLY ON brand.brand_partner_links (affiliate_professional_id) WHERE deleted_at IS NULL;` — hot-path active-partnerships query.
            - `CREATE INDEX CONCURRENTLY ON brand.brand_partner_links (affiliate_professional_id, deleted_at);` — full-history ex-partner queries.
        - Update the three RLS policies on `brand.brand_partner_links` in the **same migration** (see SCHEMA-2 — the two findings must ship together).
        - Add `brandPartnerLinksAll()` relationship using `withTrashed()` to `Professional` for ex-partner history queries, distinct from the existing `brandPartnerLinks()` (active only).
        - No code change required in `BrandPartnerLinkService::disconnectBrandFromAffiliate()` — Eloquent's `SoftDeletes` trait intercepts `->delete()` automatically.
    - **Technical:** Category 7 — `BrandPartnerLink` uses only `HasUuids`; there is no `SoftDeletes` trait on the model and no `deleted_at` column in the schema. `BrandPartnerLinkService::disconnectBrandFromAffiliate()` calls `$target->delete()` at line 99, which becomes a hard `DELETE FROM brand.brand_partner_links WHERE id = ?`. Once the row is gone, no application-layer recovery is possible. The planned `individual` sitepages architecture (§28.16 of the plan, which itself cites "REQUIRED for ex-partner mechanism to work") depends entirely on these rows surviving disconnection so the ex-partner panel can surface historical partnership data. The `BrandPartnerLinkAuditor` already records create/delete events in a separate audit log, but that log does not preserve the link row itself or support ORM-level queries like `withTrashed()`. This is not merely a future concern — any partner who disconnects today loses their link row permanently and cannot benefit from the planned history features without a manual data-restore.
    - **Plain English:** Think of `BrandPartnerLink` as the contract record between a brand and a partner. Right now, when that partnership ends, we shred the contract completely instead of filing it. That means we can never look up "what partnerships did this person have in the past?" — which the app will need in order to show ex-partners their old commission and payout history. The fix is to move from shredding to filing: we stamp the record with an end-date instead of deleting it, so the data is still there when we need it.
    - **Evidence:**
        ```php
        // app/Models/Core/Professional/BrandPartnerLink.php
        class BrandPartnerLink extends BaseModel
        {
            use HasUuids;  // ← no SoftDeletes trait
        ```
        ```php
        // app/Services/Professional/Brand/BrandPartnerLinkService.php:99
        $target->delete();  // ← hard DELETE; permanently destroys the row
        ```

- [ ] **SCHEMA-2** · P1 — RLS policies on `brand_partner_links`, `brand_profiles`, and `brand_store_settings` will expose soft-deleted links and brand data to ex-partners once SCHEMA-1 lands
    - **Where:** `supabase/migrations/20260420200000_add_rls_to_remaining_tables.sql:135–155` (`partner_links_party_select`), `:116–123` (`brand_profiles_affiliate_select`), `:186–193` (`store_settings_affiliate_select`)
    - **Affects:** Any professional whose `BrandPartnerLink` is soft-deleted (i.e., any ex-partner). Via Supabase PostgREST or the JS client, they can query `brand.brand_partner_links` and receive their soft-deleted rows (the SELECT policy checks only party membership, not `deleted_at`). Transitively, `brand_profiles_affiliate_select` and `store_settings_affiliate_select` join through that table without `deleted_at IS NULL`, so ex-partners can also read their former brand's profile and store settings — including commission rates, hold periods, and signup codes once that column lands.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the same migration that adds `deleted_at` to `brand.brand_partner_links` (SCHEMA-1), update three policies:
            1. `partner_links_party_select` — add `AND deleted_at IS NULL` to the non-staff `USING` predicates (staff path retains access to soft-deleted rows for support/audit).
            2. `brand_profiles_affiliate_select` — add `AND l.deleted_at IS NULL` to the EXISTS subquery.
            3. `store_settings_affiliate_select` — add `AND l.deleted_at IS NULL` to the EXISTS subquery.
        - Ship SCHEMA-1 and SCHEMA-2 as a single atomic migration to prevent the window where soft-deleted rows exist but RLS does not filter them.
        - Add a Pest test verifying that a non-staff authenticated user querying `brand.brand_partner_links` via Supabase REST does not see soft-deleted rows.
    - **Technical:** Category 1 — The `partner_links_party_select` policy at line 135 uses `USING (affiliate_professional_id = ... OR brand_professional_id = ... OR EXISTS (staff check))`. Once `deleted_at` is added, a soft-deleted row still satisfies both the `affiliate_professional_id` equality and the `brand_professional_id` equality conditions — the policy has no `deleted_at IS NULL` guard. The model-level `SoftDeletes` global scope protects Eloquent queries, but PostgREST queries bypass Eloquent entirely. The compound exposure via `brand_profiles_affiliate_select` (line 116) and `store_settings_affiliate_select` (line 186) — both of which join through `brand_partner_links` without filtering soft-deleted rows — means an ex-partner can read the brand's full profile (name, status, industry, `signup_code` once added) and store settings (commission rate, hold days). This is category 1 (RLS policy that allows read without sufficient tenant constraint) manifesting as a time-bomb: harmless until SCHEMA-1 ships, then immediately exploitable.
    - **Plain English:** The database security rules for the partnership table don't yet know about the concept of "ended partnerships." Right now, when someone was a partner with a brand, they could read the brand's settings. Once we add the "file instead of shred" change (SCHEMA-1), those filed-away records still look like active partnerships to the security rules. That means an ex-partner could use the Supabase API directly to read their former brand's commission rates and private settings. The fix is to update the security rules in the same step as the "filing" change, so they explicitly say "only show active partnerships" unless you're a staff member.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260420200000_add_rls_to_remaining_tables.sql:135
        CREATE POLICY partner_links_party_select ON brand.brand_partner_links FOR SELECT TO authenticated
            USING (
                affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
                OR brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
                OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
            );
        -- ↑ no deleted_at IS NULL guard — will expose soft-deleted rows after SCHEMA-1 lands
        
        -- line 116
        CREATE POLICY brand_profiles_affiliate_select ON brand.brand_profiles FOR SELECT TO authenticated
            USING (EXISTS (
                SELECT 1 FROM brand.brand_partner_links l
                JOIN core.professionals p ON p.id = l.affiliate_professional_id
                WHERE l.brand_professional_id = brand_profiles.professional_id
                  AND p.auth_user_id = auth.uid()
                  AND p.deleted_at IS NULL
                -- ↑ filters deleted professionals but NOT deleted links
            ));
        ```
