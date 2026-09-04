<?php

use App\Models\Core\Site\Site;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Owner ruling 2026-08-17: the brand logos return to the public wire as
// profile.brand {logoFull, logoSquare} — slice 7 unit E deleted `siteImages`
// wholesale and the logos went down with the noise. Reads the design-singleton
// projection getDesignSingletons() kept alive for exactly this.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupMediaTables();
    setupBlocksTable();
    setupServicesTable();
    setupDesignKitsTable();
    Queue::fake();
});

/** A ready design singleton + optimized webp variant; returns the media id. */
function brandSingleton(string $siteId, string $purpose, array $variants = ['optimized']): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id, 'site_id' => $siteId, 'usage' => 'design', 'purpose' => $purpose,
        'path' => "images/{$purpose}.png", 'sort_order' => 0, 'is_active' => 1,
        'media_type' => 'image', 'processing_state' => 'ready',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    foreach ($variants as $key) {
        DB::connection('pgsql')->table('site.media_variants')->insert([
            'id' => (string) Str::uuid(), 'media_id' => $id,
            'variant_key' => $key,
            'artifact_type' => $key === 'svg' ? 'svg' : ($key === 'icon' ? 'png' : 'webp'),
            'disk' => 'test_disk', 'path' => "variants/{$purpose}-{$key}",
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    return $id;
}

it('emits both logo slots with their variant urls', function () {
    [$pro] = poolTenant();
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    brandSingleton($site->id, 'logo_full');
    brandSingleton($site->id, 'logo_square', ['optimized', 'icon']);

    $brand = app(IndividualProfilePayloadBuilder::class)
        ->build($pro->fresh(), $site)['profile']['brand'];

    expect($brand['logoFull'])->not->toBeNull()
        ->and($brand['logoFull']['url'])->toContain('logo_full-optimized')
        ->and($brand['logoSquare']['url'])->toContain('logo_square-optimized')
        ->and($brand['logoSquare']['urlIcon'])->toContain('logo_square-icon')
        ->and(array_keys($brand['logoFull']))->toBe(['url', 'urlHd', 'urlSvg', 'urlIcon']);
});

it('emits null slots when nothing was uploaded, and never the placeholder', function () {
    [$pro] = poolTenant();
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    brandSingleton($site->id, 'placeholder');

    $brand = app(IndividualProfilePayloadBuilder::class)
        ->build($pro->fresh(), $site)['profile']['brand'];

    expect($brand)->toBe(['logoFull' => null, 'logoSquare' => null]);
});

// ── T17 (owner, 2026-08-27): profile.headshot — its own key, never in brand ──

it('emits the headshot on its own profile key with url + urlIcon', function () {
    [$pro] = poolTenant();
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    brandSingleton($site->id, 'headshot', ['optimized', 'icon']);

    $profile = app(IndividualProfilePayloadBuilder::class)
        ->build($pro->fresh(), $site)['profile'];

    expect($profile['headshot'])->not->toBeNull()
        ->and($profile['headshot']['url'])->toContain('headshot-optimized')
        ->and($profile['headshot']['urlIcon'])->toContain('headshot-icon')
        ->and(array_keys($profile['headshot']))->toBe(['url', 'urlHd', 'urlIcon'])
        // The astro layer hard-nulls brand for partna accounts — the
        // headshot must never ride inside it.
        ->and($profile['brand'])->toBe(['logoFull' => null, 'logoSquare' => null]);
});

it('emits a null headshot when the slot is empty', function () {
    [$pro] = poolTenant();
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    $profile = app(IndividualProfilePayloadBuilder::class)
        ->build($pro->fresh(), $site)['profile'];

    expect($profile['headshot'])->toBeNull();
});

it('hides a logo that is still processing', function () {
    [$pro] = poolTenant();
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $id = brandSingleton($site->id, 'logo_full');
    DB::connection('pgsql')->table('site.site_media')->where('id', $id)
        ->update(['processing_state' => 'processing']);

    $brand = app(IndividualProfilePayloadBuilder::class)
        ->build($pro->fresh(), $site)['profile']['brand'];

    expect($brand['logoFull'])->toBeNull();
});
