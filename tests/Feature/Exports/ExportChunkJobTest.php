<?php

namespace Tests\Feature\Exports;

use App\Jobs\Exports\ExportChunkJob;
use App\Jobs\Exports\ExportFinalizerJob;
use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use App\Services\Exports\JsonlPartWriter;
use App\Services\Stripe\StripeRowGenerator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ExportChunkJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        setupProfessionalsTable();
        setupCommissionPayoutsTable();
        setupCommissionExportAuditTable();
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    public function test_first_chunk_marks_processing_writes_part_and_advances_cursor(): void
    {
        Bus::fake([ExportChunkJob::class, ExportFinalizerJob::class]);
        Storage::fake('media');
        config()->set('partna.media_disk', 'media');
        config()->set('partna.exports.commission.chunk_size', 2);

        $pro = $this->makeProfessional(['primary_email' => 'j@x.test']);
        // Seed 3 payouts; chunk size 2 → first chunk picks up 2, one remains
        $this->seedPayouts($pro->id, count: 3, role: 'brand');

        $audit = $this->makeAudit($pro, payoutsTotal: 3, chunksTotal: 2, chunkSize: 2);

        $this->mockGeneratorYielding([
            ['id' => 'charge:ch_AAAA', 'type' => 'charge', 'raw_stripe_id' => 'ch_AAAA'],
            ['id' => 'charge:ch_BBBB', 'type' => 'charge', 'raw_stripe_id' => 'ch_BBBB'],
        ]);

        (new ExportChunkJob($audit->id, 0))->handle(
            app(StripeRowGenerator::class),
            app(JsonlPartWriter::class),
        );

        $fresh = $audit->fresh();
        $this->assertSame(CommissionExportAudit::STATUS_PROCESSING, $fresh->status);
        $this->assertSame(2, $fresh->payouts_processed);
        $this->assertSame(1, $fresh->chunks_completed);
        $this->assertSame(1, $fresh->next_chunk_index);
        $this->assertNotNull($fresh->last_processed_payout_id);

        $this->assertTrue(
            Storage::disk('media')->exists("exports/commissions/{$pro->id}/{$audit->id}/parts/chunk-0.jsonl")
        );
        Bus::assertDispatched(ExportChunkJob::class, fn ($j) => $j->chunkIndex === 1);
        Bus::assertNotDispatched(ExportFinalizerJob::class);
    }

    public function test_terminal_chunk_dispatches_finalizer(): void
    {
        Bus::fake([ExportChunkJob::class, ExportFinalizerJob::class]);
        Storage::fake('media');
        config()->set('partna.media_disk', 'media');
        config()->set('partna.exports.commission.chunk_size', 10);

        $pro = $this->makeProfessional(['primary_email' => 'j@x.test']);
        $this->seedPayouts($pro->id, count: 2, role: 'brand');
        // chunks_total = 1 → when chunks_completed reaches 1 we dispatch the finalizer
        $audit = $this->makeAudit($pro, payoutsTotal: 2, chunksTotal: 1, chunkSize: 10);

        $this->mockGeneratorYielding([
            ['id' => 'charge:ch_X', 'type' => 'charge', 'raw_stripe_id' => 'ch_X'],
        ]);

        (new ExportChunkJob($audit->id, 0))->handle(
            app(StripeRowGenerator::class),
            app(JsonlPartWriter::class),
        );

        Bus::assertDispatched(ExportFinalizerJob::class, fn ($j) => $j->auditId === $audit->id);
        Bus::assertNotDispatched(ExportChunkJob::class);
    }

    public function test_early_returns_when_audit_is_terminal(): void
    {
        Bus::fake();
        Storage::fake('media');

        $pro = $this->makeProfessional(['primary_email' => 'j@x.test']);
        $audit = $this->makeAudit($pro, payoutsTotal: 2, chunksTotal: 1, chunkSize: 2, status: 'completed');

        // No generator mock needed — handle() must return before touching generator/writer
        (new ExportChunkJob($audit->id, 0))->handle(
            app(StripeRowGenerator::class),
            app(JsonlPartWriter::class),
        );

        Bus::assertNothingDispatched();
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
        $handle = 'chunkpro'.Str::random(6);

        return Professional::create(array_merge([
            'id' => (string) Str::uuid(),
            'auth_user_id' => (string) Str::uuid(),
            'handle' => $handle,
            'handle_lc' => $handle,
            'display_name' => 'Chunk Pro',
            'primary_email' => $handle.'@example.com',
            'professional_type' => 'brand',
            'status' => 'active',
        ], $overrides));
    }

    /**
     * Insert $count payout rows for $professionalId in the given $role column.
     * Each row gets a distinct created_at so cursor pagination is deterministic.
     */
    private function seedPayouts(string $professionalId, int $count, string $role = 'brand'): void
    {
        $column = $role === 'brand' ? 'brand_professional_id' : 'affiliate_professional_id';

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                $column => $professionalId,
                'status' => 'completed',
                // Stagger created_at so ORDER BY (created_at DESC, id DESC) is stable
                'created_at' => now()->subSeconds($i)->toDateTimeString(),
                'updated_at' => now()->subSeconds($i)->toDateTimeString(),
            ];
        }

        foreach (array_chunk($rows, 100) as $batch) {
            DB::connection('pgsql')->table('commerce.commission_payouts')->insert($batch);
        }
    }

    /**
     * Create and persist a CommissionExportAudit row.
     *
     * @param  array<string, mixed>  $overrides  Extra column values
     */
    private function makeAudit(
        Professional $pro,
        int $payoutsTotal = 0,
        int $chunksTotal = 1,
        int $chunkSize = 500,
        string $status = CommissionExportAudit::STATUS_QUEUED,
    ): CommissionExportAudit {
        $id = (string) Str::uuid();
        $now = now()->toDateTimeString();

        DB::connection('pgsql')->table('commerce.commission_export_audit')->insert([
            'id' => $id,
            'professional_id' => $pro->id,
            'role' => 'brand',
            'format' => 'csv',
            'filters' => json_encode([]),
            'status' => $status,
            'recipient_email' => $pro->primary_email,
            'payouts_total' => $payoutsTotal,
            'payouts_processed' => 0,
            'chunk_size' => $chunkSize,
            'chunks_total' => $chunksTotal,
            'chunks_completed' => 0,
            'next_chunk_index' => 0,
            'created_at' => $now,
            'expires_at' => now()->addDays(3)->toDateTimeString(),
        ]);

        return CommissionExportAudit::findOrFail($id);
    }

    /**
     * Bind a StripeRowGenerator mock that yields the given rows from forPayouts().
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function mockGeneratorYielding(array $rows): void
    {
        $mock = Mockery::mock(StripeRowGenerator::class);
        $mock->shouldReceive('forPayouts')->andReturnUsing(function () use ($rows) {
            foreach ($rows as $r) {
                yield $r;
            }
        });
        $this->app->instance(StripeRowGenerator::class, $mock);
    }
}
