<?php

namespace App\Jobs\Cloudflare;

use App\Jobs\Concerns\HasCloudflareRetryPolicy;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Services\Cloudflare\CloudflareKvService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// Syncs one professional's subdomain routing entries in Cloudflare KV.
// Canonical handle: {"type":"brand"} or {"type":"affiliate","redirect":"..."}
// Historical aliases: {"type":"alias","target":"<current-handle>"} with expirationTtl.
// Dispatched by observers on: handle change, brand_partner_links change, brand URL change.
class SyncSubdomainToKvJob implements ShouldQueue
{
    use Dispatchable, HasCloudflareRetryPolicy, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(public readonly string $professionalId)
    {
        $this->onQueue('integrations');
    }

    public function handle(CloudflareKvService $kv): void
    {
        $pro = Professional::query()->find($this->professionalId);

        if (! $pro || ! $pro->handle) {
            return;
        }

        $current = strtolower(trim((string) $pro->handle));

        if ($pro->isBrand()) {
            $kv->put($current, ['type' => 'brand'], null);
            $this->writeAliasEntries($kv, $pro->id, $current);

            return;
        }

        // Affiliate: use their primary brand link's precomputed site_url.
        $siteUrl = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $pro->id)
            ->whereNotNull('site_url')
            ->orderBy('slot')
            ->value('site_url');

        if (! $siteUrl) {
            // No brand connection — retire the canonical entry so the Worker 404s.
            try {
                $kv->delete($current);
            } catch (\Throwable $e) {
                Log::warning('SyncSubdomainToKvJob: delete failed for unconnected affiliate', [
                    'professional_id' => $pro->id,
                    'handle'          => $current,
                    'message'         => $e->getMessage(),
                ]);
            }

            return;
        }

        $kv->put($current, ['type' => 'affiliate', 'redirect' => $siteUrl], null);
        $this->writeAliasEntries($kv, $pro->id, $current);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('cloudflare.sync_subdomain_to_kv.failed', [
            'professional_id' => $this->professionalId,
            'error'           => $e->getMessage(),
        ]);
    }

    /**
     * Write alias KV entries — {type:'alias', target:'<current>'} with a TTL
     * derived from expires_at so Cloudflare auto-evicts when the alias expires.
     * Legacy NULL-expires_at aliases get no TTL (permanent until the next prune sweep).
     */
    private function writeAliasEntries(CloudflareKvService $kv, string $proId, string $current): void
    {
        $aliases = DB::table('site.professional_handle_aliases')
            ->where('professional_id', $proId)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();

        foreach ($aliases as $alias) {
            $handle = strtolower(trim($alias->handle));
            if ($handle === '' || $handle === $current) {
                continue;
            }

            // max(60, ...) so we never pass a sub-minimum TTL to CF KV.
            $ttl = $alias->expires_at
                ? max(60, (int) now()->diffInSeconds(Carbon::parse($alias->expires_at), false))
                : null;

            $kv->put($handle, ['type' => 'alias', 'target' => $current], $ttl);
        }
    }
}
