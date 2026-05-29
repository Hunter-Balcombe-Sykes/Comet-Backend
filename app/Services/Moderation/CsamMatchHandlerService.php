<?php

namespace App\Services\Moderation;

use App\DTOs\Moderation\CloudflareCsamMatchDto;
use App\DTOs\Moderation\DecisionDto;
use App\Jobs\Moderation\FileCyberTipReportJob;
use App\Models\Moderation\CsamQuarantine;
use App\Models\Moderation\Evidence;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\NcmecSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestrates the CSAM auto-action pipeline in a single DB transaction:
 *   webhook arrives → resolve SiteMedia → case + signal + evidence
 *   → csam_quarantine row → ncmec_submissions outbox row
 *   → ModerationDecisionService::decideAsSystem(suspend_user)
 *
 * FileCyberTipReportJob is dispatched afterCommit (Task 11 wires it).
 *
 * forceCreate() is used throughout because all moderation models guard 'id'
 * via $guarded = ['id']. UUIDs are generated explicitly here so the
 * case_id FK is available immediately for child rows.
 */
class CsamMatchHandlerService
{
    // CSAM is always maximum severity (5) and top priority (1) —
    // PhotoDNA hash matching has near-zero false-positive rate.
    private const CSAM_SEVERITY = 5;
    private const CSAM_PRIORITY = 1;

    public function __construct(
        private readonly DedupHashCalculator $dedup,
        private readonly ModerationDecisionService $decisions,
    ) {}

    public function handle(CloudflareCsamMatchDto $dto): array
    {
        Log::critical('csam_handler.match_received', [
            'match_id' => $dto->matchId,
            'r2_key'   => $dto->r2Key,
            'confidence' => $dto->confidence,
        ]);

        return DB::transaction(function () use ($dto) {
            // Resolve the SiteMedia row by quarantine path.
            $siteMedia = DB::selectOne(
                'SELECT id, site_id FROM site.site_media WHERE path = ?',
                [$dto->r2Key]
            );
            if ($siteMedia === null) {
                Log::warning('csam_handler.media_not_found', ['r2_key' => $dto->r2Key, 'match_id' => $dto->matchId]);
                throw new RuntimeException("MEDIA_NOT_FOUND:{$dto->r2Key}");
            }

            // Resolve the site owner (user_id column, not professional_id).
            $siteOwner = DB::selectOne(
                'SELECT user_id FROM site.sites WHERE id = ?',
                [$siteMedia->site_id]
            );

            if ($siteOwner === null) {
                // Orphaned media (no owning site row). Proceed with null owner but
                // log critical so on-call can investigate the data integrity issue.
                Log::critical('csam_handler.no_owner_resolved', [
                    'match_id'     => $dto->matchId,
                    'site_media_id'=> $siteMedia->id,
                    'site_id'      => $siteMedia->site_id,
                ]);
            }

            $case = ModerationCase::forceCreate([
                'id'                       => Str::uuid()->toString(),
                'case_type'                => 'csam_match',
                'reportable_type'          => 'SiteMedia',
                'reportable_id'            => $siteMedia->id,
                'reportable_owner_user_id' => $siteOwner?->user_id,
                'severity'                 => self::CSAM_SEVERITY,
                'status'                   => 'open',
                'signal_count'             => 1,
                'auto_actioned'            => false,
                'priority'                 => self::CSAM_PRIORITY,
            ]);

            CaseSignal::forceCreate([
                'id'            => Str::uuid()->toString(),
                'case_id'       => $case->id,
                'signal_source' => 'csam_scan',
                'signal_data'   => $dto->rawPayload,
                'reason_code'   => 'auto_csam_hash_match',
                'dedup_hash'    => $this->dedup->forCsamMatch($dto->matchId, $siteMedia->id),
            ]);

            Evidence::forceCreate([
                'id'            => Str::uuid()->toString(),
                'case_id'       => $case->id,
                'evidence_type' => 'csam_hash_match',
                'payload'       => $dto->rawPayload,
                'content_hash'  => $dto->matchedHash,
            ]);

            $quarantine = CsamQuarantine::forceCreate([
                'id'                       => Str::uuid()->toString(),
                'case_id'                  => $case->id,
                'site_media_id'            => $siteMedia->id,
                'uploader_user_id'         => $siteOwner?->user_id,
                'content_hash'             => $dto->matchedHash,
                'cloudflare_match_payload' => $dto->rawPayload,
                'r2_quarantine_key'        => $dto->r2Key,
                // 90-day legal preservation minimum per NCMEC guidance.
                // Configurable via partna.moderation.csam.preservation_days.
                'preservation_expires_at'  => now()->addDays(
                    config('partna.moderation.csam.preservation_days', 90)
                ),
            ]);

            $ncmecSub = NcmecSubmission::forceCreate([
                'id'                 => Str::uuid()->toString(),
                'csam_quarantine_id' => $quarantine->id,
                'payload'            => [
                    'matchId'         => $dto->matchId,
                    'matchedHash'     => $dto->matchedHash,
                    'r2QuarantineKey' => $dto->r2Key,
                    'uploaderUserId'  => $siteOwner?->user_id,
                ],
                'status' => 'pending',
            ]);

            $decision = $this->decisions->decideAsSystem($case, new DecisionDto(
                decisionType:          'suspend_user',
                reason:                'auto_csam_match',
                secondStaffApprovalId: null,
            ));

            // Dispatch FileCyberTipReportJob after the transaction commits so the
            // ncmec_submission row is visible to the worker. Use the captured $ncmecSub
            // directly rather than re-querying — the row was just created above.
            DB::afterCommit(function () use ($ncmecSub) {
                FileCyberTipReportJob::dispatch($ncmecSub->id);
            });

            return [
                'case_id'       => $case->id,
                'decision_id'   => $decision->id,
                'quarantine_id' => $quarantine->id,
            ];
        });
    }
}
