`★ Insight ─────────────────────────────────────`
Key adjudication patterns in this pass:
1. DATA-1 is the clearest example of DeepSeek hallucinating a missing constraint — the migration file `20260415120000_add_purpose_to_site_media.sql` proves the partial unique indexes **already exist** for logo_full and logo_square (with `WHERE deleted_at IS NULL`).
2. DATA-2 failed because DeepSeek only read the export builder, not the actual GDPR redact jobs — `RedactCustomerJob` hard-deletes enquiry rows entirely (eliminating `ip_hash`/`user_agent` in the process) and scrubs all booking_events PII.
3. DATA-7 requires inversion: the purge job EXISTS but its scope gaps (Enquiry, ServiceCategory) are the real finding.
`─────────────────────────────────────────────────`

# Data Integrity & Privacy Audit — 2026-05-12

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Media/BrandDesignMediaService.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Services/Professional/AccountDeletionService.php
- app/Services/Professional/DataExportPayloadBuilder.php
- app/Services/Professional/SiteProvisioningService.php
- app/Services/Professional/SectionVisibilityService.php
- app/Services/Professional/BrandStatusService.php
- app/Jobs/Shopify/Gdpr/RedactCustomerJob.php
- app/Console/Commands/PurgeSoftDeleted.php
- supabase/migrations/20260415120000_add_purpose_to_site_media.sql
- supabase/migrations/20260505000001_create_brand_status_history.sql
- supabase/migrations/20260403000000_v2_baseline.sql (subdomain index verification)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#DATA-1** · P2 — OAuth credentials (squareIntegration) serialized into Redis model cache
    - **Where:** app/Services/Cache/ProfessionalCacheService.php:147 (`getByAuthId` model cache)
    - **Affects:** Every authenticated request — the 60-second SWR model cache at `pro:model:{id}` stores a serialized Eloquent model graph that includes the `squareIntegration` relation (`ProfessionalIntegration` rows with `access_token` and `refresh_token`). A Redis compromise or leaked RDB snapshot exposes live OAuth credentials for every professional with a Square integration, across the entire fleet in one shot.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `squareIntegration` from the eager-load list in `getByAuthId` — load it only on the code paths that actually need it (Square API call sites), not on every authenticated request.
        - If Square integration state is needed on the auth path (e.g. for a dashboard flag), project only a boolean or non-credential field via a separate lean query or the payload cache (`pro:payload:*`), which does not include integration credentials.
        - Add a note in the PII inventory that `pro:model:*` Redis keys previously contained OAuth credentials, so any existing snapshot backups of Redis are treated as containing secrets.
    - **Technical:** `getByAuthId` eager-loads `['site', 'squareIntegration']`. Laravel serializes the full Eloquent model graph — including all attributes — when writing to Redis via `CacheLockService::rememberLocked`. The `squareIntegration` model's `access_token` and `refresh_token` columns are therefore plaintext in every `pro:model:{id}` Redis key and its `:stale` copy (TTL up to 600s). This is a materially different risk from DB credential storage: Redis doesn't enforce column-level ACLs, is often backed by a single unencrypted RDB/AOF file, and its dump is a single-operation exfiltration target. The `toPayload` array cache (`pro:payload:*`) is separate and does NOT include integration credentials — only the model cache is affected.
    - **Plain English:** Every time someone logs in, we put a snapshot of their account data — including their Square payment-app password — in our fast-access memory store. It's like putting a photocopy of everyone's safe combination in a filing cabinet so the front desk can serve them faster. The filing cabinet is locked, but if anyone gets in, they get every combination at once. The fix is to stop putting the combinations in the filing cabinet: we only need them when we're actually calling Square, not on every login check.
    - **Evidence:**
        ```php
        // ProfessionalCacheService::getByAuthId — squareIntegration (with credentials) cached
        $professional = $this->cacheLock->rememberLocked(
            CacheKeyGenerator::professionalModel($id),
            (int) config('partna.cache.ttls.professional_model'),
            fn () => Professional::query()->with(['site', 'squareIntegration'])->find($id),
        );
        ```

