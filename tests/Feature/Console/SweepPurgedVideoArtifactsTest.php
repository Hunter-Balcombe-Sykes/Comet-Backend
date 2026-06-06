<?php

use App\Models\Core\User\UserDeletionAuditEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUserDeletionAuditTable();
    config([
        'partna.media_disk' => 'media',
        'partna.media_orphan_sweep.ledger_window_days' => 30,
    ]);
    Storage::fake('media');
});

function seedPurgedLedger(array $paths, ?string $createdAt = null): void
{
    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        'id' => (string) Str::uuid(),
        'professional_handle_snapshot' => 'gone',
        'professional_email_snapshot' => 'gone@example.com',
        'event' => UserDeletionAuditEntry::EVENT_PURGED,
        'actor_type' => UserDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
        'metadata' => json_encode(['video_artifact_paths' => $paths]),
        'created_at' => $createdAt ?? now()->toDateTimeString(),
    ]);
}

it('re-deletes a ledgered video path that a failed job left behind on R2', function () {
    $base = 'videos/pro-1/media-1';
    Storage::disk('media')->put("{$base}/optimized_abc.mp4", 'x');
    Storage::disk('media')->put("{$base}/poster_abc.jpg", 'x');
    seedPurgedLedger([$base]);

    $this->artisan('gdpr:sweep-purged-video-artifacts')->assertSuccessful();

    Storage::disk('media')->assertMissing("{$base}/optimized_abc.mp4");
    Storage::disk('media')->assertMissing("{$base}/poster_abc.jpg");
});

it('is a clean no-op when the ledgered path is already gone (idempotent re-run)', function () {
    seedPurgedLedger(['videos/pro-1/already-clean']);

    $this->artisan('gdpr:sweep-purged-video-artifacts')
        ->expectsOutputToContain('recovered 0 orphan(s)')
        ->assertSuccessful();
});

it('skips ledger rows older than the configured window (left for the prefix GC backstop)', function () {
    $base = 'videos/pro-2/stale';
    Storage::disk('media')->put("{$base}/optimized_def.mp4", 'x');
    seedPurgedLedger([$base], now()->subDays(31)->toDateTimeString());

    $this->artisan('gdpr:sweep-purged-video-artifacts')->assertSuccessful();

    Storage::disk('media')->assertExists("{$base}/optimized_def.mp4");
});
