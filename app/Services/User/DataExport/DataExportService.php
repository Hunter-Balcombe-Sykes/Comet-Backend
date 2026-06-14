<?php

namespace App\Services\User\DataExport;

use App\Exceptions\Gdpr\DataExportInProgressException;
use App\Exceptions\Gdpr\NoRecipientEmailException;
use App\Jobs\Gdpr\ExportUserDataJob;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

// V2: Single dispatch entry point for professional data exports. Inserts the
// audit row, runs the dedup check, and queues the job. Both controllers
// (self-service + staff) call this with different parameters — the only
// branching is recipient resolution.
class DataExportService
{
    /**
     * @param  'self'|'staff'  $triggeredBy
     * @param  'professional'|'staff'  $sendTo
     */
    public function dispatch(
        User $professional,
        string $triggeredBy,
        ?string $staffId,
        string $sendTo,
    ): DataExportAudit {
        $recipient = $this->resolveRecipient($professional, $staffId, $sendTo);

        if (! $recipient) {
            throw new NoRecipientEmailException;
        }

        return DB::connection('pgsql')->transaction(function () use ($professional, $triggeredBy, $staffId, $sendTo, $recipient) {
            // Lock the professional row for the duration of the dedup check.
            // Two concurrent requests serialize through this — only one wins.
            DB::connection('pgsql')
                ->table('core.users')
                ->where('id', $professional->id)
                ->lockForUpdate()
                ->first();

            $existing = $this->findRecentInFlight($professional->id);
            if ($existing) {
                throw new DataExportInProgressException($existing->id);
            }

            $audit = DataExportAudit::create([
                'user_id' => $professional->id,
                'professional_handle_snapshot' => $professional->handle,
                'professional_email_snapshot' => $professional->primary_email,
                'triggered_by' => $triggeredBy,
                'triggered_by_staff_id' => $staffId,
                'recipient_email' => $recipient,
                'send_to' => $sendTo,
            ]);

            // afterCommit(): this dispatch is inside the pgsql transaction, so without
            // it a fast worker can pick the job up before the audit row commits — the job
            // would then find no row and the GDPR export would be silently lost (LIFE-3).
            // (Set on the dispatch, not as a $afterCommit property — the Queueable trait
            // already declares that property, and redeclaring it conflicts.)
            ExportUserDataJob::dispatch($audit->id)->afterCommit();

            return $audit;
        });
    }

    /**
     * Find any audit row for this professional in 'queued' or 'processing'
     * status created within the dedup window. Used both by the dedup check
     * and by callers that want to surface the existing export id.
     */
    public function findRecentInFlight(string $userId): ?DataExportAudit
    {
        $windowMinutes = (int) config('partna.gdpr.dedup_window_minutes', 30);

        return DataExportAudit::query()
            ->where('user_id', $userId)
            ->whereIn('status', [DataExportAudit::STATUS_QUEUED, DataExportAudit::STATUS_PROCESSING])
            ->where('created_at', '>', now()->subMinutes($windowMinutes))
            ->first();
    }

    private function resolveRecipient(User $professional, ?string $staffId, string $sendTo): ?string
    {
        if ($sendTo === 'staff' && $staffId) {
            $staff = PartnaStaff::find($staffId);

            return $staff?->primary_email;
        }

        return $professional->public_contact_email
            ?: $professional->primary_email;
    }
}
