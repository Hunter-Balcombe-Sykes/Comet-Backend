<?php

namespace App\Jobs\Cloudflare;

use App\Services\Cloudflare\CloudflareDnsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provisions the Cloudflare TXT record for Shopify domain ownership verification.
 *
 * Counterpart to ProvisionBrandDnsJob (CNAME) — moved out of
 * EmbeddedSetupController::provisionDomainTxt to keep the embedded wizard's
 * PHP-FPM worker off the Cloudflare API round-trip (Master Pattern 16,
 * DB-F#SCALE-5).
 *
 * Idempotent: upsertTxt patches an existing record with a new value when
 * Shopify regenerates the verification token.
 */
class ProvisionBrandDnsTxtJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly string $professionalId,
        public readonly string $recordName,
        public readonly string $txtValue,
    ) {
        $this->onQueue('integrations');
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(CloudflareDnsService $dns): void
    {
        // upsertTxt is idempotent — patches the existing record when the
        // value differs, so re-dispatch with a fresh Shopify token always wins.
        $dns->upsertTxt($this->recordName, $this->txtValue);

        Log::info('cloudflare.provision_brand_dns_txt.provisioned', [
            'professional_id' => $this->professionalId,
            'record_name' => $this->recordName,
        ]);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('cloudflare.provision_brand_dns_txt.failed', [
            'professional_id' => $this->professionalId,
            'record_name' => $this->recordName,
            'error' => $e->getMessage(),
        ]);
    }
}
