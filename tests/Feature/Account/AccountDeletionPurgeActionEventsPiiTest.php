<?php

use App\Models\Core\User\User;
use App\Services\User\AccountDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

// Same PRIV-3 shape as AccountDeletionPurgeItemViewsPiiTest.php: analytics.
// action_events.user_id is a denormalised, nullable column (no FK to
// core.users), so forceDelete's cascade never reaches it directly. Without an
// explicit purge, a deleted professional's visitor ip_hash/user_agent/
// session_id would survive until the next PurgeRawAnalyticsEvents sweep (up
// to the 90-day analytics_raw_event_retention_days window) instead of being
// erased immediately on account deletion. Covers
// AccountDeletionService::purgeActionEventsPii().

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
 * Seed a professional who is past their 30-day grace period, mirroring
 * seedItemViewsPurgeUser() (distinct name to avoid a global-function
 * redeclaration collision across test files in the same run).
 */
function seedActionEventsPurgeUser(string $originalEmail): array
{
    $id = (string) Str::uuid();
    $authId = (string) Str::uuid();
    $handle = 'ae-'.substr($id, 0, 6);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => $authId,
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Action Events PII User',
        'primary_email' => "deleted+{$id}@partna.au", // already pseudonymised
        'status' => 'pending_deletion',
        'deletion_confirmed_at' => now()->subDays(31)->toIso8601String(),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

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

function seedActionEventRow(string $userId, string $ipHash = 'hash-abc123'): string
{
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('analytics.action_events')->insert([
        'id' => $id,
        'user_id' => $userId,
        'site_id' => (string) Str::uuid(),
        'action_id' => 'menu',
        'event' => 'tap',
        'occurred_at' => now()->toIso8601String(),
        'visitor_id' => (string) Str::uuid(),
        'ip_hash' => $ipHash,
        'user_agent' => 'Mozilla/5.0 test agent',
        'created_at' => now()->toIso8601String(),
    ]);

    return $id;
}

it('deletes analytics.action_events rows for the purged professional (PRIV-3)', function () {
    $user = seedActionEventsPurgeUser('ae-priv3@example.com');
    $userId = $user['id'];

    seedActionEventRow($userId);
    seedActionEventRow($userId);

    $professional = User::find($userId);
    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();

    $count = DB::connection('pgsql')->table('analytics.action_events')
        ->where('user_id', $userId)->count();

    expect($count)->toBe(0);
});

it('does not delete another professional\'s action_events rows (PRIV-3)', function () {
    $target = seedActionEventsPurgeUser('ae-priv3-target@example.com');
    $other = seedActionEventsPurgeUser('ae-priv3-other@example.com');

    seedActionEventRow($target['id']);
    $survivorId = seedActionEventRow($other['id'], 'hash-survivor');

    $professional = User::find($target['id']);
    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();

    expect(
        DB::connection('pgsql')->table('analytics.action_events')->where('user_id', $target['id'])->count()
    )->toBe(0);

    $survivor = DB::connection('pgsql')->table('analytics.action_events')->where('id', $survivorId)->first();
    expect($survivor)->not->toBeNull();
    expect($survivor->user_id)->toBe($other['id']);
    expect($survivor->ip_hash)->toBe('hash-survivor');
});
