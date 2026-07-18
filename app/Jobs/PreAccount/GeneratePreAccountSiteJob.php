<?php

namespace App\Jobs\PreAccount;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GeneratePreAccountSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    /** @var list<int> */
    public array $backoff = [30];

    public function __construct(
        public readonly string $buildId,
        public readonly bool $publish = false,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function handle(): void
    {
        // Implemented in the generator-job task.
    }
}
