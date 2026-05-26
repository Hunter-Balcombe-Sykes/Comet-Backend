<?php

use App\Services\Auth\AuthFactorEventRepository;
use Illuminate\Support\Str;

beforeEach(function () {
    setupAuthFactorEventsTable();
});

it('records a factor event with all fields', function () {
    $repo = app(AuthFactorEventRepository::class);
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();

    $id = $repo->record(
        userId: $userId,
        eventType: 'verify_success',
        factorId: $factorId,
        factorType: 'totp',
        sessionId: (string) Str::uuid(),
        ip: '1.2.3.4',
        userAgent: 'Test/1.0',
        metadata: ['source' => 'hook'],
    );

    expect($id)->toBeString();

    $row = \DB::connection('pgsql')->table('audit.auth_factor_events')->where('id', $id)->first();
    expect($row->user_id)->toBe($userId);
    expect($row->event_type)->toBe('verify_success');
    expect($row->factor_type)->toBe('totp');
});

it('counts recent failures within the window', function () {
    $repo = app(AuthFactorEventRepository::class);
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();

    // 3 failures in the last minute
    foreach (range(1, 3) as $_) {
        $repo->record($userId, 'verify_failed', $factorId, 'totp');
    }

    // Outside-window failure — simulate by direct DB insert with old timestamp
    \DB::connection('pgsql')->table('audit.auth_factor_events')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'event_type' => 'verify_failed',
        'factor_id' => $factorId,
        'factor_type' => 'totp',
        'metadata' => '{}',
        'created_at' => now()->subMinutes(10)->toIso8601String(),
    ]);

    expect($repo->countRecentFailures($userId, $factorId, windowSeconds: 300))->toBe(3);
});

it('countRecentFailures includes verify_rejected_by_hook events', function () {
    $repo = app(AuthFactorEventRepository::class);
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();

    $repo->record($userId, 'verify_failed', $factorId, 'totp');
    $repo->record($userId, 'verify_rejected_by_hook', $factorId, 'totp');
    $repo->record($userId, 'verify_success', $factorId, 'totp'); // not a failure

    expect($repo->countRecentFailures($userId, $factorId, 300))->toBe(2);
});
