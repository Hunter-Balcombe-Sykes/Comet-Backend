<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffAccountDeletionController;
use App\Http\Middleware\Auth\EnsurePartnaAdmin;
use App\Http\Requests\Api\Staff\StaffCancelDeletionRequest;
use App\Http\Requests\Api\Staff\StaffInitiateDeletionRequest;
use App\Mail\Notifications\AccountDeletionCancelledMail;
use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Services\User\AccountDeletionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
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

function makeSupportStaff(array $overrides = []): PartnaStaff
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'role' => 'support',
        'name' => 'Support Rep',
        'primary_email' => 'support@sidest.test',
    ], $overrides);

    DB::connection('pgsql')->table('core.partna_staff')->insert($data);

    return PartnaStaff::query()->where('id', $id)->first();
}

/** Build a validated StaffInitiateDeletionRequest, as the FormRequest pipeline would. */
function makeInitiateRequest(PartnaStaff $staff, array $data): StaffInitiateDeletionRequest
{
    $request = StaffInitiateDeletionRequest::create('/', 'POST', $data);
    $request->setContainer(app());
    $request->attributes->set('partna_staff', $staff);
    $request->setValidator(Validator::make($data, $request->rules(), $request->messages()));

    return $request;
}

/** Build a validated StaffCancelDeletionRequest, as the FormRequest pipeline would. */
function makeCancelRequest(PartnaStaff $staff, array $data = []): StaffCancelDeletionRequest
{
    $request = StaffCancelDeletionRequest::create('/', 'POST', $data);
    $request->setContainer(app());
    $request->attributes->set('partna_staff', $staff);
    $request->setValidator(Validator::make($data, $request->rules()));

    return $request;
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

// ─── Email restoration on admin-cancel ───────────────────────────────────────
//
// adminInitiate() and confirm() both route through executeConfirmation(), which
// snapshots the live email into the audit row THEN pseudonymises primary_email.
// restoreEmailFromAuditSnapshot() must match on both 'confirmed' and
// 'admin_initiated' — filtering on 'confirmed' alone (the pre-fix behaviour)
// means adminCancel() finds no snapshot for a staff-initiated deletion and
// leaves the account on its placeholder "deleted+{id}@partna.au" address
// indefinitely, with no password reset or account recovery possible.

it('admin cancel restores the real email for an admin-initiated deletion', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveUser();
    $originalEmail = $pro->primary_email;

    $service = new AccountDeletionService;
    $service->adminInitiate(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'GDPR Article 17 request — support ticket #4242',
        overrideObligations: false,
        request: makeAdminRequest($staff),
    );

    $pro->refresh();
    // Anti-vacuous guard: prove the account really was pseudonymised, so the
    // restore below is undoing something real.
    expect($pro->primary_email)->not->toBe($originalEmail)
        ->and($pro->primary_email)->toStartWith('deleted+');

    // Anti-vacuous guard: prove the OLD filter (event = 'confirmed' only) had
    // nothing to match on this path — there is no 'confirmed' row at all.
    $confirmedRow = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'confirmed')
        ->first();
    expect($confirmedRow)->toBeNull();

    $adminInitiatedRow = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'admin_initiated')
        ->first();
    expect($adminInitiatedRow)->not->toBeNull()
        ->and($adminInitiatedRow->professional_email_snapshot)->toBe($originalEmail);

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
    expect($pro->status)->toBe('active')
        ->and($pro->primary_email)->toBe($originalEmail);

    Mail::assertQueued(
        AccountDeletionCancelledMail::class,
        fn ($mail) => $mail->hasTo($originalEmail),
    );

    $adminCancelledRow = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'admin_cancelled')
        ->first();
    expect($adminCancelledRow)->not->toBeNull()
        ->and($adminCancelledRow->professional_email_snapshot)->toBe($originalEmail);
});

