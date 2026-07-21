<?php

use App\Http\Middleware\Context\LoadCurrentUser;
use Tests\TestCase;

uses(TestCase::class);
use App\Models\Core\User\User;
use App\Services\Cache\UserCacheService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;

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

// #LIFE-7 — invalidateUser() must NOT share the save()/UniqueConstraintViolationException
// catch. A Redis blip here is a different failure mode from an email collision — the DB
// write has already committed, so the request must still return 200, and the fault must
// be reported to Nightwatch (not just logged) rather than 500ing on a healthy write.
it('returns 200 and keeps the saved email when invalidateUser() throws after save() already committed', function () {
    setupUsersTable();
    Exceptions::fake();

    $uid = '550e8400-e29b-41d4-a716-446655440000';
    $proId = '00000000-0000-0000-0000-0000000000cc';

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
    // A Redis blip, not a DB collision — narrow UniqueConstraintViolationException
    // catch on save() must not swallow this; it needs its own try/catch.
    $this->cache->shouldReceive('invalidateUser')->once()->andThrow(new RuntimeException('redis down'));

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(200);

    // The write already committed before invalidateUser() ran — it must survive.
    $updated = DB::connection('pgsql')->table('core.users')->where('id', $proId)->value('primary_email');
    expect($updated)->toBe('new@example.com');

    Exceptions::assertReported(RuntimeException::class);
});

it('swallows a UniqueConstraintViolationException on email collision, leaves the row unchanged, and logs a hashed email', function () {
    setupUsersTable();

    // Production has a partial unique index on primary_email (migration
    // 20260526000000: `users_email_unique ... WHERE deleted_at IS NULL`) — the
    // test schema doesn't carry it by default, so mirror it here to make the
    // collision below a genuine DB-level violation rather than a mocked one.
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS core.users_email_unique_test ON users (primary_email) WHERE deleted_at IS NULL'
    );

    $uid = '550e8400-e29b-41d4-a716-446655440000';
    $proAId = '00000000-0000-0000-0000-0000000000aa';
    $proBId = '00000000-0000-0000-0000-0000000000bb';
    $collidingEmail = 'shared@example.com';

    // User A already owns the email that user B's verified claim will collide with.
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proAId,
        'auth_user_id' => '11111111-1111-1111-1111-111111111111',
        'primary_email' => $collidingEmail,
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proBId,
        'auth_user_id' => $uid,
        'primary_email' => 'b-old@example.com',
        'status' => 'active',
    ]);

    $proB = User::query()->where('id', $proBId)->first();

    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', $uid);
    $request->attributes->set('supabase_claims', [
        'email' => $collidingEmail,
        'email_verified' => true,
    ]);

    $this->cache->shouldReceive('getByAuthId')->with($uid)->once()->andReturn($proB);
    // save() throws before invalidateUser() is reached — the catch must skip it.
    $this->cache->shouldNotReceive('invalidateUser');

    Log::spy();

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(200);

    // User B's row is untouched — the collision was swallowed, not applied.
    $updated = DB::connection('pgsql')->table('core.users')->where('id', $proBId)->value('primary_email');
    expect($updated)->toBe('b-old@example.com');

    // Same HMAC-SHA256 scheme as SupabaseEmailEventService::hashEmail.
    $expectedHash = hash_hmac('sha256', strtolower($collidingEmail), config('app.key'));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($expectedHash, $proBId) {
            return $message === 'LoadCurrentUser email sync collision'
                && ! array_key_exists('attempted_email', $context)
                && ($context['attempted_email_hash'] ?? null) === $expectedHash
                && ($context['user_id'] ?? null) === $proBId;
        });
});

it('returns 403 when professional account is suspended', function () {
    $uid = '550e8400-e29b-41d4-a716-446655440000';
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', $uid);

    $pro = (new User)->forceFill(['status' => 'suspended']); // B11 SEC-2: status no longer fillable
    $this->cache->shouldReceive('getByAuthId')->with($uid)->once()->andReturn($pro);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(403);
});
