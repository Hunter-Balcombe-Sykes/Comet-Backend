<?php

use Symfony\Component\Finder\Finder;

// Item 5 (2026-09-01, one media pool): site_media POOL_GALLERY is retired as
// a write target — GalleryAutoGrabber (its last app-side writer) moved to
// POOL_CONTENT + the upload→pool bridge, migration 20260901200000 emptied
// the lane, and the model's default pool flipped to content. Reads survive
// (legacy filter/list surfaces, GALLERY_POOLS), and their `where('pool', …)`
// shape is deliberately not matched here — this guard forbids only the WRITE
// shapes, so the lane can never quietly refill:
//   - `pool: SiteMedia::POOL_GALLERY`   (named-arg into MediaUploadService)
//   - `'pool' => …POOL_GALLERY`         (attribute array / insert)
//   - `'pool' => 'gallery'`             (string-literal insert)
// A dynamic `'pool' => $pool` is invisible to this sweep by design; its
// sources are request-validated pools (config partna.upload_pools), which is
// the one remaining gallery door and is tracked for retirement with that
// endpoint, not here.
it('nothing in app/ writes site_media into the retired gallery pool', function () {
    $offenders = [];
    $patterns = [
        'pool: SiteMedia::POOL_GALLERY',
        "'pool' => SiteMedia::POOL_GALLERY",
        '"pool" => SiteMedia::POOL_GALLERY',
        "'pool' => self::POOL_GALLERY",
        '"pool" => self::POOL_GALLERY',
        "'pool' => 'gallery'",
        '"pool" => "gallery"',
        "'pool' => \"gallery\"",
        '"pool" => \'gallery\'',
    ];

    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        $contents = $file->getContents();
        foreach ($patterns as $pattern) {
            if (str_contains($contents, $pattern)) {
                $offenders[] = $file->getRelativePathname().' contains '.$pattern;
            }
        }
    }

    expect($offenders)->toBe([]);
});

// Positive control: the sweep above passing on a clean tree proves nothing
// about the patterns themselves — this proves the exact write shape the
// grabber used until Item 5 would be caught.
it('would catch the write shapes the sweep exists for', function () {
    $regression = '$this->uploads->upload(pool: SiteMedia::POOL_GALLERY, isVideo: false);';
    $insert = "SiteMedia::query()->create(['pool' => 'gallery']);";

    expect(str_contains($regression, 'pool: SiteMedia::POOL_GALLERY'))->toBeTrue()
        ->and(str_contains($insert, "'pool' => 'gallery'"))->toBeTrue();
});
