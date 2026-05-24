<?php

namespace App\Jobs\Gdpr;

use App\Mail\Gdpr\ProfessionalDataExportMail;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Services\Professional\DataExport\DataExportPayloadBuilder;
use App\Services\Professional\DataExport\DataExportZipWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

// V2: Builds a professional-wide data export zip, uploads to R2, generates a
// signed URL, emails the recipient, and updates the audit row. Designed to
// run on the redis_gdpr queue (660s supervisor timeout).
class ExportProfessionalDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600; // under the 660s supervisor cap

    public function __construct(public string $auditId)
    {
        $this->onQueue(config('partna.gdpr.queue'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $builder = app(DataExportPayloadBuilder::class);
        $writer = app(DataExportZipWriter::class);
        $audit = DataExportAudit::find($this->auditId);

        if (! $audit) {
            Log::warning('ExportProfessionalDataJob: audit row not found', ['audit_id' => $this->auditId]);

            return;
        }

        if (in_array($audit->status, [DataExportAudit::STATUS_COMPLETED, DataExportAudit::STATUS_FAILED], true)) {
            return;
        }

        // Professional may have been hard-deleted between dispatch and run —
        // the FK is ON DELETE SET NULL so professional_id will be null.
        if (! $audit->professional_id) {
            $audit->markFailed('professional deleted before export ran');

            return;
        }

        $audit->markProcessing();

        $tmpPath = null;

        try {
            // writeStreaming() drives the builder row-by-row so a tenant with
            // tens of thousands of customers/orders never materialises the
            // full payload in PHP memory. GDPR right-of-access must not OOM.
            $written = $writer->writeStreaming($builder, $audit->professional_id);
            $tmpPath = $written['path'];

            $disk = Storage::disk(config('partna.media_disk'));
            $remotePath = "exports/{$audit->professional_id}/{$audit->id}.zip";

            $stream = fopen($written['path'], 'rb');
            $disk->put($remotePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $ttlDays = (int) config('partna.gdpr.signed_url_ttl_days', 7);
            $signedUrl = $disk->temporaryUrl($remotePath, now()->addDays($ttlDays));

            // Lock the row to prevent concurrent workers both seeing email_sent_at = null.
            // At-least-once: a crash between send and stamp causes a retry to re-send —
            // preferable to silent loss for GDPR right-of-access requests.
            $shouldSendEmail = DB::transaction(function () use ($audit): bool {
                $fresh = DataExportAudit::query()->lockForUpdate()->find($audit->id);

                return $fresh !== null && $fresh->email_sent_at === null;
            });

            if ($shouldSendEmail) {
                Mail::to($audit->recipient_email)->send(new ProfessionalDataExportMail(
                    signedUrl: $signedUrl,
                    professionalHandle: $audit->professional_handle_snapshot,
                    sendTo: $audit->send_to ?? 'professional',
                    recordCounts: $written['record_counts'],
                ));

                $audit->markEmailSent();
            }

            $audit->markCompleted(
                filePath: $remotePath,
                fileSizeBytes: $written['size'],
                fileSha256: $written['sha256'],
                recordCounts: $written['record_counts'],
            );

            Log::info('ExportProfessionalDataJob completed', [
                'audit_id' => $audit->id,
                'professional_id' => $audit->professional_id,
                'size' => $written['size'],
            ]);
        } catch (Throwable $e) {
            $audit->markFailed($e->getMessage());
            Log::error('ExportProfessionalDataJob failed', [
                'audit_id' => $audit->id,
                'error' => $e->getMessage(),
            ]);
            throw $e; // let queue retry per $tries/$backoff
        } finally {
            if ($tmpPath && file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * Called by Laravel after $tries is exhausted. Without this, a stuck job
     * leaves the audit row in 'processing' indefinitely.
     */
    public function failed(Throwable $e): void
    {
        $audit = DataExportAudit::find($this->auditId);
        if ($audit && $audit->status !== DataExportAudit::STATUS_COMPLETED) {
            $audit->markFailed('Job failed after retries: '.$e->getMessage());
        }
    }
}
