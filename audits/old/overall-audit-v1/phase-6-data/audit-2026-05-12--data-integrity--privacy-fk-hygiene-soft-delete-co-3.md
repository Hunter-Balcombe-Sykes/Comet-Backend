`★ Insight ─────────────────────────────────────`
Several key DeepSeek claims are factually wrong: `analytics.lead_submissions` has DB-level FKs (SET NULL on all three references), `brand.brand_store_settings` has `ON DELETE CASCADE` to `core.professionals`, and `site.media_variants` has `ON DELETE CASCADE` on `media_id`. DeepSeek inferred schema gaps from model code alone without reading the migrations — adjudication always requires both sides of the stack.
`─────────────────────────────────────────────────`

# Data Integrity & Privacy Audit — 2026-05-12

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260403000000_v2_baseline.sql
- supabase/migrations/20260422040000_create_site_enquiries.sql
- supabase/migrations/20260427000000_add_missing_fk_indexes.sql
- app/Models/Core/Professional/Professional.php
- app/Models/Core/Site/Site.php
- app/Models/Core/Site/SiteMedia.php
- app/Models/Core/MediaVariant.php
- app/Models/Core/Site/Enquiry.php
- app/Models/Core/Waitlist/WaitlistSignup.php
- app/Models/Analytics/LeadSubmission.php
- app/Models/Analytics/CartEvent.php
- app/Models/Core/Notifications/EmailSubscription.php
- app/Models/Retail/BrandStoreSettings.php
- app/Jobs/Shopify/Gdpr/RedactCustomerJob.php
- app/Console/Commands/PurgeSoftDeleted.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 7 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#DATA-1** · P1 — Professional soft-delete leaves public site and child data reachable
    - **Where:** app/Models/Core/Professional/Professional.php (SoftDeletes); app/Models/Core/Site/Site.php (no SoftDeletes, no deleted-at cascade); app/Models/Core/Professional/BrandProfile.php; app/Models/Core/Professional/ProfessionalIntegration.php; app/Models/Core/Professional/BrandPartnerLink.php; app/Models/Billing/Subscription.php; app/Models/Core/Notifications/EmailSubscription.php; app/Models/Retail/BrandStoreSettings.php
    - **Affects:** Any account entering `pending_deletion` → soft-deleted state. The professional's `site.sites` row has no `deleted_at` concept and no FK cascade from a soft-delete, so the site remains published and publicly accessible by subdomain. Child models (BrandProfile, ProfessionalIntegration, BrandPartnerLink, etc.) have no awareness of the parent's soft-deletion, so staff-admin queries and cross-professional relationships still surface those rows. The "deleted account is invisible everywhere" guarantee is broken.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `AccountDeletionService` (or the `pending_deletion` state-machine handler), explicitly set `Site.is_published = false` and record `unpublished_at` when a professional enters `pending_deletion` — this is the fastest fix to stop the public site from serving deleted accounts.
        - Audit every public-facing route (subdomain lookup, public site payload) to confirm it checks `professional.deleted_at IS NULL`; add that join condition where missing.
        - For admin-side staff endpoints, add a visible "account soft-deleted" warning when loading child models for a professional whose `deleted_at IS NOT NULL` — prevents silent stale-data exposure.
        - Long-term: add `SoftDeletes` + `deleted_at` column to `Site` (and a DB-level trigger or application hook to cascade soft-delete from Professional) if full lifecycle coherence is required. This is an XL effort but closes the root cause permanently.
    - **Technical:** Professional uses `SoftDeletes` with a `pending_deletion → deleted_at` lifecycle. However, `site.sites` has no `deleted_at` column and no Eloquent `SoftDeletes` trait — it is hard-deletable only, with an FK `ON DELETE CASCADE` to `core.professionals`. Postgres FK cascades fire only on **hard** deletes, not on Eloquent soft-deletes. When `Professional::delete()` is called (setting `deleted_at`), the Site row is untouched. The public site endpoint resolves sites by subdomain: the Site query does not join against `core.professionals.deleted_at`. A soft-deleted brand's storefront thus remains live and publicly reachable. The pattern repeats for BrandProfile, ProfessionalIntegration, BrandPartnerLink, Subscription, EmailSubscription, and BrandStoreSettings — none carry `SoftDeletes`, so none filter themselves out of queries when the parent Professional is soft-deleted. The authorization architecture (`authorizeForUser`) protects authenticated API calls (the soft-deleted professional can't obtain a valid JWT), but the public/unauthenticated surface is exposed.
    - **Plain English:** Deleting a brand account sets a flag on the account record, but every storefront, profile, and integration linked to it keeps running as if nothing happened. Imagine closing a business's head office but leaving all its shop fronts open, lights on, doors unlocked. Any visitor who knows the URL still gets a fully functional experience. The deletion flag only stops the owner from logging in — it doesn't pull the shutters down on everything else.
    - **Evidence:**
        ```php
        // Professional — soft-deletes via SoftDeletes trait
        class Professional extends BaseModel
        {
            use HasFactory, HasUuids, Notifiable, SoftDeletes;
            // deleted_at is a first-class lifecycle timestamp

        // Site — no SoftDeletes; no deleted_at; FK CASCADE fires only on hard-delete
        class Site extends BaseModel
        {
            use HasUuids;  // no SoftDeletes

        // BrandProfile, ProfessionalIntegration, BrandPartnerLink, Subscription,
        // EmailSubscription, BrandStoreSettings — all lack SoftDeletes:
        class BrandProfile extends BaseModel { use HasUuids; }
        class ProfessionalIntegration extends BaseModel { use HasUuids; }
        class BrandPartnerLink extends BaseModel { use HasUuids; }
        class Subscription extends BaseModel { /* no SoftDeletes */ }
        class EmailSubscription extends BaseModel { use HasUuids; }
        class BrandStoreSettings extends BaseModel { use HasUuids; }
        ```

