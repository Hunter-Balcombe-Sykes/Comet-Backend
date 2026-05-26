<?php

namespace App\Services\User\DataExport;

use App\Models\Core\User\User;
use Generator;
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
     * Resolve the user's original (pre-pseudonymisation) email for email_lc
     * lookups against waitlist and global email_subscriptions.
     *
     * AccountDeletionService::pseudonymiseAccountPii() rewrites primary_email
     * to "deleted+{id}@partna.au" before hard-delete. A DSAR run during the
     * 30-day grace period — or any staff-initiated export against a
     * soft-deleted user — must still surface the user's original waitlist row
     * and global subscriptions. We fall back to the email snapshot stored in
     * audit.user_deletion_audit (written before pseudonymisation).
     */
    private function resolveLookupEmail(User $professional): ?string
    {
        $email = $professional->primary_email;
        if ($email !== null && str_starts_with($email, 'deleted+')) {
            $snapshot = DB::connection('pgsql')
                ->table('audit.user_deletion_audit')
                ->where('user_id', $professional->id)
                ->whereIn('event', ['requested', 'admin_initiated'])
                ->orderBy('created_at')
                ->value('professional_email_snapshot');
            if (is_string($snapshot) && $snapshot !== '') {
                return $snapshot;
            }
        }

        return $email;
    }

    /**
     * Build the full payload for a single professional, materialised in memory.
     *
     * Prefer stream() for production exports — this entry point exists for
     * tests and small-tenant scenarios. It iterates the same generators
     * stream() exposes, so memory usage scales with the largest section.
     *
     * @return array{metadata: array, profile: array, site: array, waitlist: array, media: array, integrations: array, customers: array, services: array, service_categories: array, enquiries: array, lead_submissions: array, email_subscriptions: array, notifications: array, ui_preferences: array, notification_preferences: array, auth: array, audit: array}
     */
    public function build(string $userId): array
    {
        $professional = $this->loadUser($userId);
        $lookupEmail = $this->resolveLookupEmail($professional);

        return [
            'metadata' => $this->metadata($professional),
            'profile' => $this->profile($professional),
            'site' => $this->site($userId),
            // Pre-account history — joined by email_lc, persists even if the user never finished signup.
            'waitlist' => $this->collect($this->streamWaitlistSignups($lookupEmail)),
            'media' => ['site_media' => $this->collect($this->streamMedia($userId))],
            'integrations' => $this->collect($this->streamIntegrations($userId)),
            'customers' => $this->collect($this->streamCustomers($userId)),
            'services' => $this->collect($this->streamServices($userId)),
            'service_categories' => $this->collect($this->streamServiceCategories($userId)),
            'enquiries' => $this->collect($this->streamEnquiries($userId)),
            'lead_submissions' => $this->collect($this->streamLeadSubmissions($userId)),
            // Lookup email is the pre-pseudonymisation original; covers owned, global, and cross-pro rows.
            'email_subscriptions' => $this->collect($this->streamEmailSubscriptions($userId, $lookupEmail)),
            // Dashboard messages sent specifically to this user — body text is user-specific personal data.
            'notifications' => [
                'messages' => $this->collect($this->streamNotifications($userId)),
                'receipts' => $this->collect($this->streamNotificationReceipts($userId)),
            ],
            'ui_preferences' => [
                'confirmation_preferences' => $this->collect($this->streamConfirmationPreferences($userId)),
            ],
            'notification_preferences' => [
                'category_preferences' => $this->collect($this->streamNotificationPreferences($userId)),
                'staff_policy_overrides' => $this->collect($this->streamNotificationPolicies($userId)),
            ],
            'auth' => [
                'factor_events' => $this->collect($this->streamAuthFactorEvents($professional->auth_user_id)),
            ],
            'audit' => [
                'data_export_audit' => $this->collect($this->streamAudit($userId)),
                'handle_change_log' => $this->collect($this->streamHandleChangeLog($userId)),
                'handle_aliases' => $this->collect($this->streamHandleAliases($userId)),
                'subdomain_aliases' => $this->collect($this->streamSubdomainAliases($userId)),
                'deletion_audit' => $this->collect($this->streamDeletionAudit($userId)),
            ],
        ];
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
     */
    public function stream(string $userId): Generator
    {
        $professional = $this->loadUser($userId);
        $lookupEmail = $this->resolveLookupEmail($professional);

        yield ['name' => 'metadata', 'kind' => 'value', 'value' => $this->metadata($professional)];
        yield ['name' => 'profile', 'kind' => 'value', 'value' => $this->profile($professional)];
        yield ['name' => 'site', 'kind' => 'value', 'value' => $this->site($userId)];

        // Pre-account waitlist signup (joined by email_lc, no user_id FK).
        yield [
            'name' => 'waitlist',
            'kind' => 'rows',
            'rows' => $this->streamWaitlistSignups($lookupEmail),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'media.site_media',
            'kind' => 'rows',
            'rows' => $this->streamMedia($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'integrations',
            'kind' => 'rows',
            'rows' => $this->streamIntegrations($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'customers',
            'kind' => 'rows',
            'rows' => $this->streamCustomers($userId),
            'csv_columns' => ['id', 'email', 'phone', 'full_name', 'source', 'notes', 'created_at'],
        ];

        yield [
            'name' => 'services',
            'kind' => 'rows',
            'rows' => $this->streamServices($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'service_categories',
            'kind' => 'rows',
            'rows' => $this->streamServiceCategories($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'enquiries',
            'kind' => 'rows',
            'rows' => $this->streamEnquiries($userId),
            'csv_columns' => ['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'],
        ];

        yield [
            'name' => 'lead_submissions',
            'kind' => 'rows',
            'rows' => $this->streamLeadSubmissions($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'email_subscriptions',
            'kind' => 'rows',
            'rows' => $this->streamEmailSubscriptions($userId, $lookupEmail),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'notifications.messages',
            'kind' => 'rows',
            'rows' => $this->streamNotifications($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'notifications.receipts',
            'kind' => 'rows',
            'rows' => $this->streamNotificationReceipts($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'ui_preferences.confirmation_preferences',
            'kind' => 'rows',
            'rows' => $this->streamConfirmationPreferences($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'notification_preferences.category_preferences',
            'kind' => 'rows',
            'rows' => $this->streamNotificationPreferences($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'notification_preferences.staff_policy_overrides',
            'kind' => 'rows',
            'rows' => $this->streamNotificationPolicies($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'auth.factor_events',
            'kind' => 'rows',
            'rows' => $this->streamAuthFactorEvents($professional->auth_user_id),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'audit.data_export_audit',
            'kind' => 'rows',
            'rows' => $this->streamAudit($userId),
            'csv_columns' => null,
        ];

        // Handle rename history — survives hard-delete via ON DELETE SET NULL, so disclose pre-deletion.
        yield [
            'name' => 'audit.handle_change_log',
            'kind' => 'rows',
            'rows' => $this->streamHandleChangeLog($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'audit.handle_aliases',
            'kind' => 'rows',
            'rows' => $this->streamHandleAliases($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'audit.subdomain_aliases',
            'kind' => 'rows',
            'rows' => $this->streamSubdomainAliases($userId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'audit.deletion_audit',
            'kind' => 'rows',
            'rows' => $this->streamDeletionAudit($userId),
            'csv_columns' => null,
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
        $site = DB::connection('pgsql')
            ->table('site.sites')
            ->where('user_id', $userId)
            ->first();

        if (! $site) {
            return ['site' => null, 'blocks' => []];
        }

        $blocks = $this->collect(
            $this->lazyRows(
                DB::connection('pgsql')
                    ->table('site.blocks')
                    ->where('site_id', $site->id)
                    ->orderBy('sort_order')
            )
        );

        return [
            'site' => (array) $site,
            'blocks' => $blocks,
        ];
    }

    private function streamMedia(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.site_media')
                ->select(['id', 'pool', 'purpose', 'path', 'width', 'height', 'caption', 'alt_text', 'created_at'])
                ->where('user_id', $userId)
        );
    }

    private function streamIntegrations(string $userId): Generator
    {
        // No integrations for individual-standalone accounts; yield nothing.
        yield from [];
    }

    private function streamCustomers(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.customers')
                ->where('user_id', $userId)
        );
    }

    private function streamServices(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.services')
                ->where('user_id', $userId)
        );
    }

    private function streamServiceCategories(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.service_categories')
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
     * Pre-account waitlist signup record. No FK to core.users — joined only
     * by email_lc — so the row persists indefinitely even after signup. Under
     * Article 15 it is personal data of the data subject and must be exported.
     *
     * Email-recycle note: PublicWaitlistController upserts by email_lc, so when
     * an email is recycled the row CONTENT is overwritten with the new user's
     * data; only created_at and id are preserved from the prior occupant.
     * Bounded leak — see streamEmailSubscriptions comment.
     */
    private function streamWaitlistSignups(?string $email): Generator
    {
        $emailLc = $this->normaliseEmail($email);
        if ($emailLc === null) {
            yield from [];

            return;
        }

        // Drops consent_ip_hash + consent_user_agent (technical fingerprint)
        // and email_lc (redundant with email). Mirrors the streamEnquiries
        // redaction pattern.
        yield from $this->lazyRows(
            DB::connection('pgsql')
                ->table('core.waitlist_signups')
                ->select([
                    'id', 'name', 'email', 'phone',
                    'applicant_type', 'applicant_type_other',
                    'industry', 'industry_other',
                    'pilot_program_opt_in', 'number_of_team_members',
                    'consent_source', 'last_submitted_at',
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
    private function streamNotificationPreferences(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('notifications.notification_email_preferences')
                ->where('user_id', $userId)
        );
    }

    private function streamNotificationPolicies(string $userId): Generator
    {
        // Per-professional policy overrides only — global policies apply to
        // every user and are not personal data.
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('notifications.notification_email_policies')
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

    private function streamAudit(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('audit.data_export_audit')
                ->where('user_id', $userId)
        );
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
     * account. Includes the user's IP/UA from `requested`/`confirmed` events
     * and the reason text from staff-initiated events. FK is ON DELETE SET
     * NULL — rows outlive the user, so disclosure must happen while the user
     * can still request a DSAR.
     */
    private function streamDeletionAudit(string $userId): Generator
    {
        return $this->lazyRows(
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
        );
    }

    /**
     * Dashboard notifications addressed specifically to this user. The body
     * text is user-specific personal data (e.g. billing warnings, security
     * alerts mentioning the account holder). Broadcast notifications
     * (user_id IS NULL) are NOT included — they're sent to every user
     * and contain no personal data.
     */
    private function streamNotifications(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('notifications.notifications')
                ->where('user_id', $userId)
                ->orderBy('created_at')
        );
    }

    /**
     * Per-notification read/dismiss timestamps. Behavioural data tied to the
     * identified user — EDPB Guidelines 01/2022 treats this as in-scope for
     * Article 15.
     */
    private function streamNotificationReceipts(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('notifications.notification_receipts')
                ->where('user_id', $userId)
                ->orderBy('updated_at')
        );
    }

    /**
     * Per-action UI confirmation preferences. Same shape as
     * notification_email_preferences (which is already exported); structural
     * symmetry makes inclusion the consistent choice.
     */
    private function streamConfirmationPreferences(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('core.user_confirmation_preferences')
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
    private function lazyRows(\Illuminate\Database\Query\Builder $query): Generator
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
