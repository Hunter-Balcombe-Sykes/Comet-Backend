<?php

namespace Tests\Feature\Console;

use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommissionExportsPruneExpiredCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        setupProfessionalsTable();
        setupCommissionExportAuditTable();
    }

    public function test_deletes_expired_file_and_clears_file_path(): void
    {
        Storage::fake('media');
        config()->set('partna.media_disk', 'media');

        $pro = $this->makeProfessional();
        $audit = $this->makeAudit($pro, [
            'status' => 'completed',
            'payouts_total' => 1, 'chunks_total' => 1, 'chunks_completed' => 1,
            'file_path' => "exports/commissions/{$pro->id}/x/data.csv",
            'expires_at' => now()->subDay()->toDateTimeString(),
            'completed_at' => now()->subDays(31)->toDateTimeString(),
        ]);
        Storage::disk('media')->put($audit->file_path, 'old data');

        $this->artisan('commission-exports:prune-expired')->assertSuccessful();

        $this->assertFalse(Storage::disk('media')->exists($audit->file_path));
        $this->assertNull($audit->fresh()->file_path);
        $this->assertSame('completed', $audit->fresh()->status); // status preserved
    }

    public function test_does_not_prune_unexpired_rows(): void
    {
        Storage::fake('media');
        config()->set('partna.media_disk', 'media');

        $pro = $this->makeProfessional();
        $filePath = "exports/commissions/{$pro->id}/y/data.csv";
        $audit = $this->makeAudit($pro, [
            'status' => 'completed',
            'payouts_total' => 1, 'chunks_total' => 1, 'chunks_completed' => 1,
            'file_path' => $filePath,
            'expires_at' => now()->addDays(7)->toDateTimeString(), // future
            'completed_at' => now()->subDays(1)->toDateTimeString(),
        ]);
        Storage::disk('media')->put($filePath, 'fresh data');

        $this->artisan('commission-exports:prune-expired')->assertSuccessful();

        $this->assertTrue(Storage::disk('media')->exists($filePath));
        $this->assertSame($filePath, $audit->fresh()->file_path);
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
        $handle = 'prunepro'.Str::random(6);

        return Professional::create(array_merge([
            'id' => (string) Str::uuid(),
            'auth_user_id' => (string) Str::uuid(),
            'handle' => $handle,
            'handle_lc' => $handle,
            'display_name' => 'Prune Pro',
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
