<?php

// Moved to the applied-schema lane (tranche 3 of COV-LANE). The original file
// (tests/Feature/Moderation/ContentReportServiceTest.php) seeded its target
// user via User::factory()->create() against tests/Pest.php's SQLite stand-in
// schema, which has no FK from core.users.auth_user_id onto auth.users — five
// of its six cases passed under SQLite for that reason and failed against a
// real migrated Postgres with `users_auth_user_id_fkey`. The sixth ("rejects
// when target handle does not resolve") seeds nothing and always passed either
// way. SeedsAuthUsers::seedAuthUser() unblocks the other five without touching
// ContentReportService itself; nothing here documents a real app defect, so
// the whole file moves — no green SQLite coverage is lost, and the one
// group('postgres')-gated case (dedup_hash UNIQUE) now runs unconditionally
// instead of skipping in CI.

use App\DTOs\Moderation\PublicReportDto;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Evidence;
use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\ContentReportService;
use App\Services\Moderation\ReportTargetNotFound;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Schema\Concerns\SeedsAuthUsers;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class, SeedsAuthUsers::class)->in(__FILE__);

/**
 * moderation.cases.reportable_owner_user_id is ON DELETE SET NULL (not
 * CASCADE) — cleanupSeededUser() alone would orphan every case this file
 * opens instead of removing it. Deleting the cases first (which real-Postgres
 * CASCADEs into moderation.case_signals / moderation.evidence) then the user
 * keeps this persistent, shared lane database clean between tests.
 */
function cleanupReportedUser(User $user): void
{
    ModerationCase::where('reportable_owner_user_id', $user->id)->delete();
    test()->cleanupSeededUser($user);
}

it('creates a new case + signal + evidence on first report for a target', function () {
    Queue::fake();

    $handle = 'joeplumber'.Str::lower(Str::random(6));
    $user = $this->seedAuthUser(['handle' => $handle]);

    try {
        Site::factory()->create(['user_id' => $user->id]);

        $dto = new PublicReportDto(
            targetType: 'Site',
            targetHandle: $handle,
            reasonCode: 'spam',
            details: 'looks like spam',
            reporterEmail: 'reporter@example.com',
            reporterIp: '203.0.113.42',
        );

        $result = app(ContentReportService::class)->submit($dto);

        expect(ModerationCase::where('reportable_owner_user_id', $user->id)->count())->toBe(1);
        expect(CaseSignal::whereHas('case', fn ($q) => $q->where('reportable_owner_user_id', $user->id))->count())->toBe(1);
        expect(Evidence::whereHas('case', fn ($q) => $q->where('reportable_owner_user_id', $user->id))->count())->toBe(1);

        $case = ModerationCase::where('reportable_owner_user_id', $user->id)->first();
        expect($case->case_type)->toBe('content_report');
        expect($case->status)->toBe('open');
        expect($case->signal_count)->toBe(1);
        expect($case->reportable_owner_user_id)->toBe($user->id);

        expect($result->receiptId)->toBe(CaseSignal::where('case_id', $case->id)->first()->id);
    } finally {
        cleanupReportedUser($user);
    }
});

it('returns a synthetic receipt without opening a case for a suspended user (F10)', function () {
    Queue::fake();

    $handle = 'suspendedguy'.Str::lower(Str::random(6));
    $user = $this->seedAuthUser(['handle' => $handle, 'status' => 'suspended']);

    try {
        Site::factory()->create(['user_id' => $user->id]);

        $dto = new PublicReportDto('Site', $handle, 'spam', null, 'r@e.com', '203.0.113.7');
        $result = app(ContentReportService::class)->submit($dto);

        // Looks like a normal submission (synthetic receipt) but nothing is recorded —
        // suspended/disabled accounts can't be reported and status can't be enumerated.
        expect($result->receiptId)->not->toBeEmpty();
        expect(ModerationCase::where('reportable_owner_user_id', $user->id)->count())->toBe(0);
    } finally {
        cleanupReportedUser($user);
    }
});

it('rejects when target handle does not resolve', function () {
    $dto = new PublicReportDto('Site', 'no-such-handle-'.Str::lower(Str::random(8)), 'spam', null, 'r@e.com', '127.0.0.1');
    expect(fn () => app(ContentReportService::class)->submit($dto))
        ->toThrow(ReportTargetNotFound::class);
});

it('merges into the existing open case rather than creating a new one', function () {
    Queue::fake();

    $handle = 'joeplumber'.Str::lower(Str::random(6));
    $user = $this->seedAuthUser(['handle' => $handle]);

    try {
        Site::factory()->create(['user_id' => $user->id]);

        $dto1 = new PublicReportDto('Site', $handle, 'spam', null, 'r1@e.com', '203.0.113.1');
        $dto2 = new PublicReportDto('Site', $handle, 'harassment', null, 'r2@e.com', '203.0.113.2');

        app(ContentReportService::class)->submit($dto1);
        app(ContentReportService::class)->submit($dto2);

        expect(ModerationCase::where('reportable_owner_user_id', $user->id)->count())->toBe(1);
        expect(CaseSignal::whereHas('case', fn ($q) => $q->where('reportable_owner_user_id', $user->id))->count())->toBe(2);
        expect(ModerationCase::where('reportable_owner_user_id', $user->id)->first()->signal_count)->toBe(2);
        expect(Evidence::whereHas('case', fn ($q) => $q->where('reportable_owner_user_id', $user->id))->count())->toBe(2);
    } finally {
        cleanupReportedUser($user);
    }
});

it('does NOT merge into a resolved case (opens a fresh one)', function () {
    Queue::fake();

    $handle = 'joeplumber'.Str::lower(Str::random(6));
    $user = $this->seedAuthUser(['handle' => $handle]);

    try {
        $site = Site::factory()->create(['user_id' => $user->id]);

        ModerationCase::factory()->resolved()->create([
            'reportable_type' => 'Site',
            'reportable_id' => $site->id,
            'reportable_owner_user_id' => $user->id,
        ]);

        $dto = new PublicReportDto('Site', $handle, 'spam', null, 'r@e.com', '203.0.113.5');
        app(ContentReportService::class)->submit($dto);

        expect(ModerationCase::where('reportable_owner_user_id', $user->id)->count())->toBe(2);
        expect(ModerationCase::where('reportable_owner_user_id', $user->id)->where('status', 'open')->count())->toBe(1);
    } finally {
        cleanupReportedUser($user);
    }
});

it('rejects duplicate signals via UNIQUE constraint on dedup_hash', function () {
    Queue::fake();

    $handle = 'joeplumber'.Str::lower(Str::random(6));
    $user = $this->seedAuthUser(['handle' => $handle]);

    try {
        Site::factory()->create(['user_id' => $user->id]);

        $dto = new PublicReportDto('Site', $handle, 'spam', null, 'reporter@e.com', '203.0.113.9');

        app(ContentReportService::class)->submit($dto);

        expect(fn () => app(ContentReportService::class)->submit($dto))
            ->toThrow(QueryException::class);
    } finally {
        cleanupReportedUser($user);
    }
});
