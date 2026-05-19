<?php

namespace App\Jobs\Exports;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * STUB — replaced by Plan Task 11 with the real chunk-job implementation.
 * Exists now so CommissionExportService can dispatch it and tests can assert
 * dispatch without the class being missing.
 */
class ExportChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $auditId, public int $chunkIndex) {}

    public function handle(): void
    {
        // Real implementation lands in Plan Task 11.
    }
}
