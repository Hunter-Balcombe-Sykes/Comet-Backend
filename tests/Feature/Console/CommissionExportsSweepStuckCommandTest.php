<?php

namespace Tests\Feature\Console;

use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommissionExportsSweepStuckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        setupProfessionalsTable();
        setupCommissionExportAuditTable();
    }

    public function test_flips_stuck_processing_rows_to_failed(): void
    {
        config()->set('partna.exports.commission.stuck_watchdog_minutes', 60);
        $pro = $this->makeProfessional();

        $stuck = $this->makeAudit($pro, [
            'status' => 'processing',
            'processing_at' => now()->subMinutes(90)->toDateTimeString(),
        ]);
        $fresh = $this->makeAudit($pro, [
            'status' => 'processing',
            'processing_at' => now()->subMinutes(10)->toDateTimeString(),
        ]);

        $this->artisan('commission-exports:sweep-stuck')->assertSuccessful();

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('processing watchdog timeout', $stuck->fresh()->error_message);
        $this->assertSame('processing', $fresh->fresh()->status);
    }

    public function test_outputs_correct_count_when_no_stuck_rows(): void
    {
        config()->set('partna.exports.commission.stuck_watchdog_minutes', 60);

        $this->artisan('commission-exports:sweep-stuck')
            ->assertSuccessful()
            ->expectsOutput('Swept 0 stuck export(s) → failed.');
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Create and persist a minimal Professional.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeProfessional(array $overrides = []): Professional
    {
        $handle = 'sweeppro'.Str::random(6);

        return Professional::create(array_merge([
            'id' => (string) Str::uuid(),
            'auth_user_id' => (string) Str::uuid(),
            'handle' => $handle,
            'handle_lc' => $handle,
            'display_name' => 'Sweep Pro',
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
