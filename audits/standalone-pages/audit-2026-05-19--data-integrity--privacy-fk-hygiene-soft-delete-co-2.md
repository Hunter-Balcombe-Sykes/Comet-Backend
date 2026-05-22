`★ Insight ─────────────────────────────────────`
The single draft finding is confirmed real: `BrandPartnerLink` has no `SoftDeletes` trait and `disconnectBrandFromAffiliate()` calls `$target->delete()` on line 99 — a hard PostgreSQL `DELETE`. The "Evidence" in the draft cited the planning doc (§28.16) rather than the source files, which would be a hallucination flag under the rules. I've verified both files directly and can supply verbatim source quotes.
`─────────────────────────────────────────────────`

# Data Integrity & Privacy Audit — 2026-05-19

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Models/Core/Professional/BrandPartnerLink.php
- app/Services/Professional/Brand/BrandPartnerLinkService.php
- /Users/joshuahunter/Downloads/PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md (architecture plan)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#DINT-1** · P1 — BrandPartnerLink hard-delete permanently destroys partnership history
    - **Where:** app/Models/Core/Professional/BrandPartnerLink.php:18 (no `SoftDeletes` trait); app/Services/Professional/Brand/BrandPartnerLinkService.php:99 (`$target->delete()`)
    - **Affects:** Every ex-partner's historical data. When a brand removes a partner (or a partner leaves), the `brand_partner_links` row is permanently hard-deleted from PostgreSQL. The planned "ex-partner panel" — which surfaces historical commission/payout/order data to individuals who were previously brand partners — cannot be built without this history. `commerce.orders` rows keyed on `affiliate_professional_id` survive, but the linkage record proving _which brand_ the partnership was with is gone.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `use Illuminate\Database\Eloquent\SoftDeletes;` and `use SoftDeletes;` to `BrandPartnerLink`.
        - Add `'deleted_at' => 'datetime'` to `$casts`.
        - Write a Supabase migration: `ALTER TABLE brand.brand_partner_links ADD COLUMN deleted_at TIMESTAMPTZ NULL;` with two indexes — a partial index `CREATE INDEX CONCURRENTLY ON brand.brand_partner_links (affiliate_professional_id) WHERE deleted_at IS NULL` for active-partner hot-path queries, and a composite `CREATE INDEX CONCURRENTLY ON brand.brand_partner_links (affiliate_professional_id, deleted_at)` for ex-partner historical queries.
        - Update the RLS policies on `brand.brand_partner_links` (introduced in `20260420200000_add_rls_to_remaining_tables.sql`) to add `AND deleted_at IS NULL` predicates on non-staff read paths — the model global scope is app-layer defence only; Supabase PostgREST bypasses it entirely.
        - No change to `disconnectBrandFromAffiliate()` — once the trait is active, `$target->delete()` automatically becomes a soft-delete.
        - Add a `brandPartnerLinksAll()` relationship on `Professional` returning `withTrashed()` for ex-partner derivation.
    - **Technical:** `BrandPartnerLink` extends `BaseModel` and uses only `HasUuids`. There is no `deleted_at` column in the schema and no `SoftDeletes` trait, so Eloquent issues a raw `DELETE FROM brand.brand_partner_links WHERE id = ?` at line 99. The ex-partner capability matrix (`shows_ex_partner_panel`) in the architecture plan (§11) derives its value from the existence of any soft-deleted `BrandPartnerLink` rows — without the trait and column, that derivation always returns false and the entire ex-partner panel is permanently non-functional. Additionally, `BrandPartnerLinkObserver::deleted()` fires correctly on soft-delete (Eloquent's `deleted` model event fires for both soft and hard deletes when the model is retrieved and `delete()` called on it), so the KV sync and cache bust side-effects continue working without observer changes. RLS update is mandatory: PostgREST reads the DB directly and is unaffected by Eloquent global scopes.
    - **Plain English:** Right now, when a partner leaves a brand, the record of that relationship is permanently shredded — like throwing out the only copy of a contract. The system we're building needs that contract kept in a filing cabinet marked "ended," not destroyed. This fix changes the shredder into a filing cabinet: the row stays in the database, just marked with an "ended on" date. Without it, the "previous partnerships" panel we're planning to build for ex-partners has nothing to show, no matter what other code we write.
    - **Evidence:**
        ```php
        // app/Models/Core/Professional/BrandPartnerLink.php:16-18
        class BrandPartnerLink extends BaseModel
        {
            use HasUuids;
        ```
        ```php
        // app/Services/Professional/Brand/BrandPartnerLinkService.php:99
        $target->delete();
        ```
