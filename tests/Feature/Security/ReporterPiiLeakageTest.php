<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Models\Moderation\AuditEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

beforeEach(function () {
    Queue::fake();
    Redis::flushdb();
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupModerationCasesTable();
    setupModerationCaseSignalsTable();
    setupModerationEvidenceTable();
    setupAuditModerationEventsTable();
    setupPartnaStaffTable();
    config(['partna.bot_protection.mode' => 'off']);
});

it('does not write raw IP into the audit log', function () {
    $user = User::factory()->create(['handle' => 'joeplumber', 'handle_lc' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->postJson('/api/v1/public/report', [
        'target_type'     => 'Site',
        'target_handle'   => 'joeplumber',
        'reason_code'     => 'spam',
        'reporter_email'  => 'leak@example.com',
        'turnstile_token' => 'cf',
    ])->assertStatus(202);

    // No audit event should contain raw IP or raw email
    foreach (AuditEvent::all() as $event) {
        $json = json_encode($event->payload);
        expect($json)->not->toContain('203.0.113.99');
        expect($json)->not->toContain('leak@example.com');
    }
});

it('does not log reporter PII via Log facade', function () {
    // Attach a Monolog TestHandler so we can inspect every record that flows through
    // the logger during the request. This avoids fighting Mockery's spy API.
    $testHandler = new TestHandler();
    $monolog = Log::getLogger();
    $monolog->pushHandler($testHandler);

    $user = User::factory()->create(['handle' => 'joeplumber2', 'handle_lc' => 'joeplumber2']);
    Site::factory()->for($user, 'user')->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->postJson('/api/v1/public/report', [
        'target_type'     => 'Site',
        'target_handle'   => 'joeplumber2',
        'reason_code'     => 'spam',
        'reporter_email'  => 'leak@example.com',
        'turnstile_token' => 'cf',
    ])->assertStatus(202);

    // Sweep all captured log records — assert neither raw IP nor raw email appears.
    foreach ($testHandler->getRecords() as $record) {
        $blob = json_encode($record);
        expect($blob)->not->toContain('203.0.113.99');
        expect($blob)->not->toContain('leak@example.com');
    }
});
