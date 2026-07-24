<?php

/**
 * Content Library + Selection backend — the sitepage background picks surface.
 *
 * Covers the library endpoint (uploads + google photos), the content upload
 * create/delete lifecycle, the whole-selection replace (ordering + ≤15 cap +
 * ig-placement rule), the Instagram-auto toggle (reserve/remove ig slots), the
 * connect-time hooks (GB auto-seed once, IG flips the flag), and resolve()
 * dropping a google-photo whose ref vanished.
 */

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\ContentSelection;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Site\ContentSelectionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    setupContentSelectionTable();

    // Observer side-effects (cache-warm / KV-sync / preset-resolve jobs) read
    // tables we don't provision here; faking the queue makes those dispatches
    // no-ops. Our own connect hooks run SYNCHRONOUSLY inside saved() (not as
    // jobs), so they still execute under a faked queue.
    Queue::fake();

    // A configured public URL so variantUrls() resolves servable URLs.
    config(['filesystems.disks.media.url' => 'https://cdn.test']);
});

/**
 * Create a user + their 1:1 site and return [User, Site].
 *
 * @return array{0: User, 1: Site}
 */
function contentUserWithSite(string $handle): array
{
    $user = User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);

    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => $handle,
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $site = Site::query()->findOrFail($siteId);

    return [$user->fresh()->load('site'), $site];
}

/** Insert a ready content-pool upload for $site with one servable webp variant. */
function contentUpload(Site $site, array $overrides = []): SiteMedia
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.site_media')->insert(array_merge([
        'id' => $id,
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_CONTENT,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'alt_text' => 'Content image',
        'path' => "images/content/{$id}.jpg",
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(),
        'media_id' => $id,
        'variant_key' => 'optimized',
        'artifact_type' => 'webp',
        'disk' => 'media',
        'path' => "images/content/{$id}_optimized.webp",
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return SiteMedia::query()->findOrFail($id);
}

/** Attach an active google-business connection whose payload carries photos. */
function gbConnectionWithPhotos(User $user, array $photos): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['name' => 'Test Biz', 'photos' => $photos],
        'is_active' => true,
    ]);
}

/** Attach an active instagram connection with the given mirrored payload. */
function igConnection(User $user, array $payload = []): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => $payload,
        'is_active' => true,
    ]);
}

// ── Library ──────────────────────────────────────────────────────────────────

it('library returns content uploads and google photos', function () {
    [$user, $site] = contentUserWithSite('lib1');
    contentUpload($site, ['alt_text' => 'Alpha']);
    gbConnectionWithPhotos($user, [
        ['ref' => 'places/A/photos/1', 'url' => 'https://lh3/1.jpg'],
        ['ref' => 'places/A/photos/2', 'url' => 'https://lh3/2.jpg'],
    ]);

    $res = actingAsUser($user)->getJson('/api/content/library')->assertOk();

    $res->assertJsonCount(1, 'uploads');
    expect($res->json('uploads.0.alt_text'))->toBe('Alpha');
    expect($res->json('uploads.0.url'))->toBe('https://cdn.test/images/content/'.basename($res->json('uploads.0.url')));

    $res->assertJsonCount(2, 'googlePhotos');
    expect($res->json('googlePhotos.0.ref'))->toBe('places/A/photos/1');
    expect($res->json('googlePhotos.0.url'))->toBe('https://lh3/1.jpg');
});

it('library returns an empty googlePhotos array when there is no GB connection', function () {
    [$user] = contentUserWithSite('lib2');

    actingAsUser($user)->getJson('/api/content/library')
        ->assertOk()
        ->assertJsonCount(0, 'googlePhotos');
});

// ── Uploads ──────────────────────────────────────────────────────────────────

it('upload creates a pool=content site_media row', function () {
    Storage::fake('media');
    config(['partna.media_disk' => 'media', 'filesystems.disks.media.url' => 'https://cdn.test']);

    [$user] = contentUserWithSite('up1');

    $res = actingAsUser($user)->postJson('/api/content/uploads', [
        'image' => UploadedFile::fake()->image('bg.jpg', 200, 200),
        'alt_text' => 'My BG',
    ])->assertStatus(201);

    expect($res->json('id'))->not->toBeNull();

    $row = SiteMedia::query()->where('id', $res->json('id'))->first();
    expect($row)->not->toBeNull();
    expect($row->pool)->toBe(SiteMedia::POOL_CONTENT);
    expect($row->alt_text)->toBe('My BG');
});

