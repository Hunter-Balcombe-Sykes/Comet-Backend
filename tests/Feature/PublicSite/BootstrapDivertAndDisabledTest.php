<?php

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupProfessionalsTable();
    setupWaitlistTable();
})->group('bootstrap-divert-and-disabled');

it('writes a clean divert row to core.waitlist_signups when individual_waitlist_enabled is on', function () {
    config(['partna.individual_waitlist_enabled' => true]);
    config(['partna.waitlist.enabled' => false]);

    $controller = app(BootstrapController::class);
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', [
        'primary_email' => 'newdivert@example.com',
        'first_name' => 'Casey',
        'last_name' => 'Wright',
    ]);
    $request->attributes->set('supabase_uid', 'new-divert-uid');

    $response = $controller->bootstrap($request);

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors']['code'] ?? null)->toBe('INDIVIDUAL_WAITLIST');

    $row = DB::connection('pgsql')->table('core.waitlist_signups')
        ->where('email_lc', 'newdivert@example.com')->first();

    expect($row)->not->toBeNull();
    expect($row->email)->toBe('newdivert@example.com');
    expect($row->email_lc)->toBe('newdivert@example.com');
    expect($row->applicant_type)->toBe('individual');
    expect($row->consent_source)->toBe('individual_waitlist_divert');
    expect($row->name)->toBe('Casey Wright');
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
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    $response = $controller->bootstrap($request);

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors']['code'] ?? null)->toBe('ACCOUNT_DISABLED');
});
