<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupModerationCasesTable();
    setupModerationCaseSignalsTable();
    setupModerationEvidenceTable();
    setupPartnaStaffTable();
    config(['partna.bot_protection.mode' => 'off']);
});

function validReportPayload(string $handle = 'joeplumber'): array
{
    return [
        'target_type' => 'Site',
        'target_handle' => $handle,
        'reason_code' => 'spam',
        'details' => 'this looks like spam to me',
        'reporter_email' => 'reporter@example.com',
        'turnstile_token' => 'cf-token-fixture',
    ];
}

it('accepts a valid report and returns 202 with a receipt_id', function () {
    $user = User::factory()->create(['handle' => 'joeplumber', 'handle_lc' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $res = $this->postJson('/api/v1/public/report', validReportPayload());

    $res->assertStatus(202);
    $res->assertJsonStructure(['receipt_id', 'message']);
    expect(ModerationCase::count())->toBe(1);
    expect(CaseSignal::count())->toBe(1);
});

it('returns 422 INVALID_TARGET when handle does not resolve', function () {
    $res = $this->postJson('/api/v1/public/report', validReportPayload('does-not-exist'));
    $res->assertStatus(422);
    $res->assertJsonPath('error', 'INVALID_TARGET');
});
