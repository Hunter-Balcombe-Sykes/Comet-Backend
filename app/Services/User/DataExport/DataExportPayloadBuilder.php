<?php

namespace App\Services\User\DataExport;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Services\Content\ManualServiceItems;
use App\Services\Platforms\DsarPayloadFilter;
use App\Services\User\Concerns\ResolvesDeletedEmail;
use App\Site\Pools\PersonNameMatch;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

// V2: Pure builder. Assembles the full data-export payload (Ring 1 + 2 of the
// scope rings — see docs/superpowers/specs/2026-04-25-data-export-design.md).
// No I/O beyond DB reads — testable with fixture data, no filesystem touches.
//
// Memory model: unbounded sections (customers, bookings, ledger, etc.) are
// exposed as Generators using ->lazy(LAZY_CHUNK_ROWS) so DataExportZipWriter
// can stream rows row-by-row to disk without loading the full result set into
// PHP memory. GDPR right-of-access must not OOM on large accounts. The
// legacy build() entry point still materialises the full payload (used by
// unit tests and any future small-export caller) — large callers should use
// stream() + DataExportZipWriter::writeStreaming().
class DataExportPayloadBuilder
{
    use ResolvesDeletedEmail;

    /**
     * Tables whose PII this export covers. Read by DataExportCoverageTest to
     * assert no PII-bearing model is silently missing from sectionDescriptors().
     * Adding a PII table to the export means adding it here too.
     *
     * Entries MUST be schema-qualified — they are compared against the models'
     * $table values, which carry the Postgres schema prefix.
     *
     * DINT-2: `ingest.record_versions` and `ingest.effects` are deliberately
     * NOT exported here. `record_versions.doc` is the raw, pre-projection
     * vendor document — exporting it verbatim would reproduce #PRIV-2 at ten
     * times the volume (it is precisely where Google reviewer names live
     * before projection). `effects.meta` is the same content plus billing
     * internals. The subject's own catalog is `content.*` below, which IS
     * exported.
     *
     * #PRIV-4: `site.site_documents` is also deliberately NOT exported here.
     * It is a derived projection — every field in it (display name, handle,
     * bio, social links, addresses) is already exported from its own source
     * table above. Exporting it too would duplicate the subject's own data
     * under a second name. It has no Eloquent model, so this coverage guard
     * (which is model-driven) is structurally blind to the table; this note
     * is the record of that decision, not an oversight.
     *
     * #PRIV-3: `moderation.case_signals` IS listed, but is exported only
     * PARTIALLY, and the listing is the honest entry rather than an
     * overclaim. streamContentReports() emits the signals the subject FILED
     * (`record_type => 'filed_by_me'`), matched on `reporter_user_id` OR the
     * `lower(trim(reporter_email))` fallback (SEM-1). What is withheld is
     * third-party reporter identity on signals filed AGAINST the subject —
     * deliberate, and the same Ring 3 rule as StaffAuditEntry: a reporter who
     * is not the data subject must not be unmasked by the subject's own
     * Article-15 request.
     *
     * #PRIV-13: `moderation.evidence` IS listed, and like case_signals is
     * exported only PARTIALLY. The row is a third-party moderation record, but
     * the SUBJECT'S OWN identity is frozen inside it —
     * EvidenceSnapshotService::snapshotSite() writes handle, display_name and
     * site_subdomain into `payload` at report time — and Article 15 reaches
     * that copy like any other. Note what this table is NOT: the PII lives
     * inside a JSON column, so the model-driven half of the coverage guard is
     * structurally blind to it in exactly the way it is blind to
     * `site.site_documents` above (there the table has no model; here the
     * columns are not columns). streamModerationEvidence() therefore builds
     * each exported row POSITIVELY from a fixed allowlist rather than emitting
     * `payload` and filtering it down. That direction is load-bearing, not
     * stylistic: a filter removes only what its author remembered, so a key
     * added by a future snapshot strategy (SiteMedia / Block / User are
     * documented fast-follows) would leak by default; a positive build makes
     * that structurally impossible. Deliberately withheld: `payload` itself,
     * `content_hash`, `signal_id` (an FK into moderation.case_signals, whose
     * reporter_user_id / reporter_email / reporter_ip_hash are exactly the
     * third-party identity the paragraph above withholds — exporting the
     * pointer hands the reported party a route into the reporter's record),
     * and every row whose evidence_type is not `content_snapshot`. Its erasure
     * is registered in AccountDeletionService::PURGED_PII_TABLES and is held to
     * that claim by the purge-mechanism assertion in DataExportCoverageTest.
     * `moderation.cases` is not listed because its exported column set
     * carries no PII at all: `reportable_owner_user_id` is only a filter
     * predicate on the query, never an exported column.
     *
     * #PRIV-1: `core.early_access_signups` IS listed, but is exported only
     * PARTIALLY for one of its three ownership buckets. A row owned by
     * `user_id`, or matched by email while still on the waitlist, is exported
     * in full. A row matched by email only, but already progressed past the
     * waitlist, has its occupational/invitation fields withheld — the email
     * link alone cannot prove the row is the requester's rather than a prior
     * holder of a reassigned address. See streamEarlyAccessSignups() and
     * EARLY_ACCESS_WITHHELD_DISCLOSURE.
     *
     * #W1-PRIV-2 / #W2-DINT-1: `content.f_review` is listed and IS exported,
     * but one of its columns is exported conditionally. `staff_name` names the
     * team member a venue-level review was about — the requester on their own
     * reviews, a colleague on the rest of the venue's. It is disclosed on a
     * name match and replaced with `[withheld: names another person]`
     * otherwise. See streamContentFReview() and
     * STAFF_NAME_WITHHELD_DISCLOSURE. It is deliberately NOT in
     * ContentPiiExportCoverageTest's WITHHELD_THIRD_PARTY: select-then-mask
     * IS exporting, and that guard fails a column listed both ways.
     */
    public const COVERED_PII_TABLES = [
        'core.users',
        'core.early_access_signups',
        'core.pre_account_builds',
        'core.feedback',
        'site.customers',
        'site.enquiries',
        'site.workplaces',
        'notifications.email_subscriptions',
        'moderation.case_signals',
        'moderation.evidence',
        'audit.data_export_audit',
        'audit.user_deletion_audit',
        'content.sources', 'content.items', 'content.source_items',
        'content.f_text', 'content.f_place', 'content.f_review',
        'content.f_authored', 'content.f_channel',
        // Slice 6: the first SOURCE-level fact in content.* — it hangs off
        // content.sources, not content.items, so it is scoped by the source's
        // user_id rather than an item join.
        'content.source_stats',
    ];

    private const SCHEMA_VERSION = 1;

    private const PII_DISCLOSURE = 'This export contains personally identifiable information (PII) you collected from your customers via Partna (booking history, enquiries, email subscriptions). Handle in accordance with applicable privacy law.';

    private const EARLY_ACCESS_WITHHELD_MARKER = '[withheld: ownership unverified]';

    private const EARLY_ACCESS_WITHHELD_FIELDS = ['type', 'workplace_or_industry', 'platforms', 'status', 'invited_at', 'signed_up_at'];

    /**
     * #PRIV-1: Article 15 transparency for the early-access ownership rule.
     * A withholding is lawful only if it is disclosed; this is that disclosure.
     */
    private const EARLY_ACCESS_WITHHELD_DISCLOSURE = 'Where a waitlist record is linked to you only by email address, with no verified link to your Partna account, and that record had already progressed beyond the waitlist stage, its occupational and invitation fields (`type`, `workplace_or_industry`, `platforms`, `status`, `invited_at`, `signed_up_at`) are shown as `[withheld: ownership unverified]`. Email addresses are sometimes reassigned between people, and we cannot prove such a record was filed by you rather than by a previous holder of the address; releasing it could disclose another person\'s data to you. The record itself, the address, and the date it was created are still shown, and every early-access record verifiably linked to your account is included in full. If a withheld record is yours, contact support and we will verify it and release the full detail.';

    private const STAFF_NAME_WITHHELD_MARKER = '[withheld: names another person]';

    /**
     * #W1-PRIV-2 / #W2-DINT-1: Article 15 transparency for the review
     * staff-attribution rule. A withholding is lawful only if it is disclosed;
     * this is that disclosure.
     */
    private const STAFF_NAME_WITHHELD_DISCLOSURE = 'Reviews collected from a venue-level source (a Google listing, or a storewide Booksy / Treatwell / Fresha page) carry the name of the team member the review was about. Where that name is yours it is shown in full; where it names a colleague it is shown as `[withheld: names another person]`, because your right of access does not extend to identifying another member of staff. The review\'s rating and date are shown either way. This rule is applied uniformly to every account.';

    /**
     * Normalise an email for email_lc lookups: trim whitespace then mb_strtolower.
     * Mirrors PublicWaitlistController and ensures parity with writers that use
     * `strtolower(trim($email))` — for the ASCII emails we actually accept,
     * mb_strtolower and strtolower fold identically; the trim is the load-bearing
     * part so a stray leading/trailing space in primary_email doesn't silently
     * cause a DSAR miss.
     */
    private function normaliseEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $trimmed = trim($email);

