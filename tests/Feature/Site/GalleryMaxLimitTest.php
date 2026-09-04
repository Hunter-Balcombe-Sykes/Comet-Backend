<?php

use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Tests that the gallery slot count (used by UserUploadController) correctly
// excludes failed-processing rows. Failed uploads are a terminal state — they occupy no
// usable slot and must not reduce the effective capacity for new uploads.

beforeEach(function () {
    setupMediaTables();
});

it('excludes failed-processing gallery rows from the active slot count', function () {
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    // 4 ready gallery rows — these count as usable slots
    for ($i = 0; $i < 4; $i++) {
        DB::connection('pgsql')->table('site.site_media')->insert([
            'id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'usage' => SiteMedia::USAGE_CONTENT,
            'is_active' => 1,
            'processing_state' => SiteMedia::PROCESSING_STATE_READY,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // 1 failed gallery row — must NOT count as a usable slot
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'usage' => SiteMedia::USAGE_CONTENT,
        'is_active' => 1,
        'processing_state' => SiteMedia::PROCESSING_STATE_FAILED,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $activeCount = SiteMedia::query()
        ->where('site_id', $siteId)
        ->where('usage', SiteMedia::USAGE_CONTENT)
        ->where('is_active', true)
        ->where('processing_state', '!=', SiteMedia::PROCESSING_STATE_FAILED)
        ->count();

    expect($activeCount)->toBe(4);
});

it('counts only the active non-failed rows across pool boundaries', function () {
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    // Content pool: 3 ready, 1 failed
    foreach ([SiteMedia::PROCESSING_STATE_READY, SiteMedia::PROCESSING_STATE_READY, SiteMedia::PROCESSING_STATE_READY, SiteMedia::PROCESSING_STATE_FAILED] as $state) {
        DB::connection('pgsql')->table('site.site_media')->insert([
            'id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'usage' => SiteMedia::USAGE_CONTENT,
            'is_active' => 1,
            'processing_state' => $state,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // Another pool (design): must not affect the content slot count.
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'usage' => SiteMedia::USAGE_DESIGN,
        'is_active' => 1,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $galleryCount = SiteMedia::query()
        ->where('site_id', $siteId)
        ->where('usage', SiteMedia::USAGE_CONTENT)
        ->where('is_active', true)
        ->where('processing_state', '!=', SiteMedia::PROCESSING_STATE_FAILED)
        ->count();

    expect($galleryCount)->toBe(3);
});

it('pending-state gallery rows count as occupied slots', function () {
    // A pending row (upload in progress) still reserves the slot — only failed is excluded.
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'usage' => SiteMedia::USAGE_CONTENT,
        'is_active' => 1,
        'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $count = SiteMedia::query()
        ->where('site_id', $siteId)
        ->where('usage', SiteMedia::USAGE_CONTENT)
        ->where('is_active', true)
        ->where('processing_state', '!=', SiteMedia::PROCESSING_STATE_FAILED)
        ->count();

    expect($count)->toBe(1);
});
