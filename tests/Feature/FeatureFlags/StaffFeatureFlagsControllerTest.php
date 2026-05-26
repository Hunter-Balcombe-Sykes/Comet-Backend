<?php

use App\Http\Controllers\Api\Staff\FeatureFlag\StaffFeatureFlagController;
use App\Http\Controllers\Api\Staff\FeatureFlag\StaffFeatureFlagOverrideController;
use App\Http\Requests\Api\Staff\FeatureFlag\CreateFeatureFlagRequest;
use App\Http\Requests\Api\Staff\FeatureFlag\CreateOverrideRequest;
use App\Http\Requests\Api\Staff\FeatureFlag\UpdateFeatureFlagRequest;
use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Staff\PartnaStaff;
use App\Services\FeatureFlags\FeatureFlagService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureFlags\FeatureFlagTestCase;

beforeEach(function () {
    Cache::flush();
    FeatureFlagTestCase::boot();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.partna_staff (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT,
        role TEXT,
        name TEXT,
        primary_email TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $this->staff = (new PartnaStaff)->forceFill(['id' => (string) Str::uuid()]);
    $this->service = app(FeatureFlagService::class);
    $this->flagController = new StaffFeatureFlagController($this->service);
    $this->overrideController = new StaffFeatureFlagOverrideController($this->service);
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Build a mocked FormRequest that returns $validatedData from ->validated().
 * This sidesteps DB-backed unique/exists rules during controller tests.
 *
 * We create a real Request so $attributes is initialised, then attach a
 * partial Mockery override just for ->validated().
 *
 * @template T of \Illuminate\Foundation\Http\FormRequest
 *
 * @param  class-string<T>  $class
 * @param  array<string,mixed>  $validatedData
 * @return T&\Mockery\MockInterface
 */
function mockFormRequest(string $class, array $validatedData, PartnaStaff $staff): mixed
{
    $mock = Mockery::mock($class)->makePartial();
    $mock->shouldReceive('validated')->andReturn($validatedData);

    // Symfony's $attributes is a typed property that must be initialised before
    // ->set() can be called on it. Use reflection to inject a fresh ParameterBag.
    $ref = new \ReflectionClass(\Symfony\Component\HttpFoundation\Request::class);
    $prop = $ref->getProperty('attributes');
    $prop->setValue($mock, new \Symfony\Component\HttpFoundation\ParameterBag);

    $mock->attributes->set('partna_staff', $staff);

    return $mock;
}

// ── StaffFeatureFlagController::index ────────────────────────────────────────

it('index returns 401 when staff not on request', function () {
    $request = Request::create('/', 'GET');

    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->flagController->index($request);
});

// ── StaffFeatureFlagController::store ────────────────────────────────────────

it('store returns 401 when staff not on request', function () {
    $request = CreateFeatureFlagRequest::create('/', 'POST', []);

    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->flagController->store($request);
});

it('store creates a flag with valid data', function () {
    $formRequest = mockFormRequest(CreateFeatureFlagRequest::class, [
        'key' => 'new_flag',
        'description' => 'A new flag',
        'default_enabled' => false,
        'rollout_percent' => 50,
    ], $this->staff);

    $response = $this->flagController->store($formRequest);
    $payload = json_decode($response->getContent(), true);

    expect($response->status())->toBe(201);
    expect($payload['data']['key'])->toBe('new_flag');
    expect($payload['data']['rollout_percent'])->toBe(50);
    expect(FeatureFlag::find('new_flag'))->not->toBeNull();
});

it('store validation rejects key with uppercase letters', function () {
    $rules = (new CreateFeatureFlagRequest)->rules();
    // Override the unique rule to avoid DB lookup in SQLite test environment.
    $rules['key'] = array_filter($rules['key'], fn ($r) => ! (is_string($r) && str_starts_with($r, 'unique:')));
    $validator = validator(['key' => 'Bad_Key', 'default_enabled' => false, 'rollout_percent' => 0], $rules);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('key'))->toBeTrue();
});

it('store validation rejects key with hyphens', function () {
    $rules = (new CreateFeatureFlagRequest)->rules();
    $rules['key'] = array_filter($rules['key'], fn ($r) => ! (is_string($r) && str_starts_with($r, 'unique:')));
    $validator = validator(['key' => 'bad-key', 'default_enabled' => false, 'rollout_percent' => 0], $rules);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('key'))->toBeTrue();
});

it('store validation accepts valid lowercase_underscore key', function () {
    $rules = (new CreateFeatureFlagRequest)->rules();
    $rules['key'] = array_filter($rules['key'], fn ($r) => ! (is_string($r) && str_starts_with($r, 'unique:')));
    $validator = validator(['key' => 'good_key', 'default_enabled' => true, 'rollout_percent' => 0], $rules);

    expect($validator->fails())->toBeFalse();
});

// ── StaffFeatureFlagController::update ───────────────────────────────────────

it('update returns 401 when staff not on request', function () {
    $request = UpdateFeatureFlagRequest::create('/', 'PATCH', ['rollout_percent' => 50]);

    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->flagController->update($request, 'any_flag');
});

it('update changes rollout_percent on an existing flag', function () {
    FeatureFlag::create(['key' => 'update_flag', 'default_enabled' => true, 'rollout_percent' => 10]);

    $formRequest = mockFormRequest(UpdateFeatureFlagRequest::class, ['rollout_percent' => 75], $this->staff);

    $response = $this->flagController->update($formRequest, 'update_flag');
    $payload = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200);
    expect($payload['data']['rollout_percent'])->toBe(75);
    expect(FeatureFlag::find('update_flag')->rollout_percent)->toBe(75);
});

