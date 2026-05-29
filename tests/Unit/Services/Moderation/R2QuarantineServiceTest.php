<?php

use App\Services\Moderation\R2QuarantineService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('r2_quarantine');
    Storage::fake('media');
});

it('generates a signed upload URL targeting the quarantine bucket', function () {
    // Storage::fake() does not implement temporaryUploadUrl(), so we mock the
    // underlying disk instance returned by Storage::disk('r2_quarantine').
    $diskMock = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
    $diskMock->shouldReceive('temporaryUploadUrl')
        ->once()
        ->with(
            'quarantine/abc-123.jpg',
            Mockery::type(\Carbon\Carbon::class),
            Mockery::on(fn ($opts) => ($opts['ContentType'] ?? null) === 'image/jpeg'),
        )
        ->andReturn(['url' => 'https://r2.example.com/quarantine/abc-123.jpg?X-Amz-Signature=abc']);

    Storage::shouldReceive('disk')
        ->with(R2QuarantineService::QUARANTINE_DISK)
        ->andReturn($diskMock);

    $url = app(R2QuarantineService::class)->signedUploadUrl(
        key:          'quarantine/abc-123.jpg',
        mime:         'image/jpeg',
        maxSizeBytes: 10_000_000,
        expiresIn:    600,
    );

    expect($url)->toBeString();
    expect($url)->toContain('quarantine/abc-123.jpg');
});

it('promote copies object from quarantine → production and deletes from quarantine', function () {
    Storage::disk('r2_quarantine')->put('quarantine/test.jpg', 'fake-binary');
    $service = app(R2QuarantineService::class);

    $service->promoteToProduction(quarantineKey: 'quarantine/test.jpg', productionKey: 'media/test.jpg');

    expect(Storage::disk('r2_quarantine')->exists('quarantine/test.jpg'))->toBeFalse();
    expect(Storage::disk('media')->exists('media/test.jpg'))->toBeTrue();
});

it('delete quarantine binary removes the object', function () {
    Storage::disk('r2_quarantine')->put('quarantine/del.jpg', 'binary');
    app(R2QuarantineService::class)->deleteQuarantineBinary('quarantine/del.jpg');
    expect(Storage::disk('r2_quarantine')->exists('quarantine/del.jpg'))->toBeFalse();
});
