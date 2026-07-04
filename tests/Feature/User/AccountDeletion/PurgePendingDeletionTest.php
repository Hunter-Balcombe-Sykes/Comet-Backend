<?php

use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Services\User\AccountDeletionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
});

function seedPurgeableUser(array $overrides = []): User
{
    $id = (string) Str::uuid();
    $authId = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => $authId,
        'handle' => 'purge-'.substr($id, 0, 6),
        'handle_lc' => 'purge-'.substr($id, 0, 6),
        'display_name' => 'To Purge',
        'primary_email' => 'purge-'.substr($id, 0, 6).'@example.com',
        'status' => 'pending_deletion',
        'deletion_confirmed_at' => now()->subDays(31)->toIso8601String(),
    ], $overrides);

    DB::connection('pgsql')->table('core.users')->insert($data);

    return User::query()->where('id', $id)->first();
}

it('calls Supabase Admin API and hard-deletes professional on success', function () {
    $pro = seedPurgeableUser();

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200),
    ]);

    $service = new AccountDeletionService;
    $result = $service->purge($pro);

    expect($result)->toBeTrue();

    $stillExists = DB::connection('pgsql')->table('core.users')
        ->where('id', $pro->id)->exists();
    expect($stillExists)->toBeFalse();

    Http::assertSent(function ($request) use ($pro) {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), "/auth/v1/admin/users/{$pro->auth_user_id}");
    });
});

it('treats Supabase 404 as success and still hard-deletes professional', function () {
    $pro = seedPurgeableUser();

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response(['message' => 'User not found'], 404),
    ]);

    $service = new AccountDeletionService;
    $result = $service->purge($pro);

    expect($result)->toBeTrue();

    $stillExists = DB::connection('pgsql')->table('core.users')
        ->where('id', $pro->id)->exists();
    expect($stillExists)->toBeFalse();
});

it('skips hard delete and logs purge_failed when Supabase returns 500', function () {
    $pro = seedPurgeableUser();

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response(['message' => 'server error'], 500),
    ]);

    $service = new AccountDeletionService;
    $result = $service->purge($pro);

    expect($result)->toBeFalse();

    $stillExists = DB::connection('pgsql')->table('core.users')
        ->where('id', $pro->id)->exists();
    expect($stillExists)->toBeTrue();

    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('event', 'purge_failed')
        ->where('user_id', $pro->id)
        ->first();
    expect($audit)->not->toBeNull();
});

it('writes purged audit row with handle + email snapshots', function () {
    $pro = seedPurgeableUser(['handle' => 'snapshot-me', 'primary_email' => 'snapshot@example.com']);

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200),
    ]);

    $service = new AccountDeletionService;
    $service->purge($pro);

    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('event', 'purged')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->professional_handle_snapshot)->toBe('snapshot-me')
        ->and($audit->professional_email_snapshot)->toBe('snapshot@example.com')
        ->and($audit->user_id)->toBeNull(); // professional is deleted, FK set null
});

it('purged audit row records the resolved pre-pseudonymisation email, not the placeholder (SEM-1)', function () {
    // By purge time (30 days post-confirmation) primary_email is already the
    // "deleted+{id}@partna.au" placeholder that executeConfirmation() wrote; the
    // real address survives only in the EVENT_CONFIRMED audit snapshot captured
    // before pseudonymisation. purge() must resolve THAT for the PURGED receipt.
    $pro = seedPurgeableUser();
    $realEmail = 'real-'.substr($pro->id, 0, 6).'@example.com';

    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)
        ->update(['primary_email' => 'deleted+'.$pro->id.'@partna.au']);

    UserDeletionAuditEntry::create([
        'user_id' => $pro->id,
        'professional_handle_snapshot' => $pro->handle,
        'professional_email_snapshot' => $realEmail,
        'event' => UserDeletionAuditEntry::EVENT_CONFIRMED,
        'actor_type' => UserDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL,
    ]);

    $pro->refresh(); // in-memory model must carry the pseudonymised email

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200),
    ]);

    (new AccountDeletionService)->purge($pro);

    $purged = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('event', 'purged')->first();

    // Without the SEM-1 fix this would be the "deleted+{id}@partna.au" placeholder.
    expect($purged)->not->toBeNull()
        ->and($purged->professional_email_snapshot)->toBe($realEmail)
        ->and($purged->professional_email_snapshot)->not->toContain('deleted+');
});

it('command purges professionals past 30 days but skips within grace', function () {
    AccountDeletionTestCase::boot(); // re-init DB for command-level test

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200),
    ]);

    // Past grace — should be purged
    $purgeable = seedPurgeableUser([
        'deletion_confirmed_at' => now()->subDays(31)->toIso8601String(),
    ]);

    // Within grace — should be skipped
    $withinGrace = seedPurgeableUser([
        'deletion_confirmed_at' => now()->subDays(5)->toIso8601String(),
    ]);

    Artisan::call('partna:purge-soft-deletes');

    $purgeableExists = DB::connection('pgsql')->table('core.users')
        ->where('id', $purgeable->id)->exists();
    $withinGraceExists = DB::connection('pgsql')->table('core.users')
        ->where('id', $withinGrace->id)->exists();

    expect($purgeableExists)->toBeFalse()
        ->and($withinGraceExists)->toBeTrue();
});
