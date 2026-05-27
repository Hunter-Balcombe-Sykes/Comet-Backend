<?php

namespace App\Services\Moderation;

use App\DTOs\Moderation\PublicReportDto;
use App\Jobs\Moderation\NotifyStaffOfCaseUpdateJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates the human-report submit flow:
 *   resolve target → dedup → open-or-merge case → snapshot evidence → notify staff
 *
 * Single write path for case_signals from human reports.
 */
class ContentReportService
{
    public function __construct(
        private readonly DedupHashCalculator $dedup,
        private readonly EvidenceSnapshotService $evidence,
    ) {}

    public function submit(PublicReportDto $dto): ContentReportSubmitResult
    {
        // 1. Resolve handle → Site + owner User.
        $user = User::query()->where('handle', $dto->targetHandle)->first();

        if ($user === null) {
            throw new ReportTargetNotFound($dto->targetType, $dto->targetHandle);
        }

        $site = Site::query()->where('user_id', $user->id)->first();

        if ($site === null) {
            throw new ReportTargetNotFound($dto->targetType, $dto->targetHandle);
        }

        $reporterIpHash = hash('sha256', $dto->reporterIp . '|' . config('app.key'));
        $dedupHash = $this->dedup->forReport(
            reportableType: $dto->targetType,
            reportableId:   $site->id,
            reasonCode:     $dto->reasonCode,
            reporterEmail:  $dto->reporterEmail,
            reporterIpHash: $reporterIpHash,
        );

        // 2. Open-or-merge inside a transaction.
        $signal = DB::transaction(function () use ($dto, $site, $user, $reporterIpHash, $dedupHash) {

            $case = $this->openOrMergeCase($dto->targetType, $site->id, $user->id);

            // forceCreate() is required because these models guard 'id' via $guarded.
            $signal = CaseSignal::forceCreate([
                'id'               => (string) Str::uuid(),
                'case_id'          => $case->id,
                'signal_source'    => 'content_report',
                'signal_data'      => ['details' => $dto->details],
                'reporter_email'   => $dto->reporterEmail,
                'reporter_ip_hash' => $reporterIpHash,
                'reason_code'      => $dto->reasonCode,
                'reason_details'   => $dto->details,
                'dedup_hash'       => $dedupHash,
            ]);

            $case->signal_count = $case->signal_count + 1;
            $case->save();

            $this->evidence->capture($case->id, $dto->targetType, $site->id, $signal->id);

            return $signal;
        });

        // 3. Notify staff on threshold-crossing (handled by job).
        // ShouldQueueAfterCommit ensures the job fires only after the DB transaction commits.
        NotifyStaffOfCaseUpdateJob::dispatch($signal->case_id);

        return new ContentReportSubmitResult(
            receiptId: $signal->id,
        );
    }

    private function openOrMergeCase(string $type, string $id, string $ownerId): ModerationCase
    {
        $existing = ModerationCase::query()
            ->where('reportable_type', $type)
            ->where('reportable_id', $id)
            ->whereIn('status', ['open', 'triaged', 'under_review'])
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ModerationCase::forceCreate([
            'id'                       => (string) Str::uuid(),
            'case_type'                => 'content_report',
            'reportable_type'          => $type,
            'reportable_id'            => $id,
            'reportable_owner_user_id' => $ownerId,
            'severity'                 => 2,
            'status'                   => 'open',
            'signal_count'             => 0,
            'priority'                 => 5,
        ]);
    }
}