---

## P2 — Should fix

- [ ] **#DATA-2** · P2 — SiteMedia soft-delete has no Eloquent cascade to MediaVariant; variant files orphaned during force-delete purge
    - **Where:** app/Models/Core/Site/SiteMedia.php:22; app/Models/Core/MediaVariant.php; app/Console/Commands/PurgeSoftDeleted.php:33
    - **Affects:** The 30-day soft-delete window for SiteMedia. During that window, `$siteMedia->mediaVariants` returns all variant rows for a trashed media item, confusing any code that checks variant state on trashed media. More critically: when `PurgeSoftDeleted` calls `SiteMedia::forceDelete()`, Postgres FK cascade (`ON DELETE CASCADE` on `media_id`) deletes the `site.media_variants` rows directly at the DB layer — bypassing Eloquent model events on `MediaVariant`. If `SiteMedia`'s `forceDeleted` observer only cleans up the primary media file path, the physical files for each variant on S3/R2 are silently orphaned.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `static::forceDeleted()` boot hook on `SiteMedia` (or extend the existing observer) that iterates `$siteMedia->mediaVariants()->get()` **before** the force-delete and deletes each variant's physical file from storage.
        - Alternatively, collect variant paths in the `forceDeleting` event and clean up files after the cascade. This keeps S3 and DB in sync regardless of cascade behaviour.
        - Add a note to `MediaVariant` that it intentionally lacks `SoftDeletes` because it is wholly owned by its parent SiteMedia (DB cascade is the delete mechanism).
    - **Technical:** The DB schema is internally consistent: `site.media_variants.media_id` has `ON DELETE CASCADE REFERENCES site.site_media(id)`, so hard-deleting a `SiteMedia` row will delete variant DB rows automatically. The gap is at the Eloquent lifecycle layer. When `SiteMedia::forceDelete()` fires, Eloquent raises `forceDeleted` on the `SiteMedia` instance — but the cascade deletion of `media_variants` rows happens inside the same DB transaction at the Postgres level, triggering no Eloquent events on `MediaVariant`. Any observer that uses `MediaVariant::forceDeleted` to unlink S3 objects will never fire. During the soft-delete window, `$siteMedia->mediaVariants` is also not scoped by the parent's `deleted_at`, so iteration over trashed SiteMedia yields fully live-looking variant collections.
    - **Plain English:** When you delete a photo, the system marks the photo as "in the trash" for 30 days. But all the different sized copies of that photo (thumbnail, full-size, etc.) don't get the same "trash" marker — they keep showing up as perfectly live files. When the trash is emptied after 30 days, the database records for those copies disappear, but the actual image files sitting in cloud storage don't get cleaned up, because the cleanup code only watches for Eloquent-level deletion events, not the database-level cascade that removes the copy records.
    - **Evidence:**
        ```php
        // SiteMedia — SoftDeletes present; cleanup purge calls forceDelete()
        class SiteMedia extends BaseModel
        {
            use HasUuids, SoftDeletes;
            public function mediaVariants(): HasMany
            {
                return $this->hasMany(MediaVariant::class, 'media_id');
            }

        // MediaVariant — no SoftDeletes; DB FK ON DELETE CASCADE handles rows
        //   but Eloquent forceDeleted event never fires on cascaded rows
        class MediaVariant extends BaseModel
        {
            use HasUuids;  // no SoftDeletes

        // PurgeSoftDeleted — force-deletes SiteMedia without pre-collecting variant paths
        $total += $this->purgeModel(SiteMedia::class, $cutoff);
        ```