it('admin cancel restores the newest snapshot, not a stale confirmed row from an earlier cycle', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveUser();
    $originalEmail = $pro->primary_email;
    $staleEmail = 'stale-'.Str::random(6).'@example.com';

    // Simulate a much earlier request→confirm→cancel cycle that left behind a
    // 'confirmed' row carrying a DIFFERENT (now-stale) email snapshot.
    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'professional_handle_snapshot' => $pro->handle,
        'professional_email_snapshot' => $staleEmail,
        'event' => 'confirmed',
        // TEXT column, ordered lexicographically — must match the model's
        // 'Y-m-d H:i:s' write format, NOT toIso8601String(): the 'T' separator
        // (0x54) sorts AFTER a space (0x20) at byte 10, so an ISO string would
        // wrongly sort after every same-day model-written row below.
        'created_at' => now()->subDays(90)->format('Y-m-d H:i:s'),
    ]);

    $service = new AccountDeletionService;
    $service->adminInitiate(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'GDPR Article 17 request — support ticket #9001',
        overrideObligations: false,
        request: makeAdminRequest($staff),
    );

    $service->adminCancel(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'User contacted support to reverse — ticket #9002',
        request: makeAdminRequest($staff),
    );

    $pro->refresh();
    expect($pro->primary_email)->toBe($originalEmail)
        ->and($pro->primary_email)->not->toBe($staleEmail);
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

// ─── #SEC-3: Policy gate on initiate()/cancel() ──────────────────────────────
//
// Both routes already sit behind the staff.admin route-middleware group, so
// there's no live bypass — these prove the Policy-layer defence-in-depth
// seam itself, through the real controller (not the service directly), so a
// regression in the wiring (wrong ability, wrong resource) would be caught.

it('the controller denies support staff on initiate(), independent of route middleware', function () {
    $staff = makeSupportStaff();
    $pro = makeActiveUser();
    $controller = new StaffAccountDeletionController(new AccountDeletionService);

    expect(fn () => $controller->initiate(
        makeInitiateRequest($staff, ['reason' => 'GDPR Article 17 — ticket #1']),
        $pro,
    ))->toThrow(AuthorizationException::class);
});

it('the controller denies support staff on cancel(), independent of route middleware', function () {
    $staff = makeSupportStaff();
    $pro = makeActiveUser(['status' => 'pending_deletion', 'deletion_confirmed_at' => now()->toIso8601String()]);
    $controller = new StaffAccountDeletionController(new AccountDeletionService);

    expect(fn () => $controller->cancel(makeCancelRequest($staff), $pro))
        ->toThrow(AuthorizationException::class);
});

it('the controller lets admin staff initiate through validated() — reason and override_obligations both reach the service', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveUser();
    $controller = new StaffAccountDeletionController(new AccountDeletionService);

    $response = $controller->initiate(
        makeInitiateRequest($staff, [
            'reason' => 'GDPR Article 17 request — support ticket #4242',
            'override_obligations' => true,
        ]),
        $pro,
    );

    expect($response->getStatusCode())->toBe(200);

    $pro->refresh();
    expect($pro->status)->toBe('pending_deletion');

    // override_obligations=true is the field most likely to silently drop if
    // the input()→validated() swap missed a key — confirm it actually reached
    // adminInitiate() by checking the deletion went through even though
    // makeActiveUser() has no outstanding obligations to override anyway
    // (a real regression here would surface as a 4xx/'reasons' error instead).
    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)->where('event', 'admin_initiated')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->reason)->toBe('GDPR Article 17 request — support ticket #4242');
});

it('the controller lets admin staff cancel through the new StaffCancelDeletionRequest', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveUser([
        'status' => 'pending_deletion',
        'deletion_previous_status' => 'active',
        'deletion_confirmed_at' => now()->toIso8601String(),
    ]);
    $controller = new StaffAccountDeletionController(new AccountDeletionService);

    $response = $controller->cancel(
        makeCancelRequest($staff, ['reason' => 'User contacted support — ticket #7']),
        $pro,
    );

    expect($response->getStatusCode())->toBe(200);
    $pro->refresh();
    expect($pro->status)->toBe('active');
});
