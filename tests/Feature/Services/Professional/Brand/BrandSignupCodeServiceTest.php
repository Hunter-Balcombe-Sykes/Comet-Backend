<?php

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\BrandSignupCodeAuditEntry;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\Brand\BrandSignupCodeService;
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
        id TEXT PRIMARY KEY,
        auth_user_id TEXT,
        handle TEXT,
        handle_lc TEXT,
        display_name TEXT,
        bio TEXT,
        first_name TEXT,
        last_name TEXT,
        phone TEXT,
        primary_email TEXT,
        public_contact_number TEXT,
        public_contact_email TEXT,
        professional_type TEXT DEFAULT "professional",
        account_type TEXT NULL,
        has_historical_partner_links INTEGER NULL,
        status TEXT DEFAULT "active",
        onboarding_step INTEGER DEFAULT 0,
        country_code TEXT,
        timezone TEXT,
        location_street_address TEXT,
        location_city TEXT,
        location_state TEXT,
        location_postcode TEXT,
        location_country TEXT,
        stripe_connect_account_id TEXT,
        stripe_customer_id TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
        id TEXT PRIMARY KEY,
        professional_id TEXT UNIQUE,
        abn TEXT,
        acn TEXT,
        legal_business_name TEXT,
        business_type TEXT,
        industries TEXT,
        estimated_annual_income TEXT,
        business_website TEXT,
        affiliate_visibility TEXT,
        brand_status TEXT,
        setup_complete INTEGER DEFAULT 0,
        signup_code TEXT,
        signup_code_active INTEGER NOT NULL DEFAULT 1,
        signup_code_rotated_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.signup_code_audit (
        id TEXT PRIMARY KEY,
        brand_profile_id TEXT NOT NULL,
        event TEXT NOT NULL,
        actor_type TEXT NOT NULL,
        actor_professional_id TEXT,
        staff_user_id TEXT,
        source_ip TEXT,
        code_prefix_hash TEXT,
        joined_professional_id TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
})->group('brand-signup-code-service');

function makeTestBrand(): BrandProfile
{
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'auth_user_id' => 'uid-'.Str::random(6),
        'handle' => 'brand'.Str::random(4),
        'handle_lc' => 'brand'.Str::random(4),
        'display_name' => 'Test Brand',
        'primary_email' => Str::random(6).'@example.com',
        'professional_type' => 'brand',
        'status' => 'active',
    ]);

    $profileId = (string) Str::uuid();
    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => $profileId,
        'professional_id' => $proId,
        'setup_complete' => 1,
        'signup_code' => bin2hex(random_bytes(8)),
        'signup_code_active' => 1,
    ]);

    return BrandProfile::find($profileId);
}

function makeTestPro(): Professional
{
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'auth_user_id' => 'uid-'.Str::random(6),
        'handle' => 'partner'.Str::random(4),
        'handle_lc' => 'partner'.Str::random(4),
        'display_name' => 'Test Partner',
        'primary_email' => Str::random(6).'@example.com',
        'professional_type' => 'professional',
        'status' => 'active',
    ]);

    return Professional::find($proId);
}

it('generate() produces a 16-char lowercase hex string', function () {
    $service = new BrandSignupCodeService;

    $code = $service->generate();

    expect($code)->toBeString()
        ->toHaveLength(16)
        ->toMatch('/^[0-9a-f]{16}$/');
});

it('generate() produces different codes on repeated calls', function () {
    $service = new BrandSignupCodeService;

    $codes = array_map(fn () => $service->generate(), range(1, 20));

    // All unique — probability of collision with 16-char hex is negligible
    expect(array_unique($codes))->toHaveCount(20);
});

it('resolveCode() finds an active brand profile by code', function () {
    $service = new BrandSignupCodeService;
    $brand = makeTestBrand();

    $resolved = $service->resolveCode((string) $brand->signup_code);

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($brand->id);
});

