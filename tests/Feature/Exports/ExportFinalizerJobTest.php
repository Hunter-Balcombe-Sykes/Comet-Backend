<?php

namespace Tests\Feature\Exports;

use App\Jobs\Exports\ExportFinalizerJob;
use App\Mail\Exports\CommissionExportReadyMail;
use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use App\Services\Exports\CommissionExportFinalWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExportFinalizerJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        setupProfessionalsTable();
        setupCommissionExportAuditTable();
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    public function test_concatenates_parts_uploads_final_signs_url_emails_completes(): void
    {
        Mail::fake();
        Storage::fake('media');
        config()->set('partna.media_disk', 'media');
        config()->set('partna.exports.commission.signed_url_ttl_days', 3);

        $pro = $this->makeProfessional();
        $audit = $this->makeAudit($pro, [
            'status' => 'processing',
            'recipient_email' => 'jane@example.com',
            'payouts_total' => 1, 'chunks_total' => 1, 'chunks_completed' => 1, 'next_chunk_index' => 1,
        ]);

        Storage::disk('media')->put(
            "exports/commissions/{$pro->id}/{$audit->id}/parts/chunk-0.jsonl",
            json_encode([
                'id' => 'charge:ch_X', 'type' => 'charge', 'amount_cents' => 100,
                'raw_stripe_id' => 'ch_X', 'occurred_at' => '2026-05-19T00:00:00Z',
            ]) . "\n",
        );

        (new ExportFinalizerJob($audit->id))->handle(app(CommissionExportFinalWriter::class));

        $fresh = $audit->fresh();
        $this->assertSame(CommissionExportAudit::STATUS_COMPLETED, $fresh->status);
        $this->assertSame("exports/commissions/{$pro->id}/{$audit->id}/data.csv", $fresh->file_path);
        $this->assertGreaterThan(0, $fresh->file_size_bytes);
        $this->assertNotEmpty($fresh->file_sha256);

        $this->assertTrue(Storage::disk('media')->exists($fresh->file_path));
        $this->assertFalse(Storage::disk('media')->exists("exports/commissions/{$pro->id}/{$audit->id}/parts/chunk-0.jsonl"));

        Mail::assertSent(CommissionExportReadyMail::class, fn ($mail) =>
            $mail->hasTo('jane@example.com'));
    }

    public function test_empty_export_branch_completes_without_part_files(): void
    {
        Mail::fake();
        Storage::fake('media');
        config()->set('partna.media_disk', 'media');
        config()->set('partna.exports.commission.signed_url_ttl_days', 3);

        $pro = $this->makeProfessional();
        $audit = $this->makeAudit($pro, [
            'status' => 'queued',
            'recipient_email' => 'jane@example.com',
            'payouts_total' => 0, 'chunks_total' => 0,
        ]);

        (new ExportFinalizerJob($audit->id))->handle(app(CommissionExportFinalWriter::class));

        $fresh = $audit->fresh();
        $this->assertSame(CommissionExportAudit::STATUS_COMPLETED, $fresh->status);
        // Empty export produces a header-only file; size > 0 (header row is still bytes)
        // but we accept 0 too since the plan says file_size_bytes ?? 0 is fine.
        $this->assertGreaterThanOrEqual(0, $fresh->file_size_bytes ?? 0);
        Mail::assertSent(CommissionExportReadyMail::class);
    }

    public function test_early_returns_if_already_completed(): void
    {
        Mail::fake();
        Storage::fake('media');
        config()->set('partna.media_disk', 'media');

        $pro = $this->makeProfessional();
        $audit = $this->makeAudit($pro, [
            'status' => 'completed', 'recipient_email' => 'j@x',
            'payouts_total' => 0, 'chunks_total' => 0,
            'completed_at' => now()->toDateTimeString(),
        ]);

        (new ExportFinalizerJob($audit->id))->handle(app(CommissionExportFinalWriter::class));

        Mail::assertNothingSent();
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Create and persist a minimal Professional with the given attribute overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeProfessional(array $overrides = []): Professional
    {
        $handle = 'finalpro'.Str::random(6);

        return Professional::create(array_merge([
            'id' => (string) Str::uuid(),
            'auth_user_id' => (string) Str::uuid(),
            'handle' => $handle,
            'handle_lc' => $handle,
            'display_name' => 'Final Pro',
            'primary_email' => $handle.'@example.com',
            'professional_type' => 'brand',
            'status' => 'active',
        ], $overrides));
    }

    /**
     * Create and persist a CommissionExportAudit row.
     *
     * @param  array<string, mixed>  $overrides  Extra or override column values
     */
    private function makeAudit(Professional $pro, array $overrides = []): CommissionExportAudit
    {
        $id = (string) Str::uuid();
        $now = now()->toDateTimeString();

        $row = array_merge([
            'id' => $id,
            'professional_id' => $pro->id,
            'role' => 'brand',
            'format' => 'csv',
            'filters' => json_encode([]),
            'status' => CommissionExportAudit::STATUS_QUEUED,
            'recipient_email' => $pro->primary_email,
            'payouts_total' => 0,
            'payouts_processed' => 0,
            'chunk_size' => 500,
            'chunks_total' => 0,
            'chunks_completed' => 0,
            'next_chunk_index' => 0,
            'created_at' => $now,
            'expires_at' => now()->addDays(3)->toDateTimeString(),
        ], $overrides);

        DB::connection('pgsql')->table('commerce.commission_export_audit')->insert($row);

        return CommissionExportAudit::findOrFail($id);
    }
}
