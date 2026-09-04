<?php

/**
 * Content Library backend — the browse + upload surface the owner picks
 * imagery from.
 *
 * Covers the library endpoint (content-pool uploads, referenced Google Business
 * photos, referenced Instagram post images) and the upload create/delete
 * lifecycle.
 *
 * Slice 7 unit E deleted the ordered "Content Selection" (site.content_selection,
 * its service/model/policy and the four owner verbs). The selection-shaped tests
 * that lived here went with it; `pool:media` pins are the curation lane now and
 * carry their own coverage.
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Media\VideoVariantService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();

    // Observer side-effects (cache-warm / KV-sync / preset-resolve jobs) read
    // tables we don't provision here; faking the queue makes those dispatches
    // no-ops.
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
        'first_name' => ucfirst($handle),
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
        'usage' => SiteMedia::USAGE_CONTENT,
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

// The WRITE verb for this flag (PUT /content/google-photos) retired with the
// selection surface; the library still HONOURS a stored `content_photos: false`.
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

it('library returns instagram post images whenever the connection is active', function () {
    [$user] = contentUserWithSite('igl1');
    igConnection($user, [
        'images' => ['https://r2/p1.jpg', 'https://r2/p2.jpg', 'not-a-url'],
    ]);

    $res = actingAsUser($user)->getJson('/api/content/library')->assertOk();

    // Only https URLs survive; ref is the stable reference the client echoes back.
    $res->assertJsonCount(2, 'instagramPhotos');
    expect($res->json('instagramPhotos.0'))->toBe(['ref' => 'https://r2/p1.jpg', 'url' => 'https://r2/p1.jpg']);
});

it('library returns an empty instagramPhotos array without an IG connection', function () {
    [$user] = contentUserWithSite('igl2');

    actingAsUser($user)->getJson('/api/content/library')
        ->assertOk()
        ->assertJsonCount(0, 'instagramPhotos');
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
    expect($row->usage)->toBe(SiteMedia::USAGE_CONTENT);
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

// #W2-SEC-2 (reclassified): a rejected video container must 422, not 500.
// ffprobe (VideoVariantService::probeAndValidate) already validates the
// container pre-DB/pre-storage — mirrors UserUploadController's catch chain.
it('upload maps an invalid video container to 422, not a 500', function () {
    Storage::fake('media');
    config(['partna.media_disk' => 'media', 'filesystems.disks.media.url' => 'https://cdn.test']);

    [$user] = contentUserWithSite('up5');

    $videoVariant = Mockery::mock(VideoVariantService::class);
    $videoVariant->shouldReceive('probeAndValidate')
        ->once()
        ->andThrow(new RuntimeException('ffprobe failed (exit 1): Invalid data found when processing input'));
    app()->instance(VideoVariantService::class, $videoVariant);

    $res = actingAsUser($user)->postJson('/api/content/uploads', [
        'video' => UploadedFile::fake()->create('corrupt.mp4', 512, 'video/mp4'),
    ]);

    $res->assertStatus(422);
    expect($res->json('message'))->toContain('Invalid video file');
    expect(SiteMedia::query()->count())->toBe(0);
});

it('delete soft-deletes the upload', function () {
    [$user, $site] = contentUserWithSite('up2');
    $media = contentUpload($site);

    actingAsUser($user)->deleteJson("/api/content/uploads/{$media->id}")
        ->assertOk()
        ->assertJson(['deleted' => true]);

    expect(SiteMedia::query()->where('id', $media->id)->exists())->toBeFalse(); // soft-deleted
});

it('delete returns 404 for a non-content or wrong-site upload', function () {
    [$user, $site] = contentUserWithSite('up3');
    // A design-pool row: the endpoint only deletes content-pool uploads.
    $galleryMedia = contentUpload($site, ['usage' => SiteMedia::USAGE_DESIGN]);

    actingAsUser($user)->deleteJson("/api/content/uploads/{$galleryMedia->id}")
        ->assertStatus(404);
});

// SEC-10: ownership of $upload is enforced via SiteMedia's own SitePolicy
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
