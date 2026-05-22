<?php

use App\Http\Middleware\Context\EnforcePendingDeletionReadOnly;
use App\Models\Core\Professional\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Professional\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
});

function makeProWithStatus(string $status, ?string $confirmedAt = null): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'h-'.substr($id, 0, 6),
        'handle_lc' => 'h-'.substr($id, 0, 6),
        'display_name' => 'H',
        'primary_email' => 'h-'.substr($id, 0, 6).'@example.com',
        'status' => $status,
        'deletion_confirmed_at' => $confirmedAt,
    ]);

    return User::query()->where('id', $id)->first();
}

it('returns 423 on POST when status is pending_deletion', function () {
    $pro = makeProWithStatus('pending_deletion', now()->toIso8601String());
    $request = Request::create('/api/professional/me', 'POST');
    $request->attributes->set('professional', $pro);

    $middleware = new EnforcePendingDeletionReadOnly;
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(423);
    $body = json_decode($response->getContent(), true);
    expect($body['pending_deletion'])->toBeTrue()
        ->and($body['deletes_at'])->not->toBeEmpty();
});

it('returns 423 on PATCH when status is pending_deletion', function () {
    $pro = makeProWithStatus('pending_deletion', now()->toIso8601String());
    $request = Request::create('/api/professional/me', 'PATCH');
    $request->attributes->set('professional', $pro);

    $middleware = new EnforcePendingDeletionReadOnly;
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(423);
});

it('returns 423 on DELETE when status is pending_deletion', function () {
    $pro = makeProWithStatus('pending_deletion', now()->toIso8601String());
    $request = Request::create('/api/professional/me/services/xyz', 'DELETE');
    $request->attributes->set('professional', $pro);

    $middleware = new EnforcePendingDeletionReadOnly;
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(423);
});

it('passes through GET even when status is pending_deletion', function () {
    $pro = makeProWithStatus('pending_deletion', now()->toIso8601String());
    $request = Request::create('/api/professional/me', 'GET');
    $request->attributes->set('professional', $pro);

    $middleware = new EnforcePendingDeletionReadOnly;
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('passes through HEAD even when status is pending_deletion', function () {
    $pro = makeProWithStatus('pending_deletion', now()->toIso8601String());
    $request = Request::create('/api/professional/me', 'HEAD');
    $request->attributes->set('professional', $pro);

    $middleware = new EnforcePendingDeletionReadOnly;
    $response = $middleware->handle($request, fn () => response('ok'));

    // HEAD passes through — body stripping is an HTTP kernel concern, not middleware
    expect($response->status())->toBe(200);
});

it('passes through POST when status is active', function () {
    $pro = makeProWithStatus('active');
    $request = Request::create('/api/professional/me', 'POST');
    $request->attributes->set('professional', $pro);

    $middleware = new EnforcePendingDeletionReadOnly;
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('passes through when no professional attribute is set', function () {
    $request = Request::create('/api/professional/me', 'POST');

    $middleware = new EnforcePendingDeletionReadOnly;
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});
