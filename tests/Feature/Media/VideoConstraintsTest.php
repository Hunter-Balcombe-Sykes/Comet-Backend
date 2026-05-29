<?php

use App\Http\Requests\Api\User\Uploads\UploadImageRequest;

it('caps video uploads at 200 MB and 30 seconds', function () {
    expect(config('partna.video_max_upload_size'))->toBe(204800)   // 200 MB in KB
        ->and(config('partna.video_max_duration_seconds'))->toBe(30);
});

it('keeps both 720p and 1080p MP4 variant tiers', function () {
    $variants = config('partna.video_variants');

    expect($variants)->toHaveKeys(['optimized', 'maximized'])
        ->and($variants['optimized']['resolution'])->toBe('1280x720')
        ->and($variants['maximized']['resolution'])->toBe('1920x1080');
});

it('rejects avi and oversized videos, accepts mp4 within cap', function () {
    $rules = (new UploadImageRequest)->rules();

    // mimes rule no longer lists avi
    $videoRules = collect($rules['video'])->first(fn ($r) => is_string($r) && str_starts_with($r, 'mimes:'));
    expect($videoRules)->toBe('mimes:mp4,mov,webm');

    // size rule reflects the 200 MB cap (204800 KB)
    $maxRule = collect($rules['video'])->first(fn ($r) => is_string($r) && str_starts_with($r, 'max:'));
    expect($maxRule)->toBe('max:204800');
});
