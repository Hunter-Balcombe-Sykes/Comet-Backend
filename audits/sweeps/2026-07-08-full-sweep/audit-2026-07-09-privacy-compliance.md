# Privacy & Data-Rights Compliance Audit — 2026-07-09

**Branch:** development
**Lens:** Privacy & data-rights compliance: PII inventory, export/delete completeness, retention enforcement, processor flows
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/User/DataExport/DataExportPayloadBuilder.php
- app/Models/Analytics/{SiteVisit,LinkClick,SectionView,LeadSubmission}.php
- app/Models/Core/Site/IntegrationConnection.php
- app/Services/User/AccountDeletionService.php
- app/Services/Audit/StaffAuditService.php
- app/Services/Moderation/EvidenceSnapshotService.php
- app/Console/Commands/{PruneResolvedCaseSignalsPiiCommand,PurgeRawAnalyticsEvents}.php
- app/Services/Analytics/{AnalyticsEvent,AnalyticsEventSanitizer,AnalyticsQueryService}.php
- app/Services/Analytics/Writers/PostgresEventWriter.php
- app/Http/Controllers/Concerns/DetectsClientInfo.php
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Http/Controllers/Api/PublicSite/{PublicEnquiryController,PublicWaitlistController}.php
- app/Services/Notifications/EnquirySpamBlocklist.php
- config/partna.php, routes/console.php
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/migrations/20260527010000_reorganize_schemas.sql
- supabase/migrations/20260527030000_rename_professional_to_user.sql
- supabase/migrations/20260707020000_site_visits_lat_lon.sql
- supabase/migrations/20260708124853_staff_audit_log_ip_hash_and_get_reads.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 12 complete

---

## P1 — Fix before pilot launch

- [ ] **#PRIV-1** · P1 — Analytics visitor data (site_visits, link_clicks, section_views) absent from GDPR export
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php:123-169 (`sectionDescriptors`), :478-488 (`streamLeadSubmissions` — the only analytics section wired in)
    - **Affects:** All sitepage visitors whose indirect identifiers (visitor_id, session_id, geo, UTM) are stored under a professional's account but cannot be surfaced to that professional under an Article-15-equivalent access request; also the professional, who cannot see their own full visitor ledger.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add three new descriptor entries to `sectionDescriptors()` — `analytics.site_visits`, `analytics.link_clicks`, `analytics.section_views` — each a `'rows'` generator scoped by `user_id`, following the existing `streamLeadSubmissions()` pattern.
        - Mirror the `streamEnquiries()`/`streamLeadSubmissions()` redaction convention: drop `ip_hash` and `user_agent` (technical fingerprints already excluded elsewhere in this builder for consistency) and keep `visitor_id`, `session_id`, `country_code`, `region_code`, `city`, `device_type`, `occurred_at`, UTM fields.
        - No `stream()`/`build()` change needed — both entry points derive automatically from `sectionDescriptors()`.
    - **Technical:** `sectionDescriptors()` is confirmed to be the single manifest both `build()` and `stream()` iterate (per its own docblock, "the single source of truth... adding a new exportable section means adding ONE entry here"). Only one analytics table (`lead_submissions`) has an entry; `SiteVisit`, `LinkClick`, and `SectionView` — verified via `$fillable` — carry the same class of visitor data (`visitor_id`, `session_id`, `ip_hash`, `user_agent`, geo fields) and are omitted entirely. This is a straightforward completeness gap against the builder's own established pattern, not a hypothetical.
    - **Plain English:** When someone visits a professional's page, Partna records anonymised visit data — a scrambled fingerprint, country, device type, which links they clicked. The professional has a legal right to a full copy of everything Partna holds tied to their account. Right now that copy includes customer lists and enquiries, but silently skips every visit and click event for their own site. It's like a shopkeeper getting their customer ledger but being told nothing about the foot-traffic counter — the records exist, they're just never mentioned.
    - **Evidence:**
        ```php
        ['name' => 'lead_submissions', 'kind' => 'rows', 'resolve' => fn () => $this->streamLeadSubmissions($userId)],
        // No sections for analytics.site_visits, analytics.link_clicks, or analytics.section_views.
        ```
        ```php
        // app/Models/Analytics/SiteVisit.php
        protected $fillable = [
            'occurred_at', 'session_id', 'visitor_id', 'ip_hash', 'user_agent',
            'referrer', 'utm_source', 'utm_medium', 'utm_campaign',
            'country_code', 'region_code', 'city', 'device_type',
        ];
        ```

