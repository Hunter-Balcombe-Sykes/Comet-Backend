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
//
// Wave 6 (2026-09-02): the READ surfaces left too — POOL_GALLERY the const
// is deleted, GALLERY_POOLS is [POOL_CONTENT], and the /gallery routes +
// controller + resolver arms are gone. The string-literal patterns below are
// now the whole guard — a const reference can't compile (POOL_GALLERY no
// longer exists), and re-adding the const is a review-sized diff by itself.
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

// scripts/ writes into a REAL Postgres database, unlike the SQLite test lane —
// so a leftover 'gallery' literal there is a runtime failure against the
// tightened site_media_pool_check (migration 20260902170000), not a cosmetic
// one. Both seeders had one when that migration was written (the DAST
// identity seeder and the k6 load seed); this is the guard that would have
// caught them. SQL is matched as well as PHP because the k6 seed is raw SQL.
it('nothing in scripts/ seeds site_media into the retired gallery pool', function () {
    $offenders = [];
    $patterns = [
        "'pool' => 'gallery'",
        '"pool" => "gallery"',
        "'gallery', 'image'",      // positional INSERT ... SELECT column list
        "pool = 'gallery'",        // WHERE/UPDATE against the retired lane
    ];

    $finder = Finder::create()->files()->in(base_path('scripts'))
        ->name('*.php')->name('*.sql')
        ->notPath('launch-check/k6/results');   // archived run output, not code

    foreach ($finder as $file) {
        $contents = $file->getContents();
        foreach ($patterns as $pattern) {
            if (str_contains($contents, $pattern)) {
                $offenders[] = 'scripts/'.$file->getRelativePathname().' contains '.$pattern;
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
    $seedSql = "SELECT v_site, 'loadtest.webp', g, true, 'gallery', 'image', 'ready'";
    $seedWhere = "FROM site.site_media WHERE site_id = v_site AND pool = 'gallery';";

    expect(str_contains($regression, 'pool: SiteMedia::POOL_GALLERY'))->toBeTrue()
        ->and(str_contains($insert, "'pool' => 'gallery'"))->toBeTrue()
        ->and(str_contains($seedSql, "'gallery', 'image'"))->toBeTrue()
        ->and(str_contains($seedWhere, "pool = 'gallery'"))->toBeTrue();
});
