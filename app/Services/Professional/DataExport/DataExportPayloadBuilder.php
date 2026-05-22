<?php

namespace App\Services\Professional\DataExport;

use App\Models\Core\Professional\User;
use Generator;
use Illuminate\Support\Facades\DB;

// V2: Pure builder. Assembles the full data-export payload (Ring 1 + 2 of the
// scope rings — see docs/superpowers/specs/2026-04-25-data-export-design.md).
// No I/O beyond DB reads — testable with fixture data, no filesystem touches.
//
// Memory model: unbounded sections (customers, bookings, ledger, etc.) are
// exposed as Generators using ->lazy(LAZY_CHUNK_ROWS) so DataExportZipWriter
// can stream rows row-by-row to disk without loading the full result set into
// PHP memory. GDPR right-of-access must not OOM on a large brand. The
// legacy build() entry point still materialises the full payload (used by
// unit tests and any future small-export caller) — large callers should use
// stream() + DataExportZipWriter::writeStreaming().
class DataExportPayloadBuilder
{
    private const SCHEMA_VERSION = 1;

    private const PII_DISCLOSURE = 'This export contains personally identifiable information (PII) you collected from your customers via Partna (booking history, enquiries, email subscriptions). Handle in accordance with applicable privacy law.';

    /**
     * Build the full payload for a single professional, materialised in memory.
     *
     * Prefer stream() for production exports — this entry point exists for
     * tests and small-tenant scenarios. It iterates the same generators
     * stream() exposes, so memory usage scales with the largest section.
     *
     * @return array{metadata: array, profile: array, site: array, media: array, integrations: array, customers: array, services: array, service_categories: array, enquiries: array, email_subscriptions: array, notification_preferences: array, bookings: array, billing: array, audit: array}
     */
    public function build(string $professionalId): array
    {
        $professional = $this->loadProfessional($professionalId);

        return [
            'metadata' => $this->metadata($professional),
            'profile' => $this->profile($professional),
            'site' => $this->site($professionalId),
            'media' => ['site_media' => $this->collect($this->streamMedia($professionalId))],
            'integrations' => $this->collect($this->streamIntegrations($professionalId)),
            'customers' => $this->collect($this->streamCustomers($professionalId)),
            'services' => $this->collect($this->streamServices($professionalId)),
            'service_categories' => $this->collect($this->streamServiceCategories($professionalId)),
            'enquiries' => $this->collect($this->streamEnquiries($professionalId)),
            'email_subscriptions' => $this->collect($this->streamEmailSubscriptions($professionalId)),
            'notification_preferences' => [
                'category_preferences' => $this->collect($this->streamNotificationPreferences($professionalId)),
                'staff_policy_overrides' => $this->collect($this->streamNotificationPolicies($professionalId)),
            ],
            'audit' => ['data_export_audit' => $this->collect($this->streamAudit($professionalId))],
        ];
    }

