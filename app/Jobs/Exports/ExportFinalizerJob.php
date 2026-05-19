<?php

namespace App\Jobs\Exports;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * STUB — replaced by Plan Task 12 with the real finalizer implementation.
 */
class ExportFinalizerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $auditId) {}

    public function handle(): void
    {
        // Real implementation lands in Plan Task 12.
    }
}
