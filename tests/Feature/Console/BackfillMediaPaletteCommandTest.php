<?php

/**
 * Coverage for media:backfill-palette (#76 Part A) — the sweep that fills the
 * palette on EXISTING gallery images that predate inline extraction. Seeds a
 * ready image with a real original on a local disk, runs the command, and
 * asserts the palette + dominant_color land (and that --dry-run writes nothing).
 */

use App\Console\Commands\BackfillMediaPaletteCommand;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupMediaTables();

    $testRoot = storage_path('framework/testing/disks/backfill-palette');
    config([
        'partna.media_disk' => 'local',
        'filesystems.disks.local.root' => $testRoot,
    ]);
    if (! is_dir($testRoot)) {
        mkdir($testRoot, 0777, true);
    }
});

/**
 * Seed a ready gallery image row whose original exists on the media disk.
 * Returns the media id.
 */
function seedBackfillRow(int $r, int $g, int $b): string
{
    $id = (string) Str::uuid();
    $path = "images/backfill/{$id}/original.jpg";

    // Write a real solid-colour JPEG to the local disk at $path.
    $img = imagecreatetruecolor(200, 200);
    imagefilledrectangle($img, 0, 0, 200, 200, imagecolorallocate($img, $r, $g, $b));
    $tmp = tempnam(sys_get_temp_dir(), 'backfill_seed_');
    imagejpeg($img, $tmp, 95);
    Storage::disk('local')->put($path, file_get_contents($tmp));
    @unlink($tmp);

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => (string) Str::uuid(),
        'usage' => SiteMedia::USAGE_CONTENT,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'path' => $path,
        'sort_order' => 0,
        'is_active' => true,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

it('fills the palette for an existing ready image lacking one', function () {
    $id = seedBackfillRow(230, 120, 60); // warm

    $this->artisan('media:backfill-palette')->assertExitCode(0);

    $row = SiteMedia::find($id);
    expect($row->palette)->toBeArray()
        ->and($row->dominant_color)->toMatch('/^#[0-9a-f]{6}$/')
        ->and($row->palette['warm'])->toBeTrue();
});

it('writes nothing under --dry-run', function () {
    $id = seedBackfillRow(230, 120, 60);

    $this->artisan('media:backfill-palette', ['--dry-run' => true])->assertExitCode(0);

    expect(SiteMedia::find($id)->palette)->toBeNull();
});

it('skips rows that already have a palette (idempotent)', function () {
    $id = seedBackfillRow(230, 120, 60);
    DB::connection('pgsql')->table('site.site_media')->where('id', $id)->update([
        'palette' => json_encode(['dominant' => '#abcabc', 'colors' => ['#abcabc'], 'saturation' => 0.5, 'warm' => false]),
        'dominant_color' => '#abcabc',
    ]);

    $this->artisan('media:backfill-palette')->expectsOutputToContain('No gallery images need a palette backfill.');

    // Untouched — still the seeded sentinel, not re-extracted.
    expect(SiteMedia::find($id)->dominant_color)->toBe('#abcabc');
});

// ── OBS-5: an explicit $timeout ceiling on this manual backfill sweep ──

it('declares an explicit, non-null $timeout ceiling', function () {
    $property = (new ReflectionClass(BackfillMediaPaletteCommand::class))->getProperty('timeout');
    $property->setAccessible(true);

    expect($property->getDefaultValue())->not->toBeNull()
        ->and($property->getDefaultValue())->toBeGreaterThan(0);
});

// ── #PGR-9: chunkById() replaces cursor() ──

it('fills every row across chunk boundaries — the mutating predicate case', function () {
    config(['partna.media.palette_backfill_chunk' => 2]);
    $ids = [
        seedBackfillRow(230, 120, 60),
        seedBackfillRow(10, 200, 30),
        seedBackfillRow(50, 50, 220),
    ];

    $this->artisan('media:backfill-palette')->assertExitCode(0);

    foreach ($ids as $id) {
        expect(SiteMedia::find($id)->palette)->toBeArray();
    }
});

it('--limit processes exactly N, never N + chunk - 1 (the overshoot regression)', function () {
    config(['partna.media.palette_backfill_chunk' => 2]);
    foreach (range(1, 5) as $i) {
        seedBackfillRow(10 * $i, 20 * $i % 200, 30 * $i % 200);
    }

    $this->artisan('media:backfill-palette', ['--limit' => 3])
        ->expectsOutputToContain('3 processed')
        ->assertExitCode(0);

    $filledCount = SiteMedia::query()->whereNotNull('palette')->count();
    expect($filledCount)->toBe(3);
});