    /**
     * Yield section descriptors in payload order. Each yielded item is one of:
     *   ['name' => string, 'kind' => 'value', 'value' => mixed]
     *   ['name' => string, 'kind' => 'rows',  'rows' => Generator, 'csv_columns' => ?array<string>]
     *
     * For nested groups (bookings, billing, media, notification_preferences,
     * audit) the descriptor's 'name' is the outer key and 'value' wraps the
     * group's small fixed fields, while the unbounded child arrays are yielded
     * as separate 'rows' descriptors with dotted names (e.g. 'bookings.booking_events').
     * The writer reassembles the group structure when emitting JSON.
     */
    public function stream(string $professionalId): Generator
    {
        $professional = $this->loadProfessional($professionalId);

        yield ['name' => 'metadata', 'kind' => 'value', 'value' => $this->metadata($professional)];
        yield ['name' => 'profile', 'kind' => 'value', 'value' => $this->profile($professional)];
        yield ['name' => 'site', 'kind' => 'value', 'value' => $this->site($professionalId)];

        yield [
            'name' => 'media.site_media',
            'kind' => 'rows',
            'rows' => $this->streamMedia($professionalId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'integrations',
            'kind' => 'rows',
            'rows' => $this->streamIntegrations($professionalId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'customers',
            'kind' => 'rows',
            'rows' => $this->streamCustomers($professionalId),
            'csv_columns' => ['id', 'email', 'phone', 'full_name', 'source', 'notes', 'created_at'],
        ];

        yield [
            'name' => 'services',
            'kind' => 'rows',
            'rows' => $this->streamServices($professionalId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'service_categories',
            'kind' => 'rows',
            'rows' => $this->streamServiceCategories($professionalId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'enquiries',
            'kind' => 'rows',
            'rows' => $this->streamEnquiries($professionalId),
            'csv_columns' => ['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'],
        ];

        yield [
            'name' => 'email_subscriptions',
            'kind' => 'rows',
            'rows' => $this->streamEmailSubscriptions($professionalId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'notification_preferences.category_preferences',
            'kind' => 'rows',
            'rows' => $this->streamNotificationPreferences($professionalId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'notification_preferences.staff_policy_overrides',
            'kind' => 'rows',
            'rows' => $this->streamNotificationPolicies($professionalId),
            'csv_columns' => null,
        ];

        yield [
            'name' => 'audit.data_export_audit',
            'kind' => 'rows',
            'rows' => $this->streamAudit($professionalId),
            'csv_columns' => null,
        ];
    }

    private function loadProfessional(string $professionalId): User
    {
        return User::query()
            ->withTrashed()
            ->where('id', $professionalId)
            ->firstOrFail();
    }

    private function metadata(User $p): array
    {
        return [
            'professional_id' => $p->id,
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

    private function site(string $professionalId): array
    {
        $site = DB::connection('pgsql')
            ->table('site.sites')
            ->where('professional_id', $professionalId)
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

    private function streamMedia(string $professionalId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.site_media')
                ->select(['id', 'pool', 'purpose', 'path', 'width', 'height', 'caption', 'alt_text', 'created_at'])
                ->where('professional_id', $professionalId)
        );
    }

    private function streamIntegrations(string $professionalId): Generator
    {
        // No integrations for individual-standalone accounts; yield nothing.
        yield from [];
    }

    private function streamCustomers(string $professionalId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('core.customers')
                ->where('professional_id', $professionalId)
        );
    }

    private function streamServices(string $professionalId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.services')
                ->where('professional_id', $professionalId)
        );
    }

    private function streamServiceCategories(string $professionalId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.service_categories')
                ->where('professional_id', $professionalId)
        );
    }

    private function streamEnquiries(string $professionalId): Generator
    {
        // Mirror the redaction in ExportCustomerDataJob — drop ip_hash + user_agent
        // (technical fingerprint, not part of the user-visible enquiry).
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('site.enquiries')
                ->select(['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'])
                ->where('professional_id', $professionalId)
        );
    }

    private function streamEmailSubscriptions(string $professionalId): Generator
    {
        // Explicit allow-list — `unsubscribe_token`, `consent_ip_hash`, and
        // `consent_user_agent` are in EmailSubscription::$hidden because they
        // are either credentials (token unsubscribes anyone) or technical
        // fingerprints. DB::table() bypasses $hidden, so we re-state the
        // allow-list here. Mirrors the column set returned by
        // /api/email-subscribers.
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('notifications.email_subscriptions')
                ->select(['id', 'professional_id', 'list_key', 'email', 'full_name', 'status', 'subscribed_at', 'unsubscribed_at', 'consent_source', 'created_at'])
                ->where('professional_id', $professionalId)
        );
    }

    /**
     * Per-category email opt-in/out preferences. Required for GDPR Article 15
     * (right of access) — users must be able to see every preference we store
     * about them, not just the marketing subscription list.
     */
    private function streamNotificationPreferences(string $professionalId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('notifications.notification_email_preferences')
                ->where('professional_id', $professionalId)
        );
    }

    private function streamNotificationPolicies(string $professionalId): Generator
    {
        // Per-professional policy overrides only — global policies apply to
        // every user and are not personal data.
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('notifications.notification_email_policies')
                ->where('professional_id', $professionalId)
        );
    }

    private function streamLeadSubmissions(string $professionalId): Generator
    {
        // Mirror the redaction in enquiries() — drop ip_hash + user_agent
        // (technical fingerprint, not user-visible lead data).
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('analytics.lead_submissions')
                ->select(['id', 'occurred_at', 'outcome', 'form_started_at_ms', 'customer_id', 'subdomain', 'site_id', 'referrer'])
                ->where('professional_id', $professionalId)
        );
    }

    private function streamAudit(string $professionalId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('core.data_export_audit')
                ->where('professional_id', $professionalId)
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
     * small-but-bounded sections (brand_partner_links, site.blocks) that
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