- [ ] **#PRIV-2** · P1 — Platform integration connections not exported for individual accounts
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php:139, :245-249 (`streamIntegrations`); app/Models/Core/Site/IntegrationConnection.php:39-58
    - **Affects:** Every professional who has connected a social/media platform (Instagram, YouTube, Spotify, Google Business, etc.) — their profile URLs, display names, and curated content selections stored in `site.platform_connections` never appear in their data export.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the hardcoded empty `streamIntegrations()` generator with one that streams `site.platform_connections` scoped by `user_id`.
        - Redact internal-only payload keys (refresh tokens, internal source URLs) — keep the user-facing profile/curation fields.
        - No descriptor change needed — the `integrations` section is already wired into `sectionDescriptors()`, only the generator body is a stub.
    - **Technical:** `streamIntegrations()`'s comment — "No integrations for individual-standalone accounts; yield nothing" — is stale. Per the shipped Platform Integrations Registry Redesign (complete 2026-06-29, contract frozen at 52 platforms) and `IntegrationConnection` (table `site.platform_connections`), this is now a live, actively-used individual-account feature, not a removed commerce concept. The `payload` JSONB column holds the account holder's own profile URL, display name, and curated selections — personal data the export must surface.
    - **Plain English:** Partna lets professionals connect their Instagram, YouTube, or Spotify profile and pick which posts show on their page. That data lives in Partna's database. But when the professional requests a full copy of their data, every connected platform is missing — like asking your bank for your records and getting your savings statement but not your credit card. The export tool already knows this table exists; it just resolves to nothing.
    - **Evidence:**
        ```php
        private function streamIntegrations(string $userId): Generator
        {
            // No integrations for individual-standalone accounts; yield nothing.
            yield from [];
        }
        ```
        ```php
        // app/Models/Core/Site/IntegrationConnection.php
        protected $table = 'site.platform_connections';
        protected $fillable = [
            'user_id', 'platform', 'resource_id', 'canonical_key', 'resource_kind',
            'payload', 'sort_order', 'is_active', 'last_visited_at', 'last_refreshed_at',
            'last_refresh_status', 'last_refresh_error', 'consecutive_failures',
            'apify_status', 'place_id', 'refresh_etag', 'refresh_last_modified',
            'display_settings',
        ];
        ```

- [ ] **#PRIV-3** · P1 — Handle-change audit retention (7 years) declared in config but never enforced
    - **Where:** config/partna.php:54-55 (`handle.audit_retention_years`); routes/console.php:116-129 (only alias-lifecycle commands scheduled)
    - **Affects:** Every professional who renames their handle — `audit.handle_change_log` rows accumulate unbounded, contradicting the platform's own declared 7-year retention promise.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a scheduled command (e.g. `handles:prune-audit-log`) that hard-deletes `audit.handle_change_log` rows older than `config('partna.handle.audit_retention_years')`.
        - Register it in `routes/console.php` following the existing `->dailyAt()->onOneServer()->onFailure(...)` convention used by every other scheduled purge.
    - **Technical:** `handle.audit_retention_years` defaults to 7 and is env-tunable, but no command anywhere in `app/Console/Commands` or `routes/console.php` references it (confirmed by search). The two handle-related scheduled commands present (`handles:prune-expired-aliases`, `handles:notify-expiry`) manage alias lifecycle only, not the audit trail. Per this lens's own calibration: a declared retention value with no enforcement job is config that lies — if a user or regulator later asks whether the 7-year window is real, the honest answer today is no.
    - **Plain English:** The platform's settings say "we keep handle-change history for 7 years." But nothing actually deletes rows after 7 years — they'll pile up forever. It's like setting a shredding policy for a filing cabinet but never scheduling anyone to do the shredding. If a user or regulator ever asks whether the platform enforces its own stated retention promise, right now the answer is no.
    - **Evidence:**
        ```php
        // config/partna.php
        // Years to retain handle_change_log rows. 7y matches typical fraud-investigation retention.
        'audit_retention_years' => (int) env('SIDEST_HANDLE_AUDIT_RETENTION_YEARS', 7),
        ```
        ```php
        // routes/console.php — the only two handle-related scheduled commands
        Schedule::command('handles:prune-expired-aliases')->dailyAt('03:15')...   // alias lifecycle, not audit
        Schedule::command('handles:notify-expiry')->dailyAt('09:00')...          // alias notifications, not audit
        // No handles:prune-audit-log or equivalent exists anywhere in the codebase.
        ```

