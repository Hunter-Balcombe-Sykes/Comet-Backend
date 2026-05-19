<?php

namespace App\Jobs\Exports;

use App\Mail\Exports\CommissionExportReadyMail;
use App\Models\Commerce\CommissionExportAudit;
use App\Services\Exports\CommissionExportFinalWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads all JSONL part files from R2 for an audit, concatenates them into the
 * final CSV/XLSX, uploads the consolidated file, signs a 3-day URL, emails the
 * recipient, and marks the audit completed.
 *
 * The empty-export branch (no payouts in range) lands here too — we skip the
 * part-reading step but still email a "0 transactions" confirmation so the API
 * contract stays uniform.
 */
class ExportFinalizerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public string $auditId)
    {
        $this->onConnection(config('partna.exports.commission.connection'));
        $this->onQueue(config('partna.exports.commission.queue'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(CommissionExportFinalWriter $finalWriter): void
    {
        $audit = CommissionExportAudit::find($this->auditId);
        if (! $audit || $audit->status === CommissionExportAudit::STATUS_COMPLETED) {
            return;
        }

        // Don't override failed or cancelled audits.
        if ($audit->status === CommissionExportAudit::STATUS_FAILED
            || $audit->status === CommissionExportAudit::STATUS_CANCELLED) {
            return;
        }

        Log::info('commission_export.finalizer_started', [
            'audit_id' => $audit->id, 'chunks_total' => $audit->chunks_total,
        ]);
        $start = microtime(true);

        $disk = config('partna.media_disk');
        $remoteFinalPath = sprintf(
            'exports/commissions/%s/%s/data.%s',
            $audit->professional_id,
            $audit->id,
            $audit->format,
        );

        $tmpFinal = tempnam(sys_get_temp_dir(), 'cep_final_') . '.' . $audit->format;

        try {
            $partPaths = $this->listParts($audit, $disk);
            $meta = $finalWriter->writeFinal($audit->format, $tmpFinal, $partPaths, $disk);

            // Stream-upload final to R2
            $stream = fopen($tmpFinal, 'rb');
            Storage::disk($disk)->put($remoteFinalPath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $ttlDays = (int) config('partna.exports.commission.signed_url_ttl_days');
            $signedUrl = Storage::disk($disk)->temporaryUrl($remoteFinalPath, now()->addDays($ttlDays));

            Mail::to($audit->recipient_email)->send(new CommissionExportReadyMail(
                signedUrl: $signedUrl,
                role: $audit->role,
                format: $audit->format,
                filters: $audit->filters ?? [],
                recordCount: $meta['row_count'],
                ttlDays: $ttlDays,
                expiresAt: now()->addDays($ttlDays),
            ));

            $audit->markCompleted(
                filePath: $remoteFinalPath,
                size: $meta['size'],
                sha256: $meta['sha256'],
            );

            // Cleanup parts after successful mail send + audit update.
            foreach ($partPaths as $p) {
                Storage::disk($disk)->delete($p);
            }

            Log::info('commission_export.finalizer_completed', [
                'audit_id' => $audit->id,
                'file_size_bytes' => $meta['size'],
                'total_rows' => $meta['row_count'],
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);
        } catch (Throwable $e) {
            Log::error('commission_export.failed', [
                'audit_id' => $audit->id, 'stage' => 'finalizer', 'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if (file_exists($tmpFinal)) {
                @unlink($tmpFinal);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function listParts(CommissionExportAudit $audit, string $disk): array
    {
        $prefix = sprintf('exports/commissions/%s/%s/parts', $audit->professional_id, $audit->id);
        $paths = Storage::disk($disk)->files($prefix);
        sort($paths, SORT_NATURAL); // chunk-0, chunk-1, ..., chunk-10 (natural sort handles N>9)
        return $paths;
    }

    public function failed(Throwable $e): void
    {
        $audit = CommissionExportAudit::find($this->auditId);
        if ($audit && ! $audit->isTerminal()) {
            $audit->markFailed('finalizer failed: '.$e->getMessage());
        }
    }
}
