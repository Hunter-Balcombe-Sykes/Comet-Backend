<?php

namespace Tests\Unit\Services\Exports;

use App\Services\Exports\JsonlPartWriter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JsonlPartWriterTest extends TestCase
{
    public function test_writes_jsonl_to_disk_and_returns_metadata(): void
    {
        Storage::fake('media');
        $writer = new JsonlPartWriter;

        $rows = (function () {
            yield ['id' => 'row1', 'amount' => 100];
            yield ['id' => 'row2', 'amount' => 200];
        })();

        $result = $writer->writePart(
            disk: 'media',
            remotePath: 'exports/commissions/p/a/parts/chunk-0.jsonl',
            rows: $rows,
        );

        $this->assertSame(2, $result['row_count']);
        $this->assertGreaterThan(0, $result['size']);
        $this->assertNotEmpty($result['sha256']);

        $contents = Storage::disk('media')->get('exports/commissions/p/a/parts/chunk-0.jsonl');
        $lines = array_filter(explode("\n", $contents));
        $this->assertCount(2, $lines);
        $this->assertSame(['id' => 'row1', 'amount' => 100], json_decode($lines[0], true));
    }

    public function test_writes_zero_rows_as_empty_part(): void
    {
        Storage::fake('media');
        $writer = new JsonlPartWriter;

        $result = $writer->writePart(
            disk: 'media',
            remotePath: 'exports/commissions/p/a/parts/chunk-0.jsonl',
            rows: (function () { yield from []; })(),
        );

        $this->assertSame(0, $result['row_count']);
        $this->assertSame(0, $result['size']);
        // Empty part file still gets uploaded — finalizer doesn't need to special-case missing parts.
        $this->assertTrue(Storage::disk('media')->exists('exports/commissions/p/a/parts/chunk-0.jsonl'));
    }
}
