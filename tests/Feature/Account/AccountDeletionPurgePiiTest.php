<?php

use App\Models\Core\User\User;
use App\Services\User\AccountDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

// Integration test verifying all five PII erasure surfaces cleared by purge() (Bundle B4).
// Covers #P2-08 (R2 export ZIPs), #P2-09 (waitlist signups), #P2-10 (feedback),
// #P2-11 (case_signal reporter fields), #P2-12 (global email subscriptions).

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
 * Seed a professional who is past their 30-day grace period with:
 *  - primary_email already pseudonymised (as set by executeConfirmation)
 *  - an audit snapshot recording the original email
 */
function seedPurgePiiUser(string $originalEmail): array
{
    $id = (string) Str::uuid();
    $authId = (string) Str::uuid();
    $handle = 'pii-'.substr($id, 0, 6);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => $authId,
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'PII Test User',
        'primary_email' => "deleted+{$id}@partna.au", // already pseudonymised
        'status' => 'pending_deletion',
        'deletion_confirmed_at' => now()->subDays(31)->toIso8601String(),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    // Audit snapshot written before pseudonymisation — resolveDeletedAccountEmail() reads this.
    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $id,
        'professional_handle_snapshot' => $handle,
        'professional_email_snapshot' => $originalEmail,
        'event' => 'confirmed',
        'actor_type' => 'professional',
        'created_at' => now()->subDays(31)->toIso8601String(),
    ]);

    return ['id' => $id, 'auth_user_id' => $authId, 'email' => $originalEmail];
}

it('deletes R2 export ZIP files before forceDelete (P2-08)', function () {
    $user = seedPurgePiiUser('p208@example.com');
    $userId = $user['id'];

    $zipPath = "exports/{$userId}/".Str::uuid().'.zip';
    Storage::disk('media')->put($zipPath, 'fake-zip-bytes');

    // Orphan ZIP with no audit row / no file_path — e.g. left by a crash before the
    // DB write. Only the directory catch-all sweep reaches this one.
    $orphanPath = "exports/{$userId}/orphan-".Str::uuid().'.zip';
    Storage::disk('media')->put($orphanPath, 'orphan-zip-bytes');

    DB::connection('pgsql')->table('audit.data_export_audit')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'professional_handle_snapshot' => 'pii-test',
        'status' => 'completed',
        'file_path' => $zipPath,
        'recipient_email' => 'p208@example.com',
        'triggered_by' => 'self',
        'created_at' => now()->toIso8601String(),
    ]);

    Storage::disk('media')->assertExists($zipPath);

    $professional = User::find($userId);
    app(AccountDeletionService::class)->purge($professional);

    Storage::disk('media')->assertMissing($zipPath);
    // Catch-all directory sweep removes the untracked orphan too.
    Storage::disk('media')->assertMissing($orphanPath);
});

it('deletes waitlist signup row matched by email_lc (P2-09)', function () {
    $user = seedPurgePiiUser('p209@example.com');
    $emailLc = 'p209@example.com';

    DB::connection('pgsql')->table('core.waitlist_signups')->insert([
        'id' => (string) Str::uuid(),
        'email' => 'P209@example.com',
        'email_lc' => $emailLc,
        'created_at' => now()->toIso8601String(),
    ]);

    $professional = User::find($user['id']);
    app(AccountDeletionService::class)->purge($professional);

    $row = DB::connection('pgsql')->table('core.waitlist_signups')
        ->where('email_lc', $emailLc)->first();

    expect($row)->toBeNull();
});

it('force-deletes feedback rows for the professional (P2-10)', function () {
    $user = seedPurgePiiUser('p210@example.com');
    $userId = $user['id'];

    DB::connection('pgsql')->table('core.feedback')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'kind' => 'bug',
        'message' => 'Sensitive message containing PII.',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $professional = User::find($userId);
    app(AccountDeletionService::class)->purge($professional);

    $count = DB::connection('pgsql')->table('core.feedback')
        ->where('user_id', $userId)->count();

    // Soft-deleted rows are also removed — table() queries bypass SoftDeletes scope.
    expect($count)->toBe(0);
});