## P2 — Should fix

- [ ] **#PRIV-4** · P2 — `EnquirySpamBlocklist` stores hashed visitor emails in Redis with no rights-exercise path
    - **Where:** app/Services/Notifications/EnquirySpamBlocklist.php:1-50
    - **Affects:** Visitors whose email is added to a professional's spam blocklist — the deterministic HMAC-SHA256 hash lives in Redis for 90 days with no way to look up or remove a specific entry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `remove(string $userId, string $email)` method (Redis `ZREM` on the hashed member) so a visitor's right-of-erasure request can be honoured before the 90-day TTL expires.
        - Document the blocklist as pseudonymous (not anonymous) personal data in the PII inventory.
    - **Technical:** `hash()` is `hash_hmac('sha256', strtolower(trim($email)), config('app.key'))` — deterministic, so `contains()` can confirm whether a specific email is blocklisted, making this pseudonymous rather than anonymous data under privacy law. No export builder, deletion service, or staff tool references this key, and there is no removal method — only `add()`/`addWithExpiry()`/`contains()` exist.
    - **Plain English:** When a professional marks an enquiry as spam, the system remembers that sender's email (scrambled) so they can't spam again — like a bouncer's banned-patron list. But if that person asks "please delete my data," there's no button that removes them from the list early; it only expires naturally after 90 days. The data is there, but the rights machinery can't reach it.
    - **Evidence:**
        ```php
        public function add(string $userId, string $email): void
        {
            $expiresAt = now()->addDays(self::TTL_DAYS)->timestamp;
            $this->addWithExpiry($userId, $email, $expiresAt);
        }

        private function hash(string $email): string
        {
            return hash_hmac('sha256', strtolower(trim($email)), (string) config('app.key'));
        }
        // No remove() method exists; no export/deletion path references this key.
        ```

