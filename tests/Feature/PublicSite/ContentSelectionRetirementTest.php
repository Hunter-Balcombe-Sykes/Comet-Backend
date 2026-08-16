<?php

/**
 * Slice 7 unit E — `site.content_selection` retirement.
 *
 * The owner-curation surface (four `/api/content/*` verbs + ContentSelection*)
 * is gone; `pool:media` pins are the replacement lane. Under the 2026-08-14
 * owner ruling the legacy wire keys are DELETED OUTRIGHT, not dual-served —
 * apps/pages reads break by design and are rebuilt, not repaired.
 *
 * Helpers are `csr`-prefixed: Pest test-file functions are global, and a
 * collision across files is fatal under --parallel.
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupMediaTables();
    setupServiceCategoriesTable();
    setupServicesTable();
    setupIngestTables();
    setupContentTables();
    setupDesignKitsTable();

    try {
        DB::connection('pgsql')->statement("ALTER TABLE site.sites ADD COLUMN architecture_id TEXT NOT NULL DEFAULT 'staple'");
    } catch (Throwable) {
        // Column already added by an earlier test in this process.
    }

    Cache::flush();
    Config::set('partna.throttle.enabled', false);
});

/** Seed a published individual + their 1:1 site, returning the handle. */
function csrProfile(string $handle): string
{
    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => 'Retired Selection',
        'first_name' => 'Retired',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'subdomain' => strtolower($handle),
        'settings' => json_encode([]),
        'is_published' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $handle;
}

/** Every registered route URI, "<METHOD> <uri>". */
function csrRouteSignatures(): array
{
    $out = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            $out[] = $method.' '.$route->uri();
        }
    }

    return $out;
}

it('serves a public profile payload with no designMedia or siteImages key', function () {
    $handle = csrProfile('csr-payload');

    $data = $this->getJson("/api/public/profiles/{$handle}")->assertOk()->json('data');

    expect(array_key_exists('designMedia', $data))->toBeFalse();
    expect(array_key_exists('siteImages', $data))->toBeFalse();
});

it('serves a public profile whose profile object carries no gallery or curatedGallery key', function () {
    $handle = csrProfile('csr-profile');

    $profile = $this->getJson("/api/public/profiles/{$handle}")->assertOk()->json('data.profile');

    expect(array_key_exists('gallery', $profile))->toBeFalse();
    expect(array_key_exists('curatedGallery', $profile))->toBeFalse();
});

it('no longer registers the four content-selection owner routes', function () {
    $signatures = csrRouteSignatures();

    foreach ([
        'GET api/content/selection',
        'PUT api/content/selection',
        'PUT api/content/instagram-auto',
        'PUT api/content/google-photos',
    ] as $retired) {
        expect(in_array($retired, $signatures, true))->toBeFalse();
    }
});

it('keeps the content library and upload routes, which pool:media still feeds from', function () {
    $signatures = csrRouteSignatures();

    foreach (['GET api/content/library', 'POST api/content/uploads'] as $kept) {
        expect(in_array($kept, $signatures, true))->toBeTrue();
    }
});

it('has no ContentSelection model, service or policy left to load', function () {
    foreach ([
        'App\Models\Core\Site\ContentSelection',
        'App\Services\Site\ContentSelectionService',
        'App\Policies\ContentSelectionPolicy',
        'App\Http\Requests\Api\User\Content\ReplaceContentSelectionRequest',
    ] as $class) {
        expect(class_exists($class))->toBeFalse();
    }
});