it('nulls out reporter_user_id, reporter_email and reason_details on case_signals (P2-11)', function () {
    $user = seedPurgePiiUser('p211@example.com');
    $userId = $user['id'];
    $signalId = (string) Str::uuid();

    DB::connection('pgsql')->table('moderation.case_signals')->insert([
        'id' => $signalId,
        'case_id' => (string) Str::uuid(),
        'signal_source' => 'content_report',
        // signal_data carries the reporter's verbatim report payload — must be erased.
        'signal_data' => '{"details":"My name is Jane Doe, 12 Smith St, and they doxxed me."}',
        'reporter_user_id' => $userId,
        'reporter_email' => 'p211@example.com',
        'reason_code' => 'spam',
        'reason_details' => 'This user is posting my home address and phone number.',
        'created_at' => now()->toIso8601String(),
    ]);

    $professional = User::find($userId);
    app(AccountDeletionService::class)->purge($professional);

    $signal = DB::connection('pgsql')->table('moderation.case_signals')
        ->where('id', $signalId)->first();

    // Signal survives (it's evidence) but ALL reporter PII is erased — including
    // the verbatim report payload in signal_data, reset to an empty object.
    expect($signal)->not->toBeNull()
        ->and($signal->reporter_user_id)->toBeNull()
        ->and($signal->reporter_email)->toBeNull()
        ->and($signal->reason_details)->toBeNull()
        ->and($signal->signal_data)->toBe('{}')
        // Non-PII evidence columns are retained for Trust & Safety analytics.
        ->and($signal->reason_code)->toBe('spam')
        ->and($signal->signal_source)->toBe('content_report');
});

it('deletes global email subscriptions matched by email_lc (P2-12)', function () {
    $user = seedPurgePiiUser('p212@example.com');
    $emailLc = 'p212@example.com';
    $globalId = (string) Str::uuid();

    // Global subscription (user_id IS NULL) — platform marketing list
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => $globalId,
        'user_id' => null,
        'list_key' => 'marketing',
        'email' => 'P212@example.com',
        'email_lc' => $emailLc,
        'status' => 'subscribed',
        'unsubscribe_token' => Str::random(48),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $professional = User::find($user['id']);
    app(AccountDeletionService::class)->purge($professional);

    $row = DB::connection('pgsql')->table('notifications.email_subscriptions')
        ->where('id', $globalId)->first();

    expect($row)->toBeNull();
});

// PRIV-7 Gap 1: cross-tenant subscription erasure (subscriptions owned by OTHER users
// whose email matches the deleting user — surfaced by DataExportPayloadBuilder::streamEmailSubscriptions).

it('deletes cross-tenant email_subscriptions that match the deleting user email_lc', function () {
    $originalEmail = 'priv7@example.com';
    $emailLc = 'priv7@example.com';
    $user = seedPurgePiiUser($originalEmail);

    $otherUserId = (string) Str::uuid();
    $crossTenantSubId = (string) Str::uuid();

    // A subscription owned by a DIFFERENT user but bearing the deleting user's email.
    // This arises when another professional imported the deleting user as a customer contact.
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => $crossTenantSubId,
        'user_id' => $otherUserId,
        'list_key' => 'marketing',
        'email' => $originalEmail,
        'email_lc' => $emailLc,
        'status' => 'subscribed',
        'unsubscribe_token' => Str::random(48),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $professional = User::find($user['id']);
    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();

    // Cross-tenant row must be fully deleted.
    $row = DB::connection('pgsql')->table('notifications.email_subscriptions')
        ->where('id', $crossTenantSubId)->first();

    expect($row)->toBeNull();
});

