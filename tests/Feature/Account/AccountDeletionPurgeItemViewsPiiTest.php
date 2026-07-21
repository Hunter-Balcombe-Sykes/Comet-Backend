<?php

use App\Models\Core\User\User;
use App\Services\User\AccountDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

// PRIV-3: analytics.item_views has no FK to core.users (user_id is a denormalised,
// nullable column — see the table's migration comment), so forceDelete's cascade
// never reaches it. Without an explicit purge, a deleted professional's visitor
// ip_hash/user_agent would survive until the next PurgeRawAnalyticsEvents sweep
// (up to the 90-day analytics_raw_event_retention_days window) instead of being
// erased immediately on account deletion. Covers AccountDeletionService::purgeItemViewsPii().

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
 * AccountDeletionPurgePiiTest's seedPurgePiiUser() (distinct name to avoid a
 * global-function redeclaration collision across test files in the same run).
 */
function seedItemViewsPurgeUser(string $originalEmail): array
{
    $id = (string) Str::uuid();
    $authId = (string) Str::uuid();
    $handle = 'iv-'.substr($id, 0, 6);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => $authId,
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Item Views PII User',
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

/**
 * Insert an analytics.item_views row for the given owner and return its id.
 */
function seedItemViewRow(string $userId, string $ipHash = 'hash-abc123'): string
{
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('analytics.item_views')->insert([
        'id' => $id,
        'user_id' => $userId,
        'site_id' => (string) Str::uuid(),
        'item_type' => 'shop_product',
        'item_id' => 'prod-1',
        'item_title' => 'Test Product',
        'occurred_at' => now()->toIso8601String(),
        'visitor_id' => (string) Str::uuid(),
        'ip_hash' => $ipHash,
        'user_agent' => 'Mozilla/5.0 test agent',
        'created_at' => now()->toIso8601String(),
    ]);

    return $id;
}

it('deletes analytics.item_views rows for the purged professional (PRIV-3)', function () {
    $user = seedItemViewsPurgeUser('priv3@example.com');
    $userId = $user['id'];

    seedItemViewRow($userId);
    seedItemViewRow($userId);

    $professional = User::find($userId);
    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();

    $count = DB::connection('pgsql')->table('analytics.item_views')
        ->where('user_id', $userId)->count();

    expect($count)->toBe(0);
});

it('does not delete another professional\'s item_views rows (PRIV-3)', function () {
    $target = seedItemViewsPurgeUser('priv3-target@example.com');
    $other = seedItemViewsPurgeUser('priv3-other@example.com');

    seedItemViewRow($target['id']);
    $survivorId = seedItemViewRow($other['id'], 'hash-survivor');

    $professional = User::find($target['id']);
    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();

    // The purged professional's rows are gone.
    expect(
        DB::connection('pgsql')->table('analytics.item_views')->where('user_id', $target['id'])->count()
    )->toBe(0);

    // The other professional's row survives untouched — irreversibility means this
    // must be proven, not assumed: assert both existence AND the actual data.
    $survivor = DB::connection('pgsql')->table('analytics.item_views')->where('id', $survivorId)->first();
    expect($survivor)->not->toBeNull();
    expect($survivor->user_id)->toBe($other['id']);
    expect($survivor->ip_hash)->toBe('hash-survivor');
});
