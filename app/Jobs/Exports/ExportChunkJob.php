<?php

namespace App\Jobs\Exports;

use App\Models\Commerce\CommissionExportAudit;
use App\Models\Commerce\CommissionPayout;
use App\Services\Exports\JsonlPartWriter;
use App\Services\Stripe\StripeRowGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-chunk worker for the async commission transactions export.
 *
 * Fetches up to `chunk_size` payouts after the audit's cursor, yields rows
 * through StripeRowGenerator, writes a JSONL part file to R2, and either
 * dispatches the next chunk job or hands off to the finalizer.
 *
 * Idempotent: re-running the same chunk overwrites its part file. Cursor is
 * advanced only after a successful part upload, so a crash before upload
 * replays the same payouts on retry.
 */
class ExportChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public string $auditId, public int $chunkIndex)
    {
        $this->onConnection(config('partna.exports.commission.connection'));
        $this->onQueue(config('partna.exports.commission.queue'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(StripeRowGenerator $generator, JsonlPartWriter $partWriter): void
    {
        $audit = CommissionExportAudit::find($this->auditId);
        if (! $audit || $audit->isTerminal()) {
            return;
        }

        // First chunk transitions queued → processing. Idempotent — markProcessing keeps existing processing_at.
        if ($audit->status === CommissionExportAudit::STATUS_QUEUED) {
            $audit->markProcessing();
        }

        Log::info('commission_export.chunk_started', [
            'audit_id' => $audit->id,
            'chunk_index' => $this->chunkIndex,
        ]);
        $start = microtime(true);

        try {
            $payouts = $this->fetchChunkPayouts($audit);

            if ($payouts->isEmpty()) {
                // Reconciliation: counter said there were more chunks but we found none.
                // Could happen if payouts were deleted mid-export. Skip straight to finalizer.
                Log::warning('commission_export.chunk_empty', [
                    'audit_id' => $audit->id, 'chunk_index' => $this->chunkIndex,
                ]);
                ExportFinalizerJob::dispatch($audit->id);
                return;
            }

            $remotePath = sprintf(
                'exports/commissions/%s/%s/parts/chunk-%d.jsonl',
                $audit->professional_id,
                $audit->id,
                $this->chunkIndex,
            );

            $partWriter->writePart(
                disk: config('partna.media_disk'),
                remotePath: $remotePath,
                rows: $generator->forPayouts($payouts, $audit->role),
            );

            $audit->markChunkCompleted(
                payoutsInChunk: $payouts->count(),
                lastPayoutId: $payouts->last()->id,
                nextIndex: $this->chunkIndex + 1,
            );

            Log::info('commission_export.chunk_completed', [
                'audit_id' => $audit->id,
                'chunk_index' => $this->chunkIndex,
                'payouts_in_chunk' => $payouts->count(),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);

            $fresh = $audit->fresh();
            if ($fresh->chunks_completed >= $fresh->chunks_total) {
                ExportFinalizerJob::dispatch($audit->id);
            } else {
                ExportChunkJob::dispatch($audit->id, $this->chunkIndex + 1);
            }
        } catch (Throwable $e) {
            Log::error('commission_export.failed', [
                'audit_id' => $audit->id,
                'stage' => 'chunk',
                'chunk_index' => $this->chunkIndex,
                'error' => $e->getMessage(),
            ]);
            throw $e; // let queue retry per $tries/$backoff
        }
    }

    /**
     * Stable cursor pagination by (created_at DESC, id DESC).
     * Chunk 0: no cursor → most recent N payouts.
     * Chunk N: payouts strictly after the last processed one.
     */
    private function fetchChunkPayouts(CommissionExportAudit $audit): \Illuminate\Support\Collection
    {
        $query = CommissionPayout::query()->orderByDesc('created_at')->orderByDesc('id');

        if ($audit->role === 'brand') {
            $query->where('brand_professional_id', $audit->professional_id);
        } else {
            $query->where('affiliate_professional_id', $audit->professional_id);
        }

        $filters = $audit->filters ?? [];
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'].' 23:59:59');
        }

        if ($audit->last_processed_payout_id) {
            $cursor = CommissionPayout::find($audit->last_processed_payout_id);
            if ($cursor) {
                $query->where(function ($q) use ($cursor) {
                    $q->where('created_at', '<', $cursor->created_at)
                      ->orWhere(function ($qq) use ($cursor) {
                          $qq->where('created_at', $cursor->created_at)
                             ->where('id', '<', $cursor->id);
                      });
                });
            }
        }

        return $query->limit($audit->chunk_size)->get();
    }

    public function failed(Throwable $e): void
    {
        $audit = CommissionExportAudit::find($this->auditId);
        if ($audit && ! $audit->isTerminal()) {
            $audit->markFailed('chunk '.$this->chunkIndex.' failed: '.$e->getMessage());
        }
    }
}
