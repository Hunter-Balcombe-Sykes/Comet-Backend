<?php

/**
 * DISC-6: UserBootstrapService::bootstrap() classifies a concurrent
 * core_users_handle_lc_unique collision as a RuntimeException('HANDLE_ALREADY_TAKEN')
 * (see tests/Feature/PublicSite/BootstrapEmailRaceTest.php for the service-level
 * classifier coverage). This drives the controller side: translateBootstrapException()
 * must turn that into a friendly 409, not fall through to the generic Log::error +
 * re-throw (which the framework turns into a raw 500).
 *
 * Reaching this over a genuine end-to-end request needs a real concurrent write —
 * BootstrapRequest's Rule::unique(handle_lc) already rejects a single-request
 * collision before the service ever runs. Mirrors
 * BootstrapEmailAlreadyRegisteredLogTest's approach: bind a mock UserBootstrapService
 * that throws the exact exception the real service throws on that race, so the
 * assertions here exercise BootstrapController's real catch branch.
 */

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Services\User\UserBootstrapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
});

it('translates a HANDLE_ALREADY_TAKEN service exception into a 409 with the HANDLE_ALREADY_TAKEN code, not a raw 500', function () {
    // hasExistingProfessional($uid) must be true or the controller 410s before
    // ever calling the service (create branch is retired — see BootstrapController
    // header comment).
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => 'target-uid',
        'handle' => 'targetuser',
        'handle_lc' => 'targetuser',
        'display_name' => 'Target User',
        'first_name' => 'Targetuser',
        'primary_email' => 'target@example.com',
        'account_type' => 'partna',
        'status' => 'active',
    ]);

    $mockService = Mockery::mock(UserBootstrapService::class);
    $mockService->shouldReceive('bootstrap')->once()->andThrow(new RuntimeException('HANDLE_ALREADY_TAKEN'));
    app()->instance(UserBootstrapService::class, $mockService);

    $email = 'target@example.com';
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', [
        'primary_email' => $email,
        'display_name' => 'Target User',
        'handle' => 'someoneelsestaken',
    ]);
    $request->attributes->set('supabase_uid', 'target-uid');
    $request->attributes->set('supabase_claims', ['email' => $email]);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    $response = app(BootstrapController::class)->bootstrap($request);

    expect($response->getStatusCode())->toBe(409);
    expect($response->getData(true)['errors']['code'] ?? null)->toBe('HANDLE_ALREADY_TAKEN');
});
