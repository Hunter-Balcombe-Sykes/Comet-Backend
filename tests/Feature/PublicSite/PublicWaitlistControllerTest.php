<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['partna.throttle.enabled' => true]);

    Cache::flush();
    setupWaitlistSchema();
})->group('public-waitlist');

it('stores a waitlist submission with normalized fields', function () {
    $payload = [
        'name' => '  Alex Tester  ',
        'email' => 'Alex@Example.com',
        'phone' => '+61 412 345 678',
        'type' => 'profesisonal',
        'industry' => 'mens grooming',
        'pilot_program_opt_in' => 'true',
    ];

    $response = $this->postJson('/api/public/waitlist', $payload);

    $response->assertCreated()->assertJson(['ok' => true]);

    $row = DB::connection('pgsql')->table('core.waitlist_signups')->where('email_lc', 'alex@example.com')->first();
    expect($row)->not->toBeNull();
    expect($row->applicant_type)->toBe('professional');
    expect($row->industry)->toBe('mens_grooming');
    expect($row->phone)->toBe('+61412345678');
});

it('upserts waitlist submissions by normalized email', function () {
    $email = 'upsert@example.com';

    $first = [
        'name' => 'Pro One',
        'email' => $email,
        'phone' => '+61411111111',
        'type' => 'professional',
        'industry' => 'beauty_products',
        'pilot_program_opt_in' => false,
        'number_of_team_members' => 5,
    ];

    $second = [
        'name' => 'Pro One Updated',
        'email' => 'UPSERT@example.com',
        'phone' => '+61422222222',
        'type' => 'professional',
        'industry' => 'services_and_software',
        'pilot_program_opt_in' => true,
        'number_of_team_members' => 7,
    ];

    $this->postJson('/api/public/waitlist', $first)->assertCreated();
    $this->postJson('/api/public/waitlist', $second)->assertOk();

    $count = DB::connection('pgsql')->table('core.waitlist_signups')->where('email_lc', $email)->count();
    expect($count)->toBe(1);

    $row = DB::connection('pgsql')->table('core.waitlist_signups')->where('email_lc', $email)->first();
    expect($row->name)->toBe('Pro One Updated');
    expect($row->phone)->toBe('+61422222222');
    expect($row->industry)->toBe('services_and_software');
    expect((int) $row->pilot_program_opt_in)->toBe(1);
});

it('applies waitlist throttle middleware to the waitlist endpoint', function () {
    $route = app('router')->getRoutes()->match(
        Request::create('/api/public/waitlist', 'POST')
    );

    expect($route->gatherMiddleware())->toContain('throttle:waitlist');
});

function setupWaitlistSchema(): void
{
    // The WaitlistSignup model uses 'core.waitlist_signups' as its table.
    // Attach the 'core' schema and create the table under it so the model
    // (and any raw DB::table('core.waitlist_signups') call) resolves.
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.waitlist_signups (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        email_lc TEXT NOT NULL UNIQUE,
        phone TEXT NOT NULL,
        applicant_type TEXT NOT NULL,
        applicant_type_other TEXT NULL,
        industry TEXT NOT NULL,
        industry_other TEXT NULL,
        pilot_program_opt_in INTEGER NOT NULL DEFAULT 0,
        number_of_team_members INTEGER NULL,
        consent_source TEXT NULL,
        consent_ip_hash TEXT NULL,
        consent_user_agent TEXT NULL,
        last_submitted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}
