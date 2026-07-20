<?php

/**
 * SEC-5: BootstrapController::translateBootstrapException() hashes the applicant
 * email before logging the EMAIL_ALREADY_REGISTERED conflict — mirrors the
 * honeypot pattern in PublicEarlyAccessController::store(). This drives that log
 * line directly.
 *
 * Reaching EMAIL_ALREADY_REGISTERED through a genuine end-to-end request needs a
 * real concurrent write: UserBootstrapService::bootstrap() only reaches the
 * UniqueConstraintViolationException catch that throws it when a second
 * connection commits the colliding email mid-save (already covered, Postgres-only,
 * in tests/Feature/PublicSite/BootstrapEmailRaceTest.php) — the ordinary
 * single-request path can't reach it because BootstrapRequest normalises +
 * validates the email (Rule::unique) before the service ever runs. Reproducing
 * that race again here would just re-test the service's DB mechanics, not the
 * controller's log line. Instead this binds a mock UserBootstrapService that
 * throws the exact RuntimeException the real service throws on that race, so the
 * assertions below exercise BootstrapController's real catch + Log::info call —
 * the actual code this defect is about.
 */

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Services\User\UserBootstrapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
});

it('logs a hashed email — never the raw applicant email — when bootstrap rejects EMAIL_ALREADY_REGISTERED', function () {
    Log::spy();

    // hasExistingProfessional($uid) must be true or the controller 410s before
    // ever calling the service (create branch is retired — see BootstrapController
    // header comment).
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => 'target-uid',
        'handle' => 'targetuser',
        'handle_lc' => 'targetuser',
        'display_name' => 'Target User',
        'primary_email' => 'target@example.com',
        'account_type' => 'partna',
        'status' => 'active',
    ]);

    $mockService = Mockery::mock(UserBootstrapService::class);
    $mockService->shouldReceive('bootstrap')->once()->andThrow(new RuntimeException('EMAIL_ALREADY_REGISTERED'));
    app()->instance(UserBootstrapService::class, $mockService);

    // Mixed case + surrounding whitespace on purpose: proves the log line's
    // mb_strtolower(trim(...)) actually runs, not just that a hash is present.
    $email = '  Leaked-Applicant@Example.com  ';
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', [
        'primary_email' => $email,
        'display_name' => 'Target User',
        'handle' => 'targetuser',
    ]);
    $request->attributes->set('supabase_uid', 'target-uid');
    $request->attributes->set('supabase_claims', ['email' => $email]);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    $response = app(BootstrapController::class)->bootstrap($request);

    expect($response->getStatusCode())->toBe(409);
    expect($response->getData(true)['errors']['code'] ?? null)->toBe('EMAIL_ALREADY_REGISTERED');

    $expectedHash = hash('sha256', mb_strtolower(trim($email)));

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($expectedHash) {
            return $message === 'Bootstrap rejected: email already registered to another auth user'
                && ($context['email_hash'] ?? null) === $expectedHash
                && ($context['uid'] ?? null) === 'target-uid'
                && ! array_key_exists('email', $context);
        });
});
