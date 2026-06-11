<?php

use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffDataExportController;
use App\Http\Requests\Api\Staff\RequestStaffDataExportRequest;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\User\DataExport\DataExportTestCase;

beforeEach(function () {
    DataExportTestCase::boot();
    Queue::fake();
});

function seedStaff(string $role): PartnaStaff
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.partna_staff')->insert([
        'id' => $id,
        'role' => $role,
        'primary_email' => $role.'@sidest.io',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    return PartnaStaff::find($id);
}

function seedProForStaff(string $email = 'jane@example.com'): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'jane',
        'handle_lc' => 'jane',
        'display_name' => 'Jane',
        'primary_email' => $email,
        'status' => 'active',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    return User::find($id);
}

function makeStaffExportRequest(PartnaStaff $staff, string $sendTo = 'professional'): RequestStaffDataExportRequest
{
    $request = RequestStaffDataExportRequest::create('/?send_to='.$sendTo, 'POST');
    $request->attributes->set('partna_staff', $staff);

    return $request;
}

it('returns 202 with send_to=professional (default) for any staff role', function () {
    $staff = seedStaff('support');
    $pro = seedProForStaff();
    $controller = app(StaffDataExportController::class);

    $response = $controller->store(makeStaffExportRequest($staff, 'professional'), $pro);

    expect($response->getStatusCode())->toBe(202);
    $data = json_decode($response->getContent(), true);
    expect($data['send_to'])->toBe('professional');
    expect(DataExportAudit::where('user_id', $pro->id)->first()->recipient_email)
        ->toBe('jane@example.com');
});

it('returns 202 with send_to=staff when caller is admin', function () {
    $staff = seedStaff('admin');
    $pro = seedProForStaff();
    $controller = app(StaffDataExportController::class);

    $response = $controller->store(makeStaffExportRequest($staff, 'staff'), $pro);

    expect($response->getStatusCode())->toBe(202);
    $data = json_decode($response->getContent(), true);
    expect($data['send_to'])->toBe('staff');
    expect(DataExportAudit::where('user_id', $pro->id)->first()->recipient_email)
        ->toBe('admin@sidest.io');
});

it('returns 403 with send_to=staff when caller is non-admin', function () {
    $staff = seedStaff('support');
    $pro = seedProForStaff();
    $controller = app(StaffDataExportController::class);

    $response = $controller->store(makeStaffExportRequest($staff, 'staff'), $pro);

    expect($response->getStatusCode())->toBe(403);
    Queue::assertNothingPushed();
});

it('returns 409 when an export is already in flight (dedup applies to staff too)', function () {
    $staff = seedStaff('admin');
    $pro = seedProForStaff();

    DataExportAudit::create([
        'user_id' => $pro->id,
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
        'status' => 'queued',
    ]);

    $controller = app(StaffDataExportController::class);
    $response = $controller->store(makeStaffExportRequest($staff, 'professional'), $pro);

    expect($response->getStatusCode())->toBe(409);
});
