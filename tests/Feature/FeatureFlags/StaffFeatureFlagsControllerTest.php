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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\FeatureFlags\FeatureFlagTestCase;

beforeEach(function () {
    Cache::flush();
    FeatureFlagTestCase::boot();

    $this->staff = (new PartnaStaff)->forceFill(['id' => (string) Str::uuid(), 'role' => PartnaStaff::ROLE_ADMIN]);
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
 * @return T&MockInterface
 */
function mockFormRequest(string $class, array $validatedData, PartnaStaff $staff): mixed
{
    $mock = Mockery::mock($class)->makePartial();
    $mock->shouldReceive('validated')->andReturn($validatedData);

    // Symfony's $attributes is a typed property that must be initialised before
    // ->set() can be called on it. Use reflection to inject a fresh ParameterBag.
    $ref = new ReflectionClass(Symfony\Component\HttpFoundation\Request::class);
    $prop = $ref->getProperty('attributes');
    $prop->setValue($mock, new ParameterBag);

    $mock->attributes->set('partna_staff', $staff);

    return $mock;
}

// ── StaffFeatureFlagController::index ────────────────────────────────────────

it('index returns 401 when staff not on request', function () {
    $request = Request::create('/', 'GET');

    $this->expectException(HttpException::class);
    $this->flagController->index($request);
});

it('index returns 403 for a support-role staff actor', function () {
    $request = Request::create('/', 'GET');
    $support = (new PartnaStaff)->forceFill(['id' => (string) Str::uuid(), 'role' => PartnaStaff::ROLE_SUPPORT]);
    $request->attributes->set('partna_staff', $support);

    $response = $this->flagController->index($request);

    expect($response->status())->toBe(200);
});

// ── StaffFeatureFlagController::store ────────────────────────────────────────

it('store returns 401 when staff not on request', function () {
    $formRequest = CreateFeatureFlagRequest::create('/', 'POST', [
        'key' => 'no_staff_flag',
        'default_enabled' => false,
        'rollout_percent' => 0,
    ]);

    $this->expectException(HttpException::class);
    $this->flagController->store($formRequest);
});

it('store returns 403 for a support-role staff actor', function () {
    $support = (new PartnaStaff)->forceFill(['id' => (string) Str::uuid(), 'role' => PartnaStaff::ROLE_SUPPORT]);
    $formRequest = mockFormRequest(CreateFeatureFlagRequest::class, [
        'key' => 'support_denied_flag',
        'description' => null,
        'default_enabled' => false,
        'rollout_percent' => 0,
    ], $support);

    $this->expectException(AuthorizationException::class);
    $this->flagController->store($formRequest);
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
    FeatureFlag::create(['key' => 'no_staff_update_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    $formRequest = UpdateFeatureFlagRequest::create('/', 'PATCH', ['rollout_percent' => 10]);

    $this->expectException(HttpException::class);
    $this->flagController->update($formRequest, 'no_staff_update_flag');
});

it('update returns 403 for a support-role staff actor', function () {
    FeatureFlag::create(['key' => 'support_denied_update_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    $support = (new PartnaStaff)->forceFill(['id' => (string) Str::uuid(), 'role' => PartnaStaff::ROLE_SUPPORT]);
    $formRequest = mockFormRequest(UpdateFeatureFlagRequest::class, ['rollout_percent' => 10], $support);

    $this->expectException(AuthorizationException::class);
    $this->flagController->update($formRequest, 'support_denied_update_flag');
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
    FeatureFlag::create(['key' => 'no_staff_destroy_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    $request = Request::create('/', 'DELETE');

    $this->expectException(HttpException::class);
    $this->flagController->destroy($request, 'no_staff_destroy_flag');
});

it('destroy returns 403 for a support-role staff actor', function () {
    FeatureFlag::create(['key' => 'support_denied_destroy_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    $support = (new PartnaStaff)->forceFill(['id' => (string) Str::uuid(), 'role' => PartnaStaff::ROLE_SUPPORT]);
    $request = Request::create('/', 'DELETE');
    $request->attributes->set('partna_staff', $support);

    $this->expectException(AuthorizationException::class);
    $this->flagController->destroy($request, 'support_denied_destroy_flag');
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

    $this->expectException(ModelNotFoundException::class);
    $this->flagController->destroy($request, 'nonexistent_flag');
});

// ── StaffFeatureFlagOverrideController::index ─────────────────────────────────

it('override index returns 401 when staff not on request', function () {
    $request = Request::create('/', 'GET');

    $this->expectException(HttpException::class);
    $this->overrideController->index($request, 'any_flag');
});

it('override index allows a support-role staff actor', function () {
    FeatureFlag::create(['key' => 'support_view_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    $support = (new PartnaStaff)->forceFill(['id' => (string) Str::uuid(), 'role' => PartnaStaff::ROLE_SUPPORT]);
    $request = Request::create('/', 'GET');
    $request->attributes->set('partna_staff', $support);

    $response = $this->overrideController->index($request, 'support_view_flag');

    expect($response->status())->toBe(200);
});

// ── StaffFeatureFlagOverrideController::store ─────────────────────────────────

it('override store returns 401 when staff not on request', function () {
    $request = CreateOverrideRequest::create('/', 'POST', [
        'user_id' => (string) Str::uuid(),
        'enabled' => true,
    ]);

    $this->expectException(HttpException::class);
    $this->overrideController->store($request, 'any_flag');
});

it('override store returns 403 for a support-role staff actor', function () {
    FeatureFlag::create(['key' => 'support_denied_override_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    $support = (new PartnaStaff)->forceFill(['id' => (string) Str::uuid(), 'role' => PartnaStaff::ROLE_SUPPORT]);
    $formRequest = mockFormRequest(CreateOverrideRequest::class, [
        'user_id' => (string) Str::uuid(),
        'enabled' => true,
    ], $support);

    $this->expectException(AuthorizationException::class);
    $this->overrideController->store($formRequest, 'support_denied_override_flag');
});

it('store override creates a professional override', function () {
    FeatureFlag::create(['key' => 'override_flag', 'default_enabled' => false, 'rollout_percent' => 0]);

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'override-pro',
        'handle_lc' => 'override-pro',
        'display_name' => 'Override Pro',
        'first_name' => 'Override',
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

    $this->expectException(HttpException::class);
    $this->overrideController->destroy($request, (string) Str::uuid());
});

it('override destroy returns 403 for a support-role staff actor', function () {
    FeatureFlag::create(['key' => 'destroy_ov_flag_support', 'default_enabled' => false, 'rollout_percent' => 0]);

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'support-denied-pro',
        'handle_lc' => 'support-denied-pro',
        'display_name' => 'Support Denied Pro',
        'first_name' => 'Support',
        'primary_email' => 'support-denied@example.com',
        'status' => 'active',
    ]);

    $overrideId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.feature_flag_overrides')->insert([
        'id' => $overrideId,
        'flag_key' => 'destroy_ov_flag_support',
        'user_id' => $proId,
        'enabled' => 1,
        'reason' => 'support denied',
        'created_by' => (string) Str::uuid(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $support = (new PartnaStaff)->forceFill(['id' => (string) Str::uuid(), 'role' => PartnaStaff::ROLE_SUPPORT]);
    $request = Request::create('/', 'DELETE');
    $request->attributes->set('partna_staff', $support);

    $this->expectException(AuthorizationException::class);
    $this->overrideController->destroy($request, $overrideId);
});

it('destroy override removes the override', function () {
    FeatureFlag::create(['key' => 'destroy_ov_flag', 'default_enabled' => false, 'rollout_percent' => 0]);

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'destroy-pro',
        'handle_lc' => 'destroy-pro',
        'display_name' => 'Destroy Pro',
        'first_name' => 'Destroy',
        'primary_email' => 'destroy@example.com',
        'status' => 'active',
    ]);

    $overrideId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.feature_flag_overrides')->insert([
        'id' => $overrideId,
        'flag_key' => 'destroy_ov_flag',
        'user_id' => $proId,
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