it('upload rejects a file whose real bytes do not match its declared image mime', function () {
    Storage::fake('media');
    config(['partna.media_disk' => 'media', 'filesystems.disks.media.url' => 'https://cdn.test']);

    [$user] = contentUserWithSite('up4');

    // Declares image/png (passes the `image`+`mimes` rules, which trust the
    // reported mime) but the underlying bytes are not a real PNG — only the
    // finfo-based magic-byte sniff in SniffsFileMimeType catches this.
    $disguised = UploadedFile::fake()->create('evil.png', 10, 'image/png');

    actingAsUser($user)->postJson('/api/content/uploads', [
        'image' => $disguised,
    ])->assertStatus(422)
        ->assertJsonValidationErrors('image');

    expect(SiteMedia::query()->count())->toBe(0);
});

it('delete removes the upload and any selection row referencing it', function () {
    [$user, $site] = contentUserWithSite('up2');
    $media = contentUpload($site);

    // Put the upload into the selection at position 1.
    ContentSelection::forceCreate([
        'site_id' => $site->id,
        'position' => 1,
        'entry_type' => ContentSelection::TYPE_UPLOAD,
        'media_id' => $media->id,
    ]);

    actingAsUser($user)->deleteJson("/api/content/uploads/{$media->id}")
        ->assertOk()
        ->assertJson(['deleted' => true]);

    expect(SiteMedia::query()->where('id', $media->id)->exists())->toBeFalse(); // soft-deleted
    expect(ContentSelection::query()->where('media_id', $media->id)->exists())->toBeFalse();
});

it('delete returns 404 for a non-content or wrong-site upload', function () {
    [$user, $site] = contentUserWithSite('up3');
    $galleryMedia = contentUpload($site, ['pool' => SiteMedia::POOL_GALLERY]);

    actingAsUser($user)->deleteJson("/api/content/uploads/{$galleryMedia->id}")
        ->assertStatus(404);
});

// SEC-10: ownership of $upload is now enforced via SiteMedia's own SitePolicy
// (setRelation('site', $site) + authorizeForUser('delete', $upload)) rather
// than an inline site_id comparison. This proves the Policy-routed check
// still correctly denies a cross-tenant upload.
it('delete returns 404 for another professionals content upload', function () {
    [, $ownerSite] = contentUserWithSite('up4-owner');
    [$intruder] = contentUserWithSite('up4-intruder');
    $media = contentUpload($ownerSite);

    actingAsUser($intruder)->deleteJson("/api/content/uploads/{$media->id}")
        ->assertStatus(404);

    expect(SiteMedia::query()->where('id', $media->id)->exists())->toBeTrue();
});

// ── Selection replace ────────────────────────────────────────────────────────

it('PUT selection persists the ordered entries', function () {
    [$user, $site] = contentUserWithSite('sel1');
    $m1 = contentUpload($site);
    gbConnectionWithPhotos($user, [['ref' => 'places/A/photos/9', 'url' => 'https://lh3/9.jpg']]);

    actingAsUser($user)->putJson('/api/content/selection', [
        'entries' => [
            ['type' => 'google-photo', 'ref' => 'places/A/photos/9'],
            ['type' => 'upload', 'mediaId' => $m1->id],
        ],
    ])->assertOk();

    $rows = ContentSelection::query()->where('site_id', $site->id)->orderBy('position')->get();
    expect($rows)->toHaveCount(2);
    expect($rows[0]->entry_type)->toBe('google-photo');
    expect($rows[0]->position)->toBe(1);
    expect($rows[0]->external_ref)->toBe('places/A/photos/9');
    expect($rows[1]->entry_type)->toBe('upload');
    expect($rows[1]->position)->toBe(2);
    expect((string) $rows[1]->media_id)->toBe((string) $m1->id);
});

