<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliateStatusController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('returns 404 when affiliate does not belong to the brand', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'status' => 'active']);

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('whereNull')->andReturnSelf();
    $mockQuery->shouldReceive('exists')->andReturn(false);

    DB::shouldReceive('table')->with('brand.brand_partner_links')->andReturn($mockQuery);

    $controller = new StaffAffiliateStatusController;
    $request = Request::create('/', 'PATCH', ['status' => 'suspended']);

    $response = $controller->update($request, $brand, $affiliate);

    expect($response->status())->toBe(404);
});

it('suspends an affiliate that belongs to the brand', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = Mockery::mock(Professional::class)->makePartial();
    $affiliate->id = (string) Str::uuid();
    $affiliate->status = 'active';
    $affiliate->shouldReceive('save')->once();
    $affiliate->shouldReceive('fresh')->andReturnSelf();

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('whereNull')->andReturnSelf();
    $mockQuery->shouldReceive('exists')->andReturn(true);

    DB::shouldReceive('table')->with('brand.brand_partner_links')->andReturn($mockQuery);

    $controller = new StaffAffiliateStatusController;
    $request = Request::create('/', 'PATCH', ['status' => 'suspended']);

    $response = $controller->update($request, $brand, $affiliate);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKey('professional');
});

it('reactivates a suspended affiliate', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = Mockery::mock(Professional::class)->makePartial();
    $affiliate->id = (string) Str::uuid();
    $affiliate->status = 'suspended';
    $affiliate->shouldReceive('save')->once();
    $affiliate->shouldReceive('fresh')->andReturnSelf();

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('whereNull')->andReturnSelf();
    $mockQuery->shouldReceive('exists')->andReturn(true);

    DB::shouldReceive('table')->with('brand.brand_partner_links')->andReturn($mockQuery);

    $controller = new StaffAffiliateStatusController;
    $request = Request::create('/', 'PATCH', ['status' => 'active']);

    $response = $controller->update($request, $brand, $affiliate);

    expect($response->status())->toBe(200);
});

it('rejects invalid status values', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = new Professional(['id' => (string) Str::uuid()]);

    $controller = new StaffAffiliateStatusController;
    $request = Request::create('/', 'PATCH', ['status' => 'banned']);

    expect(fn () => $controller->update($request, $brand, $affiliate))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
