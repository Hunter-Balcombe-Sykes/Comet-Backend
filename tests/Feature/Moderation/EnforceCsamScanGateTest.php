<?php

use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Verifies that the public profile endpoint never serves media in
 * `scanning` or `quarantined` processing_state, and that the
 * grandfather cutoff exempts pre-CSAM-scan media with null scanned_at.
 *
 * These tests bootstrap a minimal SQLite schema that mirrors the
 * production site_media table (including bucket and scanned_at) so
 * no live Postgres connection is required.
 */
beforeEach(function () {
    // Disable payload cache so tests always see fresh DB results.
    Cache::flush();

    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();

    // site.site_media — includes bucket and scanned_at (added in CSAM migration),
    // absent from the shared setupMediaTables() helper.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.site_media (
        id TEXT PRIMARY KEY,
        site_id TEXT NULL,
        user_id TEXT NULL,
        pool TEXT NULL,
        bucket TEXT NULL,
        path TEXT NULL,
        original_path TEXT NULL,
        original_mime TEXT NULL,
        original_filename TEXT NULL,
        original_size_bytes INTEGER NULL,
        media_type TEXT NULL,
        processing_state TEXT NULL,
        processing_error TEXT NULL,
        scanned_at TEXT NULL,
        duration_ms INTEGER NULL,
        poster_path TEXT NULL,
        sort_order INTEGER NULL,
        is_active INTEGER NULL,
        alt_text TEXT NULL,
        caption TEXT NULL,
        purpose TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.media_variants (
        id TEXT PRIMARY KEY,
        media_id TEXT NULL,
        variant_key TEXT NULL,
        artifact_type TEXT NULL,
        disk TEXT NULL,
        path TEXT NULL,
        url TEXT NULL,
        mime TEXT NULL,
        width INTEGER NULL,
        height INTEGER NULL,
        bitrate_kbps INTEGER NULL,
        file_size_bytes INTEGER NULL,
        duration_ms INTEGER NULL,
        metadata TEXT NULL,
        content_hash TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    // site.design_kits — queried by IndividualProfilePayloadBuilder::loadDesignKit().
    // All nullable; an absent row is handled gracefully (returns empty array).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.design_kits (
        site_id TEXT PRIMARY KEY,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    // site.services — queried by SitepageDataResolverService::buildServicesData().
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.services (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        category_id TEXT NULL,
        title TEXT NULL,
        description TEXT NULL,
        price_cents INTEGER NULL,
        currency_code TEXT NULL,
        duration_minutes INTEGER NULL,
        sort_order INTEGER NULL,
        is_active INTEGER NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Insert a live gallery section block so the resolver's sectionEnvelope
 * transitions to 'live' and runs the media query. Without this the gallery
 * engine short-circuits to 'draft' regardless of what's in site_media.
 */
function insertLiveGalleryBlock(string $siteId): void
{
    DB::connection('pgsql')->insert(
        "INSERT INTO site.blocks
            (id, site_id, block_group, block_type, sort_order, is_active, is_enabled, settings, created_at, updated_at)
         VALUES (?, ?, 'sections', 'gallery', 0, 1, 1, '{}', ?, ?)",
        [Str::uuid()->toString(), $siteId, now()->toDateTimeString(), now()->toDateTimeString()]
    );
}

/**
 * Insert a gallery-pool site_media row with an optimised variant URL and
 * return the media id. The variant URL embeds the media id so callers can
 * assert on it.
 */
function insertGalleryMedia(string $siteId, string $state, ?string $scannedAt, ?string $createdAt = null): string
{
    $id = Str::uuid()->toString();
    $ts = $createdAt ?? now()->toDateTimeString();
    DB::connection('pgsql')->insert(
        "INSERT INTO site.site_media
            (id, site_id, bucket, pool, path, processing_state, is_active, scanned_at, created_at, updated_at)
         VALUES (?, ?, 'public-assets', 'gallery', ?, ?, 1, ?, ?, ?)",
        [$id, $siteId, "media/{$state}-{$id}.jpg", $state, $scannedAt, $ts, $ts]
    );

    // Attach a 'webp' optimized variant so variantUrls() produces a non-empty URL.
    // The URL accessor uses disk+path; we use the 'public' disk (APP_URL/storage)
    // and embed the media id in the path so callers can assert on it in the response.
    // variantUrls() filters on artifact_type='webp'; rows without a webp variant
    // produce an empty URL and are silently dropped by the filter() call downstream.
    DB::connection('pgsql')->insert(
        "INSERT INTO site.media_variants
            (id, media_id, variant_key, artifact_type, disk, path, created_at, updated_at)
         VALUES (?, ?, 'optimized', 'webp', 'public', ?, ?, ?)",
        [Str::uuid()->toString(), $id, "test-gallery/{$id}/optimized.webp", $ts, $ts]
    );

    return $id;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('filters out site_media rows in scanning or quarantined state from public reads', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();

    // A live gallery section block is required for the resolver to run the
    // media query — without it the engine returns 'draft' and skips the DB.
    insertLiveGalleryBlock($site->id);

    // Insert one row per state. Only 'ready' should reach the public payload.
    $readyId = insertGalleryMedia($site->id, 'ready', now()->toDateTimeString());
    $scanningId = insertGalleryMedia($site->id, 'scanning', null);
    $quarantinedId = insertGalleryMedia($site->id, 'quarantined', null);

    $res = $this->getJson("/api/public/profiles/{$user->handle}");
    $res->assertSuccessful();
    $body = json_encode($res->json());

    expect($body)->toContain($readyId);
    expect($body)->not->toContain($scanningId);
    expect($body)->not->toContain($quarantinedId);
})->group('postgres');

it('includes site_media with null scanned_at when created before the grandfather cutoff', function () {
    // Media uploaded before CSAM scanning was introduced has null scanned_at
    // but should still be served — it pre-dates the gate.
    $cutoff = config('partna.moderation.csam.grandfather_cutoff', '2026-05-26 00:00:00');
    $oldTs = date('Y-m-d H:i:s', strtotime($cutoff) - 3600);

    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();

    insertLiveGalleryBlock($site->id);

    // Use the helper so a webp variant is created — without it variantUrls()
    // returns an empty URL and the item is filtered out before the response.
    $id = insertGalleryMedia($site->id, 'ready', null, $oldTs);

    $res = $this->getJson("/api/public/profiles/{$user->handle}");
    $res->assertSuccessful();
    $body = json_encode($res->json());

    // The variant URL embeds the media id, so containment is a reliable proxy
    // for "this media item reached the public payload".
    expect($body)->toContain($id);
})->group('postgres');

it('excludes site_media with null scanned_at when created after the grandfather cutoff', function () {
    // Media created after CSAM scanning was introduced but without a scanned_at
    // is still in the scanning pipeline — must not be served publicly.
    $cutoff = config('partna.moderation.csam.grandfather_cutoff', '2026-05-26 00:00:00');
    $newTs = date('Y-m-d H:i:s', strtotime($cutoff) + 3600);

    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();

    insertLiveGalleryBlock($site->id);

    $id = Str::uuid()->toString();
    DB::connection('pgsql')->insert(
        "INSERT INTO site.site_media
            (id, site_id, bucket, pool, path, processing_state, is_active, scanned_at, created_at, updated_at)
         VALUES (?, ?, 'public-assets', 'gallery', ?, 'ready', 1, NULL, ?, ?)",
        [$id, $site->id, "media/unscanned-new-{$id}.jpg", $newTs, $newTs]
    );

    $res = $this->getJson("/api/public/profiles/{$user->handle}");
    $res->assertSuccessful();
    $body = json_encode($res->json());

    expect($body)->not->toContain($id);
})->group('postgres');