// site_id is not fillable (tenancy FK, NOT NULL) — ContentSelectionService::
// persist() must set it via forceCreate() from the server-resolved Site.
// Querying by site_id already proves this indirectly (a dropped write would
// return 0 rows above), but assert the persisted value directly too so a
// regression back to a bare create() call fails loudly here, not just via a
// filtered query returning empty.
it('persist() writes site_id on every row via forceCreate, not mass-assignment', function () {
    [$user, $site] = contentUserWithSite('sel5');
    $m1 = contentUpload($site);

    app(ContentSelectionService::class)->replace($site, [
        ['type' => 'upload', 'mediaId' => $m1->id],
    ]);

    $row = ContentSelection::query()->firstOrFail();
    expect($row->fresh()->site_id)->not->toBeNull();
    expect((string) $row->fresh()->site_id)->toBe((string) $site->id);
});

it('PUT selection rejects more than 15 entries', function () {
    [$user, $site] = contentUserWithSite('sel2');

    $entries = [];
    for ($i = 0; $i < 16; $i++) {
        $entries[] = ['type' => 'google-photo', 'ref' => "places/A/photos/{$i}"];
    }

    actingAsUser($user)->putJson('/api/content/selection', ['entries' => $entries])
        ->assertStatus(422);

    expect(ContentSelection::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('PUT selection rejects an instagram row at position 3 when auto is enabled', function () {
    [$user, $site] = contentUserWithSite('sel3');
    $site->content_instagram_auto_enabled = true;
    $site->save();
    $m1 = contentUpload($site);
    $m2 = contentUpload($site);

    // Reload so the auth-resolved user carries the current site state (in
    // production every request loads a fresh User via LoadCurrentUser; the test
    // fixture holds a site relation captured before the flag flip).
    actingAsUser($user->fresh()->load('site'))->putJson('/api/content/selection', [
        'entries' => [
            ['type' => 'upload', 'mediaId' => $m1->id],
            ['type' => 'upload', 'mediaId' => $m2->id],
            ['type' => 'ig-reel'], // position 3 — not allowed while auto is on
        ],
    ])->assertStatus(422);
});

it('PUT selection allows an instagram row at position 1 when auto is enabled', function () {
    [$user, $site] = contentUserWithSite('sel4');
    $site->content_instagram_auto_enabled = true;
    $site->save();
    $m1 = contentUpload($site);

    actingAsUser($user)->putJson('/api/content/selection', [
        'entries' => [
            ['type' => 'ig-reel'],
            ['type' => 'upload', 'mediaId' => $m1->id],
        ],
    ])->assertOk();

    expect(ContentSelection::query()->where('site_id', $site->id)->count())->toBe(2);
});

// ── Instagram auto toggle ────────────────────────────────────────────────────

it('instagram-auto enable inserts ig-reel@1 and ig-post@2', function () {
    [$user, $site] = contentUserWithSite('ig1');
    igConnection($user, [
        'images' => ['https://r2/post.jpg'],
        'videoUrl' => 'https://r2/reel.mp4',
        'videoPoster' => 'https://r2/poster.jpg',
    ]);

    actingAsUser($user)->putJson('/api/content/instagram-auto', ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('instagramAutoEnabled', true);

    $rows = ContentSelection::query()->where('site_id', $site->id)->orderBy('position')->get();
    expect($rows)->toHaveCount(2);
    expect($rows[0]->entry_type)->toBe('ig-reel');
    expect($rows[0]->position)->toBe(1);
    expect($rows[1]->entry_type)->toBe('ig-post');
    expect($rows[1]->position)->toBe(2);

    expect($site->fresh()->content_instagram_auto_enabled)->toBeTrue();
});

it('instagram-auto disable removes ig-* rows and compacts positions', function () {
    [$user, $site] = contentUserWithSite('ig2');
    igConnection($user, [
        'images' => ['https://r2/post.jpg'],
        'videoUrl' => 'https://r2/reel.mp4',
        'videoPoster' => 'https://r2/poster.jpg',
    ]);
    $upload = contentUpload($site);

    // Enable, then add a manual upload at the end.
    app(ContentSelectionService::class)->setInstagramAuto($site, true);
    ContentSelection::forceCreate([
        'site_id' => $site->id,
        'position' => 3,
        'entry_type' => ContentSelection::TYPE_UPLOAD,
        'media_id' => $upload->id,
    ]);

    actingAsUser($user)->putJson('/api/content/instagram-auto', ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('instagramAutoEnabled', false);

    $rows = ContentSelection::query()->where('site_id', $site->id)->orderBy('position')->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->entry_type)->toBe('upload');
    expect($rows[0]->position)->toBe(1); // compacted from position 3 → 1
});

it('instagram-auto enable only reserves slots for the kinds the user has', function () {
    [$user, $site] = contentUserWithSite('ig3');
    // Reel present, but NO post image.
    igConnection($user, ['images' => [], 'videoUrl' => 'https://r2/reel.mp4']);

    app(ContentSelectionService::class)->setInstagramAuto($site, true);

    $rows = ContentSelection::query()->where('site_id', $site->id)->orderBy('position')->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->entry_type)->toBe('ig-reel');
    expect($rows[0]->position)->toBe(1);
});

it('instagram-auto toggle rolls back the flag when slot rebuild fails', function () {
    [$user, $site] = contentUserWithSite('ig4');
    $upload = contentUpload($site);
    $original = ContentSelection::forceCreate([
        'site_id' => $site->id,
        'position' => 1,
        'entry_type' => ContentSelection::TYPE_UPLOAD,
        'media_id' => $upload->id,
    ]);

    // Force persist()'s create loop to fail — schema-agnostic across SQLite
    // (tests) and Postgres (prod), unlike relying on a specific constraint.
    ContentSelection::creating(function () {
        throw new RuntimeException('boom');
    });

    expect(fn () => app(ContentSelectionService::class)->setInstagramAuto($site, true))
        ->toThrow(RuntimeException::class);

    // The flag flip and the slot rebuild are one transaction — a persist()
    // failure must roll back the flag too, not leave it durably true.
    expect($site->fresh()->content_instagram_auto_enabled)->not->toBeTrue();

    // persist()'s delete rolled back with it — the original manual row survives.
    $rows = ContentSelection::query()->where('site_id', $site->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->id)->toBe($original->id);

    // SiteObserver's purge is afterCommit-gated — it must never fire when the
    // wrapping transaction rolls back.
    Queue::assertNotPushed(CloudflareCachePurgeJob::class);

    // Don't leak the forced-failure listener into later tests in this file.
    ContentSelection::flushEventListeners();
});

// ── Connect-time hooks ───────────────────────────────────────────────────────

it('GB connect auto-seeds google photos only when the selection is empty', function () {
    [$user, $site] = contentUserWithSite('hook1');

    // Connecting GB with photos seeds google-photo rows.
    gbConnectionWithPhotos($user, [
        ['ref' => 'places/A/photos/1', 'url' => 'https://lh3/1.jpg'],
        ['ref' => 'places/A/photos/2', 'url' => 'https://lh3/2.jpg'],
    ]);

    $rows = ContentSelection::query()->where('site_id', $site->id)->orderBy('position')->get();
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('entry_type')->unique()->all())->toBe(['google-photo']);
    expect($rows[0]->external_ref)->toBe('places/A/photos/1');
});

it('GB connect does NOT seed when the selection already has real content', function () {
    [$user, $site] = contentUserWithSite('hook2');
    $upload = contentUpload($site);
    ContentSelection::forceCreate([
        'site_id' => $site->id,
        'position' => 1,
        'entry_type' => ContentSelection::TYPE_UPLOAD,
        'media_id' => $upload->id,
    ]);

    gbConnectionWithPhotos($user, [['ref' => 'places/A/photos/1', 'url' => 'https://lh3/1.jpg']]);

    $rows = ContentSelection::query()->where('site_id', $site->id)->orderBy('position')->get();
    // Still just the one upload — no google-photo rows appended.
    expect($rows)->toHaveCount(1);
    expect($rows[0]->entry_type)->toBe('upload');
});

it('IG connect flips the content instagram-auto toggle on', function () {
    [$user, $site] = contentUserWithSite('hook3');
    expect($site->content_instagram_auto_enabled)->toBeNull();

    igConnection($user, ['images' => ['https://r2/post.jpg'], 'videoUrl' => 'https://r2/reel.mp4']);

    expect($site->fresh()->content_instagram_auto_enabled)->toBeTrue();
});

it('IG connect with an already-filled payload reserves ig slots 1-2 immediately', function () {
    [$user, $site] = contentUserWithSite('hook6');

    igConnection($user, ['images' => ['https://r2/post.jpg'], 'videoUrl' => 'https://r2/reel.mp4']);

    $rows = ContentSelection::query()->where('site_id', $site->id)->orderBy('position')->get();
    expect($rows->pluck('entry_type')->all())->toBe(['ig-reel', 'ig-post']);
});

it('IG payload landing after the placeholder connect reserves ig slots and shifts google photos down', function () {
    [$user, $site] = contentUserWithSite('hook7');

    // GB connected first — with the full 15-photo payload the seed fills all slots.
    gbConnectionWithPhotos($user, array_map(
        fn (int $i) => ['ref' => "places/A/photos/{$i}", 'url' => "https://lh3/{$i}.jpg"],
        range(1, 15),
    ));
    expect(ContentSelection::query()->where('site_id', $site->id)->count())->toBe(15);

    // The real IG connect writes a pending placeholder row FIRST (empty payload)
    // — the flag flips, but there's nothing to reserve yet.
    $ig = igConnection($user, []);
    expect(ContentSelection::query()->where('site_id', $site->id)->whereIn('entry_type', ContentSelection::IG_TYPES)->count())->toBe(0);

    // The scrape lands: the payload update must reconcile the reserved slots —
    // ig-reel@1 + ig-post@2, google photos shifted down, overflow dropped.
    $ig->update(['payload' => ['images' => ['https://r2/post.jpg'], 'videoUrl' => 'https://r2/reel.mp4']]);

    $rows = ContentSelection::query()->where('site_id', $site->id)->orderBy('position')->get();
    expect($rows)->toHaveCount(15);
    expect($rows[0]->entry_type)->toBe('ig-reel');
    expect($rows[1]->entry_type)->toBe('ig-post');
    expect($rows->slice(2)->pluck('entry_type')->unique()->values()->all())->toBe(['google-photo']);
    // The first 13 google photos survive in order; the last 2 dropped past the cap.
    expect($rows[2]->external_ref)->toBe('places/A/photos/1');
    expect($rows[14]->external_ref)->toBe('places/A/photos/13');
});

it('the ig-slot reconcile never resurrects slots the user removed by disabling auto', function () {
    [$user, $site] = contentUserWithSite('hook8');

    $ig = igConnection($user, []); // placeholder connect — flag on
    app(ContentSelectionService::class)->setInstagramAuto($site->fresh(), false); // user turns it off

    $ig->update(['payload' => ['images' => ['https://r2/post.jpg'], 'videoUrl' => 'https://r2/reel.mp4']]);

    expect($site->fresh()->content_instagram_auto_enabled)->toBeFalse();
    expect(ContentSelection::query()->where('site_id', $site->id)->whereIn('entry_type', ContentSelection::IG_TYPES)->count())->toBe(0);
});

it('the ig-slot reconcile no-ops on later payload refreshes once slots exist', function () {
    [$user, $site] = contentUserWithSite('hook9');

    $ig = igConnection($user, []);
    $ig->update(['payload' => ['images' => ['https://r2/post.jpg'], 'videoUrl' => 'https://r2/reel.mp4']]);
    expect(ContentSelection::query()->where('site_id', $site->id)->count())->toBe(2);

    // A later weekly refresh writes a new payload — slots must not duplicate or reshuffle.
    $ig->update(['payload' => ['images' => ['https://r2/post-2.jpg'], 'videoUrl' => 'https://r2/reel-2.mp4']]);

    $rows = ContentSelection::query()->where('site_id', $site->id)->orderBy('position')->get();
    expect($rows->pluck('entry_type')->all())->toBe(['ig-reel', 'ig-post']);
});

// ── resolve() live resolution ────────────────────────────────────────────────

it('resolve() drops a google-photo whose ref has vanished from the payload', function () {
    [$user, $site] = contentUserWithSite('res1');
    // Only photo "1" exists in the payload now.
    gbConnectionWithPhotos($user, [['ref' => 'places/A/photos/1', 'url' => 'https://lh3/1.jpg']]);

    // Build the selection via replace() (clears the GB-connect auto-seed first,
    // avoiding a transient UNIQUE(position) collision): one live ref (1) and one
    // stale ref (99) that no longer exists in the payload.
    app(ContentSelectionService::class)->replace($site, [
        ['type' => 'google-photo', 'ref' => 'places/A/photos/1'],
        ['type' => 'google-photo', 'ref' => 'places/A/photos/99'],
    ]);

    $resolved = app(ContentSelectionService::class)->resolve($site);

    // Only the live ref survives; the dangling one is dropped.
    expect($resolved)->toHaveCount(1);
    expect($resolved[0]['type'])->toBe('google-photo');
    expect($resolved[0]['url'])->toBe('https://lh3/1.jpg');
    expect($resolved[0]['badge'])->toBe('google-business');
});

it('resolve() expands uploads and instagram reel/post rows', function () {
    [$user, $site] = contentUserWithSite('res2');
    $upload = contentUpload($site);
    igConnection($user, [
        'images' => ['https://r2/post.jpg'],
        'videoUrl' => 'https://r2/reel.mp4',
        'videoPoster' => 'https://r2/poster.jpg',
    ]);

    // ig-reel@1 + ig-post@2 were auto-reserved by the connect hook (the payload
    // above already carries both kinds) — only the upload row is added manually.
    ContentSelection::forceCreate([
        'site_id' => $site->id, 'position' => 3,
        'entry_type' => 'upload', 'media_id' => $upload->id,
    ]);

    $resolved = app(ContentSelectionService::class)->resolve($site);

    expect($resolved)->toHaveCount(3);
    expect($resolved[0])->toMatchArray([
        'kind' => 'video', 'type' => 'ig-reel', 'url' => 'https://r2/reel.mp4',
        'poster' => 'https://r2/poster.jpg', 'badge' => 'instagram',
    ]);
    expect($resolved[1])->toMatchArray([
        'kind' => 'image', 'type' => 'ig-post', 'url' => 'https://r2/post.jpg', 'badge' => 'instagram',
    ]);
    expect($resolved[2]['type'])->toBe('upload');
    expect($resolved[2]['kind'])->toBe('image');
    expect($resolved[2]['badge'])->toBeNull();
});

it('resolve() drops ig rows when instagram is disconnected', function () {
    [$user, $site] = contentUserWithSite('res3');
    ContentSelection::forceCreate(['site_id' => $site->id, 'position' => 1, 'entry_type' => 'ig-reel']);
    ContentSelection::forceCreate(['site_id' => $site->id, 'position' => 2, 'entry_type' => 'ig-post']);

    $resolved = app(ContentSelectionService::class)->resolve($site);
    expect($resolved)->toBe([]);
});

// ── selection endpoint payload ───────────────────────────────────────────────

it('GET selection returns resolved rows plus IG flags', function () {
    [$user, $site] = contentUserWithSite('get1');
    igConnection($user, ['images' => ['https://r2/post.jpg'], 'videoUrl' => 'https://r2/reel.mp4']);

    // Reload so the auth-resolved user reflects the flag the IG-connect observer
    // set (the fixture's site relation predates the connect).
    $res = actingAsUser($user->fresh()->load('site'))->getJson('/api/content/selection')->assertOk();

    $res->assertJsonPath('instagramConnected', true);
    // igConnection() created the connection which flips auto on via the observer.
    $res->assertJsonPath('instagramAutoEnabled', true);
    $res->assertJsonStructure(['selection', 'instagramAutoEnabled', 'instagramConnected']);
});

// ── WS-B2.1: Google-photos content-inclusion toggle ──────────────────────────

it('content_photos off excludes google photos from the library', function () {
    [$user] = contentUserWithSite('gp1');
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['name' => 'Biz', 'photos' => [['ref' => 'places/A/photos/1', 'url' => 'https://lh3/1.jpg']]],
        'display_settings' => ['content_photos' => false],
        'is_active' => true,
    ]);

    actingAsUser($user)->getJson('/api/content/library')
        ->assertOk()
        ->assertJsonCount(0, 'googlePhotos');
});

