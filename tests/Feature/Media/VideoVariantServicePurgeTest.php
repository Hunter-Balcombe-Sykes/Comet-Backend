<?php

use App\Services\Media\VideoVariantService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['partna.media_disk' => 'media']);
    Storage::fake('media');
});

it('deletes every object under a video base path and reports the deleted count', function () {
    $base = 'videos/pro-1/media-1';
    Storage::disk('media')->put("{$base}/optimized_abc.mp4", 'x');
    Storage::disk('media')->put("{$base}/maximized_abc.mp4", 'x');
    Storage::disk('media')->put("{$base}/poster_abc.jpg", 'x');

    $result = app(VideoVariantService::class)->purgeStoragePrefix($base);

    expect($result['deleted'])->toBe(3)
        ->and($result['failures'])->toBe([])
        ->and($result['listError'])->toBeNull();
    Storage::disk('media')->assertMissing("{$base}/optimized_abc.mp4");
    Storage::disk('media')->assertMissing("{$base}/poster_abc.jpg");
});

it('is a harmless no-op when the base path holds no objects (idempotent re-sweep)', function () {
    $result = app(VideoVariantService::class)->purgeStoragePrefix('videos/pro-1/already-gone');

    expect($result['deleted'])->toBe(0)
        ->and($result['failures'])->toBe([])
        ->and($result['listError'])->toBeNull();
});

it('accepts a legacy original-file path and still clears the whole directory', function () {
    $base = 'videos/pro-2/media-2';
    Storage::disk('media')->put("{$base}/optimized_def.mp4", 'x');
    Storage::disk('media')->put("{$base}/poster_def.jpg", 'x');

    // A path ending in a filename (legacy job payload) normalises to its dir.
    $result = app(VideoVariantService::class)->purgeStoragePrefix("{$base}/original_def.mp4");

    expect($result['deleted'])->toBe(2);
    Storage::disk('media')->assertMissing("{$base}/optimized_def.mp4");
});
