<?php

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
