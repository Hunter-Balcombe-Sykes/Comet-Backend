<?php

namespace App\Console\Commands\Moderation;

use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\ModerationAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * GDPR erasure: clear reporter_email and reporter_ip_hash from case_signals
 * for a specific case. Records an audit entry for compliance traceability.
 *
 * Use case: reporter requests erasure of their personal data under GDPR/Australian
 * Privacy Act. This command erases the PII without deleting the moderation record.
 */
class ModerationRedactReporterPiiCommand extends Command
{
    protected $signature = 'moderation:redact-reporter-pii {case_id} {--reason=}';
    protected $description = 'Clear reporter_email + reporter_ip_hash from case_signals for a case (GDPR erasure).';

    public function handle(ModerationAuditService $audit): int
    {
        $caseId = $this->argument('case_id');
        $reason = $this->option('reason') ?: 'unspecified';

        $case = ModerationCase::query()->find($caseId);
        if ($case === null) {
            $this->error("Case {$caseId} not found.");
            return self::FAILURE;
        }

        DB::transaction(function () use ($case, $audit, $reason) {
            CaseSignal::query()
                ->where('case_id', $case->id)
                ->update([
                    'reporter_email'   => null,
                    'reporter_ip_hash' => null,
                ]);

            $audit->recordSystemAction(
                'reporter.pii_redacted',
                'ModerationCase',
                $case->id,
                ['reason' => $reason],
            );
        });

        $this->info("Reporter PII redacted for case {$case->id}.");
        return self::SUCCESS;
    }
}