- [ ] **#PRIV-5** · P2 — `pseudonymiseAccountPii()` overwrites `first_name`/`last_name` but leaves `display_name` live
    - **Where:** app/Services/User/AccountDeletionService.php:267-282
    - **Affects:** Professionals in the 30-day deletion grace period — their public display name (often their real name) survives one-way pseudonymisation while first/last name are redacted.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'display_name' => 'Deleted Professional'` to the `forceFill()` call in `pseudonymiseAccountPii()`.
        - Check `restoreEmailFromAuditSnapshot()`/`restoreSiteAndStatus()` to confirm `display_name` doesn't need a symmetric restore path for the cancel flow (it currently isn't touched either way, so cancel already "just works" — verify this still holds once the write side changes).
    - **Technical:** `pseudonymiseAccountPii()` redacts `phone`, `primary_email`, `first_name`, `last_name`, and all `location_*` fields but skips `display_name`, which is typically the professional's real name and is the field actually rendered on the (now-unpublished) public sitepage. The method's own docblock frames it as "one-way pseudonymisation of live PII columns" — `display_name` is a direct identifier that fits that description but was never added to the list.
    - **Plain English:** When a professional confirms account deletion, Partna immediately scrubs their personal details — placeholder email, cleared name fields, wiped phone and address. But it leaves their "display name" (usually their real name) untouched. It's a small inconsistency, like blacking out someone's first and last name on a form but leaving the "full name" field filled in right next to it. The site is already taken offline at this point, so no visitor sees it — it's an incomplete redaction during the 30-day window before final deletion.
    - **Evidence:**
        ```php
        protected function pseudonymiseAccountPii(User $professional): void
        {
            $professional->forceFill([
                'phone' => 'redacted',
                'primary_email' => "deleted+{$professional->id}@partna.au",
                'first_name' => 'Deleted',
                'last_name' => null,
                'public_contact_email' => null,
                'public_contact_number' => null,
                'location_street_address' => null,
                'location_postcode' => null,
                'location_city' => null,
                'location_state' => null,
                'location_country' => null,
            ])->save();
        }
        // display_name is NOT in the forceFill array.
        ```

- [ ] **#PRIV-6** · P2 — `core.feedback` has no age-based retention rule — accumulates indefinitely while the account stays active
    - **Where:** config/partna.php (no `feedback_retention_days`-equivalent key exists); routes/console.php (no feedback-pruning command); app/Services/User/AccountDeletionService.php:692-705 (`purgeFeedbackRows` — deletion-triggered only)
    - **Affects:** Every professional who submits in-app feedback — `reply_email`, free-text `message`, and `internal_notes` persist forever as long as the account remains active; there is no independent expiry the way notifications, raw analytics, and export artifacts all have.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `feedback_retention_days` config key and a scheduled command (mirroring `PruneNotifications`) that ages out old, resolved feedback rows.
        - Alternatively, if indefinite retention of feedback is a deliberate support/QA decision, add an explicit comment in `config/partna.php` next to the other retention keys stating so — right now the absence looks like an oversight, not a decision.
    - **Technical:** Every other PII-bearing append surface in `config/partna.php` has a declared retention window — `notification_retention_days`, `analytics_raw_event_retention_days` (90), `gdpr.export_retention_days` (30) — each with a matching `Schedule::command(...)` entry in `routes/console.php` (`partna:prune-notifications`, `partna:analytics:purge-raw-events`, `gdpr:prune-completed-exports`). `core.feedback` has none; `AccountDeletionService::purgeFeedbackRows()` only fires when the submitting user hard-deletes their account, so an active user's oldest feedback rows — including their reply email address — live in the table with no clock on them at all.
    - **Plain English:** When someone leaves feedback inside the app, that message — including their email and whatever they wrote — sits in the database forever as long as their account stays open. Every other logbook the platform keeps (notifications, visitor analytics, downloaded data copies) already has an automatic expiry date. Feedback is the one exception nobody set a clock on.
    - **Evidence:**
        ```php
        // config/partna.php — every sibling retention window has a value; feedback has none
        'notification_retention_days' => [ 'policy_update' => 365, 'incident' => 14, 'feature_announcement' => 30, 'default' => 30, 'profile_task' => 180 ],
        'analytics_raw_event_retention_days' => ... 90,
        'export_retention_days' => (int) env('GDPR_EXPORT_RETENTION_DAYS', 30),
        ```
        ```php
        // AccountDeletionService::purgeFeedbackRows() — the ONLY purge path, deletion-triggered only
        private function purgeFeedbackRows(User $professional): void
        {
            DB::connection('pgsql')->table('core.feedback')->where('user_id', $professional->id)->delete();
        }
        ```

- [ ] **#PRIV-7** · P2 — Moderation evidence PII has no age-based retention rule — only cleared on account deletion
    - **Where:** app/Services/Moderation/EvidenceSnapshotService.php:53-68 (`snapshotSite`); app/Console/Commands/PruneResolvedCaseSignalsPiiCommand.php:78-82 (scoped to `moderation.case_signals` only); app/Services/User/AccountDeletionService.php:737-788 (`purgeReportedUserEvidencePii`)
    - **Affects:** Any professional whose sitepage is reported, whose case later resolves, but who never deletes their account — their `handle` and `display_name` remain frozen in `moderation.evidence.payload` indefinitely, with no time-based sweep parallel to the one that already exists for reporter PII.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extend the weekly `moderation:prune-resolved-signal-pii` command (or add a sibling command) to also redact `handle`/`display_name`/`site_subdomain` on `moderation.evidence` rows for cases resolved past the retention window — using the same tombstone-overwrite strategy (`'[redacted]'`) as the existing deletion-triggered path, so the payload shape and `content_hash` tamper-evidence stay intact.
    - **Technical:** Correcting the draft's premise: the deletion path is **not** missing — `AccountDeletionService::purgeReportedUserEvidencePii()` already tombstones `handle`/`display_name`/`site_subdomain` in `moderation.evidence.payload` when the reported user hard-deletes their account. What's genuinely missing is the **age-based** path: `PruneResolvedCaseSignalsPiiCommand` (the only scheduled PII sweep in moderation) queries `moderation.case_signals` exclusively and never touches `moderation.evidence`, so a resolved case's evidence snapshot keeps the reported user's identifiers live for as long as that account stays open — unlike reporter PII, which the same command already erases 90 days after case resolution.
    - **Plain English:** When someone reports a professional's page, the system snapshots what the page looked like at that moment — including the professional's name and handle — as evidence. There's already a cleanup rule for the reporter's side of this (their details get scrubbed 90 days after a case closes) and a rule for when the reported professional deletes their whole account. But if the reported professional keeps their account and the case simply closes, their name stays frozen in that evidence snapshot forever, with no expiry date at all.
    - **Evidence:**
        ```php
        // EvidenceSnapshotService::snapshotSite() — captures reported-user PII
        return [
            'site_id' => $site->id,
            'site_subdomain' => $site->subdomain ?? null,
            'user_id' => $site->user_id,
            'handle' => $site->user?->handle ?? null,
            'display_name' => $site->user?->display_name ?? null,
            'block_count' => $site->blocks?->count() ?? 0,
            'block_types' => $site->blocks?->pluck('block_type')->all() ?? [],
        ];
        ```
        ```php
        // PruneResolvedCaseSignalsPiiCommand — targets case_signals only, never moderation.evidence
        $query = DB::connection('pgsql')->table('moderation.case_signals')
            ->whereIn('case_id', $caseIds)
            ->whereNotNull('reporter_email');
        ```

- [ ] **#PRIV-8** · P2 — `analytics.lead_submissions` is orphaned (not cascaded) by account deletion, unlike sibling analytics tables
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql:1202-1204 (`lead_submissions_professional_fk ... ON DELETE SET NULL`) vs. :1146-1147 / :1175-1177 / :1234-1236 (`site_visits`/`link_clicks`/`section_views` all `ON DELETE CASCADE`); app/Services/User/AccountDeletionService.php:564-573 (`purge()` step list)
    - **Affects:** Visitors who submitted lead forms on a professional's site before that professional deleted their account — their `ip_hash`/`user_agent`/`referrer` survive as orphaned rows (`user_id`/`site_id`/`customer_id` nulled) until the next scheduled 90-day age-based analytics purge.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an explicit `purgeLeadSubmissionRows()` step to `AccountDeletionService::purge()`, mirroring the existing `purgeFeedbackRows()` pattern, so orphaned lead-submission rows are hard-deleted immediately at account purge rather than waiting out the remainder of the 90-day window.
    - **Technical:** This corrects the draft's premise on both sides. `site_visits`, `link_clicks`, and `section_views` all carry `professional_fk ... ON DELETE CASCADE` (verified directly in the baseline migration) — they are already correctly and immediately erased the instant `professional->forceDelete()` runs; no code change is needed for them. Only `lead_submissions` uses `ON DELETE SET NULL` — the same FK shape as `core.feedback`, which already gets an explicit purge step precisely because SET NULL survivors need one. The exposure window here is bounded (≤90 days, enforced daily by `partna:analytics:purge-raw-events`), so this is a consistency/hardening gap, not an unbounded-retention failure.
    - **Plain English:** When a professional deletes their account, three of the four visitor-analytics tables clean themselves up automatically — the database is wired to do it. The fourth, lead-form submissions, isn't wired that way; it behaves like feedback (which the system already explicitly cleans up on deletion) but doesn't get the same explicit cleanup step. Worst case, those rows linger for up to 90 days regardless — not forever — but the inconsistency is worth closing to match the pattern already used elsewhere.
    - **Evidence:**
        ```sql
        CONSTRAINT lead_submissions_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE SET NULL,
        ```
        ```sql
        CONSTRAINT site_visits_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
        ```
        ```php
        // AccountDeletionService::purge() — feedback gets an explicit step; lead_submissions does not
        $this->purgeFeedbackRows($professional);         // #P2-10: feedback (FK is SET NULL, not CASCADE)
        // Nothing equivalent for analytics.lead_submissions.
        ```

- [ ] **#PRIV-9** · P2 — Referrer path can carry personal identifiers from social platforms
    - **Where:** app/Services/Analytics/AnalyticsEventSanitizer.php:27-43 (`referrer()`)
    - **Affects:** Visitors arriving from social-media profile pages (e.g. `facebook.com/jane.doe/posts/123`, `linkedin.com/in/janedoe`) — their username/profile slug is stored in the referrer column for the full analytics retention window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For known social-platform hosts (Facebook, Instagram, LinkedIn, X/Twitter, TikTok, Reddit), reduce the stored referrer to origin-only (`scheme://host`).
        - Keep origin+path for other referrers (organic search etc.) where the path carries routing information, not personal identifiers.
    - **Technical:** `referrer()` strips the query string (removing UTM-embedded PII — already correct) but preserves `scheme://host/path`. On social platforms the path routinely embeds a username or profile identifier. This is applied consistently everywhere a referrer is persisted (`PostgresEventWriter`, `LogLeadRateLimits`, `PublicEnquiryController`, `PublicCustomerLeadController`), so the fix is centralised in one place.
    - **Plain English:** When someone clicks through to a professional's page from Facebook, the analytics system correctly strips out tracking junk after the `?`, but keeps the path — which on social platforms is often the visitor's own name (`facebook.com/jane.smith/posts/...`). So the platform ends up storing "this visitor came from Jane Smith's Facebook profile" rather than just "this visitor came from Facebook."
    - **Evidence:**
        ```php
        public static function referrer(?string $referrer): ?string
        {
            $parts = parse_url($referrer);
            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'];
            $path = $parts['path'] ?? '';

            return Str::limit($scheme.'://'.$host.$path, self::REFERRER_MAX_LENGTH, '');
        }
        ```

