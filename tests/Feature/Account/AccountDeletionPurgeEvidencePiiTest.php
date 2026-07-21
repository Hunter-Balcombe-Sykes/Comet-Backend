<?php

use App\Models\Core\User\User;
use App\Services\User\AccountDeletionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

// PRIV-4: evidence payload PII (handle, display_name, site_subdomain) must be
// tombstoned when the reported user purges their account. moderation.evidence has no
// FK to core.users, so forceDelete's cascade never reaches it — this test verifies
// AccountDeletionService::purgeReportedUserEvidencePii() fills that gap.

beforeEach(function () {
    AccountDeletionTestCase::boot();

    config([
        'partna.media_disk' => 'media',
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-key',
    ]);

    Storage::fake('media');
    Http::fake(['https://test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);
});

/**
 * Insert a content_snapshot evidence row with realistic PII payload and return its id.
 * $userId is the reported user's id (payload.user_id).
 */
function seedEvidenceRow(string $caseId, string $userId): string
{
    $evidenceId = (string) Str::uuid();

    DB::connection('pgsql')->table('moderation.evidence')->insert([
        'id' => $evidenceId,
        'case_id' => $caseId,
        'signal_id' => null,
        'evidence_type' => 'content_snapshot',
        'payload' => json_encode([
            'site_id' => (string) Str::uuid(),
            'site_subdomain' => 'jsmith',
            'user_id' => $userId,
            'handle' => 'jsmith',
            'display_name' => 'John Smith',
            'block_count' => 3,
            'block_types' => ['gallery', 'services', 'contact'],
            'captured_at' => '2026-06-01T00:00:00+00:00',
        ], JSON_THROW_ON_ERROR),
        'content_hash' => hash('sha256', 'stable-hash-input'),
        'captured_at' => now()->toIso8601String(),
    ]);

    return $evidenceId;
}

/**
 * Seed a case row so the evidence FK target resolves (SQLite doesn't enforce FKs
 * but the Evidence model reads the table by name).
 */
function seedModerationCase(): string
{
    $caseId = (string) Str::uuid();

    DB::connection('pgsql')->table('moderation.cases')->insert([
        'id' => $caseId,
        'reportable_type' => 'Site',
        'reportable_id' => (string) Str::uuid(),
        'status' => 'open',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    return $caseId;
}

/**
 * Seed a user who is past their grace period (primary_email already pseudonymised).
 */
function seedEvidencePurgeUser(): array
{
    $id = (string) Str::uuid();
    $authId = (string) Str::uuid();
    $handle = 'evpu-'.substr($id, 0, 6);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => $authId,
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Evidence PII User',
        'primary_email' => "deleted+{$id}@partna.au",
        'status' => 'pending_deletion',
        'deletion_confirmed_at' => now()->subDays(31)->toIso8601String(),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $id,
        'professional_handle_snapshot' => $handle,
        'professional_email_snapshot' => "original+{$id}@example.com",
        'event' => 'confirmed',
        'actor_type' => 'professional',
        'created_at' => now()->subDays(31)->toIso8601String(),
    ]);

    return ['id' => $id, 'auth_user_id' => $authId];
}

it('tombstones PII keys in content_snapshot evidence when reported user is purged (PRIV-4)', function () {
    $user = seedEvidencePurgeUser();
    $userId = $user['id'];
    $caseId = seedModerationCase();
    $evidenceId = seedEvidenceRow($caseId, $userId);

    $originalHash = DB::connection('pgsql')
        ->table('moderation.evidence')
        ->where('id', $evidenceId)
        ->value('content_hash');

    $professional = User::find($userId);
    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();

    $row = DB::connection('pgsql')->table('moderation.evidence')->where('id', $evidenceId)->first();
    expect($row)->not->toBeNull();

    $payload = json_decode($row->payload, true);

    // (a) PII keys are tombstoned.
    expect($payload['handle'])->toBe('[redacted]');
    expect($payload['display_name'])->toBe('[redacted]');
    expect($payload['site_subdomain'])->toBe('[redacted]');

    // (a) Case-integrity / non-PII fields are retained.
    expect($payload['site_id'])->not->toBeNull()->not->toBe('[redacted]');
    expect($payload['block_count'])->toBe(3);
    expect($payload['block_types'])->toBe(['gallery', 'services', 'contact']);
    expect($payload['user_id'])->toBe($userId); // opaque UUID — kept for case linkage

    // (a) content_hash is NOT recomputed — original hash is tamper-evidence of redaction.
    expect($row->content_hash)->toBe($originalHash);
});

it('does not touch evidence rows belonging to a different reported user (PRIV-4)', function () {
    $targetUser = seedEvidencePurgeUser();
    $otherUserId = (string) Str::uuid();
    $caseId = seedModerationCase();

    // Evidence for the user being deleted.
    seedEvidenceRow($caseId, $targetUser['id']);

    // Evidence for a different reported user — must be untouched.
    $otherEvidenceId = seedEvidenceRow($caseId, $otherUserId);
    $otherPayloadBefore = DB::connection('pgsql')
        ->table('moderation.evidence')
        ->where('id', $otherEvidenceId)
        ->value('payload');

    $professional = User::find($targetUser['id']);
    app(AccountDeletionService::class)->purge($professional);

    $otherPayloadAfter = DB::connection('pgsql')
        ->table('moderation.evidence')
        ->where('id', $otherEvidenceId)
        ->value('payload');

    // (b) Other user's evidence payload is byte-for-byte unchanged.
    expect($otherPayloadAfter)->toBe($otherPayloadBefore);
});

it('is a no-op when the deleted user has no evidence rows (PRIV-4)', function () {
    $user = seedEvidencePurgeUser();
    $caseId = seedModerationCase();

    // Evidence for a completely different user — not the one being deleted.
    $otherUserId = (string) Str::uuid();
    $evidenceId = seedEvidenceRow($caseId, $otherUserId);

    $professional = User::find($user['id']);
    $result = app(AccountDeletionService::class)->purge($professional);

    // (c) purge still succeeds; the unrelated evidence row is intact.
    expect($result)->toBeTrue();
    $row = DB::connection('pgsql')->table('moderation.evidence')->where('id', $evidenceId)->first();
    $payload = json_decode($row->payload, true);
    expect($payload['handle'])->toBe('jsmith'); // untouched
});

it('does not touch moderation case/signal/decision rows — case stays decidable (PRIV-4)', function () {
    $user = seedEvidencePurgeUser();
    $userId = $user['id'];
    $caseId = seedModerationCase();

    seedEvidenceRow($caseId, $userId);

    $professional = User::find($userId);
    app(AccountDeletionService::class)->purge($professional);

    // (d) The case row survives unmodified.
    $case = DB::connection('pgsql')->table('moderation.cases')->where('id', $caseId)->first();
    expect($case)->not->toBeNull();
    expect($case->status)->toBe('open');
});

it('logs an error and still completes forceDelete when evidence scrub throws (PRIV-4)', function () {
    $user = seedEvidencePurgeUser();
    $caseId = seedModerationCase();
    seedEvidenceRow($caseId, $user['id']);

    // Simulate a DB failure during the evidence scrub by dropping the table.
    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS moderation.evidence');

    // B10 (LIFE-5): the scrub failure is now also report()'d to Nightwatch. Fake the
    // exception handler so report() is captured here rather than cascading a second
    // Log::error (the framework handler's own log call) into the strict mock below,
    // which asserts only the explicit "Evidence payload PII erasure failed" message.
    Exceptions::fake();

    Log::shouldReceive('error')
        ->withArgs(fn ($msg) => str_contains((string) $msg, 'Evidence payload PII erasure failed'))
        ->once();

    // Allow other log calls through (purge emits several).
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $professional = User::find($user['id']);

    // (e) forceDelete still returns true despite the scrub failure.
    $result = app(AccountDeletionService::class)->purge($professional);
    expect($result)->toBeTrue();

    // ...and the failure is now visible to Nightwatch (the B10 fix under test).
    Exceptions::assertReported(QueryException::class);
});
