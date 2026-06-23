<?php

namespace Tests\Feature\User\DataExport;

use App\Jobs\Gdpr\ExportUserDataJob;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Services\User\DataExport\DataExportPayloadBuilder;
use App\Services\User\DataExport\DataExportZipWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Seed a minimal audit row in the "processing" state, owned by $userId.
function seedExportAudit(string $userId, string $status = DataExportAudit::STATUS_QUEUED, ?string $createdAt = null): DataExportAudit
{
    return DataExportAudit::create([
        'user_id' => $userId,
        'professional_handle_snapshot' => 'jane',
        'professional_email_snapshot' => 'jane@example.com',
        'triggered_by' => DataExportAudit::TRIGGERED_BY_SELF,
        'recipient_email' => 'jane@example.com',
        'send_to' => DataExportAudit::SEND_TO_PROFESSIONAL,
        'status' => $status,
        'created_at' => $createdAt ?? now()->toDateTimeString(),
    ]);
}

// Seed a user row so user_id FK is resolvable.
function seedExportUser(string $userId): void
{
    DB::connection('pgsql')->table('core.users')->insertOrIgnore([
        'id' => $userId,
        'handle' => 'jane',
        'handle_lc' => 'jane',
        'display_name' => 'Jane',
        'primary_email' => 'jane@example.com',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

// Build a fake writeStreaming result. Creates a real temp file so fopen()
// inside the job can open it as a stream, and returns metadata shaped
// exactly like DataExportZipWriter::writeStreaming().
function fakeWriteResult(string $content = 'zip-bytes'): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'export-test-');
    file_put_contents($tmp, $content);

    return [
        'path' => $tmp,
        'sha256' => hash('sha256', $content),
        'size' => strlen($content),
        'record_counts' => ['customers' => 1],
    ];
}

beforeEach(function () {
    DataExportTestCase::boot();

    // All storage interactions go through the fake 'media' disk.
    Storage::fake('media');

    config(['partna.media_disk' => 'media']);
});

// ─── P2-13: Orphaned R2 cleanup when post-upload step fails ─────────────────

it('deletes the uploaded R2 object when a post-upload step throws, and marks the audit failed', function () {
    $userId = (string) Str::uuid();
    seedExportUser($userId);

    $audit = seedExportAudit($userId);
    $remotePath = "exports/{$userId}/{$audit->id}.zip";

    // Writer succeeds — returns a real temp file so put() has bytes to stream.
    $written = fakeWriteResult();

    $writer = \Mockery::mock(DataExportZipWriter::class);
    $writer->shouldReceive('writeStreaming')->once()->andReturn($written);

    $builder = \Mockery::mock(DataExportPayloadBuilder::class);

    // Mail facade: throw AFTER upload succeeds to simulate an SMTP failure
    // between the R2 put() and the DB markCompleted(). Use Mockery on the
    // underlying mailer rather than Mail::fake() so the throw propagates.
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP failure'));

    app()->instance(DataExportZipWriter::class, $writer);
    app()->instance(DataExportPayloadBuilder::class, $builder);

    // Running the job should: upload the zip, hit SMTP throw, catch it,
    // delete the R2 object, markFailed, then re-throw for queue retry.
    expect(fn () => (new ExportUserDataJob($audit->id))->handle())
        ->toThrow(\RuntimeException::class, 'SMTP failure');

    // The R2 object must have been cleaned up — no orphan left in storage.
    Storage::disk('media')->assertMissing($remotePath);

    // The audit row must be marked failed with the error message.
    $audit->refresh();
    expect($audit->status)->toBe(DataExportAudit::STATUS_FAILED);
    expect($audit->error_message)->toContain('SMTP failure');
});

// ─── P3-18: email delivery status stamped on handoff to the mailer ─────────

it('stamps email_delivery_status = sent alongside email_sent_at when markEmailSent runs', function () {
    $userId = (string) Str::uuid();
    seedExportUser($userId);
    $audit = seedExportAudit($userId);

    expect($audit->email_sent_at)->toBeNull()
        ->and($audit->email_delivery_status)->toBeNull();

    $audit->markEmailSent();

    $audit->refresh();
    expect($audit->email_sent_at)->not->toBeNull()
        ->and($audit->email_delivery_status)->toBe(DataExportAudit::EMAIL_DELIVERY_SENT);
});

it('does NOT attempt to delete R2 when the failure happens before the upload (e.g. zip writer throws)', function () {
    $userId = (string) Str::uuid();
    seedExportUser($userId);

    $audit = seedExportAudit($userId);
    $remotePath = "exports/{$userId}/{$audit->id}.zip";

    // Writer throws before any upload happens.
    $writer = \Mockery::mock(DataExportZipWriter::class);
    $writer->shouldReceive('writeStreaming')->once()->andThrow(new \RuntimeException('disk full'));

    $builder = \Mockery::mock(DataExportPayloadBuilder::class);

    app()->instance(DataExportZipWriter::class, $writer);
    app()->instance(DataExportPayloadBuilder::class, $builder);

    expect(fn () => (new ExportUserDataJob($audit->id))->handle())
        ->toThrow(\RuntimeException::class, 'disk full');

    // Nothing was uploaded — the path must not exist.
    Storage::disk('media')->assertMissing($remotePath);

    // Audit is marked failed.
    $audit->refresh();
    expect($audit->status)->toBe(DataExportAudit::STATUS_FAILED);
});

// ─── JOB-3: null-audit path must throw, not silently succeed ─────────────────

it('throws when the audit row is missing so the queue records a failed_jobs entry (JOB-3)', function () {
    // JOB-3: a missing audit row is a real anomaly (deleted/rolled-back after afterCommit
    // dispatch). Previously the job called report() then returned — the queue marked it
    // SUCCEEDED with no failed_jobs entry, no retry, no Horizon alert, and a lost GDPR
    // export. It must now throw so the failure is recorded and the job can be retried.
    $missingId = (string) Str::uuid();

    expect(fn () => (new ExportUserDataJob($missingId))->handle())
        ->toThrow(\RuntimeException::class, "audit row not found for audit_id={$missingId}");
});