- [ ] **#PRIV-10** · P2 — User-Agent stored verbatim (length-capped only) across analytics, enquiries, and waitlist — no browser/OS derivation
    - **Where:** app/Services/Analytics/AnalyticsEventSanitizer.php:45-58 (`userAgent()` — 256-char cap); app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php:126 (`mb_substr(..., 500)`); app/Http/Controllers/Api/PublicSite/PublicWaitlistController.php:65 (`mb_substr(..., 500)`)
    - **Affects:** Every visitor to every public sitepage, every enquiry sender, and every waitlist signup — the full detailed User-Agent string (browser, exact version, OS, rendering engine) persists for the applicable retention window, when browser family + OS would serve every current dashboard use.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a shared UA-parsing helper to `AnalyticsEventSanitizer` that derives `browser + major_version + os` (or a compact string like `"Chrome 125 / macOS"`).
        - Switch the four write paths (`PostgresEventWriter`, `LogLeadRateLimits`, `PublicEnquiryController`, `PublicWaitlistController`) from length-capping to the derived value.
    - **Technical:** `AnalyticsEventSanitizer::userAgent()` already caps at 256 chars, and `PublicEnquiryController`/`PublicWaitlistController` separately cap at 500 chars via `mb_substr` — a prior hardening pass (visible in surrounding code comments tagged from an earlier audit round) already addressed length and referrer query-string stripping. What remains: a length cap alone leaves the full fingerprintable string intact for the overwhelming majority of real browsers (typical UA strings run well under either cap), so no actual minimisation of *content* — only of pathological outliers — has happened yet.
    - **Plain English:** The system records what browser and device someone used — useful for "80% of visitors use Chrome" style stats. Right now it stores the entire browser-identification string (exact version, operating system, rendering engine) up to a length limit, not a summary. A photocopy trimmed to fit in an envelope is still a photocopy. The fix is to record just "Chrome on Mac" and discard the rest, the way the IP address is already scrambled instead of kept raw.
    - **Evidence:**
        ```php
        public static function userAgent(?string $userAgent): ?string
        {
            return Str::limit($userAgent, self::USER_AGENT_MAX_LENGTH, ''); // 256 chars, no parsing
        }
        ```
        ```php
        // PublicEnquiryController.php:126 and PublicWaitlistController.php:65 — same pattern, different cap
        'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        'consent_user_agent' => mb_substr((string) ($request->userAgent() ?? ''), 0, 500) ?: null,
        ```

