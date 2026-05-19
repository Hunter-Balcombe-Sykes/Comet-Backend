<?php

use App\Http\Controllers\Api\Professional\Brand\BrandSignupCodeController;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\BrandSignupCodeAuditEntry;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\Brand\BrandSignupCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $sqlite = config('database.connections.sqlite');
    config([
        'database.default' => 'sqlite',
        'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
    ]);
    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');
    foreach (['core', 'brand'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY, auth_user_id TEXT, handle TEXT, handle_lc TEXT,
        display_name TEXT, bio TEXT, first_name TEXT, last_name TEXT, phone TEXT,
        primary_email TEXT, public_contact_number TEXT, public_contact_email TEXT,
        professional_type TEXT DEFAULT "professional", account_type TEXT NULL,
        has_historical_partner_links INTEGER NULL, status TEXT DEFAULT "active",
        onboarding_step INTEGER DEFAULT 0, country_code TEXT, timezone TEXT,
        location_street_address TEXT, location_city TEXT, location_state TEXT,
        location_postcode TEXT, location_country TEXT, stripe_connect_account_id TEXT,
        stripe_customer_id TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
        id TEXT PRIMARY KEY, professional_id TEXT UNIQUE, abn TEXT, acn TEXT,
        legal_business_name TEXT, business_type TEXT, industries TEXT,
        estimated_annual_income TEXT, business_website TEXT, affiliate_visibility TEXT,
        brand_status TEXT, setup_complete INTEGER DEFAULT 0,
        signup_code TEXT, signup_code_active INTEGER NOT NULL DEFAULT 1,
        signup_code_rotated_at TEXT, created_at TEXT, updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.signup_code_audit (
        id TEXT PRIMARY KEY, brand_profile_id TEXT NOT NULL, event TEXT NOT NULL,
        actor_type TEXT NOT NULL, actor_professional_id TEXT, staff_user_id TEXT,
        source_ip TEXT, code_prefix_hash TEXT, joined_professional_id TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
})->group('brand-signup-code-controller');

function makeBrandProfessionalWithProfile(): array
{
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId, 'auth_user_id' => 'uid-'.Str::random(6),
        'handle' => 'brand'.Str::random(4), 'handle_lc' => 'brand'.Str::random(4),
        'display_name' => 'Brand', 'primary_email' => Str::random(6).'@example.com',
        'professional_type' => 'brand', 'account_type' => 'brand', 'status' => 'active',
    ]);

    $profileId = (string) Str::uuid();
    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => $profileId, 'professional_id' => $proId, 'setup_complete' => 1,
        'signup_code' => bin2hex(random_bytes(8)), 'signup_code_active' => 1,
    ]);

    $pro = Professional::find($proId);
    $profile = BrandProfile::find($profileId);

    return [$pro, $profile];
}

function brandSignupCodeRequest(Professional $pro): Request
{
    $request = Request::create('/api/professional/brand/signup-code', 'GET');
    $request->attributes->set('professional', $pro);

    return $request;
}

it('GET show returns code, active status, and zero aggregate counts for a fresh brand', function () {
    [$pro, $profile] = makeBrandProfessionalWithProfile();
    $controller = new BrandSignupCodeController(new BrandSignupCodeService);

    $response = $controller->show(brandSignupCodeRequest($pro));

    expect($response->getStatusCode())->toBe(200);
    $data = $response->getData(true);
    expect($data['code'])->toBe($profile->signup_code);
    expect($data['active'])->toBeTrue();
    expect($data['claims_30d_count'])->toBe(0);
    expect($data['failed_attempts_7d_count'])->toBe(0);
});

it('POST rotate returns a new code different from the original', function () {
    [$pro, $profile] = makeBrandProfessionalWithProfile();
    $oldCode = $profile->signup_code;
    $controller = new BrandSignupCodeController(new BrandSignupCodeService);

    $request = Request::create('/api/professional/brand/signup-code/rotate', 'POST');
    $request->attributes->set('professional', $pro);

    $response = $controller->rotate($request);

    expect($response->getStatusCode())->toBe(200);
    $data = $response->getData(true);
    expect($data['code'])->not->toBe($oldCode);
    expect($data['code'])->toHaveLength(16)->toMatch('/^[0-9a-f]{16}$/');
    expect($data['active'])->toBeTrue();

    // Verify audit row
    $audit = BrandSignupCodeAuditEntry::query()
        ->where('brand_profile_id', $profile->id)
        ->where('event', 'rotated')
        ->first();
    expect($audit)->not->toBeNull();
});

it('POST deactivate sets active to false', function () {
    [$pro, $profile] = makeBrandProfessionalWithProfile();
    $controller = new BrandSignupCodeController(new BrandSignupCodeService);

    $request = Request::create('/api/professional/brand/signup-code/deactivate', 'POST');
    $request->attributes->set('professional', $pro);

    $response = $controller->deactivate($request);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getData(true)['active'])->toBeFalse();

    $audit = BrandSignupCodeAuditEntry::query()
        ->where('brand_profile_id', $profile->id)
        ->where('event', 'deactivated')
        ->first();
    expect($audit)->not->toBeNull();
});

it('POST reactivate sets active back to true', function () {
    [$pro, $profile] = makeBrandProfessionalWithProfile();
    DB::connection('pgsql')->table('brand.brand_profiles')
        ->where('id', $profile->id)
        ->update(['signup_code_active' => 0]);

    $controller = new BrandSignupCodeController(new BrandSignupCodeService);

    $request = Request::create('/api/professional/brand/signup-code/reactivate', 'POST');
    $request->attributes->set('professional', $pro);

    $response = $controller->reactivate($request);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getData(true)['active'])->toBeTrue();
});

it('non-brand account is blocked by EnsureBrandAccount middleware (middleware test)', function () {
    // Unit test: verify the middleware returns 403 for a non-brand professional.
    $middleware = new \App\Http\Middleware\EnsureBrandAccount;

    $nonBrandPro = new Professional(['professional_type' => 'professional', 'account_type' => 'individual']);
    $request = Request::create('/api/professional/brand/signup-code', 'GET');
    $request->attributes->set('professional', $nonBrandPro);

    $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true], 200));

    expect($response->getStatusCode())->toBe(403);
});
