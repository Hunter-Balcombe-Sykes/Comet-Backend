<?php

use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Decision;
use App\Models\Moderation\Evidence;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    setupAllModerationTables();
    setupUsersTable();
    setupPartnaStaffTable();
});

it('prints a case in JSON to stdout', function () {
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create();
    Evidence::factory()->forCase($case)->create();
    Decision::factory()->forCase($case)->systemAutoActioned()->create();

    // PendingCommand::expectsOutputToContain uses an internal buffer that diverges
    // from $this->line() output in some environments. Use Artisan::call() + Artisan::output()
    // for reliable multi-line JSON assertions.
    $exitCode = Artisan::call('moderation:show-case', ['case_id' => $case->id]);
    $output   = Artisan::output();
    $decoded  = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($decoded)->toBeArray()
        ->toHaveKeys(['case_id', 'case', 'signals', 'evidence', 'decisions']);
    expect($decoded['case_id'])->toBe($case->id);
    expect($decoded['signals'])->toBeArray()->toHaveCount(1);
    expect($decoded['evidence'])->toBeArray()->toHaveCount(1);
    expect($decoded['decisions'])->toBeArray()->toHaveCount(1);
});

it('fails when case does not exist', function () {
    $this->artisan('moderation:show-case 00000000-0000-0000-0000-000000000000')
        ->assertFailed();
});
