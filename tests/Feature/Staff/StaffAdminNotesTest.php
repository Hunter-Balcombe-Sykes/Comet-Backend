<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffProfessionalController;
use App\Http\Requests\Api\Staff\ProfessionalSite\StaffUpdateProfessionalRequest;
use App\Http\Resources\ProfessionalResource;
use App\Http\Resources\ProfessionalStaffResource;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        handle TEXT,
        display_name TEXT,
        professional_type TEXT,
        account_type TEXT NULL,
        has_historical_partner_links INTEGER NULL,
        status TEXT,
        admin_notes TEXT,
        about TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('accepts admin_notes through the staff update form request', function () {
    $request = StaffUpdateProfessionalRequest::create('/', 'PATCH', [
        'admin_notes' => 'VIP brand — do not suspend',
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));

    $validated = $request->validateResolved() ?? $request->validated();

    expect($request->validated())->toHaveKey('admin_notes')
        ->and($request->validated()['admin_notes'])->toBe('VIP brand — do not suspend');
});

it('persists admin_notes when staff PATCHes the professional', function () {
    DB::table('core.professionals')->insert([
        'id' => $id = (string) Str::uuid(),
        'handle' => 'test',
        'display_name' => 'Test Brand',
        'professional_type' => 'brand',
        'status' => 'active',
    ]);

    $professional = Professional::query()->findOrFail($id);

    $request = StaffUpdateProfessionalRequest::create('/', 'PATCH', [
        'admin_notes' => 'DMCA pending — flag any takedown requests',
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    $controller = new StaffProfessionalController;
    $controller->update($request, $professional);

    $fresh = Professional::query()->findOrFail($id);
    expect($fresh->admin_notes)->toBe('DMCA pending — flag any takedown requests');
});

it('exposes admin_notes in staff resource but not in self-service resource', function () {
    $professional = new Professional;
    $professional->id = (string) Str::uuid();
    $professional->admin_notes = 'Internal: do not contact this brand directly';
    $professional->display_name = 'Test';

    $staffShape = (new ProfessionalStaffResource($professional))->toArray(request());
    $selfShape = (new ProfessionalResource($professional))->toArray(request());

    expect($staffShape)->toHaveKey('admin_notes')
        ->and($staffShape['admin_notes'])->toBe('Internal: do not contact this brand directly')
        ->and($selfShape)->not->toHaveKey('admin_notes');
});

it('rejects admin_notes longer than 5000 chars', function () {
    $request = StaffUpdateProfessionalRequest::create('/', 'PATCH', [
        'admin_notes' => str_repeat('a', 5001),
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));

    expect(fn () => $request->validateResolved())
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
