<?php

namespace App\Console\Commands;

use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\User\UserDeletionAuditEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// B8 / models-data PRIV-2 + PRIV-3: bounds the retention of the PII email/handle
// snapshots on audit.user_deletion_audit and audit.data_export_audit. app_backend has
// no UPDATE on the audit schema (schema-wide REVOKE, 20260527010000), so the redaction
// runs inside the SECURITY DEFINER audit.prune_user_deletion_audit() /
// audit.prune_data_export_audit() Postgres functions
// (20260722010000_audit_pii_snapshot_retention_prune.sql). Anonymise-in-place: the
// deletion/export event row survives for compliance/fraud audit; only the PII columns
// are redacted (see the migration for the exact column treatment).
class PruneAuditPiiSnapshots extends Command
{
    // Fat-finger floor: a misconfigured retention below 1 year would silently redact
    // PII snapshots far earlier than intended, destroying fraud/dispute evidence.
    private const MIN_RETENTION_YEARS = 1;

    protected $signature = 'audit:prune-pii-snapshots {--dry-run : Report the eligible counts without redacting}';

    protected $description = 'Redact PII snapshots on audit.user_deletion_audit and audit.data_export_audit past the configured retention window.';

    public function handle(): int
    {
        $years = (int) config('partna.audit.pii_retention_years');

        if ($years < self::MIN_RETENTION_YEARS) {
            $this->error(sprintf(
                'Retention window must be at least %d year(s) (got %d). Check PARTNA_AUDIT_PII_RETENTION_YEARS.',
                self::MIN_RETENTION_YEARS,
                $years
            ));

            return self::FAILURE;
        }

        $cutoff = now()->subYears($years);
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            // Mirror each function's WHERE, sentinel guard included, so the count
            // reflects only rows the real run would actually redact.
            $deletionEligible = UserDeletionAuditEntry::where('created_at', '<', $cutoff)
                ->where('professional_email_snapshot', '<>', '[redacted]')
                ->count();
            $exportEligible = DataExportAudit::where('created_at', '<', $cutoff)
                ->where('recipient_email', '<>', '[redacted]')
                ->count();

            $this->info("[DRY RUN] Would redact {$deletionEligible} user_deletion_audit + {$exportEligible} data_export_audit rows older than {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        // Redaction is confined to these SECURITY DEFINER RPCs — app_backend cannot
        // UPDATE the audit schema directly (append-only REVOKE).
        $deletionRedacted = (int) DB::connection('pgsql')
            ->selectOne('SELECT audit.prune_user_deletion_audit(?) AS redacted', [$cutoff])
            ->redacted;
        $exportRedacted = (int) DB::connection('pgsql')
            ->selectOne('SELECT audit.prune_data_export_audit(?) AS redacted', [$cutoff])
            ->redacted;

        $this->info("Redacted {$deletionRedacted} user_deletion_audit + {$exportRedacted} data_export_audit rows older than {$cutoff->toDateTimeString()}.");

        // Count only — never log row contents (emails, handles) from an audit table.
        Log::info('audit.prune_pii_snapshots', [
            'user_deletion_audit_redacted' => $deletionRedacted,
            'data_export_audit_redacted' => $exportRedacted,
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        return self::SUCCESS;
    }
}
