<?php

namespace App\Jobs\Cloudflare;

use App\Jobs\Concerns\HasCloudflareRetryPolicy;
use App\Models\Core\Professional\User;
use App\Services\Cloudflare\CloudflareKvService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// Syncs one professional's subdomain routing entries in Cloudflare KV.
// All accounts are individual; only {"type":"individual"} entries are written.
// Historical aliases: {"type":"alias","target":"<current-handle>"} with expirationTtl.
// Genuine deletes go through RetireSubdomainFromKvJob, NOT this job.
//
// `ShouldBeUnique` with a 45s window collapses observer storms to a single KV write per 45s.
class SyncSubdomainToKvJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, HasCloudflareRetryPolicy, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $uniqueFor = 45;

    public function __construct(public readonly string $professionalId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->professionalId;
    }

    public function handle(CloudflareKvService $kv): void
    {
        $pro = User::query()->find($this->professionalId);

        if (! $pro || ! $pro->handle) {
            return;
        }

        $current = strtolower(trim((string) $pro->handle));

        // All accounts are individual. The Astro Worker reads this entry via
        // Service Binding and renders the public profile page.
        $kv->put($current, ['type' => 'individual'], null);
        $this->writeAliasEntries($kv, $pro->id, $current);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('cloudflare.sync_subdomain_to_kv.failed', [
            'professional_id' => $this->professionalId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Write alias KV entries — {type:'alias', target:'<current>'} with a TTL
     * derived from expires_at so Cloudflare auto-evicts when the alias expires.
     * Legacy NULL-expires_at aliases get no TTL (permanent until the next prune sweep).
     */
    private function writeAliasEntries(CloudflareKvService $kv, string $proId, string $current): void
    {
        $aliases = DB::table('core.professional_handle_aliases')
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
