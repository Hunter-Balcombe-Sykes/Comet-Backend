<?php

namespace App\Services\Stripe;

use App\Exceptions\CommissionExportInProgressException;
use App\Exceptions\NoRecipientEmailException;
use App\Jobs\Exports\ExportChunkJob;
use App\Jobs\Exports\ExportFinalizerJob;
use App\Models\Commerce\CommissionExportAudit;
use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Dispatcher for the async commission transactions export.
 *
 * Responsibilities:
 *  - resolve recipient email (fail closed if none)
 *  - dedup against in-flight exports for the same pro+role+format
 *  - count payouts up-front so the audit row carries an accurate total
 *  - insert the audit row, then kick off the first chunk (or finalizer for empty)
 *
 * Heavy work is the job's problem; this stays sync and fast.
 */
class CommissionExportService
{
    public function dispatch(
        Professional $professional,
        string $role,
        string $format,
        array $filters,
    ): CommissionExportAudit {
        // public_contact_email is the preferred delivery address; fall back to primary_email
        $email = $professional->public_contact_email ?: $professional->primary_email;
        if (! $email) {
            throw new NoRecipientEmailException($professional->id);
        }

        $chunkSize = (int) config('partna.exports.commission.chunk_size');
        $dedupWindow = (int) config('partna.exports.commission.dedup_window_minutes');
        $retentionDays = (int) config('partna.exports.commission.retention_days');

        $audit = DB::transaction(function () use (
            $professional, $role, $format, $filters, $email, $chunkSize, $dedupWindow, $retentionDays,
        ) {
            // Pessimistic dedup: lock recent rows for this pro to serialise concurrent dispatch calls.
            // lockForUpdate() is a no-op on SQLite (tests) but is correct for PostgreSQL (prod).
            $existing = CommissionExportAudit::query()
                ->where('professional_id', $professional->id)
                ->where('role', $role)
                ->where('format', $format)
                ->whereIn('status', [
                    CommissionExportAudit::STATUS_QUEUED,
                    CommissionExportAudit::STATUS_PROCESSING,
                ])
                ->where('created_at', '>=', now()->subMinutes($dedupWindow))
                ->lockForUpdate()
                ->orderByDesc('created_at')
                ->first();

            if ($existing) {
                throw new CommissionExportInProgressException($existing);
            }

            $payoutsTotal = $this->countPayouts($professional->id, $role, $filters);
            $chunksTotal = (int) ceil($payoutsTotal / $chunkSize);

            return CommissionExportAudit::create([
                'id' => (string) Str::uuid(),
                'professional_id' => $professional->id,
                'role' => $role,
                'format' => $format,
                'filters' => $filters,
                'status' => CommissionExportAudit::STATUS_QUEUED,
                'recipient_email' => $email,
                'payouts_total' => $payoutsTotal,
                'chunk_size' => $chunkSize,
                'chunks_total' => $chunksTotal,
                'next_chunk_index' => 0,
                'expires_at' => now()->addDays($retentionDays),
                // created_at must be explicit: SQLite test mirror has no DEFAULT now()
                'created_at' => now(),
            ]);
        });

        Log::info('commission_export.dispatched', [
            'audit_id' => $audit->id,
            'professional_id' => $professional->id,
            'role' => $role,
            'format' => $format,
            'payouts_total' => $audit->payouts_total,
            'chunks_total' => $audit->chunks_total,
        ]);

        if ($audit->payouts_total === 0) {
            // Skip the chunk pipeline entirely — finalizer handles the empty-result branch.
            ExportFinalizerJob::dispatch($audit->id);
        } else {
            ExportChunkJob::dispatch($audit->id, 0);
        }

        return $audit;
    }

    private function countPayouts(string $professionalId, string $role, array $filters): int
    {
        $query = CommissionPayout::query();

        if ($role === 'brand') {
            $query->where('brand_professional_id', $professionalId);
        } else {
            $query->where('affiliate_professional_id', $professionalId);
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'].' 23:59:59');
        }

        return $query->count();
    }
}