- [ ] **#PRIV-11** · P2 — Staff audit log stores staff/impersonator email as plaintext while IP is hashed
    - **Where:** app/Services/Audit/StaffAuditService.php:20-69
    - **Affects:** Every `PartnaStaff` member whose actions are audited — their email is recorded in plaintext in `audit.staff_audit_log`, while the professional/visitor IP on the same row is hashed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Hash `staff_email_snapshot` and `impersonator_email_snapshot` with the same HMAC-SHA256 convention already applied to `ip_hash` in the same method.
        - `staff_id`/`impersonator_staff_id` (already stored) remain the join key for resolving a live email in the staff UI.
    - **Technical:** The very migration that hashed this table's IP column (`20260708124853_staff_audit_log_ip_hash_and_get_reads.sql`, tagged DINT-1/OBS-3/PRIV-4) shipped last week — it deliberately dropped the raw `ip` column and added `ip_hash`, but left `staff_email_snapshot`/`impersonator_email_snapshot` untouched. The asymmetry is now more visible, not less: the row treats visitor/professional PII as sensitive enough to hash but staff PII as not, on the exact same audit row.
    - **Plain English:** The platform keeps a detailed log of staff actions — good for security. But in that log, staff email addresses are written in plain text while visitor IP addresses are scrambled. It's like a security logbook that blacks out visitor names but prints the guard's own email on every page. If that log ever leaked, every staff member's email would be exposed alongside what they did and whose account they accessed.
    - **Evidence:**
        ```php
        return StaffAuditEntry::query()->create([
            'staff_id' => $staff?->id,
            'staff_email_snapshot' => $staff?->primary_email,           // plaintext
            'impersonator_staff_id' => $impersonator?->id,
            'impersonator_email_snapshot' => $impersonator?->primary_email, // plaintext
            ...
            'ip_hash' => $this->hashIp($ip),  // hashed — inconsistent with the two lines above
        ]);
        ```