it('resolveCode() returns null when code is inactive', function () {
    $service = new BrandSignupCodeService;
    $brand = makeTestBrand();
    DB::connection('pgsql')->table('brand.brand_profiles')->where('id', $brand->id)->update(['signup_code_active' => 0]);

    $resolved = $service->resolveCode((string) $brand->signup_code);

    expect($resolved)->toBeNull();
});

it('resolveCode() returns null for unknown code', function () {
    $service = new BrandSignupCodeService;

    $resolved = $service->resolveCode('0000000000000000');

    expect($resolved)->toBeNull();
});

it('resolveCode() returns null for empty string', function () {
    $service = new BrandSignupCodeService;

    expect($service->resolveCode(''))->toBeNull();
});

it('rotate() generates a new code and writes a rotated audit entry', function () {
    $service = new BrandSignupCodeService;
    $brand = makeTestBrand();
    $oldCode = $brand->signup_code;

    $newCode = $service->rotate($brand);

    expect($newCode)->not->toBe($oldCode);
    expect($newCode)->toHaveLength(16)->toMatch('/^[0-9a-f]{16}$/');
    expect($brand->signup_code)->toBe($newCode);
    expect($brand->signup_code_active)->toBeTrue();
    expect($brand->signup_code_rotated_at)->not->toBeNull();

    $audit = BrandSignupCodeAuditEntry::query()
        ->where('brand_profile_id', $brand->id)
        ->where('event', 'rotated')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->actor_type)->toBe('brand');
});

it('deactivate() sets signup_code_active to false and writes audit entry', function () {
    $service = new BrandSignupCodeService;
    $brand = makeTestBrand();

    $service->deactivate($brand);

    expect($brand->signup_code_active)->toBeFalse();

    $audit = BrandSignupCodeAuditEntry::query()
        ->where('brand_profile_id', $brand->id)
        ->where('event', 'deactivated')
        ->first();

    expect($audit)->not->toBeNull();
});

it('reactivate() sets signup_code_active to true and writes audit entry', function () {
    $service = new BrandSignupCodeService;
    $brand = makeTestBrand();
    DB::connection('pgsql')->table('brand.brand_profiles')->where('id', $brand->id)->update(['signup_code_active' => 0]);
    $brand->refresh();

    $service->reactivate($brand);

    expect($brand->signup_code_active)->toBeTrue();

    $audit = BrandSignupCodeAuditEntry::query()
        ->where('brand_profile_id', $brand->id)
        ->where('event', 'reactivated')
        ->first();

    expect($audit)->not->toBeNull();
});

it('recordClaim() inserts a claimed audit row with joined_professional_id', function () {
    $service = new BrandSignupCodeService;
    $brand = makeTestBrand();
    $partner = makeTestPro();

    $service->recordClaim($brand, $partner, '1.2.3.4');

    $audit = BrandSignupCodeAuditEntry::query()
        ->where('brand_profile_id', $brand->id)
        ->where('event', 'claimed')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->joined_professional_id)->toBe((string) $partner->id);
    expect($audit->actor_type)->toBe('public');
    expect($audit->source_ip)->toBe('1.2.3.4');
    expect($audit->code_prefix_hash)->not->toBeNull();
});

it('recordFailedClaim() inserts a failed_claim row when the code matches a (deactivated) brand', function () {
    $service = new BrandSignupCodeService;
    $brand = makeTestBrand();
    // Deactivate so resolveCode would return null, but the code still exists
    DB::connection('pgsql')->table('brand.brand_profiles')->where('id', $brand->id)->update(['signup_code_active' => 0]);

    $service->recordFailedClaim((string) $brand->signup_code, '5.5.5.5');

    $audit = BrandSignupCodeAuditEntry::query()
        ->where('brand_profile_id', $brand->id)
        ->where('event', 'failed_claim')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->source_ip)->toBe('5.5.5.5');
});

it('recordFailedClaim() silently skips when the code is completely unknown', function () {
    $service = new BrandSignupCodeService;

    // Should not throw
    $service->recordFailedClaim('0000000000000000', '9.9.9.9');

    $count = BrandSignupCodeAuditEntry::query()->where('event', 'failed_claim')->count();
    expect($count)->toBe(0);
});
