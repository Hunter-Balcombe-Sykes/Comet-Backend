<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();

    // partna_url is a trigger-managed column not in the base schema stub.
    // Add it so we can exercise the QR controller's null-check.
    try {
        DB::connection('pgsql')->statement(
            'ALTER TABLE core.users ADD COLUMN partna_url TEXT NULL'
        );
    } catch (Throwable $e) {
        // Already exists from a prior test in the same process.
    }

    config(['partna.throttle.enabled' => false]);
});

/**
 * Seed a user + site and return ['userId' => ..., 'siteId' => ...].
 *
 * @return array{userId: string, siteId: string}
 */
function seedQrUser(bool $isPublished, ?string $partnaUrl = 'https://testhandle.partna.au'): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'testhandle',
        'handle_lc' => 'testhandle',
        'display_name' => 'Test Handle',
        'first_name' => 'Testhandle',
        'status' => 'active',
        'partna_url' => $partnaUrl,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'testhandle',
        'is_published' => $isPublished ? 1 : 0,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return ['userId' => $userId, 'siteId' => $siteId];
}

it('returns 200 SVG for a user with a published site', function () {
    ['userId' => $userId] = seedQrUser(isPublished: true);

    $response = $this->get("/p/{$userId}.svg");

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('image/svg+xml');
});

it('returns 404 when the site is unpublished', function () {
    ['userId' => $userId] = seedQrUser(isPublished: false);

    $this->get("/p/{$userId}.svg")->assertNotFound();
});

it('returns 404 when the user has no site row', function () {
    $userId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'nosite',
        'handle_lc' => 'nosite',
        'display_name' => 'No Site',
        'first_name' => 'Nosite',
        'status' => 'active',
        'partna_url' => 'https://nosite.partna.au',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // No site.sites row inserted — controller must 404.
    $this->get("/p/{$userId}.svg")->assertNotFound();
});

it('returns 404 when the user does not exist', function () {
    $this->get('/p/'.Str::uuid().'.svg')->assertNotFound();
});

it('returns 404 when the user exists but has no partna_url', function () {
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'nourl',
        'handle_lc' => 'nourl',
        'display_name' => 'No Url',
        'first_name' => 'Nourl',
        'status' => 'active',
        'partna_url' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'nourl',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->get("/p/{$userId}.svg")->assertNotFound();
});