- [ ] **#PRIV-12** · P2 — `site_visits.latitude`/`longitude` stored at full precision (added 2026-07-07)
    - **Where:** supabase/migrations/20260707020000_site_visits_lat_lon.sql; app/Http/Controllers/Concerns/DetectsClientInfo.php:165-189 (`parseCoordinate` — bounds-checks only, no rounding); app/Services/Analytics/Writers/PostgresEventWriter.php:92-116 (`visitRow` — stores raw floats); app/Services/Analytics/AnalyticsQueryService.php:465-475 (rounds to 4dp only at read time, for display)
    - **Affects:** Every visitor whose browser/edge network supplies geolocation — full-precision coordinates persist per pageview for the 90-day analytics retention window, precise enough to distinguish individual buildings.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Round `latitude`/`longitude` to 2–3 decimal places (city/neighbourhood precision, ~100m–1km) at ingest — either in `DetectsClientInfo::parseCoordinate()` or in `PostgresEventWriter::visitRow()` before insert.
    - **Technical:** This is a very recent addition — the column didn't exist before `20260707020000_site_visits_lat_lon.sql` — so it's not legacy debt, it's a fresh gap. `parseCoordinate()` validates numeric bounds only; `visitRow()` writes `$e->latitude`/`$e->longitude` unmodified. The only rounding anywhere in the pipeline is `AnalyticsQueryService::cities()`'s `AVG(latitude)` rounded to 4 decimal places (~11m) for the map-pin display — that's a read-time convenience, not a storage-time minimisation, so the raw per-visit precision is retained in Postgres regardless.
    - **Plain English:** When someone visits a professional's page, the analytics system now also records their exact GPS coordinates — precise enough to identify which building they were in — even though the dashboard only ever needs city-level detail to draw a dot on a map. It's like a shopkeeper tracking a customer's exact position in the store when all they need is "came from Melbourne." This was just added and is worth catching before it accumulates 90 days of full-precision visitor location history.
    - **Evidence:**
        ```sql
        ALTER TABLE analytics.site_visits
            ADD COLUMN IF NOT EXISTS latitude double precision,
            ADD COLUMN IF NOT EXISTS longitude double precision;
        ```
        ```php
        // PostgresEventWriter::visitRow() — stored unmodified
        'latitude' => $e->latitude,
        'longitude' => $e->longitude,
        ```
        ```php
        // AnalyticsQueryService::cities() — only rounds at READ time, for the map display
        'latitude' => $r->latitude !== null ? round((float) $r->latitude, 4) : null,
        ```

