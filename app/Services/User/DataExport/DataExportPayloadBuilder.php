<?php

namespace App\Services\User\DataExport;

use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Services\User\Concerns\ResolvesDeletedEmail;
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
     */
    public const COVERED_PII_TABLES = [
        'core.users',
        'core.early_access_signups',
        'core.feedback',
        'site.customers',
        'site.enquiries',
        'site.workplaces',
        'notifications.email_subscriptions',
        'audit.data_export_audit',
        'audit.user_deletion_audit',
    ];

    private const SCHEMA_VERSION = 1;

    private const PII_DISCLOSURE = 'This export contains personally identifiable information (PII) you collected from your customers via Partna (booking history, enquiries, email subscriptions). Handle in accordance with applicable privacy law.';

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
     * @return array{metadata: array, profile: array, site: array, early_access: array, media: array, design_kit: array, integrations: array, analytics: array, customers: array, services: array, service_categories: array, enquiries: array, lead_submissions: array, feedback: array, content_reports: array, email_subscriptions: array, notifications: array, ui_preferences: array, notification_preferences: array, auth: array, audit: array}
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
     * For nested groups (notifications, ui_preferences, notification_preferences,
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
                'resolve' => fn () => $this->streamEarlyAccessSignups($lookupEmail),
                'csv_columns' => ['id', 'email', 'type', 'workplace_or_industry', 'platforms', 'status', 'source', 'invited_at', 'signed_up_at', 'created_at', 'updated_at'],
            ],
            ['name' => 'media.site_media', 'kind' => 'rows', 'resolve' => fn () => $this->streamMedia($siteId)],
            ['name' => 'design_kit', 'kind' => 'rows', 'resolve' => fn () => $this->streamDesignKit($siteId)],
            ['name' => 'integrations', 'kind' => 'rows', 'resolve' => fn () => $this->streamIntegrations($userId)],
            ['name' => 'analytics.site_visits', 'kind' => 'rows', 'resolve' => fn () => $this->streamAnalyticsSiteVisits($userId)],
            ['name' => 'analytics.link_clicks', 'kind' => 'rows', 'resolve' => fn () => $this->streamAnalyticsLinkClicks($userId)],
            ['name' => 'analytics.section_views', 'kind' => 'rows', 'resolve' => fn () => $this->streamAnalyticsSectionViews($userId)],
            ['name' => 'analytics.item_views', 'kind' => 'rows', 'resolve' => fn () => $this->streamAnalyticsItemViews($userId)],
            [
                'name' => 'customers',
                'kind' => 'rows',
                'resolve' => fn () => $this->streamCustomers($userId),
                'csv_columns' => ['id', 'email', 'phone', 'full_name', 'source', 'notes', 'created_at'],
            ],
            ['name' => 'services', 'kind' => 'rows', 'resolve' => fn () => $this->streamServices($userId)],
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
            ['name' => 'email_subscriptions', 'kind' => 'rows', 'resolve' => fn () => $this->streamEmailSubscriptions($userId, $lookupEmail)],
            ['name' => 'notifications.messages', 'kind' => 'rows', 'resolve' => fn () => $this->streamNotifications($userId)],
            ['name' => 'notifications.receipts', 'kind' => 'rows', 'resolve' => fn () => $this->streamNotificationReceipts($userId)],
            ['name' => 'ui_preferences.confirmation_preferences', 'kind' => 'rows', 'resolve' => fn () => $this->streamConfirmationPreferences($userId)],
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
                'architecture_id', 'moderation_state',
                'custom_domain', 'custom_domain_status', 'custom_domain_verified_at',
                'custom_domain_cf_id', 'custom_domain_primary',
                'show_branding', 'charlie_enabled', 'services_auto_sync_enabled',
                'booking_mode', 'manual_booking_url', 'content_instagram_auto_enabled',
                'shop_link_mode', 'shop_auto_latest',
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
        // Explicit allow-list (PRIV-5): previous_website_analysis (WebsiteStyleAnalyzer
        // output) is excluded — WorkplaceResource deliberately withholds it as internal
        // brand-signal detail, not part of the user-facing workplace-card contract, and
        // a `select(['*'])`-shaped `first()` was disclosing it anyway.
        $workplaceRow = DB::connection('pgsql')
            ->table('site.workplaces')
            ->select([
                'site_id', 'name', 'address', 'address_line1', 'city', 'state', 'postcode',
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
            $row['payload'] = $this->decodeJsonColumn($row['payload'] ?? null);
            $row['display_settings'] = $this->decodeJsonColumn($row['display_settings'] ?? null);
            yield $row;
        }
    }

    /**
     * Visitor analytics: page visits to the user's site. Fingerprint columns
     * (ip_hash, visitor_id, session_id, user_agent) are excluded — they identify
     * the *visitor*, a third party, not the site owner who is the DSAR subject.
     * latitude/longitude are included: coarse (city-level) geo the owner already
     * sees on their own analytics dashboard, not a fingerprint.
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
     * PRIV-2: explicit allow-list, equal to the table's current full column set.
     */
    private function streamServices(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.services')
                ->select([
                    'id', 'user_id', 'title', 'description', 'category', 'price_cents',
                    'currency_code', 'duration_minutes', 'is_active', 'sort_order',
                    'category_id', 'deleted_origin', 'created_at', 'updated_at', 'deleted_at',
                ])
                ->where('user_id', $userId)
        );
    }

    /**
     * PRIV-2: explicit allow-list, equal to the table's current full column set.
     */
    private function streamServiceCategories(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.service_categories')
                ->select(['id', 'user_id', 'title', 'sort_order', 'created_at', 'updated_at', 'deleted_at'])
                ->where('user_id', $userId)
        );
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
        // Note on email-recycle: writers upsert by (list_key, email_lc), so when
        // an email is recycled (rare today) the row CONTENT is overwritten with
        // the new user's data; only created_at and id are preserved from the
        // prior occupant. Bounded leak, mostly metadata. Schema-level fix is to
        // add an owner FK; that's a follow-up.
        $emailLc = $this->normaliseEmail($email);

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
                })
        );
    }

    /**
     * Early-access signup record. No FK to core.users — joined only by email_lc —
     * so the row persists after signup. Under Article 15 it is personal data of
     * the data subject and must be exported.
     *
     * Email-recycle note: EarlyAccessService::signupFromMarketing() upserts by
     * email_lc via firstOrCreate, refreshing type/workplace_or_industry/platforms
     * only while the existing row's status is still 'waitlist'. Once a row has
     * progressed to invited or signed_up, a resubmission under a recycled email
     * is a silent no-op — the row keeps the FORMER occupant's type, status,
     * invited_at, and signed_up_at, not just created_at/id. Larger leak than the
     * streamEmailSubscriptions case; no schema-level fix yet.
     */
    private function streamEarlyAccessSignups(?string $email): Generator
    {
        $emailLc = $this->normaliseEmail($email);
        if ($emailLc === null) {
            yield from [];

            return;
        }

        // Drops consent_ip_hash + consent_user_agent (technical fingerprint),
        // email_lc (redundant with email), and invite_token_hash (credential
        // material — never exported). Mirrors the streamEnquiries redaction pattern.
        yield from $this->lazyRows(
            DB::connection('pgsql')
                ->table('core.early_access_signups')
                ->select([
                    'id', 'email', 'type', 'workplace_or_industry',
                    'platforms', 'status', 'source',
                    'invited_at', 'signed_up_at',
                    'created_at', 'updated_at',
                ])
                ->where('email_lc', $emailLc)
        );
    }

    /**
     * Per-category email opt-in/out preferences. Required for GDPR Article 15
     * (right of access) — users must be able to see every preference we store
     * about them, not just the marketing subscription list.
     */
    /**
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
     */
    /**
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
     */
    /**
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
     * Per-action UI confirmation preferences. Same shape as
     * notification_email_preferences (which is already exported); structural
     * symmetry makes inclusion the consistent choice.
     */
    /**
     * PRIV-2: explicit allow-list, equal to the table's current full column set.
     */
    private function streamConfirmationPreferences(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('core.user_confirmation_preferences')
                ->select(['id', 'user_id', 'action_key', 'skip_confirmation', 'created_at', 'updated_at'])
                ->where('user_id', $userId)
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
    private function lazyRows(Builder $query): Generator
    {
        foreach ($query->cursor() as $row) {
            yield (array) $row;
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