        return $trimmed === '' ? null : mb_strtolower($trimmed);
    }

    /**
     * Build the full payload for a single professional, materialised in memory.
     *
     * Prefer stream() for production exports — this entry point exists for
     * tests and small-tenant scenarios. It iterates the same generators
     * stream() exposes, so memory usage scales with the largest section.
     *
     * @return array{metadata: array, profile: array, site: array, early_access: array, pre_account_build: array, media: array, design_kit: array, integrations: array, analytics: array, customers: array, services: array, service_categories: array, enquiries: array, lead_submissions: array, feedback: array, content_reports: array, moderation_evidence: array, email_subscriptions: array, notifications: array, notification_preferences: array, auth: array, audit: array}
     */
    public function build(string $userId): array
    {
        $out = [];
        foreach ($this->stream($userId) as $section) {
            // $section['name'] is dot-delimited for nested groups (e.g. 'audit.handle_change_log');
            // Arr::set treats each dot as a nesting level, so this reconstructs the exact
            // nested shape the old hand-written build() built by hand — from stream()'s own
            // output, not a second independently-maintained list.
            Arr::set(
                $out,
                $section['name'],
                $section['kind'] === 'value' ? $section['value'] : $this->collect($section['rows'])
            );
        }

        return $out;
    }

    /**
     * Yield section descriptors in payload order. Each yielded item is one of:
     *   ['name' => string, 'kind' => 'value', 'value' => mixed]
     *   ['name' => string, 'kind' => 'rows',  'rows' => Generator, 'csv_columns' => ?array<string>]
     *
     * For nested groups (notifications, notification_preferences,
     * auth, audit, media) the descriptor's 'name' uses dotted form (e.g.
     * 'audit.handle_change_log'); the writer reassembles the group structure
     * when emitting JSON, preserving the order each group is first encountered.
     *
     * This is the ONLY place sections are enumerated (FOUND-1) — sectionDescriptors()
     * below is the single manifest both this method and build() derive from.
     */
    public function stream(string $userId): Generator
    {
        $professional = $this->loadUser($userId);
        $lookupEmail = $this->resolveDeletedAccountEmail($professional);
        $siteId = DB::connection('pgsql')
            ->table('site.sites')
            ->where('user_id', $userId)
            ->value('id');

        foreach ($this->sectionDescriptors($professional, $lookupEmail, $siteId) as $section) {
            if ($section['kind'] === 'value') {
                yield ['name' => $section['name'], 'kind' => 'value', 'value' => ($section['resolve'])()];
            } else {
                yield [
                    'name' => $section['name'],
                    'kind' => 'rows',
                    'rows' => ($section['resolve'])(),
                    'csv_columns' => $section['csv_columns'] ?? null,
                ];
            }
        }
    }

    /**
     * Single source of truth for every GDPR export section: name, whether it's a
     * scalar 'value' or a 'rows' generator, how to resolve it, and its CSV column
     * allow-list (if any). Adding a new exportable section means adding ONE entry
     * here — both build() and stream() automatically pick it up. This directly
     * closes FOUND-1: previously build() and stream() each hand-enumerated the
     * same ~26 sections independently, so a missed edit to one silently omitted
     * a section from that entry point.
     *
     * @return array<int, array{name: string, kind: 'value'|'rows', resolve: \Closure, csv_columns?: ?array<string>}>
     */
    private function sectionDescriptors(User $professional, ?string $lookupEmail, ?string $siteId): array
    {
        $userId = $professional->id;

        return [
            ['name' => 'metadata', 'kind' => 'value', 'resolve' => fn () => $this->metadata($professional)],
            ['name' => 'profile', 'kind' => 'value', 'resolve' => fn () => $this->profile($professional)],
            ['name' => 'site', 'kind' => 'value', 'resolve' => fn () => $this->site($userId)],
            [
                'name' => 'early_access',
                'kind' => 'rows',
                'resolve' => fn () => $this->streamEarlyAccessSignups($userId, $lookupEmail),
                'csv_columns' => ['id', 'ownership', 'email', 'type', 'workplace_or_industry', 'platforms', 'status', 'source', 'invited_at', 'signed_up_at', 'created_at', 'updated_at'],
            ],
            [
                'name' => 'pre_account_build',
                'kind' => 'rows',
                'resolve' => fn () => $this->streamPreAccountBuilds($userId),
                'csv_columns' => ['id', 'source_type', 'source_ref', 'contact_email', 'built_via', 'build_state', 'expires_at', 'claimed_at', 'created_at'],
            ],
            ['name' => 'media.site_media', 'kind' => 'rows', 'resolve' => fn () => $this->streamMedia($siteId)],
            ['name' => 'design_kit', 'kind' => 'rows', 'resolve' => fn () => $this->streamDesignKit($siteId)],
            ['name' => 'integrations', 'kind' => 'rows', 'resolve' => fn () => $this->streamIntegrations($userId)],
            // DINT-2: the subject's own content catalog (content.* — migration
            // 20260727140000). ingest.record_versions/effects are deliberately
            // NOT exported (see COVERED_PII_TABLES docblock).
            ['name' => 'content.sources', 'kind' => 'rows', 'resolve' => fn () => $this->streamContentSources($userId)],
            ['name' => 'content.items', 'kind' => 'rows', 'resolve' => fn () => $this->streamContentItems($userId)],
            ['name' => 'content.source_items', 'kind' => 'rows', 'resolve' => fn () => $this->streamContentSourceItems($userId)],
            ['name' => 'content.f_text', 'kind' => 'rows', 'resolve' => fn () => $this->streamContentFText($userId)],
            ['name' => 'content.f_place', 'kind' => 'rows', 'resolve' => fn () => $this->streamContentFPlace($userId)],
            // #PRIV-2 applied at the schema layer: this section discloses THAT
            // a third-party-authored review exists and its rating/timestamp,
            // never the reviewer's identity or verbatim words — those are
            // withheld the same way DsarPayloadFilter withholds them from the
            // integrations section (see WITHHELD_DISCLOSURE). author_name,
            // author_photo_url, author_uri and text are deliberately omitted
            // below. staff_name IS exported, but only where it names the
            // requester — see streamContentFReview() and
            // STAFF_NAME_WITHHELD_DISCLOSURE.
            ['name' => 'content.f_review', 'kind' => 'rows', 'resolve' => fn () => $this->streamContentFReview($userId, $professional->display_name, $professional->first_name)],
            // Slice 6: source-level aggregates. summary_text is Google-authored
            // prose derived from reviews and is withheld the same way
            // DsarPayloadFilter withholds the legacy reviewSummary key — see
            // WITHHELD_DISCLOSURE. rating_avg/rating_count are business facts
            // about the subject's own listing and ARE disclosed.
            ['name' => 'content.source_stats', 'kind' => 'rows', 'resolve' => fn () => $this->streamContentSourceStats($userId)],
            ['name' => 'content.f_authored', 'kind' => 'rows', 'resolve' => fn () => $this->streamContentFAuthored($userId)],
            ['name' => 'content.f_channel', 'kind' => 'rows', 'resolve' => fn () => $this->streamContentFChannel($userId)],
            ['name' => 'analytics.site_visits', 'kind' => 'rows', 'resolve' => fn () => $this->streamAnalyticsSiteVisits($userId)],
            ['name' => 'analytics.link_clicks', 'kind' => 'rows', 'resolve' => fn () => $this->streamAnalyticsLinkClicks($userId)],
            ['name' => 'analytics.section_views', 'kind' => 'rows', 'resolve' => fn () => $this->streamAnalyticsSectionViews($userId)],
            ['name' => 'analytics.item_views', 'kind' => 'rows', 'resolve' => fn () => $this->streamAnalyticsItemViews($userId)],
            ['name' => 'analytics.action_events', 'kind' => 'rows', 'resolve' => fn () => $this->streamAnalyticsActionEvents($userId)],
            [
                'name' => 'customers',
                'kind' => 'rows',
                'resolve' => fn () => $this->streamCustomers($userId),
                'csv_columns' => ['id', 'email', 'phone', 'full_name', 'source', 'notes', 'created_at'],
            ],
            ['name' => 'services', 'kind' => 'rows', 'resolve' => fn () => $this->streamServices($userId, $siteId)],
            ['name' => 'service_categories', 'kind' => 'rows', 'resolve' => fn () => $this->streamServiceCategories($userId)],
            [
                'name' => 'enquiries',
                'kind' => 'rows',
                'resolve' => fn () => $this->streamEnquiries($userId),
                'csv_columns' => ['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'],
            ],
            ['name' => 'lead_submissions', 'kind' => 'rows', 'resolve' => fn () => $this->streamLeadSubmissions($userId)],
            ['name' => 'feedback', 'kind' => 'rows', 'resolve' => fn () => $this->streamFeedback($userId)],
            ['name' => 'content_reports', 'kind' => 'rows', 'resolve' => fn () => $this->streamContentReports($userId, $lookupEmail)],
            // #PRIV-13: the subject's OWN identity, frozen into a moderation
            // evidence snapshot at report time. Allowlist-built — see
            // streamModerationEvidence() and the COVERED_PII_TABLES docblock.
            // Name is undotted on purpose: DataExportZipWriter treats a dot as
            // a JSON-group prefix, which would reduce this to the bare,
            // ambiguous record-count key `evidence`.
            ['name' => 'moderation_evidence', 'kind' => 'rows', 'resolve' => fn () => $this->streamModerationEvidence($userId)],
            ['name' => 'email_subscriptions', 'kind' => 'rows', 'resolve' => fn () => $this->streamEmailSubscriptions($userId, $lookupEmail)],
            ['name' => 'notifications.messages', 'kind' => 'rows', 'resolve' => fn () => $this->streamNotifications($userId)],
            ['name' => 'notifications.receipts', 'kind' => 'rows', 'resolve' => fn () => $this->streamNotificationReceipts($userId)],
            ['name' => 'notification_preferences.category_preferences', 'kind' => 'rows', 'resolve' => fn () => $this->streamNotificationPreferences($userId)],
            ['name' => 'notification_preferences.staff_policy_overrides', 'kind' => 'rows', 'resolve' => fn () => $this->streamNotificationPolicies($userId)],
            ['name' => 'auth.factor_events', 'kind' => 'rows', 'resolve' => fn () => $this->streamAuthFactorEvents($professional->auth_user_id)],
            ['name' => 'audit.data_export_audit', 'kind' => 'rows', 'resolve' => fn () => $this->streamAudit($userId)],
            ['name' => 'audit.handle_change_log', 'kind' => 'rows', 'resolve' => fn () => $this->streamHandleChangeLog($userId)],
            ['name' => 'audit.handle_aliases', 'kind' => 'rows', 'resolve' => fn () => $this->streamHandleAliases($userId)],
            ['name' => 'audit.subdomain_aliases', 'kind' => 'rows', 'resolve' => fn () => $this->streamSubdomainAliases($userId)],
            ['name' => 'audit.deletion_audit', 'kind' => 'rows', 'resolve' => fn () => $this->streamDeletionAudit($userId)],
        ];
    }

    private function loadUser(string $userId): User
    {
        return User::query()
            ->withTrashed()
            ->where('id', $userId)
            ->firstOrFail();
    }

    private function metadata(User $p): array
    {
        return [
            'user_id' => $p->id,
            'professional_handle' => $p->handle,
            'exported_at' => now()->toIso8601String(),
            'schema_version' => self::SCHEMA_VERSION,
            'notes' => self::PII_DISCLOSURE,
            // Article 15 transparency: telling the subject what was withheld
            // and why is what makes the withholding lawful rather than an
            // undisclosed omission. See DsarPayloadFilter::WITHHELD_DISCLOSURE.
            'withheld' => DsarPayloadFilter::WITHHELD_DISCLOSURE.' '.self::EARLY_ACCESS_WITHHELD_DISCLOSURE.' '.self::STAFF_NAME_WITHHELD_DISCLOSURE,
        ];
    }

    private function profile(User $p): array
    {
        // Strip secrets — never let auth or tokens leak into an export.
        $row = $p->toArray();
        unset($row['auth_user_id'], $row['deletion_token_hash']);

        return [
            'professional' => $row,
        ];
    }

    private function site(string $userId): array
    {
        // PRIV-2: explicit allow-list, equal to site.sites' current full
        // column set (behaviour-neutral — no column stops being exported).
        // This is the highest-churn table in the schema, which is exactly
        // why it needs the guard: a future internal-only column is more
        // likely to land here than anywhere else.
        $site = DB::connection('pgsql')
            ->table('site.sites')
            ->select([
                'id', 'user_id', 'subdomain', 'is_published', 'settings',
                'created_at', 'updated_at', 'subdomain_changed_at', 'unpublished_at',
                'moderation_state',
                'custom_domain', 'custom_domain_status', 'custom_domain_verified_at',
                'custom_domain_cf_id', 'custom_domain_primary',
                'show_branding', 'charlie_enabled', 'services_auto_sync_enabled',
                'booking_mode', 'manual_booking_url',
                'shop_link_mode',
            ])
            ->where('user_id', $userId)
            ->first();

        if (! $site) {
            return ['site' => null, 'blocks' => [], 'workplace' => null];
        }

        // PRIV-2: explicit allow-list, equal to site.blocks' current full column set.
        $blocks = $this->collect(
            $this->lazyRows(
                DB::connection('pgsql')
                    ->table('site.blocks')
                    ->select([
                        'id', 'site_id', 'user_id', 'block_group', 'block_type', 'title', 'url',
                        'sort_order', 'is_active', 'live_check_enabled', 'category', 'platform',
                        'handle', 'icon_key', 'is_enabled', 'settings', 'created_at', 'updated_at', 'deleted_at',
                    ])
                    ->where('site_id', $site->id)
                    ->orderBy('sort_order')
            )
        );

        // Include workplace from the child table (FOUND-4 — promoted from settings JSONB).
        // Explicit allow-list (PRIV-5), not a `select(['*'])` — keeps this export
        // immune to any future internal-only column landing on site.workplaces.
        $workplaceRow = DB::connection('pgsql')
            ->table('site.workplaces')
            ->select([
                'site_id', 'name', 'address_line1', 'city', 'state', 'postcode',
                'country', 'latitude', 'longitude', 'phone', 'website', 'previous_website',
                'category', 'description', 'opening_hours', 'contact_email', 'field_sources',
                'created_at', 'updated_at',
            ])
            ->where('site_id', $site->id)
            ->first();

        return [
            'site' => (array) $site,
            'blocks' => $blocks,
            'workplace' => $workplaceRow ? (array) $workplaceRow : null,
        ];
    }

    /**
     * Uploaded media metadata, scoped through the user's SITE.
     *
     * site.site_media has NO user_id column — tenancy runs site_id -> site.sites
     * -> user_id — and no width/height columns (those live on site.media_variants,
     * one row per rendition). The original implementation selected width/height
     * and filtered on user_id, so it threw 42703 on Postgres and took the whole
     * DSAR export down with it. It stayed green in CI because SQLite silently
     * reinterprets an unknown double-quoted identifier as a string literal
     * rather than erroring, so the bad filter merely matched zero rows.
     *
     * Dimensions are deliberately not re-added via a media_variants join: they
     * are rendition detail, not personal data, and Article 15 does not need them.
     */
    private function streamMedia(?string $siteId): Generator
    {
        if ($siteId === null) {
            yield from [];

            return;
        }

        yield from $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.site_media')
                ->select([
                    'id', 'pool', 'purpose', 'path', 'media_type',
                    'original_filename', 'caption', 'alt_text', 'created_at',
                ])
                ->where('site_id', $siteId)
        );
    }

    /**
     * Live platform connections (site.platform_connections) — the user's linked
     * external platforms (Instagram, Shopify, Fresha, etc.). Explicit allowlist:
     * excludes internal refresh machinery (last_refresh_error,
     * consecutive_failures, apify_status, place_id, refresh_etag,
     * refresh_last_modified) — operational bookkeeping the user never sees, not
     * personal data about them. Soft-deleted rows are included (mirrors
     * streamCustomers/streamServices) so a row pending its 30-day purge still
     * surfaces in a DSAR. payload and display_settings are stored as JSONB;
     * decoded here so the export nests them as arrays rather than raw JSON text.
     *
     * #PRIV-2: unlike display_settings (the owner's own toggle state, no
     * third-party content), `payload` is passed through DsarPayloadFilter —
     * without it, this section would hand back verbatim Google reviewer
     * names/photos/text and Eventbrite/Humanitix organiser/venue identity,
     * none of which is personal data about the account holder. See
     * DsarPayloadFilter::WITHHELD_DISCLOSURE (surfaced in metadata.withheld).
     */
    private function streamIntegrations(string $userId): Generator
    {
        foreach ($this->lazyRows(
            DB::connection('pgsql')
                ->table('site.platform_connections')
                ->select([
                    'id', 'platform', 'resource_id', 'resource_kind', 'canonical_key',
                    'payload', 'display_settings', 'sort_order', 'is_active',
                    'last_visited_at', 'last_refreshed_at', 'last_refresh_status',
                    'created_at', 'updated_at', 'deleted_at',
                ])
                ->where('user_id', $userId)
                ->orderBy('sort_order')
        ) as $row) {
            $row['payload'] = DsarPayloadFilter::filter(
                (string) ($row['platform'] ?? ''),
                $this->decodeJsonColumn($row['payload'] ?? null),
            );
            $row['display_settings'] = $this->decodeJsonColumn($row['display_settings'] ?? null);
            yield $row;
        }
    }

    /**
     * DINT-2: the subject's own content catalog — the contribution channel
     * (content.sources), never the raw per-source records.
     */
    private function streamContentSources(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('content.sources')
                ->select(['id', 'user_id', 'kind', 'connection_id', 'import_run_id', 'label', 'priority', 'created_at', 'updated_at'])
                ->where('user_id', $userId)
        );
    }

    /**
     * DINT-2: the resolved item spine. headline_cache/facets_cache are
     * dashboard-filtering caches only, not third-party content.
     * (eligible_cache dropped 2026-08-27 — written '[]', never read.)
     */
    private function streamContentItems(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('content.items')
                ->select(['id', 'user_id', 'kind', 'headline_cache', 'facets_cache', 'removed_at', 'review_flag', 'first_seen_at', 'last_seen_at', 'created_at', 'updated_at'])
                ->where('user_id', $userId)
        );
    }

    /**
     * DINT-2: per-external-record units. content.source_items carries no
     * user_id of its own — scoped through content.sources.user_id. Highest
     * cardinality of the content.* sections; streamed via lazyRows/cursor.
     */
    private function streamContentSourceItems(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('content.source_items')
                ->join('content.sources', 'content.source_items.source_id', '=', 'content.sources.id')
                ->where('content.sources.user_id', $userId)
                ->select([
                    'content.source_items.id', 'content.source_items.source_id', 'content.source_items.coord',
                    'content.source_items.stream_id', 'content.source_items.record_key', 'content.source_items.item_id',
                    'content.source_items.kind', 'content.source_items.projector_version',
                    'content.source_items.first_seen_at', 'content.source_items.last_seen_at', 'content.source_items.removed_at',
                ])
        );
    }

    /**
     * DINT-2: typed text facet (headline/body/summary). Facets carry no
     * user_id of their own — scoped through content.items.user_id.
     */
    private function streamContentFText(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('content.f_text')
                ->join('content.items', 'content.f_text.item_id', '=', 'content.items.id')
                ->where('content.items.user_id', $userId)
                ->select(['content.f_text.item_id', 'content.f_text.source_id', 'content.f_text.headline', 'content.f_text.body', 'content.f_text.summary', 'content.f_text.updated_at'])
        );
    }

    /**
     * DINT-2: typed place facet (venue/address). Scoped through
     * content.items.user_id, same as every other facet in this builder.
     */
    private function streamContentFPlace(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('content.f_place')
                ->join('content.items', 'content.f_place.item_id', '=', 'content.items.id')
                ->where('content.items.user_id', $userId)
                ->select([
                    'content.f_place.item_id', 'content.f_place.source_id', 'content.f_place.venue_name',
                    'content.f_place.address', 'content.f_place.locality', 'content.f_place.region',
                    'content.f_place.country_code', 'content.f_place.latitude', 'content.f_place.longitude',
                    'content.f_place.updated_at',
                ])
        );
    }

    /**
     * DINT-2 / #PRIV-2 applied at the schema layer: discloses THAT a
     * third-party-authored review exists (rating, timestamp) but never the
     * reviewer's identity or verbatim words — author_name, author_photo_url,
     * author_uri and text are deliberately OMITTED from the select list.
     * Without this, the integrations section's DsarPayloadFilter withholding
     * would be undone by handing the same reviewer PII straight back through
     * this facet section.
     *
     * author_uri (slice 6, migration 20260813110000) joined that omission list
     * on the day the column landed: it is a permanent link to the reviewer's
     * Google contributor profile, so it identifies them at least as directly
     * as author_name.
     *
     * #W1-PRIV-2 / #W2-DINT-1 (2026-08-29): staff_name (migration
     * 20260828030000) IS now selected, having been silently absent. It is NOT
     * unconditionally the subject's own name — the findings asserted that, and
     * it is false. A venue-level source (Google listing, storewide Fresha)
     * lands reviews of the whole team under this user's items, and staff_name
     * is exactly the field naming WHICH team member; PoolResolver's person
     * scope exists because of that. So the value is disclosed when it names
     * the requester and replaced with STAFF_NAME_WITHHELD_MARKER otherwise —
     * the same ownership-bucket + disclosed-marker shape
     * streamEarlyAccessSignups() uses. Null stays null: nothing was withheld.
     *
     * The match is PersonNameMatch, shared verbatim with the pool's person
     * scope so the two cannot drift. Business accounts get the SAME rule: one
     * could argue venue staff names are that account's own operational data
     * (AccountCapabilities::reviews_scoped_to_person is false for them), but a
     * carve-out there would disclose a named third party on the strength of an
     * account-type flag. Uniform is the deliberate choice, not an oversight.
     *
     * @param  ?string  $displayName  the requester's core.users.display_name
     * @param  ?string  $firstName  the requester's core.users.first_name
     */
    private function streamContentFReview(string $userId, ?string $displayName = null, ?string $firstName = null): Generator
    {
        $names = PersonNameMatch::tokens($displayName, $firstName);

        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('content.f_review')
                ->join('content.items', 'content.f_review.item_id', '=', 'content.items.id')
                ->where('content.items.user_id', $userId)
                ->select(['content.f_review.item_id', 'content.f_review.source_id', 'content.f_review.rating', 'content.f_review.staff_name', 'content.f_review.reviewed_at', 'content.f_review.updated_at']),
            transform: function (object $row) use ($names): array {
                $row = (array) $row;

                $staff = $row['staff_name'];
                // Fails CLOSED with no usable name on file ($names === null):
                // an attribution we cannot verify as the requester's is a
                // colleague's until proven otherwise.
                if ($staff !== null && trim((string) $staff) !== ''
                    && ($names === null || ! PersonNameMatch::matchesStaffName((string) $staff, $names))) {
                    $row['staff_name'] = self::STAFF_NAME_WITHHELD_MARKER;
                }

                return $row;
            },
        );
    }

    /**
     * Slice 6 §5.5: source-level aggregates for a connected place. Carries no
     * user_id of its own — scoped through content.sources.user_id, the same
     * shape streamContentSourceItems() uses.
     *
     * summary_text is deliberately OMITTED from the select list. It is
     * Google-authored prose derived from third-party reviews, withheld exactly
     * as DsarPayloadFilter withholds the legacy `reviewSummary` payload key
     * (see WITHHELD_DISCLOSURE). rating_avg/rating_count are business facts
     * about the subject's own listing and are disclosed in full — the same
     * asymmetry GoogleBusinessPayload::stripThirdPartyPii already carries, and
     * mirrored here rather than resolved.
     */
    private function streamContentSourceStats(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('content.source_stats')
                ->join('content.sources', 'content.source_stats.source_id', '=', 'content.sources.id')
                ->where('content.sources.user_id', $userId)
                ->select([
                    'content.source_stats.source_id', 'content.source_stats.rating_avg',
                    'content.source_stats.rating_count', 'content.source_stats.updated_at',
                ])
        );
    }

    /**
     * DINT-2: typed authorship facet (creator/collaborators) — the account
     * holder's own attribution data for their content, not third-party PII.
     */
    private function streamContentFAuthored(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('content.f_authored')
                ->join('content.items', 'content.f_authored.item_id', '=', 'content.items.id')
                ->where('content.items.user_id', $userId)
                ->select(['content.f_authored.item_id', 'content.f_authored.source_id', 'content.f_authored.creator', 'content.f_authored.creator_url', 'content.f_authored.collaborators', 'content.f_authored.updated_at'])
        );
    }

    /**
     * DINT-2: typed channel facet (handle/followers) — the account holder's
     * own channel presence data.
     */
    private function streamContentFChannel(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('content.f_channel')
                ->join('content.items', 'content.f_channel.item_id', '=', 'content.items.id')
                ->where('content.items.user_id', $userId)
                ->select([
                    'content.f_channel.item_id', 'content.f_channel.source_id', 'content.f_channel.handle',
                    'content.f_channel.followers', 'content.f_channel.avatar_url', 'content.f_channel.is_live',
                    'content.f_channel.verified', 'content.f_channel.updated_at',
                ])
        );
    }

    /**
     * Visitor analytics: page visits to the user's site. Fingerprint columns
     * (ip_hash, visitor_id, session_id, user_agent) are excluded — they identify
     * the *visitor*, a third party, not the site owner who is the DSAR subject.
     * latitude/longitude are included: rounded to 4dp (~11m, PRIV-9) at ingest
     * in DetectsClientInfo::parseCoordinate() — a geo-IP/PoP estimate the owner
     * already sees on their own analytics dashboard, not a visitor fingerprint.
     */
    private function streamAnalyticsSiteVisits(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('analytics.site_visits')
                ->select([
                    'id', 'occurred_at', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign',
                    'country_code', 'region_code', 'city', 'device_type', 'latitude', 'longitude',
                    'created_at',
                ])
                ->where('user_id', $userId)
                ->orderBy('occurred_at')
        );
    }

    /**
     * Outbound link/platform click analytics. Same fingerprint exclusion as
     * streamAnalyticsSiteVisits — the visitor's identity is redacted, the
     * click's structured labels (platform/product/section) are not.
     */
    private function streamAnalyticsLinkClicks(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('analytics.link_clicks')
                ->select([
                    'id', 'occurred_at', 'link_block_id', 'url', 'platform', 'product_id', 'product_title',
                    'section_key', 'label', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign',
                    'country_code', 'region_code', 'device_type', 'created_at',
                ])
                ->where('user_id', $userId)
                ->orderBy('occurred_at')
        );
    }

    /**
     * Per-session section visibility/dwell events. Fingerprints excluded per the
     * pattern above; duration_ms is the dwell signal already surfaced on the
     * owner's own analytics dashboard.
     */
    private function streamAnalyticsSectionViews(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('analytics.section_views')
                ->select([
                    'id', 'section_key', 'block_id', 'occurred_at', 'referrer', 'utm_source',
                    'utm_medium', 'utm_campaign', 'country_code', 'device_type', 'duration_ms', 'created_at',
                ])
                ->where('user_id', $userId)
                ->orderBy('occurred_at')
        );
    }

    /**
     * Item-level (product/menu-item/gallery-item) impression events. user_id is
     * nullable on this table (fail-open write path — see the table's migration
     * comment); scoping by it here is still correct since a null-owner row
     * belongs to no one's DSAR.
     */
    private function streamAnalyticsItemViews(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('analytics.item_views')
                ->select([
                    'id', 'item_type', 'item_id', 'item_title', 'section_key', 'occurred_at',
                    'referrer', 'country_code', 'device_type', 'created_at',
                ])
                ->where('user_id', $userId)
                ->orderBy('occurred_at')
        );
    }

    /**
     * Action exposure/tap events (the unified actions system). Same
     * denormalised-user_id posture as streamAnalyticsItemViews() above.
     */
    private function streamAnalyticsActionEvents(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('analytics.action_events')
                ->select([
                    'id', 'action_id', 'event', 'occurred_at',
                    'referrer', 'country_code', 'device_type', 'created_at',
                ])
                ->where('user_id', $userId)
                ->orderBy('occurred_at')
        );
    }

    /**
     * Decode a JSONB column fetched via DB::table() (returned as a raw JSON
     * string by the PDO pgsql/sqlite drivers, not auto-decoded). Passes arrays
     * through unchanged for callers/tests that pre-decode; null stays null.
     */
    private function decodeJsonColumn(mixed $value): ?array
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true);
    }

    /**
     * PRIV-2: explicit allow-list, equal to the table's current full column
     * set (behaviour-neutral — no column stops being exported). external_id
     * is a third-party POS reconciliation key the user set themselves via
     * their own POS integration, not internal bookkeeping — kept.
     */
    private function streamCustomers(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.customers')
                ->select([
                    'id', 'user_id', 'email', 'phone', 'full_name', 'source', 'notes',
                    'external_id', 'marketing_opt_in_cached', 'redacted_at',
                    'created_at', 'updated_at', 'deleted_at',
                ])
                ->where('user_id', $userId)
        );
    }

    /**
     * Slice 3a moved owner-authored services to content.*; slice 7 Task 18
     * moves the connector half too, because `site.services`,
     * `site.service_categories` and `site.service_category_assignments` are
     * all dropped in that slice's final phase. Both halves now read
     * content.*: the OWNER lane via ManualServiceItems::exportRows() (the ONE
     * shared manual read, also used by the public renderer and the dashboard
     * controller), the CONNECTOR lane via streamConnectorServices() below.
     *
     * The section KEY does not move with the store. The 2026-08-05 precedent
     * is that DSAR allowlists keep their legacy keys so a payload exported
     * before the cutover stays disclosable under the same name; deleting
     * `services` or `service_categories` would be a disclosure regression
     * dressed as a cleanup.
     */
    private function streamServices(string $userId, ?string $siteId): Generator
    {
        $manual = app(ManualServiceItems::class);
        // sectionId() only ever reads $site->id — forceFill() sets that
        // without a round trip, since $siteId is already resolved by the
        // caller (stream()). Skips a redundant Site::find() per export.
        $site = $siteId !== null ? (new Site)->forceFill(['id' => $siteId]) : null;

        yield from $manual->exportRows($userId, $site);
        yield from $this->streamConnectorServices($userId);
    }

    /**
     * Connector-projected services (Fresha today — nothing else lands a
     * `kind = 'service'` item through a connection source), in the same legacy
     * `site.services` row shape the owner lane emits. Removed items are KEPT
     * and surface as `deleted_at`, matching exportRows()' rule that Article 15
     * covers data still held, not just live data.
     *
     * Three legacy columns have no content.* equivalent and are emitted as
     * their honest null/default rather than a guessed value — the same
     * never-fabricate rule exportRows() follows for an excluded row's
     * sort_key:
     *   - `sort_order` — the legacy lane's own append counter; content.* keeps
     *     connector ordering in the vendor's projection, not a stored integer.
     *   - `deleted_origin` — the legacy 'user'/'sync' soft-delete provenance,
     *     which the projector maintained on the row it is losing.
     *   - `is_active` — was the public hidden toggle. That state lives in the
     *     connection payload's `selection`, which IS disclosed in full in the
     *     `integrations` section (DsarPayloadFilter's `fresha` allowlist), so
     *     it is re-derived here as "the item is still held", not withheld.
     *
     * Facets are read through ManualServiceItems::facets() (public, source-id
     * scoped, kind-agnostic) rather than a second copy of its three lookups —
     * the same reuse FreshaServiceItems makes. facets() takes ONE source id,
     * so rows are grouped by source first: a user has at most one live Fresha
     * connection today, but the grouping means a second connector source is a
     * correct extra iteration rather than a silently mis-priced row.
     *
     * No ->distinct(), for FreshaServiceItems::rows()' reason rather than
     * "there is no LEFT JOIN so nothing fans out" (which is false): record_key
     * rides in the select list, so two source_items on one item are two
     * genuinely different rows a whole-row DISTINCT would not collapse. What
     * keeps this one-row-per-service is the data — one live connection per
     * user, and source_items unique on (source_id, coord). An item carrying
     * BOTH a manual and a connection source_item would likewise appear once
     * here and once from exportRows(); `content.item_merges` is empty and
     * cross-kind folding is refused upstream, so that shape does not exist
     * today. Neither is a leak if it ever does — a duplicate row, not a
     * foreign one.
     */
    private function streamConnectorServices(string $userId): Generator
    {
        // Unaliased table names throughout: DataExportCoverageTest's T1/T2
        // guards match a table() call by regex on the bare schema-qualified
        // name, so an "as x" alias makes the call invisible to them and its
        // column list would go unchecked against the real schema.
        $rows = DB::connection('pgsql')->table('content.items')
            ->join('content.source_items', 'content.source_items.item_id', '=', 'content.items.id')
            ->join('content.sources', 'content.sources.id', '=', 'content.source_items.source_id')
            ->leftJoin('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
            ->select([
                'content.items.id', 'content.items.headline_cache', 'content.items.removed_at',
                'content.items.created_at', 'content.items.updated_at',
                'content.source_items.source_id', 'content.source_items.record_key',
                'site.platform_connections.platform',
            ])
            ->where('content.items.user_id', $userId)
            ->where('content.items.kind', 'service')
            ->where('content.sources.kind', 'connection')
            ->whereNull('content.source_items.removed_at')
            ->orderBy('content.items.first_seen_at')
            ->orderBy('content.source_items.id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $manual = app(ManualServiceItems::class);

        foreach ($rows->groupBy('source_id') as $sourceId => $group) {
            $itemIds = $group->pluck('id')->all();
            $facets = $manual->facets($itemIds, (string) $sourceId);
            $categories = $this->connectorServiceCategoryIds($itemIds, (string) $sourceId);

            foreach ($group as $row) {
                $offer = $facets['offers']->get($row->id);
                // Mirrors ManualServiceItems::priceOf(): a 'free' offer carries
                // no price and no currency, and 'AUD' is the legacy column's
                // own default for a row with no offer at all.
                $isFree = $offer !== null && $offer->qualifier === 'free';
                $seconds = $facets['durations'][$row->id] ?? null;

                yield [
                    'id' => (string) $row->id,
                    'user_id' => $userId,
                    'title' => (string) ($row->headline_cache ?? ''),
                    'description' => $facets['descriptions'][$row->id] ?? null,
                    'price_cents' => $offer === null || $isFree ? 0 : (int) $offer->amount_minor,
                    'currency_code' => $offer === null || $isFree ? 'AUD' : (string) $offer->currency,
                    'duration_minutes' => $seconds === null ? null : (int) ((int) $seconds / 60),
                    'is_active' => $row->removed_at === null,
                    'sort_order' => null,
                    'source' => $row->platform === null ? null : (string) $row->platform,
                    'is_manual' => false,
                    'external_id' => $row->record_key === null ? null : (string) $row->record_key,
                    'deleted_origin' => null,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                    'deleted_at' => $row->removed_at,
                    'category_ids' => $categories[(string) $row->id] ?? [],
                ];
            }
        }
    }

    /**
     * The connector lane's replacement for site.service_category_assignments:
     * `content.collection_items` scoped to THIS connector source, in position
     * order. Source-scoped rather than kind-scoped because a connection's own
     * memberships are exactly the ones written under its source id — the same
     * predicate FreshaServiceItems::categories() reads on.
     *
     * @param  list<string>  $itemIds
     * @return array<string, list<string>> item_id => collection ids
     */
    private function connectorServiceCategoryIds(array $itemIds, string $sourceId): array
    {
        if ($itemIds === []) {
            return [];
        }

        $out = [];
        $rows = DB::connection('pgsql')->table('content.collection_items')
            ->select(['item_id', 'collection_id'])
            ->whereIn('item_id', $itemIds)
            ->where('source_id', $sourceId)
            ->orderBy('position')
            ->get();

        foreach ($rows as $row) {
            $out[(string) $row->item_id][] = (string) $row->collection_id;
        }

        return $out;
    }

    /**
     * Slice 7 Task 18: service categories re-sourced from
     * `content.collections` (kind = 'service_category'), mapped back onto the
     * legacy `site.service_categories` column names — label→title,
     * position→sort_order, removed_at→deleted_at, is_user_created→source. That
     * mapping is not invented here: it is the one
     * `ServiceCategoryResource::fromCollectionRow()` already ships on the
     * owner-facing wire, and the two must not drift.
     *
     * Removed rows are NOT filtered. ServiceCollections::list() drops empty
     * machine-derived collections for RENDER purposes; a DSAR is the opposite
     * question — a category the owner deleted is still held data.
     *
     * PRIV-2: explicit allow-list. `external_ref` (the vendor's own category
     * key) is deliberately NOT selected: the legacy shape this section retains
     * has no slot for it, and the provenance it encodes is already disclosed
     * as `source`.
     */
    private function streamServiceCategories(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('content.collections')
                ->select(['id', 'user_id', 'label', 'position', 'is_user_created', 'removed_at', 'created_at', 'updated_at'])
                ->where('user_id', $userId)
                ->where('kind', 'service_category')
                ->orderBy('position'),
            transform: fn (object $row): array => [
                'id' => (string) $row->id,
                'user_id' => (string) $row->user_id,
                'title' => (string) $row->label,
                'sort_order' => (int) $row->position,
                // false = the projector created it from a vendor category,
                // which today only ever means Fresha; true = the owner did, so
                // no source. Normalised rather than compared loosely —
                // PDO_PGSQL hands a boolean column back as the strings "t"/"f",
                // so `=== false` on the raw value would call every Fresha
                // category owner-made on real Postgres and never on SQLite.
                'source' => $this->isTrue($row->is_user_created) ? null : 'fresha',
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'deleted_at' => $row->removed_at,
            ],
        );
    }

    /** Driver-independent truthiness for a boolean column read through the query builder. */
    private function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return in_array(mb_strtolower((string) $value), ['1', 't', 'true'], true);
    }

    private function streamEnquiries(string $userId): Generator
    {
        // Mirror the redaction in ExportCustomerDataJob — drop ip_hash + user_agent
        // (technical fingerprint, not part of the user-visible enquiry).
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.enquiries')
                ->select(['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'])
                ->where('user_id', $userId)
        );
    }

    /**
     * In-app feedback submissions filed by this user. ip_hash and user_agent
     * are technical fingerprints excluded per the enquiries/lead_submissions
     * redaction pattern. internal_notes (staff triage) are included because
     * Article 15 covers all data Partna holds about the subject.
     * Soft-deleted rows are included — during the 30-day grace window the user
     * can still request a DSAR and all held data must surface.
     */
    private function streamFeedback(string $userId): Generator
    {
        // PRIV-6: type/area/target (OV-D taxonomy — which feature/page/tool the
        // feedback concerns) were missing from the allow-list even though the
        // columns are live and NULLABLE; the user is entitled to see them.
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('core.feedback')
                ->select(['id', 'user_id', 'reply_email', 'kind', 'severity', 'type', 'area', 'target', 'message', 'page_url', 'viewport', 'app_version', 'request_id', 'status', 'source', 'tags', 'internal_notes', 'created_at', 'updated_at'])
                ->where('user_id', $userId)
        );
    }

    /**
     * Content moderation records involving this user in either direction:
     *   1. Cases where the user's content was reported — basic case metadata only;
     *      signal-level detail (including who reported them) is withheld to protect
     *      third-party reporter identity.
     *   2. Signals filed by the user as reporter — their own reason/details, but
     *      reporter_ip_hash and dedup_hash are excluded as technical fingerprints.
     * Each row carries a `record_type` discriminator ('reported_against_me' or
     * 'filed_by_me') so the export consumer can split the sections.
     */
    private function streamContentReports(string $userId, ?string $lookupEmail): Generator
    {
        // Cases about the user's content — omit signal-level rows to avoid leaking reporter identity.
        foreach ($this->lazyRows(
            DB::connection('pgsql')
                ->table('moderation.cases')
                ->select(['id', 'case_type', 'reportable_type', 'reportable_id', 'severity', 'status', 'signal_count', 'auto_actioned', 'resolved_at', 'created_at', 'updated_at'])
                ->where('reportable_owner_user_id', $userId)
        ) as $row) {
            yield array_merge(['record_type' => 'reported_against_me'], $row);
        }

        // Signals this user filed as reporter — joined by user_id or email (email survives ON DELETE SET NULL).
        // reporter_ip_hash is a technical fingerprint; dedup_hash is an internal deduplication key.
        $query = DB::connection('pgsql')
            ->table('moderation.case_signals')
            ->select(['id', 'case_id', 'signal_source', 'reason_code', 'reason_details', 'created_at'])
            ->where('reporter_user_id', $userId);

        $emailLc = $this->normaliseEmail($lookupEmail);
        if ($emailLc !== null) {
            // SEM-1: case-insensitive match so a legacy mixed-case reporter_email
            // (written before normalisation-on-write) still surfaces in the
            // reporter's Article-15 export, even before the backfill runs.
            $query = $query->orWhereRaw('lower(trim(reporter_email)) = ?', [$emailLc]);
        }

        foreach ($this->lazyRows($query) as $row) {
            yield array_merge(['record_type' => 'filed_by_me'], $row);
        }
    }

    /**
     * #PRIV-13: the subject's own identity as FROZEN into a moderation evidence
     * snapshot (EvidenceSnapshotService::snapshotSite()). Their live handle and
     * display_name are already exported from core.users; this is the separate,
     * immutable copy taken when their content was reported, and it is held
     * about them, so Article 15 reaches it.
     *
     * Built POSITIVELY from an allowlist — `payload` is SELECTed only so the
     * three subject-owned keys can be read out of it, and is never assigned
     * onto the yielded row. Withheld by construction: `payload` itself,
     * `content_hash`, and `signal_id` (an FK into moderation.case_signals,
     * which carries the REPORTER's user id, email and ip hash — exporting the
     * pointer would hand the reported party a route into a third party's
     * record). Do not "filter down" a spread here: a filter only removes what
     * its author remembered, so a key added by a future snapshot strategy would
     * leak by default.
     *
     * evidence_type is pinned to 'content_snapshot'. The CHECK also permits
     * csam_hash_match (disclosing a hash match to the subject is a
     * tipping-off risk), upload_metadata and staff_attachment (internal) —
     * none of which is the subject's own frozen identity.
     *
     * The predicate is deliberately IDENTICAL to
     * AccountDeletionService::purgeReportedUserEvidencePii(): evidence_type +
     * payload->user_id, with NO join to moderation.cases. Divergence would mean
     * a row this export discloses is not a row that purge erases, or vice
     * versa. `where('payload->user_id', ...)` compiles to json_extract() on
     * SQLite and "payload"->>'user_id' on Postgres — the purge has run this
     * exact form in production since PRIV-3.
     */
    private function streamModerationEvidence(string $userId): Generator
    {
        foreach ($this->lazyRows(
            DB::connection('pgsql')
                ->table('moderation.evidence')
                ->select(['id', 'case_id', 'evidence_type', 'payload', 'captured_at'])
                ->where('evidence_type', 'content_snapshot')
                ->where('payload->user_id', $userId)
        ) as $row) {
            $payload = $this->decodeJsonColumn($row['payload'] ?? null) ?? [];

            // Positive build. Do NOT spread $row or $payload here — see docblock.
            yield [
                'id' => $row['id'] ?? null,
                'case_id' => $row['case_id'] ?? null,
                'evidence_type' => $row['evidence_type'] ?? null,
                'captured_at' => $row['captured_at'] ?? null,
                'handle' => $payload['handle'] ?? null,
                'display_name' => $payload['display_name'] ?? null,
                'site_subdomain' => $payload['site_subdomain'] ?? null,
            ];
        }
    }

    /**
     * Per-site design kit variables. All columns are NULLABLE; a null value
     * means the code-side default from the @partnaau/design-system package
     * applies. Only the stored (non-default) overrides appear as non-null.
     * Returns at most one row (design_kits has a 1:1 FK to site.sites).
     */
    private function streamDesignKit(?string $siteId): Generator
    {
        if ($siteId === null) {
            yield from [];

            return;
        }

        yield from $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
        );
    }

    private function streamEmailSubscriptions(string $userId, ?string $email): Generator
    {
        // Explicit allow-list — `unsubscribe_token`, `consent_ip_hash`, and
        // `consent_user_agent` are in EmailSubscription::$hidden because they
        // are either credentials (token unsubscribes anyone) or technical
        // fingerprints. DB::table() bypasses $hidden, so we re-state the
        // allow-list here. Mirrors the column set returned by
        // /api/email-subscribers.
        //
        // Three OR branches — each one is the user's personal data:
        //   1. Owned rows (user_id = X). The user is the owner of the list.
        //   2. Global rows (user_id IS NULL AND email_lc = user's email).
        //      Bootstrap creates sidest_updates rows with no owner; the only
        //      link is email_lc.
        //   3. Cross-tenant rows (user_id != X AND email_lc = user's
        //      email). The user subscribed to ANOTHER professional's newsletter
        //      via that professional's public site form — the row contains the
        //      user's email/consent/subscribed_at and must surface in their DSAR.
        //
        // #W1-DINT-3 / #W1-LIFE-7 (re-checked 2026-08-30, WONTFIX-stale): a
        // prior version of this note claimed writers upsert globally by bare
        // (list_key, email_lc) with no owner FK and no unique constraint
        // backing the key. Both claims are false against the current schema —
        // `email_subscriptions_user_fk` (user_id -> core.users, baseline since
        // 2026-07-26) and the two partial unique indexes
        // (`..._unique_pro_list_email_lc` on (user_id, list_key, email_lc) and
        // `..._unique_global_list_email_lc` on (list_key, email_lc) WHERE
        // user_id IS NULL) already exist, and PublicEmailSubscriptionController
        // already scopes its upsert lookup by (user_id, list_key, email_lc),
        // not by email_lc alone. `user_id` here is the site-OWNER
        // (professional) attribution FK, not a subscriber-identity FK — a
        // subscriber to another professional's newsletter need not hold a
        // Partna account at all, so there is no stronger subscriber identity
        // to key on.
        //
        // #W1-PRIV-3 (re-checked 2026-08-30, residual risk accepted, not
        // fixed): because the per-(user_id, list_key, email_lc) row is keyed
        // on email alone within that scope, a genuinely recycled email address
        // (rare — a mailbox provider reassigns it to a new person, who then
        // subscribes to the SAME professional's SAME list) overwrites the row
        // CONTENT with the new subscriber's data but leaves `id`/`created_at`
        // from the prior occupant. Unlike #PRIV-1's early_access_signups
        // case, there is no reliable ownership signal to bucket on here — the
        // system cannot distinguish "the same subscriber returning" from "a
        // different person who inherited this address" from email alone, so
        // building a withholding rule would be guessing, not verifying. The
        // residual disclosure is two non-identifying fields (a UUID and a
        // timestamp, no name/email/status/consent from the prior occupant) —
        // bounded enough that a detection heuristic isn't worth the false
        // positives it would create. Documented here per the finding's own
        // stated alternative; revisit if subscriber-level identity is ever
        // added to this table.
        $emailLc = $this->normaliseEmail($email);

        // #W1-SEC-14: `user_id` in this table is the LIST OWNER (professional),
        // never the subscriber. On the caller's own list (bucket 1 below) it
        // equals their own id and is safe to show; on a cross-tenant row
        // (bucket 3 — the subject subscribed to a DIFFERENT professional's
        // list) it is a third party's internal primary key, not necessary to
        // satisfy the subject's own Article 15 request, so it is nulled out.
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('notifications.email_subscriptions')
                ->select(['id', 'user_id', 'list_key', 'email', 'email_lc', 'full_name', 'status', 'subscribed_at', 'unsubscribed_at', 'consent_source', 'created_at'])
                ->where(function ($q) use ($userId, $emailLc) {
                    $q->where('user_id', $userId);
                    if ($emailLc !== null) {
                        $q->orWhere(function ($q2) use ($userId, $emailLc) {
                            $q2->where('email_lc', $emailLc)
                                ->where(function ($q3) use ($userId) {
                                    $q3->whereNull('user_id')
                                        ->orWhere('user_id', '!=', $userId);
                                });
                        });
                    }
                }),
            transform: function (object $row) use ($userId): array {
                $row = (array) $row;

                if ($row['user_id'] !== $userId) {
                    $row['user_id'] = null;
                }

                return $row;
            },
        );
    }

    /**
     * #PRIV-1: three-bucket ownership rule. `user_id` has existed since the
     * 2026-07-26 baseline; a row is either (A) owned via `user_id`, (B)
     * matched only by email and still on the waitlist, or (C) matched only
     * by email but already progressed past the waitlist — an unverifiable
     * link, since email addresses get reassigned between people. Bucket C is
     * exported but has its occupational/invitation fields withheld (see
     * EARLY_ACCESS_WITHHELD_DISCLOSURE); a row owned by a DIFFERENT user_id
     * falls out of the predicate entirely.
     *
     * Bucket B is safe to export in full: EarlyAccessService::signupFromMarketing()
     * refreshes type/workplace_or_industry/platforms only while the existing
     * row's status is still 'waitlist', so a resubmission under a recycled
     * email has already overwritten any predecessor's content by the time the
     * row could reach this export unowned.
     */
    private function streamEarlyAccessSignups(string $userId, ?string $email): Generator
    {
        $emailLc = $this->normaliseEmail($email);

        // Drops consent_ip_hash + consent_user_agent (technical fingerprint),
        // email_lc (redundant with email), and invite_token_hash (credential
        // material — never exported). user_id is selected only to classify
        // ownership below and is stripped before the row is yielded — it is
        // an internal FK, not part of the published section shape.
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('core.early_access_signups')
                ->select([
                    'id', 'user_id', 'email', 'type', 'workplace_or_industry',
                    'platforms', 'status', 'source',
                    'invited_at', 'signed_up_at',
                    'created_at', 'updated_at',
                ])
                ->where(function ($q) use ($userId, $emailLc) {
                    $q->where('user_id', $userId);
                    if ($emailLc !== null) {
                        $q->orWhere(fn ($q2) => $q2->whereNull('user_id')->where('email_lc', $emailLc));
                    }
                }),
            transform: function (object $row) use ($userId): array {
                $row = (array) $row;

                $ownership = $row['user_id'] === $userId
                    ? 'verified'
                    : ($row['status'] === 'waitlist' ? 'email_only' : 'unverified');

                unset($row['user_id']);

                if ($ownership === 'unverified') {
                    foreach (self::EARLY_ACCESS_WITHHELD_FIELDS as $field) {
                        $row[$field] = self::EARLY_ACCESS_WITHHELD_MARKER;
                    }
                }

                return array_merge(['ownership' => $ownership], $row);
            },
        );
    }

    /**
     * Permanent pre-account build origin record (1:1 with the user via
     * user_id UNIQUE + ON DELETE CASCADE — erased automatically when the
     * account is deleted, no explicit purge needed). Explicit allow-list:
     * drops built_by_staff_id (internal staff FK, third-party PII), failure_code
     * (internal bookkeeping vocabulary), and created_ip_hash (a hash, not raw
     * PII — mirrors the exclusion pattern for other *_ip_hash columns
     * elsewhere in this builder, e.g. streamContentReports' reporter_ip_hash).
     */
    private function streamPreAccountBuilds(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('core.pre_account_builds')
                ->select([
                    'id', 'source_type', 'source_ref', 'contact_email',
                    'built_via', 'build_state', 'expires_at', 'claimed_at', 'created_at',
                ])
                ->where('user_id', $userId)
        );
    }

    /**
     * Per-category email opt-in/out preferences. Required for GDPR Article 15
     * (right of access) — users must be able to see every preference we store
     * about them, not just the marketing subscription list.
     *
     * PRIV-2: explicit allow-list, equal to the table's current full column set.
     */
    private function streamNotificationPreferences(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('notifications.notification_email_preferences')
                ->select(['id', 'user_id', 'category_key', 'enabled', 'created_at', 'updated_at'])
                ->where('user_id', $userId)
        );
    }

    /**
     * PRIV-2: explicit allow-list, equal to the table's current full column set.
     * Per-professional policy overrides only — global policies apply to
     * every user and are not personal data.
     */
    private function streamNotificationPolicies(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('notifications.notification_email_policies')
                ->select(['id', 'user_id', 'category_key', 'mode', 'created_at', 'updated_at'])
                ->where('user_id', $userId)
        );
    }

    private function streamLeadSubmissions(string $userId): Generator
    {
        // Mirror the redaction in enquiries() — drop ip_hash + user_agent
        // (technical fingerprint, not user-visible lead data).
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('analytics.lead_submissions')
                ->select(['id', 'occurred_at', 'outcome', 'form_started_at_ms', 'customer_id', 'subdomain', 'site_id', 'referrer'])
                ->where('user_id', $userId)
        );
    }

    /**
     * Export-request audit trail. PRIV-1: an explicit allowlist (like every other
     * sensitive builder) — the bare DB::table() returned all columns, leaking
     * third-party PII. recipient_email is the professional's own address only when
     * send_to === 'professional'; for a staff-recipient export it holds the staff
     * member's email — a third party who never consented to disclosure — so redact
     * it. triggered_by_staff_id (the staff UUID) is dropped entirely; the
     * triggered_by marker already records whether self or staff triggered the export.
     */
    private function streamAudit(string $userId): Generator
    {
        foreach ($this->lazyRows(
            DB::connection('pgsql')
                ->table('audit.data_export_audit')
                ->select([
                    'id', 'user_id', 'professional_handle_snapshot', 'professional_email_snapshot',
                    'triggered_by', 'recipient_email', 'send_to', 'status', 'file_size_bytes',
                    'file_sha256', 'record_counts', 'error_message', 'email_sent_at',
                    'email_delivery_status', 'created_at', 'completed_at',
                ])
                ->where('user_id', $userId)
        ) as $row) {
            if (($row['send_to'] ?? null) !== 'professional') {
                $row['recipient_email'] = '[redacted]';
            }
            yield $row;
        }
    }

    /**
     * Handle rename history. Drops ip_address + user_agent (technical
     * fingerprint per the streamEnquiries precedent). Maps actor_id to a
     * coarse `actor_kind` marker — exposing the raw staff UUID would leak
     * third-party PII (the staff member) that isn't justified by Article 15.
     * Rows outlive the user because the FK is ON DELETE SET NULL — making
     * pre-deletion disclosure the only practical Article 15 surface.
     */
    private function streamHandleChangeLog(string $userId): Generator
    {
        foreach ($this->lazyRows(
            DB::connection('pgsql')
                ->table('audit.handle_change_log')
                ->select(['id', 'user_id', 'old_handle', 'new_handle', 'reason', 'actor_id', 'changed_at'])
                ->where('user_id', $userId)
        ) as $row) {
            $actorId = $row['actor_id'] ?? null;
            $row['actor_kind'] = $actorId === null
                ? 'system'
                : ((string) $actorId === $userId ? 'self' : 'staff');
            unset($row['actor_id']);
            yield $row;
        }
    }

    /**
     * Former handles the user has held. Joined by user_id (FK
     * ON DELETE CASCADE — disappears with the user). The handles themselves
     * are user identifiers; the lifecycle timestamps document reclaim/expiry
     * windows the user can see in the dashboard.
     */
    private function streamHandleAliases(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('core.user_handle_aliases')
                ->select(['id', 'handle', 'reclaim_until', 'expires_at', 'created_at', 'updated_at'])
                ->where('user_id', $userId)
        );
    }

    /**
     * Former subdomains for the user's site. Joined via site_id → site.sites
     * (which has the professional FK). The subdomains themselves are user
     * identifiers; same DSAR rationale as handle aliases.
     */
    private function streamSubdomainAliases(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.site_subdomain_aliases')
                ->join('site.sites', 'site.site_subdomain_aliases.site_id', '=', 'site.sites.id')
                ->where('site.sites.user_id', $userId)
                ->select([
                    'site.site_subdomain_aliases.id',
                    'site.site_subdomain_aliases.subdomain',
                    'site.site_subdomain_aliases.reclaim_until',
                    'site.site_subdomain_aliases.expires_at',
                    'site.site_subdomain_aliases.created_at',
                    'site.site_subdomain_aliases.updated_at',
                ])
        );
    }

    /**
     * Deletion lifecycle events the user (or staff) triggered against this
     * account. Includes the user's own IP/UA from `requested`/`confirmed`/
     * `cancelled` events (actor_type='professional') and the reason text from
     * staff-initiated events. PRIV-1: ip_address/user_agent are redacted for
     * any row NOT authored by the subject — logAuditEvent() captures
     * $request->ip()/userAgent() unconditionally, so an admin_initiated or
     * admin_cancelled row carries the ACTING STAFF MEMBER's IP/UA, not the
     * subject's; exporting it would disclose a third party's PII in the
     * subject's own Article-15 export. `reason` is deliberately kept even on
     * staff rows — it's about the subject, and Article 15 covers it. Mirrors
     * streamHandleChangeLog()'s actor_id -> actor_kind pattern one method
     * above (self/staff/system), keyed here off the already-present actor_type
     * column rather than a raw actor id. FK is ON DELETE SET NULL — rows
     * outlive the user, so disclosure must happen while the user can still
     * request a DSAR.
     */
    private function streamDeletionAudit(string $userId): Generator
    {
        foreach ($this->lazyRows(
            DB::connection('pgsql')
                ->table('audit.user_deletion_audit')
                ->select([
                    'id', 'event', 'actor_type', 'reason',
                    'ip_address', 'user_agent', 'metadata',
                    'professional_email_snapshot',
                    'professional_handle_snapshot',
                    'created_at',
                ])
                ->where('user_id', $userId)
                ->orderBy('created_at')
        ) as $row) {
            $actorType = $row['actor_type'] ?? null;

            $row['actor_kind'] = match ($actorType) {
                UserDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL => 'self',
                UserDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN => 'staff',
                default => 'system',
            };

            if ($actorType !== UserDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL) {
                $row['ip_address'] = null;
                $row['user_agent'] = null;
            }

            yield $row;
        }
    }

    /**
     * Dashboard notifications addressed specifically to this user. The body
     * text is user-specific personal data (e.g. billing warnings, security
     * alerts mentioning the account holder). Broadcast notifications
     * (user_id IS NULL) are NOT included — they're sent to every user
     * and contain no personal data.
     *
     * PRIV-2: explicit allow-list, equal to the table's current full column set.
     */
    private function streamNotifications(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('notifications.notifications')
                ->select([
                    'id', 'user_id', 'type', 'title', 'body', 'cta_url', 'severity', 'critical',
                    'starts_at', 'ends_at', 'primary_action_label', 'secondary_action_label',
                    'secondary_action_url', 'category', 'dedupe_key', 'email_sent_at',
                    'created_at', 'updated_at',
                ])
                ->where('user_id', $userId)
                ->orderBy('created_at')
        );
    }

    /**
     * Per-notification read/dismiss timestamps. Behavioural data tied to the
     * identified user — EDPB Guidelines 01/2022 treats this as in-scope for
     * Article 15.
     *
     * PRIV-2: explicit allow-list, equal to the table's current full column set.
     */
    private function streamNotificationReceipts(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('notifications.notification_receipts')
                ->select(['id', 'notification_id', 'user_id', 'read_at', 'dismissed_at', 'created_at', 'updated_at'])
                ->where('user_id', $userId)
                ->orderBy('updated_at')
        );
    }

    /**
     * MFA factor lifecycle events (enroll/challenge/verify) with the user's
     * own IP/UA — the user's data, not a third party's. Joined on the auth
     * user_id (auth.users(id) → core.users.auth_user_id) rather than
     * user_id; auth_factor_events predates the professional row in
     * some flows and doesn't carry a professional FK.
     */
    private function streamAuthFactorEvents(?string $authUserId): Generator
    {
        if ($authUserId === null || $authUserId === '') {
            yield from [];

            return;
        }

        yield from $this->lazyRows(
            DB::connection('pgsql')
                ->table('audit.auth_factor_events')
                // webhook_id is deliberately excluded (WHK-101): it's an internal
                // Supabase delivery correlator for dedup, not the user's data.
                ->select([
                    'id', 'session_id', 'event_type', 'factor_id', 'factor_type',
                    'ip', 'user_agent', 'metadata', 'created_at',
                ])
                ->where('user_id', $authUserId)
                ->orderBy('created_at')
        );
    }

    /**
     * Iterate a query as a PDO cursor, yielding each row as a plain array.
     * ->cursor() returns a LazyCollection that fetches rows from the
     * PDOStatement one at a time rather than building a full result array,
     * which keeps peak PHP memory bounded regardless of total row count.
     */
    private function lazyRows(Builder $query, ?callable $transform = null): Generator
    {
        foreach ($query->cursor() as $row) {
            yield $transform !== null ? $transform($row) : (array) $row;
        }
    }

    /**
     * Materialise a generator to an array. Used only by build() and by the
     * small-but-bounded sections (site.blocks) that
     * never realistically grow into the OOM danger zone.
     */
    private function collect(Generator $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $row;
        }

        return $out;
    }
}
