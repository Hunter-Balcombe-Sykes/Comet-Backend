<?php

use App\Services\Media\MediaDiskResolver;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Store and restore superglobals around each test so we don't bleed state.
beforeEach(function () {
    $this->savedEnv = [
        'PARTNA_MEDIA_DISK' => $_ENV['PARTNA_MEDIA_DISK'] ?? null,
        'SIDEST_MEDIA_DISK' => $_ENV['SIDEST_MEDIA_DISK'] ?? null,
    ];
    $this->savedServer = [
        'PARTNA_MEDIA_DISK' => $_SERVER['PARTNA_MEDIA_DISK'] ?? null,
        'SIDEST_MEDIA_DISK' => $_SERVER['SIDEST_MEDIA_DISK'] ?? null,
    ];

    unset(
        $_ENV['PARTNA_MEDIA_DISK'],   $_SERVER['PARTNA_MEDIA_DISK'],
        $_ENV['SIDEST_MEDIA_DISK'],   $_SERVER['SIDEST_MEDIA_DISK'],
    );
});

afterEach(function () {
    foreach ($this->savedEnv as $key => $val) {
        if ($val === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $val;
        }
    }
    foreach ($this->savedServer as $key => $val) {
        if ($val === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $val;
        }
    }
});

it('returns the probed env value when PARTNA_MEDIA_DISK is set and config is the stale sentinel', function () {
    // Simulates Laravel Cloud: config was cached before the env var was injected,
    // so config('partna.media_disk') is still 'media' while $_ENV has the real disk.
    config(['partna.media_disk' => 'media']);
    $_ENV['PARTNA_MEDIA_DISK'] = 'my-r2';

    expect(MediaDiskResolver::resolve())->toBe('my-r2');
});

it('returns the probed env value when SIDEST_MEDIA_DISK legacy var is set and config is the stale sentinel', function () {
    config(['partna.media_disk' => 'media']);
    $_ENV['SIDEST_MEDIA_DISK'] = 'legacy-r2';

    expect(MediaDiskResolver::resolve())->toBe('legacy-r2');
});

it('returns the configured disk when it is not the media sentinel', function () {
    config(['partna.media_disk' => 'production-r2']);

    expect(MediaDiskResolver::resolve())->toBe('production-r2');
});

it('falls back to filesystems.default when media disk is unconfigured and default is s3', function () {
    config([
        'partna.media_disk' => 'media',
        'filesystems.default' => 'r2-cloud',
        'filesystems.disks.r2-cloud' => ['driver' => 's3', 'bucket' => 'test-bucket'],
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->with(
            'PARTNA_MEDIA_DISK not set (legacy fallback: SIDEST_MEDIA_DISK); using filesystems.default disk for media operations.',
            Mockery::subset(['fallback_disk' => 'r2-cloud'])
        );

    expect(MediaDiskResolver::resolve())->toBe('r2-cloud');
});

it('returns media sentinel when filesystems.default is local', function () {
    config([
        'partna.media_disk' => 'media',
        'filesystems.default' => 'local',
        'filesystems.disks.local' => ['driver' => 'local', 'root' => storage_path('app')],
    ]);

    Log::shouldReceive('warning')->never();

    expect(MediaDiskResolver::resolve())->toBe('media');
});

it('returns media sentinel when filesystems.default disk is not s3-backed', function () {
    config([
        'partna.media_disk' => 'media',
        'filesystems.default' => 'ftp-disk',
        'filesystems.disks.ftp-disk' => ['driver' => 'ftp', 'host' => 'example.com'],
    ]);

    Log::shouldReceive('warning')->never();

    expect(MediaDiskResolver::resolve())->toBe('media');
});