it('update changes default_enabled on an existing flag', function () {
    FeatureFlag::create(['key' => 'update_flag_de', 'default_enabled' => true, 'rollout_percent' => 0]);

    $formRequest = mockFormRequest(UpdateFeatureFlagRequest::class, ['default_enabled' => false], $this->staff);

    $response = $this->flagController->update($formRequest, 'update_flag_de');
    $payload = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200);
    expect($payload['data']['default_enabled'])->toBeFalse();
    expect((bool) FeatureFlag::find('update_flag_de')->default_enabled)->toBeFalse();
});

it('update changes description on an existing flag', function () {
    FeatureFlag::create(['key' => 'update_flag_desc', 'default_enabled' => false, 'rollout_percent' => 0, 'description' => 'old']);

    $formRequest = mockFormRequest(UpdateFeatureFlagRequest::class, ['description' => 'updated description'], $this->staff);

    $response = $this->flagController->update($formRequest, 'update_flag_desc');
    $payload = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200);
    expect($payload['data']['description'])->toBe('updated description');
});

// ── StaffFeatureFlagController::destroy ──────────────────────────────────────

it('destroy returns 401 when staff not on request', function () {
    $request = Request::create('/', 'DELETE');

    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->flagController->destroy($request, 'any_flag');
});

it('destroy soft-deletes a flag', function () {
    FeatureFlag::create(['key' => 'delete_flag', 'default_enabled' => false, 'rollout_percent' => 0]);

    $request = Request::create('/', 'DELETE');
    $request->attributes->set('partna_staff', $this->staff);

    $response = $this->flagController->destroy($request, 'delete_flag');

    expect($response->status())->toBe(204);
    expect(FeatureFlag::find('delete_flag'))->toBeNull();
    expect(FeatureFlag::withTrashed()->find('delete_flag'))->not->toBeNull();
});

it('destroy throws ModelNotFoundException for unknown flag', function () {
    $request = Request::create('/', 'DELETE');
    $request->attributes->set('partna_staff', $this->staff);

    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $this->flagController->destroy($request, 'nonexistent_flag');
});

// ── StaffFeatureFlagOverrideController::index ─────────────────────────────────

it('override index returns 401 when staff not on request', function () {
    $request = Request::create('/', 'GET');

    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->overrideController->index($request, 'any_flag');
});

// ── StaffFeatureFlagOverrideController::store ─────────────────────────────────

it('override store returns 401 when staff not on request', function () {
    $request = CreateOverrideRequest::create('/', 'POST', [
        'user_id' => (string) Str::uuid(),
        'enabled' => true,
    ]);

    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->overrideController->store($request, 'any_flag');
});

it('store override creates a professional override', function () {
    FeatureFlag::create(['key' => 'override_flag', 'default_enabled' => false, 'rollout_percent' => 0]);

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'override-pro',
        'display_name' => 'Override Pro',
        'primary_email' => 'override@example.com',
        'status' => 'active',
    ]);

    $formRequest = mockFormRequest(CreateOverrideRequest::class, [
        'user_id' => $proId,
        'enabled' => true,
        'reason' => 'Testing override creation',
    ], $this->staff);

    $response = $this->overrideController->store($formRequest, 'override_flag');
    $payload = json_decode($response->getContent(), true);

    expect($response->status())->toBe(201);
    expect($payload['data']['user_id'])->toBe($proId);
    expect($payload['data']['enabled'])->toBeTrue();
});


// ── StaffFeatureFlagOverrideController::destroy ───────────────────────────────

it('override destroy returns 401 when staff not on request', function () {
    $request = Request::create('/', 'DELETE');

    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->overrideController->destroy($request, (string) Str::uuid());
});

it('destroy override removes the override', function () {
    FeatureFlag::create(['key' => 'destroy_ov_flag', 'default_enabled' => false, 'rollout_percent' => 0]);

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'destroy-pro',
        'display_name' => 'Destroy Pro',
        'primary_email' => 'destroy@example.com',
        'status' => 'active',
    ]);

    $overrideId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.feature_flag_overrides')->insert([
        'id' => $overrideId,
        'flag_key' => 'destroy_ov_flag',
        'user_id' => $proId,
        'brand_id' => null,
        'enabled' => 1,
        'reason' => 'to be deleted',
        'created_by' => (string) Str::uuid(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $request = Request::create('/', 'DELETE');
    $request->attributes->set('partna_staff', $this->staff);

    $response = $this->overrideController->destroy($request, $overrideId);

    expect($response->status())->toBe(204);
    expect(FeatureFlagOverride::find($overrideId))->toBeNull();
});
