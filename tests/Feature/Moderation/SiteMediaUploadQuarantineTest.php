<?php

use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\SiteMediaService;
use App\Services\Moderation\R2QuarantineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2_quarantine');
    Storage::fake('r2');
    // Reset to the default (disabled) state before each test.
    config(['partna.moderation.csam.enabled' => false]);
});

it('creates the SiteMedia row with processing_state=scanning when CSAM scanning is enabled', function () {
    if (DB::connection('pgsql')->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-specific test.');
    }

    config(['partna.moderation.csam.enabled' => true]);

    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();

    // Storage::fake() does not implement temporaryUploadUrl(), so we mock the
    // quarantine disk and intercept the call at the Storage facade level.
    $diskMock = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
    $diskMock->shouldReceive('temporaryUploadUrl')
        ->once()
        ->andReturn(['url' => 'https://quarantine.r2.example.com/quarantine/test.jpg?sig=abc']);

    Storage::shouldReceive('disk')
        ->with(R2QuarantineService::QUARANTINE_DISK)
        ->andReturn($diskMock);

    $result = app(SiteMediaService::class)->createSignedUploadUrl(
        siteId: $site->id,
        mime:   'image/jpeg',
        pool:   'gallery',
    );

    expect($result['url'])->toBeString();
    expect($result['site_media_id'])->toBeString();

    $row = DB::connection('pgsql')->selectOne(
        'SELECT processing_state FROM site.site_media WHERE id = ?',
        [$result['site_media_id']]
    );
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_SCANNING);
})->group('postgres');

it('when CSAM scanning is disabled (feature flag), goes straight to production bucket and state=pending', function () {
    if (DB::connection('pgsql')->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-specific test.');
    }

    config(['partna.moderation.csam.enabled' => false]);

    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();

    // Mock the production media disk's temporaryUploadUrl.
    $diskMock = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
    $diskMock->shouldReceive('temporaryUploadUrl')
        ->once()
        ->andReturn(['url' => 'https://media.r2.example.com/images/test.jpg?sig=xyz']);

    // MediaDiskResolver::resolve() returns config('partna.media_disk') which defaults to 'media'.
    $resolvedDisk = (string) config('partna.media_disk', 'media');
    Storage::shouldReceive('disk')
        ->with($resolvedDisk)
        ->andReturn($diskMock);

    $result = app(SiteMediaService::class)->createSignedUploadUrl(
        siteId: $site->id,
        mime:   'image/jpeg',
        pool:   'gallery',
    );

    expect($result['url'])->toBeString();
    $row = DB::connection('pgsql')->selectOne(
        'SELECT processing_state FROM site.site_media WHERE id = ?',
        [$result['site_media_id']]
    );
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PENDING);
})->group('postgres');
