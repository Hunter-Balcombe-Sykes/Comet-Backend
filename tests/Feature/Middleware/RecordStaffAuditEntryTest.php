<?php

use App\Http\Middleware\Logging\RecordStaffAuditEntry;
use App\Models\Core\User\User;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\Staff\StaffAuditEntry;
use App\Services\Audit\StaffAuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (\Throwable) {
    }
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS audit");
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        user_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL,
        http_method TEXT NOT NULL,
        status_code INTEGER NOT NULL,
        payload_summary TEXT NOT NULL DEFAULT "{}",
        ip TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

function makeAuditRequest(string $method, string $uri, array $bindings = []): Request
{
    $request = Request::create($uri, $method);
    $route = new RoutingRoute([$method], $uri, fn () => null);
    $route->name('staff.professionals.update');
    $route->parameters = $bindings;
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('records a row for POST/PATCH/PUT/DELETE writes', function (string $method) {
    $staff = new PartnaStaff();
    $staff->id = (string) Str::uuid();
    $staff->primary_email = 'support@partna.au';

    $professional = new User();
    $professional->id = (string) Str::uuid();
    $professional->handle = 'acme';

    $request = makeAuditRequest($method, '/staff/professionals/'.$professional->id, [
        'professional' => $professional,
    ]);
    $request->attributes->set('partna_staff', $staff);

    $middleware = new RecordStaffAuditEntry(new StaffAuditService());

    $response = new Response('', 200);
    $middleware->terminate($request, $response);

    expect(StaffAuditEntry::query()->count())->toBe(1);
    $row = StaffAuditEntry::query()->first();
    expect($row->http_method)->toBe($method)
        ->and($row->staff_id)->toBe($staff->id)
        ->and($row->user_id)->toBe($professional->id)
        ->and($row->professional_handle_snapshot)->toBe('acme')
        ->and($row->status_code)->toBe(200)
        ->and($row->payload_summary)->toBe(['professional' => $professional->id]);
})->with(['POST', 'PATCH', 'PUT', 'DELETE']);

it('skips GET/HEAD/OPTIONS requests', function (string $method) {
    $request = makeAuditRequest($method, '/staff/professionals');
    $middleware = new RecordStaffAuditEntry(new StaffAuditService());
    $middleware->terminate($request, new Response('', 200));

    expect(StaffAuditEntry::query()->count())->toBe(0);
})->with(['GET', 'HEAD', 'OPTIONS']);

it('records the row even when status code is 4xx', function () {
    $staff = new PartnaStaff();
    $staff->id = (string) Str::uuid();

    $request = makeAuditRequest('DELETE', '/staff/professionals/123/force', [
        'professional' => '123',
    ]);
    $request->attributes->set('partna_staff', $staff);

    $middleware = new RecordStaffAuditEntry(new StaffAuditService());
    $middleware->terminate($request, new Response('Forbidden', 403));

    $row = StaffAuditEntry::query()->first();
    expect($row)->not->toBeNull()
        ->and($row->status_code)->toBe(403);
});

it('records a null staff_id when partna_staff is missing from the request', function () {
    $request = makeAuditRequest('POST', '/staff/notifications');
    // No partna_staff attribute — simulating a write that somehow got past auth.

    $middleware = new RecordStaffAuditEntry(new StaffAuditService());
    $middleware->terminate($request, new Response('', 200));

    $row = StaffAuditEntry::query()->first();
    expect($row)->not->toBeNull()
        ->and($row->staff_id)->toBeNull();
});

it('accepts a string professional binding when route-model binding is not in effect', function () {
    $staff = new PartnaStaff();
    $staff->id = (string) Str::uuid();

    $userId = (string) Str::uuid();
    $request = makeAuditRequest('PATCH', '/staff/professionals/'.$userId, [
        'professional' => $userId,
    ]);
    $request->attributes->set('partna_staff', $staff);

    $middleware = new RecordStaffAuditEntry(new StaffAuditService());
    $middleware->terminate($request, new Response('', 200));

    $row = StaffAuditEntry::query()->first();
    expect($row->user_id)->toBe($userId)
        ->and($row->professional_handle_snapshot)->toBeNull();
});

it('serialises route bindings to scalar UUIDs in payload_summary', function () {
    $staff = new PartnaStaff();
    $staff->id = (string) Str::uuid();

    $professional = new User();
    $professional->id = (string) Str::uuid();
    $professional->handle = 'acme';

    $request = makeAuditRequest('PATCH', '/staff/professionals/'.$professional->id.'/services/abc', [
        'professional' => $professional,
        'service' => 'service-uuid-abc',
    ]);
    $request->attributes->set('partna_staff', $staff);

    $middleware = new RecordStaffAuditEntry(new StaffAuditService());
    $middleware->terminate($request, new Response('', 200));

    $row = StaffAuditEntry::query()->first();
    expect($row->payload_summary)->toBe([
        'professional' => $professional->id,
        'service' => 'service-uuid-abc',
    ]);
});
