<?php

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
})->group('bootstrap-disabled');

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
        'first_name' => 'Disableduser',
        'account_type' => 'partna',
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
