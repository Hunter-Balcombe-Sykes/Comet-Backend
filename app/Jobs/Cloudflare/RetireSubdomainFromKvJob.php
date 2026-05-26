<?php

namespace App\Jobs\Cloudflare;

use App\Jobs\Concerns\HasCloudflareRetryPolicy;
use App\Services\Cloudflare\CloudflareKvService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Removes a stale KV routing entry when a professional renames their handle.
// Without this, the old <handle>.partna.au subdomain continues to resolve
// via the stale entry — and could route to a future owner's profile if the
// handle is later claimed by someone else.
class RetireSubdomainFromKvJob implements ShouldQueue
{
    use Dispatchable, HasCloudflareRetryPolicy, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(public readonly string $handle)
    {
        $this->onQueue('default');
    }

    public function handle(CloudflareKvService $kv): void
    {
        if ($this->handle === '') {
            return;
        }

        // Exception is re-thrown so failed() runs as the single log/report site.
        $kv->delete($this->handle);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::warning('cloudflare.retire_subdomain_from_kv.failed', [
            'handle' => $this->handle,
            'error' => $e->getMessage(),
        ]);
    }
}
