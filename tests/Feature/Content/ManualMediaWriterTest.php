<?php

use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Content\ManualMediaWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The upload→pool bridge (plan 04 step E). These tests run add()/remove()
// END-TO-END against the real container on purpose: the writer's first
// deploy shipped with SiteCacheLanes imported from a guessed namespace and
// every suite stayed green because nothing executed add() past its first
// line — PHP resolves an import at call time, so only a test that reaches
// the last line of the lane can catch an unresolvable symbol in it.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
});

function mmwUpload(string $siteId, string $type = 'image', ?string $caption = null): SiteMedia
{
    $id = (string) Str::uuid();
    DB::table('site.site_media')->insert([
        'id' => $id, 'site_id' => $siteId, 'bucket' => 'media',
        'path' => "uploads/{$id}.bin", 'pool' => SiteMedia::POOL_CONTENT,
        'media_type' => $type, 'processing_state' => 'ready',
        'is_active' => 1, 'sort_order' => 0,
        'alt_text' => null, 'caption' => $caption,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return SiteMedia::query()->findOrFail($id);
}

it('mints a live LIBRARY media item for an image upload — and does NOT pin it', function () {
    [$pro, $siteId] = poolTenant();
    $user = User::query()->findOrFail($pro->id);
    $media = mmwUpload($siteId, caption: 'Our shopfront');

    $result = app(ManualMediaWriter::class)->add($user, $media);

    expect($result)->not->toBeNull();

    $item = DB::table('content.items')->where('id', $result['id'])->first();
    expect($item->kind)->toBe('media')
        ->and($item->removed_at)->toBeNull();

    $anchor = DB::table('content.item_anchors')
        ->where('item_id', $result['id'])->value('coord');
    expect($anchor)->toBe('upload:'.$media->id);

    $entry = DB::table('content.item_media')->where('item_id', $result['id'])->first();
    expect($entry->role)->toBe('cover');

    // Owner, 2026-08-27: an add-sheet upload is library-only — it appears as
    // the sheet's top unselected option; selection stays an explicit choice.
    expect(DB::table('site.section_items')->where('item_id', $result['id'])->exists())->toBeFalse();
});

it('lands a video upload with the video role', function () {
    [$pro, $siteId] = poolTenant();
    $user = User::query()->findOrFail($pro->id);
    $media = mmwUpload($siteId, type: SiteMedia::MEDIA_TYPE_VIDEO);

    $result = app(ManualMediaWriter::class)->add($user, $media);

    $entry = DB::table('content.item_media')->where('item_id', $result['id'])->first();
    expect($entry->role)->toBe('video');
});

it('re-adding the same upload un-deletes the same item, not a duplicate', function () {
    [$pro, $siteId] = poolTenant();
    $user = User::query()->findOrFail($pro->id);
    $media = mmwUpload($siteId);
    $writer = app(ManualMediaWriter::class);

    $first = $writer->add($user, $media);
    DB::table('content.items')->where('id', $first['id'])
        ->update(['removed_at' => now()]);

    $second = $writer->add($user, $media);

    expect($second['id'])->toBe($first['id'])
        ->and(DB::table('content.items')->where('id', $first['id'])->value('removed_at'))
        ->toBeNull()
        ->and(DB::table('content.items')->where('user_id', $pro->id)->count())->toBe(1);
});

it('remove() takes the item off the pool without touching the projection rows', function () {
    [$pro, $siteId] = poolTenant();
    $user = User::query()->findOrFail($pro->id);
    $media = mmwUpload($siteId);
    $writer = app(ManualMediaWriter::class);

    $added = $writer->add($user, $media);
    $writer->remove($user, $media);

    expect(DB::table('content.items')->where('id', $added['id'])->value('removed_at'))
        ->not->toBeNull()
        ->and(DB::table('content.item_media')->where('item_id', $added['id'])->count())
        ->toBe(1);
});

it('is a no-op for a user without a site', function () {
    [$pro, $siteId] = poolTenant();
    $media = mmwUpload($siteId);
    DB::table('site.sites')->where('id', $siteId)->delete();
    $user = User::query()->findOrFail($pro->id)->fresh();

    expect(app(ManualMediaWriter::class)->add($user, $media))->toBeNull();
});
