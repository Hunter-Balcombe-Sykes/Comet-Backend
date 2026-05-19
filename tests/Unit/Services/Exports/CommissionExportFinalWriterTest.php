<?php

namespace Tests\Unit\Services\Exports;

use App\Services\Exports\CommissionExportFinalWriter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommissionExportFinalWriterTest extends TestCase
{
    public function test_writes_csv_with_header_and_part_rows(): void
    {
        Storage::fake('media');

        Storage::disk('media')->put('exports/p/a/parts/chunk-0.jsonl',
            json_encode($this->sampleRow('charge', 'row1')) . "\n");
        Storage::disk('media')->put('exports/p/a/parts/chunk-1.jsonl',
            json_encode($this->sampleRow('refund', 'row2')) . "\n");

        $writer = new CommissionExportFinalWriter;
        $tmpFinal = tempnam(sys_get_temp_dir(), 'final_') . '.csv';

        $meta = $writer->writeFinal(
            format: 'csv',
            outputPath: $tmpFinal,
            partPaths: ['exports/p/a/parts/chunk-0.jsonl', 'exports/p/a/parts/chunk-1.jsonl'],
            disk: 'media',
        );

        $this->assertGreaterThan(0, $meta['size']);
        $this->assertSame(2, $meta['row_count']);
        $contents = file_get_contents($tmpFinal);
        $this->assertStringContainsString('date,type,description,counterparty,amount_cents', $contents);
        $this->assertStringContainsString('row1', $contents);
        $this->assertStringContainsString('row2', $contents);

        @unlink($tmpFinal);
    }

    public function test_writes_xlsx_format(): void
    {
        Storage::fake('media');
        Storage::disk('media')->put('exports/p/a/parts/chunk-0.jsonl',
            json_encode($this->sampleRow('charge', 'row1')) . "\n");

        $writer = new CommissionExportFinalWriter;
        $tmpFinal = tempnam(sys_get_temp_dir(), 'final_') . '.xlsx';

        $meta = $writer->writeFinal(
            format: 'xlsx',
            outputPath: $tmpFinal,
            partPaths: ['exports/p/a/parts/chunk-0.jsonl'],
            disk: 'media',
        );

        $this->assertGreaterThan(0, $meta['size']);
        $this->assertSame('PK', substr(file_get_contents($tmpFinal), 0, 2));  // XLSX = zip magic

        @unlink($tmpFinal);
    }

    private function sampleRow(string $type, string $id): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'occurred_at' => '2026-05-19T00:00:00Z',
            'description' => 'sample',
            'amount_cents' => 1000,
            'currency_code' => 'AUD',
            'status' => 'succeeded',
            'payout_id' => 'po_x',
            'raw_stripe_id' => 'ch_x',
            'brand' => ['name' => 'B'],
            'affiliate' => ['name' => 'A'],
        ];
    }
}
