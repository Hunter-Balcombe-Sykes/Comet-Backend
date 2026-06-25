<?php

use App\Jobs\Notifications\SendFeedbackEmailJob;
use App\Models\Core\Feedback;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'partna.throttle.enabled' => false,
        'partna.feedback.notify_emails' => ['team@partna.test'],
        'partna.feedback.ip_hash_pepper' => 'test-pepper',
        'partna.feedback.duplicate_window_seconds' => 60,
    ]);
    Cache::flush();

    setupUsersTable();
    setupFeedbackTable();
    // Register SQLite shims for pg_advisory_xact_lock + hashtext so the
    // advisory-lock call inside FeedbackService::submit() runs as a no-op
    // under the in-memory SQLite test driver.
    shimPgAdvisoryLockForSqlite();
});

function seedFeedbackPro(): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'fb-'.Str::random(6),
        'handle_lc' => 'fb-'.Str::random(6),
        'display_name' => 'Feedback Tester',
        'primary_email' => 'tester@example.test',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return User::findOrFail($id);
}

it('rejects unauthenticated submissions with 401', function () {
    $response = $this->postJson('/api/me/feedback', [
        'kind' => 'idea',
        'message' => 'hello',
    ]);

    expect($response->getStatusCode())->toBeIn([401, 403]);
});

