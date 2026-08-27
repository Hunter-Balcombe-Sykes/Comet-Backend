<?php

use App\Jobs\ProcessImageVariantsJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Design\HeadshotAutoSeeder;
use App\Services\Media\ImageVariantService;
use App\Services\Media\MediaDiskResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * T17 (owner, 2026-08-27): the headshot design singleton auto-seeds from the
 * Instagram profile picture at build time — fill-empty only, partna accounts
 * only (the capability mirror of LogoAutoGrabber's brand gate), reading the
 * ALREADY-MIRRORED bytes off the media disk rather than any network fetch.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSectionsTables();
    setupMediaTables();
    setupIntegrationConnectionsTable();
    shimPgAdvisoryLockForSqlite();
    Bus::fake();

    $svc = Mockery::mock(ImageVariantService::class);
    $svc->shouldReceive('storeOriginal')->andReturn('images/new/original.jpg');
    $svc->shouldReceive('deleteVariants')->andReturnNull();
    $svc->shouldReceive('resolvedDiskName')->andReturn(MediaDiskResolver::resolve());
    app()->instance(ImageVariantService::class, $svc);
});

function hasSeedTenant(string $handle, string $accountType = 'partna'): array
{
    $pro = createTenant($handle);
    if ($accountType !== 'partna') {
        $pro->forceFill(['account_type' => $accountType])->save();
    }
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    return [$pro->fresh(), $site];
}

function hasSeedInstagramConnection(string $userId, string $folder): void
{
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'resource_id' => 'ig-'.$userId,
        'payload' => json_encode([
            'username' => 'someone',
            'profilePicUrl' => "https://cdn.example.com/{$folder}/profile.jpg",
            '_folder' => $folder,
        ]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function hasSeedMirroredPic(string $folder): void
{
    $img = imagecreatetruecolor(64, 64);
    ob_start();
    imagejpeg($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);
    Storage::disk(MediaDiskResolver::resolve())->put("{$folder}/profile.jpg", $bytes);
}

function headshotRows(string $siteId)
{
    return SiteMedia::query()->where('site_id', $siteId)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('purpose', SiteMedia::PURPOSE_HEADSHOT);
}

it('seeds the headshot singleton from the mirrored Instagram profile picture', function () {
    Storage::fake(MediaDiskResolver::resolve());
    [$pro, $site] = hasSeedTenant('has-partna');
    hasSeedInstagramConnection((string) $pro->id, 'platforms/instagram/123');
    hasSeedMirroredPic('platforms/instagram/123');

    app(HeadshotAutoSeeder::class)->seedFromInstagram($pro, $site);

    expect(headshotRows((string) $site->id)->count())->toBe(1);
    // Not a logo purpose → the standard variants job (which grows the icon
    // variant for headshots), never the logo pipeline.
    Bus::assertDispatched(ProcessImageVariantsJob::class);
});

it('never seeds for a brand-identity (business) account', function () {
    Storage::fake(MediaDiskResolver::resolve());
    [$pro, $site] = hasSeedTenant('has-biz', 'business');
    hasSeedInstagramConnection((string) $pro->id, 'platforms/instagram/456');
    hasSeedMirroredPic('platforms/instagram/456');

    app(HeadshotAutoSeeder::class)->seedFromInstagram($pro, $site);

    expect(headshotRows((string) $site->id)->exists())->toBeFalse();
});

it('fill-empty only: an occupied slot is never replaced', function () {
    Storage::fake(MediaDiskResolver::resolve());
    [$pro, $site] = hasSeedTenant('has-occupied');
    hasSeedInstagramConnection((string) $pro->id, 'platforms/instagram/789');
    hasSeedMirroredPic('platforms/instagram/789');

    $existingId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $existingId, 'site_id' => (string) $site->id, 'pool' => 'design',
        'purpose' => 'headshot', 'path' => 'images/owner-own.jpg', 'sort_order' => 0,
        'is_active' => 1, 'media_type' => 'image', 'processing_state' => 'ready',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    app(HeadshotAutoSeeder::class)->seedFromInstagram($pro, $site);

    $rows = headshotRows((string) $site->id)->get();
    expect($rows)->toHaveCount(1)
        ->and((string) $rows->first()->id)->toBe($existingId);
});

it('does nothing without a mirrored profile picture', function () {
    Storage::fake(MediaDiskResolver::resolve());
    [$pro, $site] = hasSeedTenant('has-nopic');
    hasSeedInstagramConnection((string) $pro->id, 'platforms/instagram/000');
    // No file written to the disk.

    app(HeadshotAutoSeeder::class)->seedFromInstagram($pro, $site);

    expect(headshotRows((string) $site->id)->exists())->toBeFalse();
});
