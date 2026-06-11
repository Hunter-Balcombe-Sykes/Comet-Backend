<?php

use App\Http\Middleware\Auth\EnsurePartnaStaff;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Helper: build a request with a pre-resolved staff record (bypasses DB lookup).
function makeStaffRequest(PartnaStaff $staff): Request
{
    $request = Request::create('/', 'GET');
    $request->attributes->set('supabase_uid', 'test-uid');
    $request->attributes->set('partna_staff', $staff);

    return $request;
}

function makeStaffInstance(string $role): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = $role;

    return $staff;
}

it('passes through when no role restriction is set', function () {
    $middleware = new EnsurePartnaStaff;
    $request = makeStaffRequest(makeStaffInstance(PartnaStaff::ROLE_SUPPORT));

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});

it('passes through when staff matches the required role', function () {
    $middleware = new EnsurePartnaStaff;
    $request = makeStaffRequest(makeStaffInstance(PartnaStaff::ROLE_ADMIN));

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), PartnaStaff::ROLE_ADMIN);

    expect($response->getStatusCode())->toBe(200);
});

it('returns 403 when staff role does not match the required role', function () {
    $middleware = new EnsurePartnaStaff;
    $request = makeStaffRequest(makeStaffInstance(PartnaStaff::ROLE_SUPPORT));

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), PartnaStaff::ROLE_ADMIN);

    expect($response->getStatusCode())->toBe(403);
    $data = json_decode($response->getContent(), true);
    expect($data['message'])->toBe('Insufficient staff role');
});

it('returns 401 when no supabase uid is present', function () {
    $middleware = new EnsurePartnaStaff;
    $request = Request::create('/', 'GET');

    $response = $middleware->handle($request, fn () => new Response('ok'));

    expect($response->getStatusCode())->toBe(401);
});

it('accepts multiple allowed roles (any match passes)', function () {
    $middleware = new EnsurePartnaStaff;
    $request = makeStaffRequest(makeStaffInstance(PartnaStaff::ROLE_SUPPORT));

    $response = $middleware->handle(
        $request,
        fn () => response()->json(['ok' => true]),
        PartnaStaff::ROLE_SUPPORT,
        PartnaStaff::ROLE_ADMIN
    );

    expect($response->getStatusCode())->toBe(200);
});

// The next two tests exercise the DB lookup path (no pre-set partna_staff attribute).
// They verify the middleware is fail-closed: a valid Supabase UID alone is not enough —
// a matching PartnaStaff DB record must also exist.

it('returns 403 when supabase uid has no matching PartnaStaff record in DB', function () {
    setupPartnaStaffTable();

    $middleware = new EnsurePartnaStaff;
    $request = Request::create('/', 'GET');
    $request->attributes->set('supabase_uid', 'uid-with-no-staff-record');

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
    $data = json_decode($response->getContent(), true);
    expect($data['message'])->toBe('Staff access required');
});

it('passes through when supabase uid maps to a PartnaStaff record in DB', function () {
    setupPartnaStaffTable();

    $uid = 'uid-with-staff-record';
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.partna_staff')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => $uid,
        'role' => PartnaStaff::ROLE_SUPPORT,
        'primary_email' => 'staff@example.test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $middleware = new EnsurePartnaStaff;
    $request = Request::create('/', 'GET');
    $request->attributes->set('supabase_uid', $uid);

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});