it('does not delete cross-tenant subscriptions with a different email_lc', function () {
    $originalEmail = 'priv7b@example.com';
    $user = seedPurgePiiUser($originalEmail);

    $otherUserId = (string) Str::uuid();
    $otherSubId = (string) Str::uuid();

    // A subscription owned by another user but with a completely different email —
    // must survive (not the deleting user's data).
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => $otherSubId,
        'user_id' => $otherUserId,
        'list_key' => 'marketing',
        'email' => 'unrelated@example.com',
        'email_lc' => 'unrelated@example.com',
        'status' => 'subscribed',
        'unsubscribe_token' => Str::random(48),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $professional = User::find($user['id']);
    app(AccountDeletionService::class)->purge($professional);

    // Guard row with a different email must survive intact.
    $row = DB::connection('pgsql')->table('notifications.email_subscriptions')
        ->where('id', $otherSubId)->first();

    expect($row)->not->toBeNull();
    expect($row->email_lc)->toBe('unrelated@example.com');
});

it('clears all five PII surfaces in a single purge run', function () {
    $originalEmail = 'p2all@example.com';
    $emailLc = 'p2all@example.com';
    $user = seedPurgePiiUser($originalEmail);
    $userId = $user['id'];

    // #P2-08: export ZIP on R2
    $zipPath = "exports/{$userId}/".Str::uuid().'.zip';
    Storage::disk('media')->put($zipPath, 'fake-zip-bytes');
    DB::connection('pgsql')->table('audit.data_export_audit')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'professional_handle_snapshot' => 'pii-test',
        'status' => 'completed',
        'file_path' => $zipPath,
        'recipient_email' => $originalEmail,
        'triggered_by' => 'self',
        'created_at' => now()->toIso8601String(),
    ]);

    // #P2-09: waitlist signup
    DB::connection('pgsql')->table('core.waitlist_signups')->insert([
        'id' => (string) Str::uuid(),
        'email' => $originalEmail,
        'email_lc' => $emailLc,
        'created_at' => now()->toIso8601String(),
    ]);

    // #P2-10: feedback
    DB::connection('pgsql')->table('core.feedback')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'kind' => 'idea',
        'message' => 'PII-bearing message.',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    // #P2-11: case signal with reporter PII
    $signalId = (string) Str::uuid();
    DB::connection('pgsql')->table('moderation.case_signals')->insert([
        'id' => $signalId,
        'case_id' => (string) Str::uuid(),
        'signal_source' => 'content_report',
        'signal_data' => '{"details":"verbatim reporter freetext PII"}',
        'reporter_user_id' => $userId,
        'reporter_email' => $originalEmail,
        'reason_code' => 'spam',
        'reason_details' => 'Sensitive freetext from reporter.',
        'created_at' => now()->toIso8601String(),
    ]);

    // #P2-12: global email subscription
    $globalSubId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => $globalSubId,
        'user_id' => null,
        'list_key' => 'marketing',
        'email' => $originalEmail,
        'email_lc' => $emailLc,
        'status' => 'subscribed',
        'unsubscribe_token' => Str::random(48),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $professional = User::find($userId);
    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();

    // P2-08: ZIP deleted from R2
    Storage::disk('media')->assertMissing($zipPath);

    // P2-09: waitlist row gone
    expect(
        DB::connection('pgsql')->table('core.waitlist_signups')->where('email_lc', $emailLc)->exists()
    )->toBeFalse();

    // P2-10: feedback gone (all rows for this user)
    expect(
        DB::connection('pgsql')->table('core.feedback')->where('user_id', $userId)->count()
    )->toBe(0);

    // P2-11: signal survives but PII columns nulled (incl. the signal_data payload)
    $signal = DB::connection('pgsql')->table('moderation.case_signals')->where('id', $signalId)->first();
    expect($signal)->not->toBeNull()
        ->and($signal->reporter_user_id)->toBeNull()
        ->and($signal->reporter_email)->toBeNull()
        ->and($signal->reason_details)->toBeNull()
        ->and($signal->signal_data)->toBe('{}');

    // P2-12: global subscription deleted
    expect(
        DB::connection('pgsql')->table('notifications.email_subscriptions')->where('id', $globalSubId)->exists()
    )->toBeFalse();
});