- [ ] **#DATA-3** · P2 — WaitlistSignup core PII columns absent from `$hidden`
    - **Where:** app/Models/Core/Waitlist/WaitlistSignup.php
    - **Affects:** Any serialisation of a `WaitlistSignup` instance — queue job payloads, `Log::info($signup)`, future API endpoints, broadcast events. Pre-launch applicant names, emails, and phone numbers travel in plaintext wherever `toArray()` / `toJson()` is called on the model.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'name'`, `'email'`, `'email_lc'`, and `'phone'` to `$hidden`.
        - Audit all `WaitlistSignup` usage for endpoints that legitimately need these fields; create an explicit `WaitlistSignupResource` for those responses.
        - Verify whether GDPR `customers/redact` scope should cover waitlist signups (pre-launch EU applicants); if so, wire in a redact path.
    - **Technical:** `WaitlistSignup` already hides `consent_ip_hash` and `consent_user_agent` — showing the team understood the pattern. But `name`, `email`, `email_lc`, and `phone` are in `$fillable` with no matching `$hidden` entry, meaning they are included in every default serialisation. Compare to `BrandAffiliateInvite`, which explicitly hides `email`, `email_lc`, `phone`, `first_name`, `last_name`, `message`, and `token`. The doctrine in CLAUDE.md (Resource classes for API responses) prevents direct API exposure, but queue serialisation, log statements, and any test that inspects `$signup->toArray()` all silently emit full PII.
    - **Plain English:** The waitlist signup form collects real names, emails, and phone numbers from pilot program applicants. The "blur" setting that prevents these from accidentally leaking into system logs and job queues is only switched on for the consent fingerprint — not for the actual contact details. Compare this to the brand invite records, which have the blur properly applied to all personal details. The fix is three lines.
    - **Evidence:**
        ```php
        class WaitlistSignup extends BaseModel
        {
            use HasUuids;

            protected $hidden = [
                'consent_ip_hash',    // ← consent metadata hidden
                'consent_user_agent', // ← consent metadata hidden
                // name, email, email_lc, phone NOT hidden
            ];

            protected $fillable = [
                'name',
                'email',
                'email_lc',
                'phone',
                // ... other fields
            ];
        ```

- [ ] **#DATA-4** · P2 — Enquiry model exposes visitor PII with no `$hidden` guard
    - **Where:** app/Models/Core/Site/Enquiry.php
    - **Affects:** Visitor-submitted contact form data (name, email, phone, message, ip_hash, user_agent). Any serialisation of an `Enquiry` instance — notification job payloads carrying enquiry data to the professional, `Log::info` calls, or future API endpoints — emits submitter identity in plaintext.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$hidden = ['email', 'phone', 'name', 'ip_hash', 'user_agent']` to `Enquiry`.
        - Create an `EnquiryResource` that deliberately surfaces these fields only to the owning professional's authenticated API response.
        - The GDPR redact path already hard-deletes enquiry rows by email address — no change needed there.
    - **Technical:** `Enquiry` stores visitor PII across nine fillable columns with zero `$hidden` entries. The GDPR `customers/redact` webhook correctly hard-deletes enquiry rows (bypassing Eloquent's soft-delete scope), so the deletion path is covered. The gap is at the model's serialisation boundary: any code path that calls `$enquiry->toArray()` — a notification job attaching enquiry context, a Nightwatch log entry, or an unguarded future endpoint — emits raw submitter identity. `DataExportAudit` and `ProfessionalDeletionAuditEntry` both set `$hidden` for their sensitive columns; `Enquiry` should follow the same pattern.
    - **Plain English:** When a visitor fills out the contact form on a professional's site, their name, email, phone number, and message are stored. Right now, those details have no privacy wrapper on the data object — if the system writes the enquiry into a background job, a log, or a notification email, the visitor's details travel completely exposed rather than in an envelope. The fix is adding a short list of column names that should stay private by default.
    - **Evidence:**
        ```php
        class Enquiry extends BaseModel
        {
            use HasUuids, SoftDeletes;

            protected $fillable = [
                'professional_id',
                'site_id',
                'name',      // ← no $hidden entry
                'email',     // ← no $hidden entry
                'phone',     // ← no $hidden entry
                'subject',
                'message',   // ← no $hidden entry
                'ip_hash',   // ← no $hidden entry
                'user_agent',// ← no $hidden entry
                'read_at',
            ];
            // No $hidden array defined — contrast with DataExportAudit:
            // protected $hidden = ['professional_email_snapshot', 'recipient_email', 'file_sha256'];
        ```

- [ ] **#DATA-5** · P2 — GDPR `customers/redact` does not null `customer_id` in `analytics.lead_submissions`
    - **Where:** app/Jobs/Shopify/Gdpr/RedactCustomerJob.php; supabase/migrations/20260403000000_v2_baseline.sql (analytics.lead_submissions table definition)
    - **Affects:** Any customer who submits a GDPR deletion request (`customers/redact`). After the job runs, `core.customers` is anonymised and `site.enquiries` / `notifications.email_subscriptions` are hard-deleted. However, `analytics.lead_submissions` rows keyed by `customer_id` retain their FK link to the (now-anonymised) customer, as well as `ip_hash` and `user_agent` values that were recorded at submission time.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `RedactCustomerJob::handle()`, after `$customer->update([...])` (anonymisation block), add a `DB::connection('pgsql')->table('analytics.lead_submissions')->where('customer_id', $customer->id)->update(['customer_id' => null, 'ip_hash' => null, 'user_agent' => null])` call.
        - Add this to the `$scrubbedOrders` counter pattern (name it `$scrubbed_leads`) so it appears in the completion log line.
        - Update the `ExportCustomerDataJob` unit tests to assert that redacted customers no longer have lead_submissions linked via `customer_id`.
    - **Technical:** `RedactCustomerJob` explicitly handles `analytics.booking_events` because its comment notes "those rows have no customer_id FK so they don't cascade via the customers anonymisation." By the same logic, `analytics.lead_submissions` DOES have a `customer_id FK ... ON DELETE SET NULL` — but that rule only fires on a **hard-delete** of the customer row, not on an anonymisation update. Since the job anonymises (updates) rather than deletes the customer row, the FK's `ON DELETE SET NULL` never triggers. Lead submissions thus retain a live `customer_id` pointing to the anonymised customer record, plus `ip_hash` / `user_agent` fields that were recorded at form-submission time. The `ExportCustomerDataJob` proves these fields are considered PII: it includes lead submissions keyed by `customer_id` in the data export. The redact path should be symmetric.
    - **Plain English:** When someone asks the system to delete their data, the job correctly wipes their customer profile, email subscription, and contact enquiries. But it misses one table: the form-submission analytics log, which still has a reference linking back to their (now scrambled) profile, along with their browser fingerprint from when they filled out the form. It's like shredding a person's file but leaving a sticky note on the shredder that says "this file belonged to customer #1234." The data request job already knows to export this table — the deletion job should be symmetric.
    - **Evidence:**
        ```php
        // RedactCustomerJob — handles email_subscriptions, enquiries,
        // booking_events, and orders — but NOT lead_submissions:

        $deletedSubs = DB::connection('pgsql')
            ->table('notifications.email_subscriptions')
            ->where('professional_id', $professionalId)
            ->where('email_lc', $emailLc)
            ->delete();

        $deletedEnquiries = DB::connection('pgsql')
            ->table('site.enquiries')
            ->where('professional_id', $professionalId)
            ->whereRaw('LOWER(email) = ?', [$emailLc])
            ->delete();

        // analytics.lead_submissions ← not touched; customer_id and
        // ip_hash/user_agent remain after customer anonymisation
        ```
        ```sql
        -- Migration confirms FK is SET NULL (fires on hard-delete only):
        ADD CONSTRAINT lead_submissions_customer_fk
            FOREIGN KEY (customer_id) REFERENCES core.customers(id) ON DELETE SET NULL;
        ```

- [ ] **#DATA-6** · P2 — `PurgeSoftDeleted` omits `Enquiry`; soft-deleted enquiry PII accumulates past retention window
    - **Where:** app/Console/Commands/PurgeSoftDeleted.php:31–35; app/Models/Core/Site/Enquiry.php (SoftDeletes present)
    - **Affects:** Visitors whose contact enquiries a professional "archives" (soft-deletes from their inbox). The soft-deleted rows contain name, email, phone, message, ip_hash, and user_agent. Without a purge step, these rows accumulate indefinitely — the 30-day retention guarantee documented in CLAUDE.md does not apply in practice because no job enforces it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$total += $this->purgeModel(Enquiry::class, $cutoff);` to `PurgeSoftDeleted::handle()`.
        - Verify the `partna:purge-soft-deletes` command is scheduled in `bootstrap/app.php` / `routes/console.php` (confirm it runs daily).
        - Consider adding `ServiceCategory::class` to the purge list as well (it has `SoftDeletes` and is also missing from the purge list, though it contains no PII).
    - **Technical:** `app/Console/Commands/PurgeSoftDeleted.php` explicitly purges `Customer`, `Service`, and `SiteMedia` soft-deleted rows past the retention window. `Enquiry` uses `SoftDeletes` and stores visitor PII in nine columns, yet it is absent from the purge list. The result is that soft-deletes by the professional (e.g., clearing their enquiry inbox) never trigger the 30-day purge path. Note: the GDPR `customers/redact` path correctly hard-deletes enquiries via a direct `DB::table(...)->delete()` call (bypassing Eloquent soft-delete), so GDPR compliance is not broken by this gap — but first-party retention is. This is the same retention guarantee the codebase advertises (`SOFT_DELETE_RETENTION_DAYS` config) that silently fails to apply.
    - **Plain English:** The system promises that deleted items are only kept for 30 days before being permanently wiped. There's an automated cleanup job that runs this promise for customer profiles, services, and media files. But contact form submissions are quietly missing from that list — if a professional deletes an enquiry from their inbox, the visitor's name and email sit permanently in the database rather than disappearing after a month. The fix is one line added to the cleanup job.
    - **Evidence:**
        ```php
        // PurgeSoftDeleted::handle() — Enquiry is absent from the purge list
        $total += $this->purgeModel(Customer::class, $cutoff);
        $total += $this->purgeModel(Service::class, $cutoff);
        $total += $this->purgeModel(SiteMedia::class, $cutoff);
        // ← Enquiry::class not included; soft-deleted enquiry PII never purged

        // Enquiry — SoftDeletes present; contains visitor PII
        class Enquiry extends BaseModel
        {
            use HasUuids, SoftDeletes;
            protected $fillable = [
                'name', 'email', 'phone', 'message', 'ip_hash', 'user_agent', // ...
            ];
        ```

- [ ] **#DATA-7** · P2 — `EmailSubscription.status` is an unguarded varchar with no DB CHECK constraint
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql:1035; app/Models/Core/Notifications/EmailSubscription.php
    - **Affects:** Marketing consent integrity. `status` is the canonical source of truth for `Customer.marketing_opt_in_cached`. An invalid status value (from a raw SQL insert, a future bulk-import bug, or a migration typo) silently drops consent: `isMarketingOptedIn()` returns `false` for any value that isn't exactly `'subscribed'`, meaning affected professionals stop receiving marketing emails without an audit trail.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add migration: `ALTER TABLE notifications.email_subscriptions ADD CONSTRAINT email_subscriptions_status_check CHECK (status IN ('subscribed', 'unsubscribed'));`
        - Backfill and inspect any existing rows with non-conforming status values before applying.
        - Consider a matching CHECK on `list_key` if the set of list keys is closed (e.g., only `'marketing'` today).
    - **Technical:** `EmailSubscription.status` is `varchar(20) NOT NULL DEFAULT 'subscribed'` with no CHECK constraint. The two valid values (`'subscribed'` / `'unsubscribed'`) are enforced only at the application layer via `markSubscribed()` and `markUnsubscribed()`. Any path that bypasses Eloquent — a raw `DB::statement`, a migration backfill, or a future import job — can write an arbitrary string. The `Customer.isMarketingOptedIn()` method checks `status === 'subscribed'` (exact match), so `'active'`, `'opted_in'`, or an extra space would silently return `false`, suppressing legitimate marketing delivery. Compare to `brand.brand_team_memberships`, which has `CONSTRAINT brand_team_memberships_role_check CHECK (role IN ('owner','finance','marketing','analyst','read_only'))` and `CONSTRAINT brand_team_memberships_status_check CHECK (status IN ('active','inactive'))` — the same table-level pattern should be applied to consent state.
    - **Plain English:** The email opt-in table records whether someone said "yes" or "no" to marketing. The database has no rule enforcing that only those two answers are valid. If a bug or bad data import writes anything else — "active," "opted_in," or even a typo — the system quietly treats it as "no" and stops sending emails. The person thinks they're opted in; the system thinks otherwise. Adding a simple database rule that rejects anything but "subscribed" or "unsubscribed" prevents this from happening silently.
    - **Evidence:**
        ```sql
        -- v2_baseline: no CHECK constraint on status column
        CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
            -- ...
            status varchar(20) DEFAULT 'subscribed' NOT NULL,
            -- ... no CONSTRAINT email_subscriptions_status_check
        );
        ```
        ```php
        // Application-layer only — any raw DB path bypasses this:
        public function markSubscribed(array $meta = []): void
        {
            $this->status = 'subscribed';
        }

        public function markUnsubscribed(): void
        {
            $this->status = 'unsubscribed';
        }
        ```

---

## P3 — Nice to have

- [ ] **#DATA-8** · P3 — `LeadSubmission` missing Eloquent `BelongsTo` relationship methods
    - **Where:** app/Models/Analytics/LeadSubmission.php
    - **Affects:** Any developer code that tries to eager-load or traverse `$lead->professional`, `$lead->site`, or `$lead->customer` relationships — currently forces raw `DB::table()` queries or produces a fatal method-not-found error. The GDPR export and redact paths already use raw queries, so this is a developer-experience and consistency gap rather than a runtime bug.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `professional(): BelongsTo`, `site(): BelongsTo`, and `customer(): BelongsTo` relationship methods mirroring the pattern in `CartEvent`, `SiteVisit`, and `LinkClick`.
        - Note: all three FK constraints already exist at the DB level with `ON DELETE SET NULL` — this is purely an Eloquent layer gap.
    - **Technical:** The baseline migration defines three FK constraints on `analytics.lead_submissions`: `professional_fk` (SET NULL), `site_fk` (SET NULL), and `customer_fk` (SET NULL). The DB integrity layer is complete. The Eloquent model is the only outlier among analytics models — `SiteVisit`, `LinkClick`, and `CartEvent` all define `professional()` and `site()` `BelongsTo` methods. Without them, `LeadSubmission` cannot participate in `with(['professional', 'site'])` eager loads, and any future dashboard code that treats it the same as sibling analytics models will fail silently or throw.
    - **Plain English:** Every other analytics model has built-in "connectors" that let the code say "give me the professional and site for this event" in one step. The lead submissions model has those connectors defined in the database but hasn't wired them up in the application code. It works right now because the GDPR jobs use manual queries — but it's the odd one out among its siblings and will trip up the next developer who writes analytics code assuming consistency.
    - **Evidence:**
        ```php
        // LeadSubmission — no BelongsTo methods despite site_id, professional_id, customer_id in $fillable
        class LeadSubmission extends BaseModel
        {
            use HasUuids;
            protected $fillable = [
                'site_id',
                'professional_id',
                'customer_id',
                // ...
            ];
            // No professional(), site(), or customer() relationship methods
        }

        // Contrast CartEvent — same schema pattern, relationships present:
        public function professional(): BelongsTo
        {
            return $this->belongsTo(Professional::class, 'professional_id');
        }
        public function site(): BelongsTo
        {
            return $this->belongsTo(Site::class, 'site_id');
        }
        ```

- [ ] **#DATA-9** · P3 — `BrandStoreSettings` missing `professional()` Eloquent relationship
    - **Where:** app/Models/Retail/BrandStoreSettings.php
    - **Affects:** Any code that tries to traverse `$settings->professional` — forces a raw query workaround or produces a fatal error. The DB-level FK (`REFERENCES core.professionals(id) ON DELETE CASCADE`) is intact; this is a model-layer consistency gap.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public function professional(): BelongsTo { return $this->belongsTo(Professional::class, 'professional_id'); }`.
    - **Technical:** The v2 baseline migration defines `professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE` with a `UNIQUE (professional_id)` constraint on `brand.brand_store_settings`. The DB relationship is fully enforced. The Eloquent model stores `professional_id` in `$fillable` and uses it in `clearWizardProgress(string $professionalId)`, but no `BelongsTo` relationship is declared. This breaks the `$this->belongsTo()` pattern used universally elsewhere (every other model that holds a `professional_id` FK defines the method). Staff controllers or analytics code that loads `BrandStoreSettings` alongside its owner currently cannot use `with('professional')` eager loading.
    - **Plain English:** The store settings table has a database-level link to the brand that owns it, but the application layer hasn't been told about that link. Every other table that has an owner has the connection explicitly wired — this one was missed. Adding it is a one-line fix that makes the model consistent with everything else.
    - **Evidence:**
        ```php
        class BrandStoreSettings extends BaseModel
        {
            use HasUuids;

            protected $fillable = [
                'professional_id',  // ← FK exists at DB level; relationship missing in Eloquent
                'default_commission_rate',
                'payout_hold_days',
                // ...
            ];
            // No professional(): BelongsTo method defined
        ```

- [ ] **#DATA-10** · P3 — `BrandStoreSettings.hydrogen_install_confirmed` missing explicit boolean cast
    - **Where:** app/Models/Retail/BrandStoreSettings.php:30–38
    - **Affects:** Any code reading `$settings->hydrogen_install_confirmed` without an explicit `(bool)` cast — theoretically fragile across driver configurations and future connection changes, though in practice the pgsql driver returns native PHP booleans for `BOOLEAN` columns today.
    - **Effort:** S (< 30 min)
    - **What to do:**
        - Add `'hydrogen_install_confirmed' => 'boolean'` to the `$casts` array.
    - **Technical:** Every other typed column in `BrandStoreSettings` has an explicit cast: `default_commission_rate => decimal:2`, `payout_hold_days => integer`, `theme_id => integer`, `oxygen_deployment_token => encrypted`. `hydrogen_install_confirmed` is the sole outlier — it stores a boolean for the Shopify Hydrogen storefront wizard step but relies on implicit driver-level type coercion. The pgsql driver in modern versions correctly returns `true`/`false` for `BOOLEAN` columns, so this is not a current bug. The inconsistency is a maintenance hazard: the next developer adding a cast to this model may miss `hydrogen_install_confirmed` and introduce a real type mismatch.
    - **Plain English:** Every other field in this table that has a data type is explicitly labeled with that type in the application code — except this one yes/no flag about whether the Shopify Hydrogen install is confirmed. It works today because the database driver handles it correctly, but it's the only unlabeled switch on the panel. Adding the label makes the code consistent and prevents future confusion.
    - **Evidence:**
        ```php
        protected $casts = [
            'default_commission_rate' => 'decimal:2',
            'payout_hold_days' => 'integer',
            'theme_id' => 'integer',
            'oxygen_deployment_token' => 'encrypted',
            // hydrogen_install_confirmed ← in $fillable but absent from $casts
        ];

        protected $fillable = [
            // ...
            'hydrogen_install_confirmed',
        ];
        ```

`★ Insight ─────────────────────────────────────`
Two of the highest-value findings here were DeepSeek misses discovered by reading the GDPR jobs end-to-end: the `customers/redact` webhook is carefully implemented for most tables but has an asymmetry with its own `ExportCustomerDataJob` sibling (DATA-5), and the `PurgeSoftDeleted` purge command has a systematic gap for `Enquiry` despite the model having `SoftDeletes` (DATA-6). Reading both sides of a lifecycle contract — export and redact, soft-delete and purge — is the most reliable way to find these gaps.
`─────────────────────────────────────────────────`
