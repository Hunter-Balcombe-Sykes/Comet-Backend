`★ Insight ─────────────────────────────────────`
The three `ON DELETE SET NULL` FKs (`feedback_user_fk`, `staff_audit_log_professional_fk`, `data_export_audit_professional_fk`) share a deliberate pattern: rows are kept for forensic/audit purposes after a user is hard-deleted. This is architecturally intentional — but it creates a GDPR tension where the pre-deletion DSAR export becomes the **only** window to fulfill Article 15 disclosure for those rows. The export builder must therefore be exhaustive, and these two findings (GDP-1, GDP-2) are its gaps.
`─────────────────────────────────────────────────`

# GDPR End-to-End Completeness Audit — 2026-05-25

**Branch:** development
**Lens:** GDPR end-to-end completeness, DSAR coverage, deletion cascade gaps, retention enforcement, data-export integrity, missing models in deletion flow
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Services/Professional/DataExport/DataExportPayloadBuilder.php`
- `app/Jobs/Gdpr/ExportProfessionalDataJob.php`
- `app/Models/Core/Feedback.php`
- `app/Models/Core/Staff/StaffAuditEntry.php`
- `app/Models/Core/Site/SiteMedia.php`
- `app/Models/Core/Professional/User.php`
- `supabase/migrations/20260526000000_baseline_standalone_user.sql`
- `supabase/migrations/20260526210001_create_feedback_table.sql`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#GDP-2** · P1 — Staff audit log rows omitted from DSAR export
    - **Where:** `app/Services/Professional/DataExport/DataExportPayloadBuilder.php:281–287` (stream ends at `audit.deletion_audit`; no `audit.staff_actions` section exists)
    - **Affects:** Any user who submits a DSAR (Subject Access Request). `core.staff_audit_log` rows record the staff member's IP, user-agent, the user's handle snapshot, and a `payload_summary` of every write action a staff member performed on their account. These rows survive the user's hard-delete (`ON DELETE SET NULL`), making the pre-deletion export the only window for disclosure. Omitting them is an Article 15 breach.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a private `streamStaffAuditEntries(string $professionalId): \Generator` method to `DataExportPayloadBuilder` that queries `core.staff_audit_log WHERE professional_id = ?` in cursor batches.
        - Before yielding each row, redact `staff_email_snapshot` and `impersonator_email_snapshot` (replace with a placeholder such as `"[staff]"`) — the user is entitled to see that staff acted on their account and what was done, but not the staff member's personal email address. Follow the existing `streamHandleChangeLog()` redaction pattern for `actor_kind`.
        - Yield the section just before `audit.deletion_audit` so audit sections remain grouped: `'name' => 'audit.staff_actions'`.
        - Add a Pest test asserting the section appears in the export fixture and that email fields are redacted.
    - **Technical:** `StaffAuditEntry` is append-only (`const UPDATED_AT = null`) and its `professional_id` FK is `ON DELETE SET NULL` (migration line 606), so rows outlive the user's hard-delete. The `stream()` generator terminates at `audit.deletion_audit` (line 286–287 in the builder) with no further yields; confirmed via `Read` — nothing follows the closing brace. The fields `professional_handle_snapshot`, `route`, `http_method`, `status_code`, `payload_summary`, `ip`, and `user_agent` are all personal-data-adjacent under GDPR Article 4(1) when linked to a `professional_id`. Cross-PII fields (`staff_email_snapshot`, `impersonator_email_snapshot`) must be redacted before disclosure to the data subject.
    - **Plain English:** Every time a support or admin person at Partna makes a change to a user's account, the system writes a log entry recording what they did, from which IP address, and using which browser. Those log entries belong to the user's data under privacy law — if the user asks "show me everything you hold about me," this log is part of the answer. Right now the download we send them is missing it entirely. We also need to scrub the staff person's email from what we share (the user can know *that* staff acted, just not *who*).
    - **Evidence:**
        ```php
        // DataExportPayloadBuilder.php — stream() terminates here; no staff_audit_log section
        yield [
            'name' => 'audit.deletion_audit',
            'kind' => 'rows',
            'rows' => $this->streamDeletionAudit($professionalId),
            'csv_columns' => null,
        ];
        // ← closing brace of stream() follows immediately
        ```
        ```php
        // StaffAuditEntry.php
        protected $table = 'core.staff_audit_log';
        const UPDATED_AT = null; // append-only
        protected $fillable = [
            'staff_id',
            'staff_email_snapshot',
            'impersonator_staff_id',
            'impersonator_email_snapshot',
            'professional_id',
            'professional_handle_snapshot',
            'route',
            'http_method',
            'status_code',
            'payload_summary',
            'ip',
            'user_agent',
        ];
        ```
        ```sql
        -- baseline_standalone_user.sql:606
        CONSTRAINT staff_audit_log_professional_fk FOREIGN KEY (professional_id)
            REFERENCES core.users(id) ON DELETE SET NULL
        ```

- [ ] **#GDP-1** · P1 — Feedback records omitted from DSAR export
    - **Where:** `app/Services/Professional/DataExport/DataExportPayloadBuilder.php:281–287`; `app/Models/Core/Feedback.php`
    - **Affects:** Any user who submits a DSAR after having submitted in-app feedback. `core.feedback` rows contain `message` (free-text personal content), `reply_email`, `ip_hash`, `page_url`, and `user_agent`. The `user_id` FK is `ON DELETE SET NULL`, so rows survive the user's hard-delete — the pre-deletion DSAR export is the only disclosure window. The table was created in commit `f9c30a42` but was never wired into the export builder.
    - **Effort:** S (~1h)
    - **What to do:**
        - Add a private `streamFeedback(string $professionalId): \Generator` method to `DataExportPayloadBuilder` that cursor-queries `core.feedback WHERE user_id = ?`.
        - Yield the section anywhere before `audit.deletion_audit`, e.g. after `lead_submissions`: `'name' => 'feedback'`.
        - Include all columns in the export except `internal_notes` and `status` — these are Partna-internal workflow fields, not personal data belonging to the user.
        - Add a Pest test asserting the section appears in the export.
    - **Technical:** The `Feedback` model (`app/Models/Core/Feedback.php`) was added in commit `f9c30a42` alongside its migration (`supabase/migrations/20260526210001_create_feedback_table.sql`). The model has `SoftDeletes` and a `belongsTo(User::class, 'user_id')` relation, but `DataExportPayloadBuilder::stream()` was not updated. The FK is `ON DELETE SET NULL` (migration confirmed), meaning soft-deleted and hard-deleted users' feedback rows persist in the table indefinitely. Fields `message`, `reply_email`, `ip_hash`, and `page_url` are personal data under GDPR Article 4(1) and must be included in an Article 15 disclosure. The fix is purely additive — one new private method and one yield.
    - **Plain English:** When a user reports a bug or sends feedback through the app, we store what they wrote, their email, and technical fingerprints like their IP address (hashed). Under privacy law, if they later ask "what do you hold about me?", that feedback is part of the answer. Right now it's not in the download we send them. This is a small gap — the feedback feature was just added and the download system wasn't updated to match. It's a one-method fix.
    - **Evidence:**
        ```php
        // DataExportPayloadBuilder.php — stream() ends here; no feedback section
        yield [
            'name' => 'audit.deletion_audit',
            'kind' => 'rows',
            'rows' => $this->streamDeletionAudit($professionalId),
            'csv_columns' => null,
        ];
        ```
        ```php
        // Feedback.php — personal data fields
        protected $fillable = [
            'user_id',
            'reply_email',
            'kind',
            'severity',
            'message',
            'page_url',
            // ...
            'ip_hash',
        ];

        public function user(): BelongsTo
        {
            return $this->belongsTo(User::class, 'user_id');
        }
        ```

- [ ] **#GDP-3** · P1 — R2 media files orphaned when a user is hard-deleted via DB cascade
    - **Where:** `app/Models/Core/Site/SiteMedia.php:100–135` (hook exists but is never reached); `app/Models/Core/Professional/User.php` (no `forceDeleting` hook); `supabase/migrations/20260526000000_baseline_standalone_user.sql:703,791`
    - **Affects:** Every user who is hard-deleted after the 30-day soft-delete window. All their uploaded media — profile images, gallery photos, documents — remain in R2 storage indefinitely after the DB rows are gone. This is both a GDPR Article 17 ("right to erasure") failure and a storage cost leak.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `booted()` method (or extend the existing one) to `app/Models/Core/Professional/User.php` with a `forceDeleting` static hook.
        - Inside the hook, load all `SiteMedia` rows belonging to the user (via `site_id` in their sites) **before** the cascade fires, and call `forceDelete()` on each individually. This triggers the existing `SiteMedia::forceDeleting` hook which already handles R2 variant and original deletion correctly.
        - The cascade will then find no `site.site_media` rows to delete (they're already gone), which is safe.
        - Add a Pest test that mocks the `Storage` disk and asserts the R2 delete is called when a User is force-deleted.
        - Confirm the `finally` block approach: if any individual media deletion fails, log it and continue — do not abort the user deletion. Media leakage is preferable to a stuck deletion.
    - **Technical:** `SiteMedia` has a well-constructed `forceDeleting` hook (lines 105–135) that deletes R2 variants and the original upload. The hook comment even warns: *"Collect variant storage paths BEFORE forceDelete fires — the DB cascade wipes media_variants rows at the same time the parent row is deleted."* However, this Eloquent event only fires when Eloquent's own `forceDelete()` is called on a `SiteMedia` instance. When a `User` is force-deleted, PostgreSQL's `ON DELETE CASCADE` chain (`core.users → site.sites → site.site_media`) deletes the DB rows at the SQL level, bypassing Eloquent entirely. `User.php` has no `forceDeleting` hook (confirmed via Grep — zero matches for `forceDeleting`, `forceDelete`, `booted`, or `boot` in that file). The fix must intercept at the `User` level before the cascade fires.
    - **Plain English:** When we permanently delete a user's account, our code is supposed to delete their photos and files from storage too. We have that cleanup code written for individual file deletions. But there's a shortcut: our database is configured to automatically wipe a user's data when their account row is deleted, all in one SQL operation. That database shortcut is faster, but it skips our cleanup code entirely — like demolishing a building without clearing out the contents first. The files stay in the storage bucket forever, which violates the user's right to have their data erased and costs us money for storage we're no longer using.
    - **Evidence:**
        ```php
        // SiteMedia.php:100–110 — hook fires only on Eloquent-triggered forceDelete
        protected static function booted(): void
        {
            // Collect variant storage paths BEFORE forceDelete fires — the DB cascade
            // wipes media_variants rows at the same time the parent row is deleted,
            // so forceDeleted (after-event) would find an empty relation.
            static::forceDeleting(function (SiteMedia $media): void {
                // Delete processed variants (each row tracks its own disk).
                $variantPaths = $media->mediaVariants()
                    ->whereNotNull('path')
                    ->get(['disk', 'path']);
        ```
        ```sql
        -- baseline_standalone_user.sql — cascade chain that bypasses the hook
        CONSTRAINT sites_professional_fk FOREIGN KEY (professional_id)
            REFERENCES core.users(id) ON DELETE CASCADE           -- line 703
        CONSTRAINT site_media_site_fk FOREIGN KEY (site_id)
            REFERENCES site.sites(id) ON DELETE CASCADE            -- line 791
        ```
        ```
        // User.php — Grep for forceDeleting/booted/boot → no matches
        // User model has no forceDeleting hook and no booted() override
        ```

---

## P2 — Should fix

- [ ] **#GDP-4** · P2 — GDPR export zips accumulate indefinitely in R2 after download window closes
    - **Where:** `app/Jobs/Gdpr/ExportProfessionalDataJob.php:77–131`
    - **Affects:** All users who have ever requested a data export. Each completed export writes a zip containing the user's entire personal data set to `exports/{professional_id}/{audit_id}.zip` in R2. The 7-day signed URL expires but the underlying object persists forever. There is no scheduled job, artisan command, or lifecycle rule to prune these objects. Over time: GDPR Article 5(1)(e) storage-limitation obligation is not met, and any future R2 credential compromise exposes every historical export.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an artisan command `gdpr:prune-export-zips` that queries `data_export_audit WHERE email_sent_at IS NOT NULL AND email_sent_at < NOW() - INTERVAL '? days'` (configurable, default `config('partna.gdpr.signed_url_ttl_days', 7) + 1`), deletes the R2 object at `file_path` for each row, and nulls out `file_path` and `file_size_bytes` on the audit row to record the purge.
        - Schedule the command daily in `app/Console/Kernel.php` (or `routes/console.php`).
        - Alternatively, configure an R2 object lifecycle rule (`X-Amz-Expiration` via Cloudflare dashboard) for the `exports/` prefix, but prefer the Eloquent-aware command so the audit row is updated and the purge is logged.
        - Do not delete the `DataExportAudit` row itself — it is the compliance record of the request and must be retained per Article 5(2).
    - **Technical:** `ExportProfessionalDataJob` uploads to `$remotePath = "exports/{$audit->professional_id}/{$audit->id}.zip"` and stores this path on the audit row via `markCompleted(filePath: $remotePath, ...)`. The `finally` block (line 128–130) only deletes the local temp file with `@unlink($tmpPath)` — the R2 object is never scheduled for deletion. Confirmed with Grep: the only file in `app/` referencing the `exports/` path prefix is `ExportProfessionalDataJob.php`. No scheduled cleanup command exists. The signed URL TTL (`partna.gdpr.signed_url_ttl_days`, default 7) controls access but not object lifecycle.
    - **Plain English:** When a user downloads their data, we create a zip file containing everything we know about them, upload it to cloud storage, and give them a link that expires in 7 days. That's the right privacy-conscious approach for the link. But the actual zip file sitting in our storage bucket is never deleted — it just sits there forever, even after the link has long expired. Privacy regulations require that we only keep personal data as long as necessary. We should automatically delete the zip file a day or two after the download link expires, while keeping a note in our records that says "we fulfilled this request on this date."
    - **Evidence:**
        ```php
        // ExportProfessionalDataJob.php:77–86
        $remotePath = "exports/{$audit->professional_id}/{$audit->id}.zip";

        $stream = fopen($written['path'], 'rb');
        $disk->put($remotePath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $ttlDays = (int) config('partna.gdpr.signed_url_ttl_days', 7);
        $signedUrl = $disk->temporaryUrl($remotePath, now()->addDays($ttlDays));
        ```
        ```php
        // ExportProfessionalDataJob.php:127–131 — only local temp file is cleaned up
        } finally {
            if ($tmpPath && file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
        // R2 object at $remotePath is never deleted
        ```

`★ Insight ─────────────────────────────────────`
GDP-3 and GDP-4 together expose a cleanup asymmetry: the job's `finally` block diligently cleans up the local temp file but ignores the durable R2 copy. This is a common pattern when jobs are built incrementally — the "write to R2" step is added later and the cleanup path doesn't grow with it. One architectural improvement worth noting: storing `file_path` on the `DataExportAudit` row (which GDP-4's fix already leverages) is the exact hook a cleanup command needs — the data model is already shaped correctly for the fix.
`─────────────────────────────────────────────────`
