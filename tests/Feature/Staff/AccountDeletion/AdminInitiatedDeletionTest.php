<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffAccountDeletionController;
use App\Http\Middleware\Auth\EnsurePartnaAdmin;
use App\Http\Requests\Api\Staff\StaffInitiateDeletionRequest;
use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Services\User\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function makeAdminStaff(array $overrides = []): PartnaStaff
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'role' => 'admin',
        'name' => 'Support Admin',
        'primary_email' => 'admin@sidest.test',
    ], $overrides);

    DB::connection('pgsql')->table('core.partna_staff')->insert($data);

    return PartnaStaff::query()->where('id', $id)->first();
}

function makeActiveUser(array $overrides = []): User
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
        'stripe_manual_balance_cents' => 0,
    ], $overrides);

    DB::connection('pgsql')->table('core.users')->insert($data);

    return User::query()->where('id', $id)->first();
}

function makeAdminRequest(PartnaStaff $staff, array $body = []): Request
{
    $request = Request::create('/', 'POST', $body);
    $request->attributes->set('partna_staff', $staff);

    return $request;
}

// ─── Tests ───────────────────────────────────────────────────────────────────

it('admin can initiate erasure for a clean account', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveUser();

    $service = new AccountDeletionService;
    $result = $service->adminInitiate(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'GDPR Article 17 request — support ticket #1234',
        overrideObligations: false,
        request: makeAdminRequest($staff),
    );

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200)
        ->and($result['deletes_at'])->not->toBeEmpty();

    $pro->refresh();
    expect($pro->status)->toBe('pending_deletion')
        ->and($pro->deletion_confirmed_at)->not->toBeNull();

    Mail::assertQueued(AccountDeletionScheduledMail::class);

    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'admin_initiated')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_type)->toBe(UserDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN)
        ->and($audit->actor_id)->toBe($staff->id)
        ->and($audit->reason)->toBe('GDPR Article 17 request — support ticket #1234');
});

it('admin cannot initiate while another deletion is already in flight', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveUser(['status' => 'pending_deletion']);

    $service = new AccountDeletionService;
    $result = $service->adminInitiate(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'GDPR Article 17 — ticket #9999',
        overrideObligations: false,
        request: makeAdminRequest($staff),
    );

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(409);
});

it('reason is required and validated at the form request level', function () {
    $request = new StaffInitiateDeletionRequest;

    // min:10 — too short
    $validator = validator(['reason' => 'short'], $request->rules(), $request->messages());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('reason'))->toBeTrue();

    // max:500 — too long
    $validator = validator(['reason' => str_repeat('x', 501)], $request->rules(), $request->messages());
    expect($validator->fails())->toBeTrue();

    // missing
    $validator = validator([], $request->rules(), $request->messages());
    expect($validator->fails())->toBeTrue();

    // valid
    $validator = validator(['reason' => 'GDPR Article 17 — support ticket #1234'], $request->rules(), $request->messages());
    expect($validator->fails())->toBeFalse();
});

it('admin can cancel a pending deletion during grace period', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveUser([
        'status' => 'pending_deletion',
        'deletion_previous_status' => 'active',
        'deletion_confirmed_at' => now()->toIso8601String(),
    ]);

    $service = new AccountDeletionService;
    $result = $service->adminCancel(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'User contacted support to reverse — ticket #5678',
        request: makeAdminRequest($staff),
    );

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200);

    $pro->refresh();
    expect($pro->status)->toBe('active');

    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'admin_cancelled')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_type)->toBe(UserDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN);
});

it('admin cancel fails with 409 if no pending deletion exists', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveUser(); // status = 'active', not pending_deletion

    $service = new AccountDeletionService;
    $result = $service->adminCancel(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: null,
        request: makeAdminRequest($staff),
    );

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(409);
});

it('non-admin staff get 403 from EnsurePartnaAdmin middleware', function () {
    $nonAdminUid = (string) Str::uuid();
    $staffId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.partna_staff')->insert([
        'id' => $staffId,
        'auth_user_id' => $nonAdminUid,
        'role' => 'support',
        'name' => 'Support User',
        'primary_email' => 'support@sidest.test',
    ]);

    $nonAdmin = PartnaStaff::query()->where('id', $staffId)->first();

    $request = Request::create('/', 'POST');
    $request->attributes->set('supabase_uid', $nonAdminUid);
    $request->attributes->set('partna_staff', $nonAdmin);

    $middleware = new EnsurePartnaAdmin;
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
    $data = json_decode($response->getContent(), true);
    expect($data['error'])->toBe('admin_required');
});

it('GET show returns deletion state and non-PII audit entries', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveUser([
        'status' => 'pending_deletion',
        'deletion_confirmed_at' => now()->toIso8601String(),
        'deletion_previous_status' => 'active',
    ]);

    // Seed an audit row with PII fields
    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'professional_handle_snapshot' => $pro->handle,
        'professional_email_snapshot' => $pro->primary_email,
        'event' => 'admin_initiated',
        'actor_type' => 'staff_admin',
        'actor_id' => $staff->id,
        'actor_handle_snapshot' => 'Support Admin',
        'reason' => 'GDPR Article 17 — ticket #1234',
        'ip_address' => '1.2.3.4',
        'user_agent' => 'TestAgent',
        'created_at' => now()->toIso8601String(),
    ]);

    $controller = new StaffAccountDeletionController(new AccountDeletionService);
    $response = $controller->show($pro);
    $data = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['status'])->toBe('pending_deletion')
        ->and($data['deletes_at'])->not->toBeNull()
        ->and($data['audit_entries'])->toHaveCount(1);

    $entry = $data['audit_entries'][0];
    expect($entry)->toHaveKey('event')
        ->and($entry)->toHaveKey('actor_type')
        ->and($entry)->toHaveKey('reason')
        ->and($entry)->not->toHaveKey('actor_handle_snapshot')
        ->and($entry)->not->toHaveKey('ip_address')
        ->and($entry)->not->toHaveKey('user_agent');
});