it('content_photos off drops google photos from the resolved selection', function () {
    [$user, $site] = contentUserWithSite('gp2');
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['name' => 'Biz', 'photos' => [['ref' => 'places/A/photos/1', 'url' => 'https://lh3/1.jpg']]],
        'display_settings' => ['content_photos' => false],
        'is_active' => true,
    ]);

    app(ContentSelectionService::class)->replace($site, [
        ['type' => 'google-photo', 'ref' => 'places/A/photos/1'],
    ]);

    expect(app(ContentSelectionService::class)->resolve($site))->toBe([]);
});

it('content_photos off makes a GB connect seed no google photos', function () {
    [$user, $site] = contentUserWithSite('gp3');

    // display_settings present AT create time → the connect-seed hook sees the
    // toggle off and seeds nothing.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['name' => 'Biz', 'photos' => [
            ['ref' => 'places/A/photos/1', 'url' => 'https://lh3/1.jpg'],
            ['ref' => 'places/A/photos/2', 'url' => 'https://lh3/2.jpg'],
        ]],
        'display_settings' => ['content_photos' => false],
        'is_active' => true,
    ]);

    expect(ContentSelection::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('PUT google-photos toggles content inclusion and stores it sparsely', function () {
    [$user] = contentUserWithSite('gp4');
    $conn = gbConnectionWithPhotos($user, [['ref' => 'places/A/photos/1', 'url' => 'https://lh3/1.jpg']]);

    actingAsUser($user)->putJson('/api/content/google-photos', ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('googlePhotosEnabled', false)
        ->assertJsonPath('googlePhotosConnected', true);

    // Toggling purges the sitepage profile cache (backgrounds are fed by the
    // content selection) — proves it "genuinely stops flowing", not client-hidden.
    Queue::assertPushed(CloudflareCachePurgeJob::class);

    expect(IntegrationConnection::query()->find($conn->id)->display_settings)->toBe(['content_photos' => false]);

    // Re-enabling removes the key entirely (sparse deviations only).
    actingAsUser($user)->putJson('/api/content/google-photos', ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('googlePhotosEnabled', true);

    expect(IntegrationConnection::query()->find($conn->id)->display_settings)->toBeNull();
});

it('PUT google-photos 404s without a google-business connection', function () {
    [$user] = contentUserWithSite('gp5');

    actingAsUser($user)->putJson('/api/content/google-photos', ['enabled' => false])
        ->assertStatus(404);
});

it('GET selection reports the googlePhotos flags', function () {
    [$user] = contentUserWithSite('gp6');
    gbConnectionWithPhotos($user, [['ref' => 'places/A/photos/1', 'url' => 'https://lh3/1.jpg']]);

    actingAsUser($user->fresh()->load('site'))->getJson('/api/content/selection')
        ->assertOk()
        ->assertJsonPath('googlePhotosConnected', true)
        ->assertJsonPath('googlePhotosEnabled', true);
});

it('content_photos and display-section toggles do not clobber each other', function () {
    [$user] = contentUserWithSite('coexist');
    $conn = gbConnectionWithPhotos($user, [['ref' => 'places/A/photos/1', 'url' => 'https://lh3/1.jpg']]);

    // Content path (PUT /content/google-photos) writes content_photos.
    actingAsUser($user)->putJson('/api/content/google-photos', ['enabled' => false])->assertOk();
    expect(IntegrationConnection::query()->find($conn->id)->display_settings)->toBe(['content_photos' => false]);

    // Platform path (PATCH display-settings) writes a display toggle — content_photos survives.
    actingAsUser($user)->patchJson('/api/platforms/google-business/display-settings', [
        'toggles' => ['reviews' => false],
    ])->assertOk();
    expect(IntegrationConnection::query()->find($conn->id)->display_settings)
        ->toEqual(['content_photos' => false, 'reviews' => false]);

    // Re-enabling reviews leaves content_photos intact.
    actingAsUser($user)->patchJson('/api/platforms/google-business/display-settings', [
        'toggles' => ['reviews' => true],
    ])->assertOk();
    expect(IntegrationConnection::query()->find($conn->id)->display_settings)->toBe(['content_photos' => false]);

    // Reverse: the content path re-enabling leaves an existing display toggle intact.
    actingAsUser($user)->patchJson('/api/platforms/google-business/display-settings', [
        'toggles' => ['reviews' => false],
    ])->assertOk();
    actingAsUser($user)->putJson('/api/content/google-photos', ['enabled' => true])->assertOk();
    expect(IntegrationConnection::query()->find($conn->id)->display_settings)->toBe(['reviews' => false]);
});
