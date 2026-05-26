<?php

use App\Http\Middleware\Context\LoadCurrentUser;
use Tests\TestCase;

uses(TestCase::class);
use App\Models\Core\User\User;
use App\Services\Cache\UserCacheService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->cache = Mockery::mock(UserCacheService::class);
    $this->middleware = new LoadCurrentUser($this->cache);
    $this->next = fn ($req) => new Response('ok', 200);
});

it('returns 401 when supabase_uid attribute is missing', function () {
    $request = Request::create('/test', 'GET');

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode($response->getContent(), true)['message'])->toBe('Missing uid');
});

it('returns 401 when supabase_uid is not a valid UUID', function () {
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', 'not-a-uuid');

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode($response->getContent(), true)['message'])->toBe('Invalid uid');
});

it('returns 401 for SQL injection attempt in supabase_uid', function () {
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', "1' OR '1'='1");

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});

it('returns 403 when no professional matches a valid UUID', function () {
    $uid = '550e8400-e29b-41d4-a716-446655440000';
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', $uid);

    $this->cache->shouldReceive('getByAuthId')->with($uid)->once()->andReturn(null);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(403);
});

it('proceeds when supabase_uid is a valid UUID and professional is found', function () {
    $uid = '550e8400-e29b-41d4-a716-446655440000';
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', $uid);

    $pro = new User(['status' => 'active']);
    $this->cache->shouldReceive('getByAuthId')->with($uid)->once()->andReturn($pro);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(200);
});

it('skips email sync when emails already match (case-insensitive)', function () {
    $uid = '550e8400-e29b-41d4-a716-446655440000';
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', $uid);
    $request->attributes->set('supabase_claims', [
        'email' => 'Josh@Example.com',
        'email_verified' => true,
    ]);

    // No save() would touch DB — if sync ran, this test would blow up with a
    // missing-connection error. Survival here proves the strcasecmp short-circuit fires.
    $pro = new User(['status' => 'active', 'primary_email' => 'josh@example.com']);
    $this->cache->shouldReceive('getByAuthId')->with($uid)->once()->andReturn($pro);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(200);
});

it('skips email sync when email_verified is false', function () {
    $uid = '550e8400-e29b-41d4-a716-446655440000';
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', $uid);
    $request->attributes->set('supabase_claims', [
        'email' => 'new@example.com',
        'email_verified' => false,
    ]);

    $pro = new User(['status' => 'active', 'primary_email' => 'old@example.com']);
    $this->cache->shouldReceive('getByAuthId')->with($uid)->once()->andReturn($pro);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(200)
        ->and($pro->primary_email)->toBe('old@example.com');
});

it('syncs primary_email when claim differs and is verified', function () {
    setupUsersTable();

    $uid = '550e8400-e29b-41d4-a716-446655440000';
    $proId = '00000000-0000-0000-0000-0000000000aa';

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'auth_user_id' => $uid,
        'primary_email' => 'old@example.com',
        'status' => 'active',
    ]);

    $pro = User::query()->where('id', $proId)->first();

    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', $uid);
    $request->attributes->set('supabase_claims', [
        'email' => 'new@example.com',
        'email_verified' => true,
    ]);

    $this->cache->shouldReceive('getByAuthId')->with($uid)->once()->andReturn($pro);
    $this->cache->shouldReceive('invalidateUser')->once();

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(200);

    $updated = DB::connection('pgsql')->table('core.users')->where('id', $proId)->value('primary_email');
    expect($updated)->toBe('new@example.com');
});

it('returns 403 when professional account is suspended', function () {
    $uid = '550e8400-e29b-41d4-a716-446655440000';
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', $uid);

    $pro = new User(['status' => 'suspended']);
    $this->cache->shouldReceive('getByAuthId')->with($uid)->once()->andReturn($pro);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(403);
});
