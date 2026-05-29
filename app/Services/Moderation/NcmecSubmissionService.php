<?php

namespace App\Services\Moderation;

use App\Models\Moderation\NcmecSubmission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Outbox-pattern submitter for NCMEC's CyberTipline API.
 *
 * Strategy:
 *   1. Mark submitting (increment attempts) → call API → write outcome.
 *   2. On HTTP success: status=submitted + ncmec_tip_id from response.
 *   3. On failure: increment attempts, status=failed, last_error.
 *   4. If attempts ≥ max: status=manual_fallback_required + critical alert.
 *
 * Retry orchestration: ModerationRetryNcmecSubmissionsCommand (Task 16, every 5 min)
 * plus FileCyberTipReportJob (immediate dispatch afterCommit, $tries=1).
 */
class NcmecSubmissionService
{
    public function submit(NcmecSubmission $sub): void
    {
        $endpoint    = config('partna.moderation.csam.ncmec_endpoint');
        $apiKey      = config('partna.moderation.csam.ncmec_api_key');
        $espId       = config('partna.moderation.csam.ncmec_esp_id');
        $maxAttempts = (int) config('partna.moderation.csam.ncmec_max_attempts', 5);

        // Write-then-attempt: increment attempts BEFORE the API call so a
        // process crash mid-call still records the attempt.
        $sub->update(['status' => 'submitting', 'attempts' => $sub->attempts + 1]);

        try {
            $response = Http::withHeaders([
                    'Authorization'  => "Bearer {$apiKey}",
                    'X-NCMEC-ESP-Id' => $espId,
                ])
                ->timeout(30)
                ->post($endpoint, $sub->payload);

            if (! $response->successful()) {
                throw new RuntimeException(
                    "NCMEC API responded {$response->status()}: {$response->body()}"
                );
            }

            $sub->update([
                'status'                 => 'submitted',
                'ncmec_tip_id'           => $response->json('tipId'),
                'ncmec_response_payload' => $response->json(),
                'submitted_at'           => now(),
                'response_received_at'   => now(),
            ]);

        } catch (\Throwable $e) {
            Log::warning('moderation.ncmec_submission.failed', [
                'submission_id' => $sub->id,
                'attempts'      => $sub->attempts,
                'error'         => $e->getMessage(),
            ]);

            $newStatus = $sub->attempts >= $maxAttempts
                ? 'manual_fallback_required'
                : 'failed';

            $sub->update([
                'status'     => $newStatus,
                'last_error' => substr($e->getMessage(), 0, 2000),
            ]);

            if ($newStatus === 'manual_fallback_required') {
                Log::critical('moderation.ncmec_submission.manual_fallback_required', [
                    'submission_id'      => $sub->id,
                    'csam_quarantine_id' => $sub->csam_quarantine_id,
                ]);
                throw new NcmecSubmissionFailedTooManyTimes($sub->id);
            }

            throw $e;
        }
    }
}
