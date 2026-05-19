<?php

use App\Models\Core\Professional\BrandProfile;
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
})->group('brand-profile-signup-code-hook');

function insertTestProfessional(): string
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

    return $proId;
}

it('BrandProfile::create() gets a non-null 16-char signup code via the creating hook', function () {
    $proId = insertTestProfessional();

    $profile = BrandProfile::query()->create([
        'professional_id' => $proId,
        'setup_complete' => false,
    ]);

    expect($profile->signup_code)->not->toBeNull()
        ->toHaveLength(16)
        ->toMatch('/^[0-9a-f]{16}$/');
});

it('BrandProfile::create() respects an explicit signup_code — hook is a no-op when set', function () {
    $proId = insertTestProfessional();
    $explicit = 'aabbccdd11223344';

    $profile = BrandProfile::query()->create([
        'professional_id' => $proId,
        'setup_complete' => false,
        'signup_code' => $explicit,
    ]);

    expect($profile->signup_code)->toBe($explicit);
});

it('BrandProfile::create() with explicit null still gets a generated code via the creating hook', function () {
    $proId = insertTestProfessional();

    $profile = BrandProfile::query()->create([
        'professional_id' => $proId,
        'setup_complete' => false,
        'signup_code' => null,
    ]);

    // Hook fires because signup_code is null at create time
    expect($profile->signup_code)->not->toBeNull();
    expect($profile->signup_code)->toHaveLength(16)->toMatch('/^[0-9a-f]{16}$/');
});

it('BrandProfile::saveQuietly() does NOT invoke the creating hook (backfill pattern)', function () {
    // Simulate the backfill: manually insert a row with NULL signup_code, then
    // assign + saveQuietly(). The creating hook must NOT interfere.
    $proId = insertTestProfessional();
    $profileId = (string) Str::uuid();

    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => $profileId,
        'professional_id' => $proId,
        'setup_complete' => 1,
        // Intentionally NULL — simulates a pre-migration row
        'signup_code' => null,
    ]);

    $profile = BrandProfile::find($profileId);
    expect($profile->signup_code)->toBeNull();

    $backfillCode = app(BrandSignupCodeService::class)->generate();
    $profile->signup_code = $backfillCode;
    $profile->saveQuietly();

    $profile->refresh();
    expect($profile->signup_code)->toBe($backfillCode);
});