it('persists a valid submission and dispatches the notification job', function () {
    Bus::fake();
    $pro = seedFeedbackPro();

    $response = actingAsUser($pro)->postJson('/api/me/feedback', [
        'kind' => 'bug',
        'severity' => 'medium',
        'message' => 'The save button does not respond on first click.',
        'page_url' => 'https://app.partna.au/dashboard/gallery',
        'viewport' => '1440x900',
        'app_version' => '2026.05.25-abc',
        'request_id' => '01HV-test-id',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure(['feedback' => ['id', 'kind', 'severity', 'message', 'status', 'created_at']]);

    $row = Feedback::query()->where('user_id', $pro->id)->first();
    expect($row)->not->toBeNull();
    expect($row->kind)->toBe('bug');
    expect($row->severity)->toBe('medium');
    expect($row->source)->toBe('dashboard');
    expect($row->ip_hash)->not->toBeNull();
    expect($row->ip_hash)->not->toBe('127.0.0.1');     // hashed, not raw

    Bus::assertDispatched(SendFeedbackEmailJob::class, fn ($job) => $job->feedbackId === (string) $row->id);
});

it('returns 422 when kind=bug but severity missing', function () {
    $pro = seedFeedbackPro();

    $response = actingAsUser($pro)->postJson('/api/me/feedback', [
        'kind' => 'bug',
        'message' => 'broken',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['severity']);
});

it('returns 422 when message is too long', function () {
    $pro = seedFeedbackPro();

    $response = actingAsUser($pro)->postJson('/api/me/feedback', [
        'kind' => 'idea',
        'message' => str_repeat('a', 5001),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['message']);
});

it('returns 422 for invalid kind', function () {
    $pro = seedFeedbackPro();

    $response = actingAsUser($pro)->postJson('/api/me/feedback', [
        'kind' => 'rant',
        'message' => 'hi',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['kind']);
});

it('returns 429 when an identical message is resubmitted inside the duplicate window', function () {
    Bus::fake();
    $pro = seedFeedbackPro();

    $payload = ['kind' => 'idea', 'message' => 'same thing twice'];
    actingAsUser($pro)->postJson('/api/me/feedback', $payload)->assertStatus(201);
    actingAsUser($pro)->postJson('/api/me/feedback', $payload)->assertStatus(429);

    expect(Feedback::query()->where('user_id', $pro->id)->count())->toBe(1);
});

it('omits internal triage fields from the API response', function () {
    Bus::fake();
    $pro = seedFeedbackPro();

    $response = actingAsUser($pro)->postJson('/api/me/feedback', [
        'kind' => 'praise',
        'message' => 'love it',
        'reply_email' => 'somewhere-else@example.test',
    ])->assertStatus(201);

    $body = $response->json('feedback');
    expect($body)->not->toHaveKey('reply_email');
    expect($body)->not->toHaveKey('ip_hash');
    expect($body)->not->toHaveKey('internal_notes');
    expect($body)->not->toHaveKey('tags');
    expect($body)->not->toHaveKey('user_id');
});

it('lists only the actor own feedback', function () {
    $alice = seedFeedbackPro();
    $bob = seedFeedbackPro();

    DB::connection('pgsql')->table('core.feedback')->insert([
        ['id' => (string) Str::uuid(), 'user_id' => $alice->id, 'kind' => 'idea', 'message' => 'alice-1', 'status' => 'new', 'internal_notes' => '[]', 'tags' => '[]', 'source' => 'dashboard', 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
        ['id' => (string) Str::uuid(), 'user_id' => $bob->id, 'kind' => 'idea', 'message' => 'bob-1', 'status' => 'new', 'internal_notes' => '[]', 'tags' => '[]', 'source' => 'dashboard', 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
    ]);

    $response = actingAsUser($alice)->getJson('/api/me/feedback');

    $response->assertStatus(200);
    $items = $response->json('feedback');
    expect($items)->toHaveCount(1);
    expect($items[0]['message'])->toBe('alice-1');
});

it('returns 404 when viewing another user feedback (no existence leak)', function () {
    $alice = seedFeedbackPro();
    $bob = seedFeedbackPro();

    $bobId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.feedback')->insert([
        'id' => $bobId,
        'user_id' => $bob->id,
        'kind' => 'idea',
        'message' => 'bob private',
        'status' => 'new',
        'internal_notes' => '[]',
        'tags' => '[]',
        'source' => 'dashboard',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $response = actingAsUser($alice)->getJson("/api/me/feedback/{$bobId}");
    $response->assertStatus(404);
});

it('hashes the IP rather than storing it raw', function () {
    Bus::fake();
    $pro = seedFeedbackPro();

    actingAsUser($pro)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
        ->postJson('/api/me/feedback', ['kind' => 'idea', 'message' => 'ip test'])
        ->assertStatus(201);

    $row = Feedback::query()->where('user_id', $pro->id)->first();
    expect($row->ip_hash)->not->toContain('203.0.113.42');
    expect($row->ip_hash)->toHaveLength(64);    // SHA256 hex
});

it('falls back user_agent to the request header when not in the body', function () {
    Bus::fake();
    $pro = seedFeedbackPro();

    actingAsUser($pro)
        ->withHeaders(['User-Agent' => 'TestAgent/9.9'])
        ->postJson('/api/me/feedback', ['kind' => 'idea', 'message' => 'ua fallback'])
        ->assertStatus(201);

    $row = Feedback::query()->where('user_id', $pro->id)->first();
    expect($row->user_agent)->toBe('TestAgent/9.9');
});

it('rejects an oversized user_agent in the body with 422 (SEC-1c FormRequest guard)', function () {
    $pro = seedFeedbackPro();

    $response = actingAsUser($pro)->postJson('/api/me/feedback', [
        'kind' => 'idea',
        'message' => 'ua too long',
        'user_agent' => str_repeat('A', 1025),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['user_agent']);
});

it('caps a raw-header user_agent to 1024 chars when not supplied in the body (SEC-1c fallback cap)', function () {
    Bus::fake();
    $pro = seedFeedbackPro();

    // 2000-char UA supplied via header (not body) — service must truncate to 1024.
    $longUa = str_repeat('X', 2000);

    actingAsUser($pro)
        ->withHeaders(['User-Agent' => $longUa])
        ->postJson('/api/me/feedback', ['kind' => 'idea', 'message' => 'ua cap test'])
        ->assertStatus(201);

    $row = Feedback::query()->where('user_id', $pro->id)->first();
    // Header fallback is capped to 1024 in FeedbackService — raw 2000-char
    // value must never reach the row.
    expect(mb_strlen((string) $row->user_agent))->toBeLessThanOrEqual(1024);
});

it('advisory lock makes duplicate window check atomic: two sequential submits of the same message produce exactly one row', function () {
    // This test pins the atomicity contract introduced to close LIFE-1c (TOCTOU).
    // In production the pg_advisory_xact_lock serialises concurrent requests so
    // only one INSERT wins. Under SQLite the lock is a registered no-op (via
    // shimPgAdvisoryLockForSqlite in beforeEach), so sequential calls exercise
    // the same code path without needing real concurrency.
    Bus::fake();
    $pro = seedFeedbackPro();

    $payload = ['kind' => 'bug', 'severity' => 'low', 'message' => 'advisory lock dedup test'];

    // First submit — should succeed.
    actingAsUser($pro)->postJson('/api/me/feedback', $payload)->assertStatus(201);

    // Second submit with same message inside the window — duplicate guard fires.
    actingAsUser($pro)->postJson('/api/me/feedback', $payload)->assertStatus(429);

    // Exactly one row must exist despite both attempts.
    expect(Feedback::query()->where('user_id', $pro->id)->count())->toBe(1);
});

it('advisory lock SQL is issued during a successful submit', function () {
    // Assert the advisory lock statement actually fires on the real code path.
    // DB::listen captures every query; we verify the lock call appears in the log.
    Bus::fake();
    $pro = seedFeedbackPro();

    $lockCalled = false;
    DB::listen(function ($query) use (&$lockCalled) {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $lockCalled = true;
        }
    });

    actingAsUser($pro)->postJson('/api/me/feedback', [
        'kind' => 'idea',
        'message' => 'lock instrumentation test',
    ])->assertStatus(201);

    expect($lockCalled)->toBeTrue();
});
