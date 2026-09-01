<?php

/**
 * Purging a professional profile must not revoke a staff login.
 *
 * core.partna_staff.auth_user_id and core.users.auth_user_id can point at the
 * SAME GoTrue identity. purge() Step 1 deletes that identity so the email frees
 * up — correct for an ordinary professional, catastrophic when the identity is
 * also somebody's staff login: nothing in the app can recreate a GoTrue user, so
 * the staff row survives pointing at an identity that can never sign in again.
 *
 * This is the exact shape of retiring the platform admin's unwanted profile
 * (2026-09-01): one auth user, one staff row, one junk professional row to
 * erase. The rest of the purge is unchanged — the assertion is that the row and
 * its site go and the login stays.
 */

use App\Models\Core\User\User;
use App\Services\User\AccountDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
    DB::connection('pgsql')->statement('DELETE FROM core.partna_staff');
});

function seedPurgeUser(string $authUserId): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => $authUserId,
        'handle' => 'junk-'.substr($id, 0, 6),
        'handle_lc' => 'junk-'.substr($id, 0, 6),
        'display_name' => 'Junk Profile',
        'primary_email' => 'owner-'.substr($id, 0, 6).'@example.com',
        'status' => 'active',
    ]);

    return User::query()->where('id', $id)->first();
}

function seedStaffFor(string $authUserId): string
{
    $staffId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.partna_staff')->insert([
        'id' => $staffId,
        'auth_user_id' => $authUserId,
        'role' => 'admin',
        'name' => 'Platform Admin',
        'primary_email' => 'admin@partna.au',
    ]);

    return $staffId;
}

it('erases the profile but leaves the Supabase auth user alone when it is a staff login', function () {
    $authUserId = (string) Str::uuid();
    $staffId = seedStaffFor($authUserId);
    $pro = seedPurgeUser($authUserId);

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    expect((new AccountDeletionService)->purge($pro))->toBeTrue();

    // The profile is gone...
    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeFalse();

    // ...the staff row is untouched...
    $staff = DB::connection('pgsql')->table('core.partna_staff')->where('id', $staffId)->first();
    expect($staff)->not->toBeNull()
        ->and($staff->auth_user_id)->toBe($authUserId)
        ->and($staff->role)->toBe('admin');

    // ...and GoTrue was never asked to delete the identity behind that row.
    Http::assertNothingSent();
});

it('still deletes the Supabase auth user for an ordinary professional', function () {
    // Control. Without this the assertion above is satisfied by any change that
    // breaks the auth-delete entirely.
    $authUserId = (string) Str::uuid();
    seedStaffFor((string) Str::uuid()); // a DIFFERENT staff member exists
    $pro = seedPurgeUser($authUserId);

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    expect((new AccountDeletionService)->purge($pro))->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/auth/v1/admin/users/'.$authUserId));

    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeFalse();
});
