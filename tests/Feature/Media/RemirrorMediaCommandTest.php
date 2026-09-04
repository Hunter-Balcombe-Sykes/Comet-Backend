<?php

use App\Jobs\Media\MirrorMediaAssetJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// media:remirror — the bucket-move / thumbnail-backfill tool (2026-09-04).
// Owned mirrored rows lose their storage_path and get a fresh mirror job;
// uploads, borrowed media and never-mirrored rows are left alone.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Bus::fake();
});

function remirrorAsset(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('content.media_assets')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'fingerprint' => 'url-'.sha1(Str::random(12)),
        'source_url' => 'https://scontent.cdninstagram.com/v/'.Str::random(6).'.jpg',
        'storage_path' => 'content-media/'.$userId.'/'.Str::random(8).'.webp',
        'mime_type' => 'image/webp',
        'mirror_eligible' => true,
        'mirror_attempts' => 0,
        'created_at' => now(),
    ], $overrides));

    return $id;
}

it('clears storage_path on owned mirrored assets and re-dispatches them, videos on the Horizon lane', function () {
    $userId = (string) createTenant('rm-'.Str::lower(Str::random(6)))->id;
    $image = remirrorAsset($userId);
    $video = remirrorAsset($userId, ['mime_type' => 'video/mp4', 'storage_path' => 'content-media/'.$userId.'/reel.mp4']);
    $upload = remirrorAsset($userId, ['site_media_id' => (string) Str::uuid()]);
    $borrowed = remirrorAsset($userId, ['mirror_eligible' => false]);
    $unmirrored = remirrorAsset($userId, ['storage_path' => null]);
    $failed = remirrorAsset($userId, ['storage_path' => null, 'mirror_attempts' => 5, 'mirror_last_reason' => 'fetch_failed']);

    $this->artisan('media:remirror')->assertSuccessful();

    $paths = DB::table('content.media_assets')->pluck('storage_path', 'id');
    expect($paths[$image])->toBeNull()
        ->and($paths[$video])->toBeNull()
        ->and($paths[$upload])->not->toBeNull()
        ->and($paths[$borrowed])->not->toBeNull();
    expect((int) DB::table('content.media_assets')->where('id', $failed)->value('mirror_attempts'))->toBe(5);

    Bus::assertDispatchedTimes(MirrorMediaAssetJob::class, 2);
    Bus::assertDispatched(MirrorMediaAssetJob::class, fn (MirrorMediaAssetJob $job) => $job->assetId === $image && ! $job->video);
    Bus::assertDispatched(MirrorMediaAssetJob::class, fn (MirrorMediaAssetJob $job) => $job->assetId === $video && $job->video);
    Bus::assertNotDispatched(MirrorMediaAssetJob::class, fn (MirrorMediaAssetJob $job) => in_array($job->assetId, [$upload, $borrowed, $unmirrored, $failed], true));
});

it('resets exhausted assets too with --include-failed, and writes nothing on --dry-run', function () {
    $userId = (string) createTenant('rm-'.Str::lower(Str::random(6)))->id;
    $mirrored = remirrorAsset($userId);
    $failed = remirrorAsset($userId, ['storage_path' => null, 'mirror_attempts' => 5, 'mirror_last_reason' => 'fetch_failed']);

    $this->artisan('media:remirror', ['--dry-run' => true])->assertSuccessful();
    expect(DB::table('content.media_assets')->where('id', $mirrored)->value('storage_path'))->not->toBeNull();
    Bus::assertNothingDispatched();

    $this->artisan('media:remirror', ['--include-failed' => true])->assertSuccessful();
    $row = DB::table('content.media_assets')->where('id', $failed)->first();
    expect((int) $row->mirror_attempts)->toBe(0)
        ->and($row->mirror_last_reason)->toBeNull();
    Bus::assertDispatchedTimes(MirrorMediaAssetJob::class, 2);
});

it('scopes to one user with --user', function () {
    $a = (string) createTenant('rm-'.Str::lower(Str::random(6)))->id;
    $b = (string) createTenant('rm-'.Str::lower(Str::random(6)))->id;
    remirrorAsset($a);
    $other = remirrorAsset($b);

    $this->artisan('media:remirror', ['--user' => $a])->assertSuccessful();

    expect(DB::table('content.media_assets')->where('id', $other)->value('storage_path'))->not->toBeNull();
    Bus::assertDispatchedTimes(MirrorMediaAssetJob::class, 1);
});
