<?php

use App\DTOs\Moderation\PublicReportDto;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Evidence;
use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\ContentReportService;
use App\Services\Moderation\ReportTargetNotFound;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupModerationCasesTable();
    setupModerationCaseSignalsTable();
    setupModerationEvidenceTable();
});

it('creates a new case + signal + evidence on first report for a target', function () {
    Queue::fake();

    $user = User::factory()->create(['handle' => 'joeplumber', 'handle_lc' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $dto = new PublicReportDto(
        targetType:    'Site',
        targetHandle:  'joeplumber',
        reasonCode:    'spam',
        details:       'looks like spam',
        reporterEmail: 'reporter@example.com',
        reporterIp:    '203.0.113.42',
    );

    $result = app(ContentReportService::class)->submit($dto);

    expect(ModerationCase::count())->toBe(1);
    expect(CaseSignal::count())->toBe(1);
    expect(Evidence::count())->toBe(1);

    $case = ModerationCase::first();
    expect($case->case_type)->toBe('content_report');
    expect($case->status)->toBe('open');
    expect($case->signal_count)->toBe(1);
    expect($case->reportable_owner_user_id)->toBe($user->id);

    expect($result->receiptId)->toBe(CaseSignal::first()->id);
});

it('rejects when target handle does not resolve', function () {
    $dto = new PublicReportDto('Site', 'no-such-handle', 'spam', null, 'r@e.com', '127.0.0.1');
    expect(fn () => app(ContentReportService::class)->submit($dto))
        ->toThrow(ReportTargetNotFound::class);
});
