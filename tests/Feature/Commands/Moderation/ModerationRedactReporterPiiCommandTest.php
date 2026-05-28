<?php

use App\Models\Moderation\AuditEvent;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\ModerationCase;

beforeEach(function () {
    setupAllModerationTables();
    setupUsersTable();
});

it('clears reporter_email and reporter_ip_hash on signals for the case', function () {
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create([
        'reporter_email'   => 'leak@example.com',
        'reporter_ip_hash' => 'somehash',
    ]);
    CaseSignal::factory()->forCase($case)->create([
        'reporter_email'   => 'leak2@example.com',
        'reporter_ip_hash' => 'somehash2',
    ]);

    $this->artisan("moderation:redact-reporter-pii {$case->id} --reason=gdpr-erasure")
        ->assertSuccessful();

    $signals = CaseSignal::query()->where('case_id', $case->id)->get();
    foreach ($signals as $s) {
        expect($s->reporter_email)->toBeNull();
        expect($s->reporter_ip_hash)->toBeNull();
    }

    $audit = AuditEvent::query()->where('action', 'reporter.pii_redacted')->latest('created_at')->first();
    expect($audit)->not->toBeNull();
    expect($audit->actor_kind)->toBe('system');
});

it('fails when case does not exist', function () {
    $this->artisan('moderation:redact-reporter-pii 00000000-0000-0000-0000-000000000000 --reason=test')
        ->assertFailed();
});

it('is idempotent (running twice on same case does not error)', function () {
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create([
        'reporter_email'   => 'leak@example.com',
        'reporter_ip_hash' => 'somehash',
    ]);

    $this->artisan("moderation:redact-reporter-pii {$case->id} --reason=gdpr")->assertSuccessful();
    $this->artisan("moderation:redact-reporter-pii {$case->id} --reason=gdpr")->assertSuccessful();

    $signals = CaseSignal::query()->where('case_id', $case->id)->get();
    foreach ($signals as $s) {
        expect($s->reporter_email)->toBeNull();
    }
});
