<?php

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\Brand\BrandAffiliateInviteService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use App\Services\Professional\Brand\BrandSignupCodeService;
use App\Services\Professional\SiteProvisioningService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupProfessionalsTable();
    setupWaitlistTable();

    // The §28.14 divert writes columns the test schema doesn't ship by default.
    // Add them defensively so the upsert succeeds in-memory.
    $conn = DB::connection('pgsql');
    foreach (
        [
            'name' => 'TEXT NULL',
            'email_lc' => 'TEXT NULL',
            'phone' => 'TEXT NULL',
            'applicant_type' => 'TEXT NULL',
            'applicant_type_other' => 'TEXT NULL',
            'industry_other' => 'TEXT NULL',
            'pilot_program_opt_in' => 'INTEGER NULL',
            'number_of_team_members' => 'INTEGER NULL',
            'number_of_affiliates_ambassadors' => 'INTEGER NULL',
            'is_brand_partner_or_ambassador' => 'INTEGER NULL',
            'currently_sells_products' => 'INTEGER NULL',
            'consent_source' => 'TEXT NULL',
            'consent_ip_hash' => 'TEXT NULL',
            'consent_user_agent' => 'TEXT NULL',
            'last_submitted_at' => 'TEXT NULL',
        ] as $col => $type
    ) {
        try {
            $conn->statement("ALTER TABLE core.waitlist_signups ADD COLUMN {$col} {$type}");
        } catch (\Throwable) {
            // column already added in a prior test run
        }
    }
    try {
        $conn->statement('CREATE UNIQUE INDEX IF NOT EXISTS waitlist_signups_email_lc_uq ON core.waitlist_signups(email_lc)');
    } catch (\Throwable) {
        // already exists
    }
})->group('bootstrap-individual-waitlist');

function bootstrapWith(array $data, string $uid = 'fresh-uid'): \Illuminate\Http\JsonResponse
{
    $controller = new BootstrapController(new SiteProvisioningService);
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', $data);
    $request->attributes->set('supabase_uid', $uid);
    $request->setContainer(app())->setRedirector(app('redirect'));
    // Trigger FormRequest validation so $request->validated() returns the
    // sanitized payload. validateResolved() is what Laravel calls during
    // controller injection in a real HTTP request.
    try {
        $request->validateResolved();
    } catch (\Throwable) {
        // Validation may fail for inputs we deliberately under-spec (we're
        // testing the divert short-circuit which sits AFTER validation in
        // the happy path; for the brand / invite / code branches we just
        // need the divert to NOT fire, and downstream errors don't matter).
    }

    try {
        return $controller->bootstrap(
            $request,
            \Mockery::mock(BrandAffiliateInviteService::class),
            \Mockery::mock(BrandPartnerLinkService::class),
            \Mockery::mock(AccountTypeDefaultsService::class),
        );
    } catch (\Throwable $e) {
        return response()->json([
            'errors' => ['code' => 'DOWNSTREAM_'.class_basename($e), 'message' => $e->getMessage()],
        ], 500);
    }
}

it('flag OFF: individual signup is NOT diverted (no INDIVIDUAL_WAITLIST response, no waitlist row)', function () {
    config(['partna.individual_waitlist_enabled' => false]);

    $response = bootstrapWith(validBootstrapData());

    expect($response->getData(true)['errors']['code'] ?? null)->not->toBe('INDIVIDUAL_WAITLIST');
    expect(DB::connection('pgsql')->table('core.waitlist_signups')->where('email_lc', 'solo@example.test')->exists())
        ->toBeFalse();
});

function validBootstrapData(array $overrides = []): array
{
    return array_merge([
        'handle' => 'janedoe',
        'display_name' => 'Jane Doe',
        'primary_email' => 'solo@example.test',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'professional_type' => 'professional',
    ], $overrides);
}

it('flag ON: individual signup (no invite, no code, not brand) is diverted to a waitlist row', function () {
    config(['partna.individual_waitlist_enabled' => true]);
    app()->instance(BrandSignupCodeService::class, \Mockery::mock(BrandSignupCodeService::class));

    $response = bootstrapWith(validBootstrapData());

    $body = $response->getData(true);
    expect(($body['errors']['code'] ?? null).' | '.($body['errors']['message'] ?? ''))
        ->toContain('INDIVIDUAL_WAITLIST');
    expect($response->getStatusCode())->toBe(403);

    expect(DB::connection('pgsql')->table('core.waitlist_signups')->where('email_lc', 'solo@example.test')->exists())
        ->toBeTrue();
});

it('flag ON: brand signup is NOT diverted', function () {
    config(['partna.individual_waitlist_enabled' => true]);

    $response = bootstrapWith(validBootstrapData([
        'primary_email' => 'brandfounder@example.test',
        'professional_type' => 'brand',
    ]));

    // The controller proceeds past the divert into validation / DB work — we just
    // assert it did NOT return INDIVIDUAL_WAITLIST. Whatever else fails downstream
    // is outside the scope of §28.14.
    expect($response->getData(true)['errors']['code'] ?? null)->not->toBe('INDIVIDUAL_WAITLIST');
});

it('flag ON: signup with invite_token is NOT diverted', function () {
    config(['partna.individual_waitlist_enabled' => true]);

    $response = bootstrapWith(validBootstrapData([
        'primary_email' => 'partner-invite@example.test',
        'invite_token' => 'some-token',
    ]));

    expect($response->getData(true)['errors']['code'] ?? null)->not->toBe('INDIVIDUAL_WAITLIST');
});

it('flag ON: signup with a valid brand_signup_code is NOT diverted', function () {
    config(['partna.individual_waitlist_enabled' => true]);

    $codeService = \Mockery::mock(BrandSignupCodeService::class);
    $codeService->shouldReceive('resolveCode')->andReturn((object) ['id' => 'brand-uuid']);
    app()->instance(BrandSignupCodeService::class, $codeService);

    $response = bootstrapWith(validBootstrapData([
        'primary_email' => 'partner-code@example.test',
        'brand_signup_code' => 'ABCD1234EFGH5678',
    ]));

    expect($response->getData(true)['errors']['code'] ?? null)->not->toBe('INDIVIDUAL_WAITLIST');
});
