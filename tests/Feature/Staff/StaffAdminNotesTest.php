<?php

use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffUserController;
use App\Http\Requests\Api\Staff\UserSite\StaffUpdateUserRequest;
use App\Http\Resources\UserDashboardResource;
use App\Http\Resources\UserStaffResource;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.users (
        id TEXT PRIMARY KEY,
        handle TEXT,
        display_name TEXT,
        professional_type TEXT,
        account_type TEXT NULL,
        status TEXT,
        admin_notes TEXT,
        about TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    // UserDashboardResource (the self-service /me resource) reads the `site`
    // relationship, so the table must exist for its query to run (returns null here).
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS site");
    } catch (Throwable) {
    }
    $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        subdomain TEXT NULL,
        custom_domain TEXT NULL,
        custom_domain_status TEXT NULL,
        custom_domain_primary INTEGER NULL,
        deleted_at TEXT NULL
    )');
});

it('accepts admin_notes through the staff update form request', function () {
    $request = StaffUpdateUserRequest::create('/', 'PATCH', [
        'admin_notes' => 'VIP brand — do not suspend',
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));

    $validated = $request->validateResolved() ?? $request->validated();

    expect($request->validated())->toHaveKey('admin_notes')
        ->and($request->validated()['admin_notes'])->toBe('VIP brand — do not suspend');
});

it('persists admin_notes when staff PATCHes the professional', function () {
    DB::table('core.users')->insert([
        'id' => $id = (string) Str::uuid(),
        'handle' => 'test',
        'display_name' => 'Test Brand',
        'status' => 'active',
    ]);

    $professional = User::query()->findOrFail($id);

    $request = StaffUpdateUserRequest::create('/', 'PATCH', [
        'admin_notes' => 'DMCA pending — flag any takedown requests',
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    // The write methods now enforce UserSelfPolicy::staffManage (admin-only, #P2-03).
    // This direct controller call bypasses the staff middleware that normally sets
    // the attribute, so inject an admin actor the same way the middleware would.
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $request->attributes->set('partna_staff', $staff);

    $controller = new StaffUserController;
    $controller->update($request, $professional);

    $fresh = User::query()->findOrFail($id);
    expect($fresh->admin_notes)->toBe('DMCA pending — flag any takedown requests');
});

it('exposes admin_notes in staff resource but not in self-service resource', function () {
    $professional = new User;
    $professional->id = (string) Str::uuid();
    $professional->admin_notes = 'Internal: do not contact this brand directly';
    $professional->display_name = 'Test';

    $staffShape = (new UserStaffResource($professional))->toArray(request());
    $selfShape = (new UserDashboardResource($professional))->toArray(request());

    expect($staffShape)->toHaveKey('admin_notes')
        ->and($staffShape['admin_notes'])->toBe('Internal: do not contact this brand directly')
        ->and($selfShape)->not->toHaveKey('admin_notes');
});

it('rejects admin_notes longer than 5000 chars', function () {
    $request = StaffUpdateUserRequest::create('/', 'PATCH', [
        'admin_notes' => str_repeat('a', 5001),
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));

    expect(fn () => $request->validateResolved())
        ->toThrow(ValidationException::class);
});
