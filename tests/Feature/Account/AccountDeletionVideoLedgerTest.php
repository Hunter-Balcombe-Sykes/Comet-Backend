<?php

use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Services\User\AccountDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    setupUserDeletionAuditTable();

    config([
        'partna.media_disk' => 'media',
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-key',
    ]);

    Storage::fake('media');
    Queue::fake();
    Http::fake(['https://test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);
});

function insertVideoMedia(string $siteId, string $proId): string
{
    $mediaId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $siteId,
        'pool' => SiteMedia::POOL_GALLERY,
        'path' => "videos/{$proId}/{$mediaId}",
        'media_type' => SiteMedia::MEDIA_TYPE_VIDEO,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $mediaId;
}

it('records video artifact paths in the purged audit row so the sweep can recover orphans', function () {
    $professional = createTenant('purge-ledger');
    $mediaId = insertVideoMedia($professional->site->id, $professional->id);

    app(AccountDeletionService::class)->purge($professional);

    // forceDelete() has hard-deleted the site_media row by now — the audit
    // metadata is the ONLY surviving record of which R2 path must be erased.
    $purged = UserDeletionAuditEntry::query()
        ->where('event', UserDeletionAuditEntry::EVENT_PURGED)
        ->first();

    expect($purged)->not->toBeNull()
        ->and($purged->metadata['video_artifact_paths'] ?? null)
        ->toContain("videos/{$professional->id}/{$mediaId}");
});

it('omits the video path ledger entirely when the account has no video media', function () {
    $professional = createTenant('purge-ledger-empty');

    app(AccountDeletionService::class)->purge($professional);

    $purged = UserDeletionAuditEntry::query()
        ->where('event', UserDeletionAuditEntry::EVENT_PURGED)
        ->first();

    expect($purged)->not->toBeNull()
        ->and($purged->metadata['video_artifact_paths'] ?? null)->toBeNull();
});
