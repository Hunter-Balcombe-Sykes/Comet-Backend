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
        targetType: 'Site',
        targetHandle: 'joeplumber',
        reasonCode: 'spam',
        details: 'looks like spam',
        reporterEmail: 'reporter@example.com',
        reporterIp: '203.0.113.42',
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

it('returns a synthetic receipt without opening a case for a suspended user (F10)', function () {
    Queue::fake();

    $user = User::factory()->create([
        'handle' => 'suspendedguy',
        'handle_lc' => 'suspendedguy',
        'status' => 'suspended',
    ]);
    Site::factory()->for($user, 'user')->create();

    $dto = new PublicReportDto('Site', 'suspendedguy', 'spam', null, 'r@e.com', '203.0.113.7');
    $result = app(ContentReportService::class)->submit($dto);

    // Looks like a normal submission (synthetic receipt) but nothing is recorded —
    // suspended/disabled accounts can't be reported and status can't be enumerated.
    expect($result->receiptId)->not->toBeEmpty();
    expect(ModerationCase::count())->toBe(0);
    expect(CaseSignal::count())->toBe(0);
});

it('rejects when target handle does not resolve', function () {
    $dto = new PublicReportDto('Site', 'no-such-handle', 'spam', null, 'r@e.com', '127.0.0.1');
    expect(fn () => app(ContentReportService::class)->submit($dto))
        ->toThrow(ReportTargetNotFound::class);
});

it('merges into the existing open case rather than creating a new one', function () {
    Queue::fake();

    $user = User::factory()->create(['handle' => 'joeplumber', 'handle_lc' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $dto1 = new PublicReportDto('Site', 'joeplumber', 'spam', null, 'r1@e.com', '203.0.113.1');
    $dto2 = new PublicReportDto('Site', 'joeplumber', 'harassment', null, 'r2@e.com', '203.0.113.2');

    app(ContentReportService::class)->submit($dto1);
    app(ContentReportService::class)->submit($dto2);

    expect(ModerationCase::count())->toBe(1);
    expect(CaseSignal::count())->toBe(2);
    expect(ModerationCase::first()->signal_count)->toBe(2);
    expect(Evidence::count())->toBe(2);
});

it('does NOT merge into a resolved case (opens a fresh one)', function () {
    Queue::fake();

    $user = User::factory()->create(['handle' => 'joeplumber', 'handle_lc' => 'joeplumber']);
    $site = Site::factory()->for($user, 'user')->create();

    ModerationCase::factory()->resolved()->create([
        'reportable_type' => 'Site',
        'reportable_id' => $site->id,
    ]);

    $dto = new PublicReportDto('Site', 'joeplumber', 'spam', null, 'r@e.com', '203.0.113.5');
    app(ContentReportService::class)->submit($dto);

    expect(ModerationCase::count())->toBe(2);
    expect(ModerationCase::query()->where('status', 'open')->count())->toBe(1);
});

it('rejects duplicate signals via UNIQUE constraint on dedup_hash', function () {
    if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('UNIQUE index enforcement requires PostgreSQL.');
    }

    Queue::fake();

    $user = User::factory()->create(['handle' => 'joeplumber', 'handle_lc' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $dto = new PublicReportDto('Site', 'joeplumber', 'spam', null, 'reporter@e.com', '203.0.113.9');

    app(ContentReportService::class)->submit($dto);

    expect(fn () => app(ContentReportService::class)->submit($dto))
        ->toThrow(\Illuminate\Database\QueryException::class);
})->group('postgres');
