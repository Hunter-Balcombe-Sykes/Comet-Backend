<?php

use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupMediaTables();
});

/**
 * Insert a site_media row with explicit processing_state, media_type and age.
 */
function seedMediaRow(string $mediaType, string $state, int $updatedMinutesAgo): string
{
    $id = (string) Str::uuid();
    $ts = now()->subMinutes($updatedMinutesAgo)->toDateTimeString();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => (string) Str::uuid(),
        'usage' => 'content',
        'path' => '',
        'sort_order' => 0,
        'is_active' => true,
        'media_type' => $mediaType,
        'processing_state' => $state,
        'created_at' => $ts,
        'updated_at' => $ts,
    ]);

    return $id;
}

it('fails image rows stuck in processing past the 30m threshold', function () {
    $stuck = seedMediaRow(SiteMedia::MEDIA_TYPE_IMAGE, SiteMedia::PROCESSING_STATE_PROCESSING, 40);

    $this->artisan('media:cleanup-stuck-processing')->assertExitCode(0);

    $row = SiteMedia::find($stuck);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_FAILED)
        ->and($row->processing_error)->toBe('processing watchdog timeout');
});

it('fails video rows stuck in processing past the 60m threshold', function () {
    $stuck = seedMediaRow(SiteMedia::MEDIA_TYPE_VIDEO, SiteMedia::PROCESSING_STATE_PROCESSING, 90);

    $this->artisan('media:cleanup-stuck-processing')->assertExitCode(0);

    expect(SiteMedia::find($stuck)->processing_state)->toBe(SiteMedia::PROCESSING_STATE_FAILED);
});

it('leaves recently-updated processing rows untouched', function () {
    // A video updated 40m ago is still within the 60m video threshold — and
    // would have been swept by the 30m image threshold if the media_type
    // filter were wrong.
    $freshImage = seedMediaRow(SiteMedia::MEDIA_TYPE_IMAGE, SiteMedia::PROCESSING_STATE_PROCESSING, 5);
    $freshVideo = seedMediaRow(SiteMedia::MEDIA_TYPE_VIDEO, SiteMedia::PROCESSING_STATE_PROCESSING, 40);

    $this->artisan('media:cleanup-stuck-processing')->assertExitCode(0);

    expect(SiteMedia::find($freshImage)->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PROCESSING)
        ->and(SiteMedia::find($freshVideo)->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PROCESSING);
});

it('leaves rows already in a terminal state untouched', function () {
    $ready = seedMediaRow(SiteMedia::MEDIA_TYPE_IMAGE, SiteMedia::PROCESSING_STATE_READY, 120);
    $failed = seedMediaRow(SiteMedia::MEDIA_TYPE_VIDEO, SiteMedia::PROCESSING_STATE_FAILED, 120);

    $this->artisan('media:cleanup-stuck-processing')->assertExitCode(0);

    expect(SiteMedia::find($ready)->processing_state)->toBe(SiteMedia::PROCESSING_STATE_READY)
        ->and(SiteMedia::find($failed)->processing_state)->toBe(SiteMedia::PROCESSING_STATE_FAILED);
});

it('dry-run reports without mutating rows', function () {
    $stuck = seedMediaRow(SiteMedia::MEDIA_TYPE_IMAGE, SiteMedia::PROCESSING_STATE_PROCESSING, 40);

    $this->artisan('media:cleanup-stuck-processing', ['--dry-run' => true])->assertExitCode(0);

    expect(SiteMedia::find($stuck)->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PROCESSING);
});
