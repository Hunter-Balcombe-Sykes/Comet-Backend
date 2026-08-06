<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\User\User;
use App\Services\User\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

function seedAdminPurgeUser(array $overrides = []): User
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'pro-'.substr($id, 0, 6),
        'handle_lc' => 'pro-'.substr($id, 0, 6),
        'display_name' => 'Pro User',
        'primary_email' => 'pro-'.substr($id, 0, 6).'@example.com',
        'status' => 'active',
    ], $overrides);

    DB::connection('pgsql')->table('core.users')->insert($data);

    return User::query()->where('id', $id)->first();
}

function makeAdminPurgeStaffRequest(): Request
{
    $request = Request::create('/', 'DELETE');
    $request->attributes->set('supabase_uid', (string) Str::uuid());

    return $request;
}

it('purges immediately and does NOT queue the grace-period email', function () {
    $pro = seedAdminPurgeUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    $result = (new AccountDeletionService)->adminPurgeNow(
        professional: $pro,
        staffActorId: (string) Str::uuid(),
        staffActorHandle: 'Admin One',
        reason: 'Spam account — support ticket #999',
        overrideObligations: false,
        request: makeAdminPurgeStaffRequest(),
    );

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200);

    $exists = DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists();
    expect($exists)->toBeFalse();

    Mail::assertNotQueued(AccountDeletionScheduledMail::class);
});

it('returns 502 and leaves the row present when the auth-delete fails', function () {
    $pro = seedAdminPurgeUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 500)]);

    $result = (new AccountDeletionService)->adminPurgeNow(
        professional: $pro,
        staffActorId: (string) Str::uuid(),
        staffActorHandle: 'Admin One',
        reason: 'Spam account — support ticket #999',
        overrideObligations: false,
        request: makeAdminPurgeStaffRequest(),
    );

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(502);

    $exists = DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists();
    expect($exists)->toBeTrue();

    // Left in the same retryable state the daily command handles.
    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->value('status'))
        ->toBe('pending_deletion');
});

it('dispatches the edge cache purge job via the staff immediate-delete path (EDGE-1)', function () {
    Bus::fake();

    $pro = seedAdminPurgeUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    $result = (new AccountDeletionService)->adminPurgeNow(
        professional: $pro,
        staffActorId: (string) Str::uuid(),
        staffActorHandle: 'Admin One',
        reason: 'Spam account — support ticket #999',
        overrideObligations: false,
        request: makeAdminPurgeStaffRequest(),
    );

    expect($result['success'])->toBeTrue();

    Bus::assertDispatched(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) use ($pro) {
        return $job->handle === $pro->handle;
    });
});

it('skips the confirmation writes for an account already in the grace period', function () {
    $pro = seedAdminPurgeUser(['status' => 'pending_deletion', 'deletion_confirmed_at' => now()]);

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    $result = (new AccountDeletionService)->adminPurgeNow(
        professional: $pro,
        staffActorId: (string) Str::uuid(),
        staffActorHandle: 'Admin One',
        reason: 'Finishing an in-progress deletion now',
        overrideObligations: false,
        request: makeAdminPurgeStaffRequest(),
    );

    expect($result['success'])->toBeTrue();
    Mail::assertNotQueued(AccountDeletionScheduledMail::class);
});
