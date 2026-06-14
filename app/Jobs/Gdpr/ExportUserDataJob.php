<?php

namespace App\Jobs\Gdpr;

use App\Mail\Gdpr\UserDataExportMail;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Services\User\DataExport\DataExportPayloadBuilder;
use App\Services\User\DataExport\DataExportZipWriter;
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
class ExportUserDataJob implements ShouldQueue
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
            // The dispatch is deferred to afterCommit (DataExportService), so the audit
            // row is committed before this job runs — a missing row is a real anomaly
            // (deleted/rolled back), not a timing race. report() it so a lost GDPR request
            // is visible to ops instead of the job "succeeding" silently.
            report(new \RuntimeException(
                "ExportUserDataJob: audit row not found for audit_id={$this->auditId}"
            ));
            Log::warning('ExportUserDataJob: audit row not found', ['audit_id' => $this->auditId]);

            return;
        }

        if (in_array($audit->status, [DataExportAudit::STATUS_COMPLETED, DataExportAudit::STATUS_FAILED], true)) {
            return;
        }

        // Professional may have been hard-deleted between dispatch and run —
        // the FK is ON DELETE SET NULL so user_id will be null.
        if (! $audit->user_id) {
            $audit->markFailed('professional deleted before export ran');

            return;
        }

        $audit->markProcessing();

        $tmpPath = null;
        // Declared outside try so the catch block can reference $remotePath
        // even when the exception originates in the post-upload steps.
        $remotePath = null;
        // Tracks whether the R2 put() completed so the catch block knows
        // whether an orphaned object exists to clean up. Without this flag,
        // a failure before put() would cause the catch to attempt a pointless
        // (and potentially misleading) delete of a path that never existed.
        $uploaded = false;

        try {
            // writeStreaming() drives the builder row-by-row so a tenant with
            // tens of thousands of customers/orders never materialises the
            // full payload in PHP memory. GDPR right-of-access must not OOM.
            $written = $writer->writeStreaming($builder, $audit->user_id);
            $tmpPath = $written['path'];

            $disk = Storage::disk(config('partna.media_disk'));
            $remotePath = "exports/{$audit->user_id}/{$audit->id}.zip";

            $stream = fopen($written['path'], 'rb');
            $disk->put($remotePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            // Flag set IMMEDIATELY after put() returns so the catch block can
            // clean up the object if any subsequent step (signed URL, mail,
            // markCompleted) throws. If put() itself throws, $uploaded stays
            // false and no delete is attempted — there is nothing to delete.
            $uploaded = true;

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
                // #P3-17: deliberate at-least-once window. If the worker dies between
                // this send() and markEmailSent() below, the retry re-sends one
                // duplicate export email. Accepted by design — a duplicate is
                // preferable to silently dropping a GDPR right-of-access delivery.
                Mail::to($audit->recipient_email)->send(new UserDataExportMail(
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

            Log::info('ExportUserDataJob completed', [
                'audit_id' => $audit->id,
                'user_id' => $audit->user_id,
                'size' => $written['size'],
            ]);
        } catch (Throwable $e) {
            // If the upload succeeded but a later step failed, the R2 object
            // is orphaned at $remotePath. Delete it before marking failed so
            // the next retry starts clean. Wrap in its own try/catch so a
            // delete failure cannot mask the original exception.
            if ($uploaded && $remotePath !== null) {
                try {
                    Storage::disk(config('partna.media_disk'))->delete($remotePath);
                } catch (Throwable $deleteError) {
                    Log::warning('ExportUserDataJob: failed to delete orphaned R2 object', [
                        'audit_id' => $audit->id,
                        'remote_path' => $remotePath,
                        'error' => $deleteError->getMessage(),
                    ]);
                }
            }

            $audit->markFailed($e->getMessage());
            Log::error('ExportUserDataJob failed', [
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
        report($e);
        $audit = DataExportAudit::find($this->auditId);
        if ($audit && $audit->status !== DataExportAudit::STATUS_COMPLETED) {
            $audit->markFailed('Job failed after retries: '.$e->getMessage());
        }
    }
}
