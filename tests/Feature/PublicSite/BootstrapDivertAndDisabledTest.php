<?php

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupWaitlistTable();
})->group('bootstrap-divert-and-disabled');

// Task 14: the individual-waitlist divert only ever fired for
// `! hasExistingProfessional($uid)` callers — such callers now 410
// SIGNUP_MOVED before the divert block is reached, so the block was removed
// as dead code (no successor; the divert had no analogue on the build
// endpoint and simply retires with the create branch). This single test
// replaces the two former divert-specific tests (the "writes a clean divert
// row" happy path and the "P1-A" no-clobber regression guard) — both are now
// moot: a new signup attempt never reaches the waitlist_signups write at all,
// pre-existing or not.
it('no longer diverts a new signup attempt to the individual waitlist — 410s SIGNUP_MOVED and writes no row (divert block retired)', function () {
    config(['partna.individual_waitlist_enabled' => true]);
    config(['partna.waitlist.enabled' => false]);

    $controller = app(BootstrapController::class);
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', [
        'primary_email' => 'newdivert@example.com',
        'first_name' => 'Casey',
        'last_name' => 'Wright',
    ]);
    $request->attributes->set('supabase_uid', 'new-divert-uid');
    $request->attributes->set('supabase_claims', ['email' => 'newdivert@example.com']);

    $response = $controller->bootstrap($request);

    expect($response->getStatusCode())->toBe(410);
    expect($response->getData(true)['code'] ?? null)->toBe('SIGNUP_MOVED');

    $row = DB::connection('pgsql')->table('core.waitlist_signups')
        ->where('email_lc', 'newdivert@example.com')->first();

    expect($row)->toBeNull();
});

it('returns 403 ACCOUNT_DISABLED for disabled accounts (not 200 with empty body)', function () {
    config(['partna.individual_waitlist_enabled' => false]);
    config(['partna.waitlist.enabled' => false]);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => '00000000-0000-0000-0000-0000000000aa',
        'auth_user_id' => 'disabled-uid',
        'primary_email' => 'disabled@example.com',
        'handle' => 'disableduser',
        'handle_lc' => 'disableduser',
        'display_name' => 'Disabled User',
        'account_type' => 'individual',
        'status' => 'disabled',
    ]);

    $controller = app(BootstrapController::class);
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', [
        'primary_email' => 'disabled@example.com',
        'display_name' => 'Disabled User',
        'handle' => 'disableduser',
    ]);
    $request->attributes->set('supabase_uid', 'disabled-uid');
    $request->attributes->set('supabase_claims', ['email' => 'disabled@example.com']);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    $response = $controller->bootstrap($request);

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors']['code'] ?? null)->toBe('ACCOUNT_DISABLED');
});
