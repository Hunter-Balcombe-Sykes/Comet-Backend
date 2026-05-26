# Data Integrity & Model Consistency Audit — 2026-05-20

**Branch:** development
**Lens:** Data integrity and model consistency
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Models/Core/Site/Block.php`
- `app/Observers/Core/BlockObserver.php`
- `supabase/migrations/20260403000000_v2_baseline.sql`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **DATA-1** · P1 — `Block` model missing `SoftDeletes` trait despite `deleted_at` column and observer expecting soft-delete semantics
    - **Where:** `app/Models/Core/Site/Block.php:13`
    - **Affects:** Every delete call on a `Block` — hard-DELETEs the row instead of setting `deleted_at`, permanently destroying click-analytics foreign-key relationships (`analytics.link_clicks.link_block_id`) and silently skipping the `BlockObserver::deleted()` cache-invalidation hook, leaving stale site data in cache until the next write.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `use Illuminate\Database\Eloquent\SoftDeletes;` to `app/Models/Core/Site/Block.php` and include `SoftDeletes` in the `use` clause on the class.
        - Add `'deleted_at' => 'datetime'` to `$casts` so the column is treated correctly by Eloquent.
        - Verify `scopeActive`, `scopeEnabled`, and `scopeVisible` scopes don't need to explicitly exclude `deleted_at IS NOT NULL` rows (they won't — `SoftDeletes` adds a global scope that filters deleted rows automatically).
        - Confirm `BlockObserver::deleted()` fires by running the existing delete path in tests.
    - **Technical:** The `site.blocks` DDL in the v2 baseline migration includes `deleted_at timestamptz,` — the database always expected soft-delete semantics. Without `SoftDeletes`, `$block->delete()` issues a hard `DELETE` statement: (1) the row is gone, breaking any `analytics.link_clicks` rows that reference it via `link_block_id`; (2) Eloquent's observer dispatch only fires the `deleted` event on the Eloquent model lifecycle, which in turn only fires when the trait intercepts the deletion and sets `deleted_at` — a raw hard delete does NOT trigger the observer, so `BlockObserver::deleted()` → `SiteCacheService::invalidateSite()` is silently skipped, leaving the public-facing site cache stale. Both consequences are "known scenario" class issues (any block deletion in production triggers them), meeting the P1 bar.
    - **Plain English:** Think of each content block like a Post-It note on a corkboard. The database has a "remove date" field expecting the app to gently peel the note off and write today's date on it (soft delete). Instead, without the right setting, the app is ripping the note off and shredding it (hard delete). Two problems follow: first, any record of who clicked that link gets orphaned because it was pointing to a note that no longer exists; second, the cleanup crew (the observer that refreshes the public page cache) never gets told the note was removed, so visitors might still see the deleted block on the live site until something else triggers a refresh.
    - **Evidence:**
        ```php
        // app/Models/Core/Site/Block.php
        class Block extends BaseModel
        {
            use HasUuids;
            // SoftDeletes trait absent — deleted_at exists in DB but is never written
        ```
        ```php
        // app/Observers/Core/BlockObserver.php — fires only on soft-delete, not hard DELETE
        public function deleted(Block $block): void
        {
            if ($block->site) {
                try {
                    $this->siteCache->invalidateSite($block->site);
        ```
        ```sql
        -- supabase/migrations/20260403000000_v2_baseline.sql
        deleted_at timestamptz,
        ```