## Standalone — do NOT bundle

- **#PRIV-13 — `audit.auth_factor_events.ip` stores raw IP as `inet`, missed by the B4 hash sweep** · standalone: requires a `DROP COLUMN ip` / `ADD COLUMN ip_hash` migration (schema change) on a security-sensitive, append-only table, mirroring `20260708124853_staff_audit_log_ip_hash_and_get_reads.sql`, which fixed the sibling `staff_audit_log` table but did not touch this one. Hash at collection time via `App\Services\User\DataExport`'s existing HMAC-SHA256 + `app.key` convention.
- **#PRIV-14 — `audit.handle_change_log.ip_address` stores raw IP as `inet`, missed by the B4 hash sweep** · standalone: same reasoning as #PRIV-13 — requires its own `DROP`/`ADD COLUMN` migration on an append-only audit table.
- **#PRIV-15 — `audit.user_deletion_audit.ip_address` stores raw IP as `text`, missed by the B4 hash sweep** · standalone: same reasoning — requires its own migration; this table is also directly surfaced in `DataExportPayloadBuilder::streamDeletionAudit()`, so the export builder's column list needs updating in lockstep with the schema change.

## Suggested Bundled Sessions

- **Bundle 1 — GDPR export completeness:** #PRIV-1, #PRIV-2
    - **Why grouped:** both are missing/stubbed sections in the same file (`DataExportPayloadBuilder::sectionDescriptors()`), same review shape (add generator + redaction allowlist).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Retention enforcement jobs (no schema change):** #PRIV-3, #PRIV-6, #PRIV-7
    - **Why grouped:** all three add a new/extended scheduled command enforcing a retention window that's either declared-but-unenforced or absent entirely; same file family (`app/Console/Commands` + `routes/console.php`), same review pattern as the existing `PruneNotifications`/`PruneResolvedCaseSignalsPiiCommand`.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Account-deletion PII completeness follow-ons:** #PRIV-5, #PRIV-8
    - **Why grouped:** both are small additions to `AccountDeletionService.php`, following the file's own established `purgeXRows()` pattern.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Analytics ingest minimisation:** #PRIV-9, #PRIV-10, #PRIV-12
    - **Why grouped:** all three touch the same analytics-ingest surface (`AnalyticsEventSanitizer` / `PostgresEventWriter` / `DetectsClientInfo`) and are pure application-layer minimisation changes with no schema impact.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Audit/processor PII hygiene:** #PRIV-4, #PRIV-11
    - **Why grouped:** both are small, self-contained "hash or add a removal path" fixes to PII already at rest in Redis/Postgres, no cross-file coordination needed.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.