- [ ] **#DATA-2** · P2 — `PurgeSoftDeleted` command omits `Enquiry` and `ServiceCategory`, leaving PII-bearing rows in perpetual soft-delete limbo
    - **Where:** app/Console/Commands/PurgeSoftDeleted.php:31–33 (model list), app/Models/Core/Site/Enquiry.php, app/Models/Core/Professional/ServiceCategory.php
    - **Affects:** Soft-deleted enquiry rows (name, email, phone, `ip_hash`, `user_agent`) and service-category rows accumulate indefinitely past the 30-day retention window stated in CLAUDE.md. Enquiry rows are the more serious gap: they contain visitor PII from contact forms that a professional may have soft-deleted from their dashboard. The 30-day trash-bin promise is broken for these two models.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Enquiry::class` and `ServiceCategory::class` to the `$total +=` loop in `PurgeSoftDeleted::handle()`.
        - Verify that `Enquiry` and `ServiceCategory` have no FK children that would block `forceDelete()` (they cascade from `professional_id`, and their children if any should also soft-delete or cascade).
        - Consider adding a log line per model consistent with the existing `$this->line(class_basename($modelClass)."...")` pattern.
        - Confirm the command is scheduled in `routes/console.php` (or equivalent) at least daily.
    - **Technical:** `PurgeSoftDeleted` currently calls `purgeModel()` for `Customer`, `Service`, and `SiteMedia` — the three models added when the command was first written. Both `Enquiry` and `ServiceCategory` use the `SoftDeletes` trait (confirmed by source scan), but neither is in the purge loop. Enquiry rows contain `email`, `phone`, `name`, `ip_hash`, and `user_agent` captured from contact form submissions. `RedactCustomerJob` handles GDPR-scoped deletion by email, but soft-deleted enquiries for non-GDPR-requesting visitors accumulate beyond the retention window without the purge job. The longer this runs un-fixed, the larger the backlog.
    - **Plain English:** The system promises to permanently empty the bin every 30 days. The janitor's checklist covers four types of records (customer profiles, services, images, and professional accounts), but it skips two bins: deleted contact-form messages and deleted service categories. Contact-form messages contain people's names, phone numbers, and email addresses — the kind of information you want to actually delete, not just hide. It's a simple fix: add two more items to the janitor's checklist.
    - **Evidence:**
        ```php
        // PurgeSoftDeleted::handle() — Enquiry and ServiceCategory absent from purge loop
        $total += $this->purgeModel(Customer::class, $cutoff);
        $total += $this->purgeModel(Service::class, $cutoff);
        $total += $this->purgeModel(SiteMedia::class, $cutoff);
        // Enquiry::class missing — holds email, phone, name, ip_hash, user_agent
        // ServiceCategory::class missing
        ```
        ```php
        // Confirmed: both models use SoftDeletes (source scan, 6 models total)
        // app/Models/Core/Site/Enquiry.php — SoftDeletes trait present
        // app/Models/Core/Professional/ServiceCategory.php — SoftDeletes trait present
        ```

---

## P3 — Nice to have

- [ ] **#DATA-3** · P3 — `brand_status_history.from_status` / `to_status` lack a `CHECK` constraint enumerating the `BrandStatus` enum values
    - **Where:** supabase/migrations/20260505000001_create_brand_status_history.sql:5–6
    - **Affects:** History rows inserted directly via `DB::table('core.brand_status_history')->insert(...)` in `BrandStatusService::sync()` — any string up to 50 characters is accepted. If a new enum member is added on the PHP side without updating a DB constraint, the history table silently accepts the new value; conversely, a typo or renamed constant writes a non-canonical status that breaks any tool parsing the audit trail.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a CHECK constraint to the migration or a subsequent migration: `ADD CONSTRAINT brand_status_history_to_status_check CHECK (to_status IN ('onboarding','shopify_linked','shopify_configured','storefront_live','ready_for_affiliates','disconnected','systems_down'))`.
        - Apply the same constraint to `from_status` (allowing NULL since new brands have no prior status).
        - Keep the constraint in sync with `app/Enums/BrandStatus.php` — document this pairing in the enum file.
    - **Technical:** The migration creates both columns as `VARCHAR(50)` with no enumeration constraint. `BrandStatusService::sync()` writes via `DB::table()->insert()` — the raw query path, which bypasses Eloquent model casting and PHP-side enum enforcement. A CHECK constraint at the DB level is the only layer that can catch a mismatched string. Category (4): status column without CHECK.
    - **Plain English:** The status-history log accepts any text as a status label, not just the seven it's supposed to. It's like a mood-tracking app that accepts "happy" and "sad" but also "purple" and "3". Adding a short list of allowed values to the database table means the system refuses to record a nonsense status rather than silently accepting it and confusing future reports.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260505000001_create_brand_status_history.sql
        CREATE TABLE core.brand_status_history (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            professional_id UUID NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
            from_status VARCHAR(50),          -- no CHECK constraint
            to_status   VARCHAR(50) NOT NULL, -- no CHECK constraint
            reason VARCHAR(100),
            metadata JSONB DEFAULT '{}',
            created_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );
        ```
        ```php
        // BrandStatusService::sync() — raw insert, PHP enum enforcement doesn't reach DB
        DB::table('core.brand_status_history')->insert([
            'professional_id' => $professional->id,
            'from_status' => $currentStatusValue,
            'to_status' => $newStatusValue,
            'reason' => 'auto',
            'created_at' => now(),
        ]);
        ```
