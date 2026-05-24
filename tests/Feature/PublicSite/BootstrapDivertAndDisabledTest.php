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

it('divert does not clobber an existing full-form waitlist row (P1-A regression guard)', function () {
    config(['partna.individual_waitlist_enabled' => true]);
    config(['partna.waitlist.enabled' => false]);

    // Pre-existing row from the full PublicWaitlistController submission.
    DB::connection('pgsql')->table('core.waitlist_signups')->insert([
        'id' => '00000000-0000-0000-0000-000000000bb1',
        'email' => 'preexisting@example.com',
        'email_lc' => 'preexisting@example.com',
        'name' => 'Original Pro',
        'phone' => '+61400000000',
        'applicant_type' => 'professional',
        'industry' => 'mens_grooming',
        'consent_source' => 'waitlist_form',
        'consent_ip_hash' => 'sha256-real-hash',
        'last_submitted_at' => '2026-05-01 00:00:00',
    ]);

    $controller = app(BootstrapController::class);
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', [
        'primary_email' => 'preexisting@example.com',
        'first_name' => 'Should',
        'last_name' => 'NotOverwrite',
    ]);
    $request->attributes->set('supabase_uid', 'divert-collision-uid');

    $response = $controller->bootstrap($request);

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors']['code'] ?? null)->toBe('INDIVIDUAL_WAITLIST');

    $row = DB::connection('pgsql')->table('core.waitlist_signups')
        ->where('email_lc', 'preexisting@example.com')->first();

    // None of the original consent fields may have been overwritten.
    expect($row->name)->toBe('Original Pro');
    expect($row->phone)->toBe('+61400000000');
    expect($row->applicant_type)->toBe('professional');
    expect($row->industry)->toBe('mens_grooming');
    expect($row->consent_source)->toBe('waitlist_form');
    expect($row->consent_ip_hash)->toBe('sha256-real-hash');
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
